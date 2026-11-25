#!/usr/bin/env bash
# Only for a fresh GitHub-hosted Ubuntu runner. Never run on a production host.
set -euo pipefail
: "${GITHUB_ACTIONS:?GitHub-hosted CI required}"
: "${RUNNER_TEMP:?Disposable runner required}"
: "${GITHUB_WORKSPACE:?Checkout required}"
if [[ "${RUNNER_ENVIRONMENT:-}" != github-hosted ]]; then
  echo 'Refusing Flutter execution outside a GitHub-hosted runner.' >&2
  exit 1
fi

# Enforce kernel egress denial BEFORE any SDK command. Do not probe metadata URLs.
# IPv4 link-local covers Azure/GCP/AWS metadata; IPv6 ULA covers AWS IPv6 IMDS.
for range in 169.254.0.0/16 100.100.100.200/32; do
  sudo iptables -I OUTPUT 1 -d "$range" -j REJECT
  sudo iptables -C OUTPUT -d "$range" -j REJECT
done
for range in fe80::/10 fc00::/7; do
  sudo ip6tables -I OUTPUT 1 -d "$range" -j REJECT
  sudo ip6tables -C OUTPUT -d "$range" -j REJECT
done

sdk_root="$RUNNER_TEMP/edu-flutter-sdk"
verify_home="$RUNNER_TEMP/edu-flutter-home"
mkdir -p "$sdk_root" "$verify_home" "$verify_home/.android"
curl --fail --location --retry 2 --proto '=https' \
  https://storage.googleapis.com/flutter_infra_release/releases/stable/linux/flutter_linux_3.47.8-stable.tar.xz \
  -o "$sdk_root/sdk.tar.xz"
echo 'baf7dcc5a3dbba60fe863bf493a6cf203195621f7786eaa5a1bba02cd0d87dd2  '"$sdk_root/sdk.tar.xz" | sha256sum --check --strict
tar -xJf "$sdk_root/sdk.tar.xz" -C "$sdk_root"

android_home="${ANDROID_HOME:-/usr/local/lib/android/sdk}"
java_home="${JAVA_HOME:-/usr/lib/jvm/temurin-17-jdk-amd64}"

# Drop capabilities and privilege escalation. Pass no GitHub/cloud/proxy credentials.
# CI/BOT also short-circuit Flutter's Azure bot detector, independent of the firewall.
sudo setpriv --no-new-privs --bounding-set=-all --inh-caps=-all --ambient-caps=-all \
  --reuid="$(id -u)" --regid="$(id -g)" --init-groups \
  env -i HOME="$verify_home" PATH="$sdk_root/flutter/bin:$java_home/bin:/usr/local/bin:/usr/bin:/bin" \
  ANDROID_HOME="$android_home" ANDROID_USER_HOME="$verify_home/.android" JAVA_HOME="$java_home" \
  CI=true BOT=true GITHUB_ACTIONS=true FLUTTER_SUPPRESS_ANALYTICS=true \
  PUB_ENVIRONMENT=github-actions PUB_CACHE="$verify_home/pub-cache" \
  bash -euo pipefail -c '
    grep -E "^(NoNewPrivs|CapEff):" /proc/self/status
    test "$(awk "/^NoNewPrivs:/ {print \$2}" /proc/self/status)" = 1
    test "$(awk "/^CapEff:/ {print \$2}" /proc/self/status)" = 0000000000000000
    cd "$1/apps/edu-mobile"
    flutter --version
    flutter pub get --enforce-lockfile
    flutter analyze --no-pub
    flutter test --no-pub
    if flutter build apk --debug --no-pub; then
      :
    else
      flutter_status=$?
      printf "Flutter APK build exit: %s\n" "$flutter_status"
      (
        umask 077
        diagnostic_log=$(mktemp) || exit 1
        trap "rm -f -- \"\$diagnostic_log\"" EXIT
        cd android || exit 1
        printf "%s\n" "Diagnostic command: ./gradlew --no-daemon :app:assembleDebug --stacktrace"
        java -version 2>&1 | grep -m 1 -E "^openjdk version \"[0-9.+_-]+\"|^java version \"[0-9.+_-]+\"" || true
        grep -oE "gradle-[0-9.]+-bin[.]zip" gradle/wrapper/gradle-wrapper.properties || true
        grep -oE "com[.]android[.]application.*version \"[0-9.]+\"" settings.gradle || true
        grep -oE "^[[:space:]]*(compileSdk|targetSdk|minSdk|ndkVersion)[[:space:]]+[0-9.]+" app/build.gradle || true
        for ndk in "$ANDROID_HOME"/ndk/*; do
          if [ -d "$ndk" ]; then basename "$ndk" | grep -Ex "[0-9.]+" || true; fi
        done
        df -Pk . "$HOME" "$ANDROID_HOME" | awk "NR > 1 {print \"Available KiB: \" \$4}"
        df -Pi . "$HOME" "$ANDROID_HOME" | awk "NR > 1 {print \"Free inodes: \" \$4}"
        for directory in ../build ../build/app .gradle; do
          if [ -d "$directory" ]; then stat -c "%a %n" "$directory"; fi
        done
        if timeout --kill-after=10s 5m ./gradlew --no-daemon :app:assembleDebug --stacktrace >"$diagnostic_log" 2>&1; then
          gradle_status=0
        else
          gradle_status=$?
        fi
        printf "Gradle diagnostic exit: %s\n" "$gradle_status"
        # Emit exception types and fixed evidence labels, never raw Gradle messages.
        awk "
          /Caused by: / && causes < 8 {
            cause = \$0
            sub(/^.*Caused by: /, \"\", cause)
            sub(/[^[:alnum:]._\$].*$/, \"\", cause)
            if (cause ~ /[.]/ && length(cause) <= 160) {
              print \"Caused by: \" cause
              causes++
            }
          }
          /No space left on device/ {space=1}
          /Permission denied|AccessDeniedException/ {permission=1}
          /ZipException/ {zip=1}
          /build\/app\/outputs\/apk\/debug/ {apk=1}
          /build\/app\/intermediates/ {intermediates=1}
          END {
            if (!causes) print \"No nested exception type captured\"
            if (space) print \"Evidence: no space left on device\"
            if (permission) print \"Evidence: file access denied\"
            if (zip) print \"Evidence: ZIP exception\"
            if (apk) print \"Path category: build/app/outputs/apk/debug\"
            if (intermediates) print \"Path category: build/app/intermediates\"
            print \"Root cause requires review\"
          }" "$diagnostic_log"
        exit "$gradle_status"
      ) || printf "%s\n" "Gradle diagnostic did not complete successfully."
      exit "$flutter_status"
    fi
    echo "=== DEBUG APK ASSEMBLY EVIDENCE ==="
    ls -lh build/app/outputs/flutter-apk/app-debug.apk
    stat -c "%s bytes %n" build/app/outputs/flutter-apk/app-debug.apk
    sha256sum build/app/outputs/flutter-apk/app-debug.apk | tee build/app/outputs/flutter-apk/app-debug.apk.sha256
    # Compile the device-test APK and run JVM tests inside the same restricted process.
    # Compilation is NOT execution of AndroidKeyStore instrumentation.
    cd android
    ./gradlew --no-daemon testDebugUnitTest assembleDebugAndroidTest
    echo "=== NATIVE TEST APK COMPILED; DEVICE INSTRUMENTATION NOT RUN ==="
    # A distinct offline application/UID; never replaces the normal application.
    cd ..
    mkdir -p build/sec01-standard
    cp build/app/outputs/flutter-apk/app-debug.apk build/sec01-standard/app-debug.apk
    cp build/app/outputs/flutter-apk/app-debug.apk.sha256 build/sec01-standard/app-debug.apk.sha256
    cp build/app/outputs/apk/androidTest/debug/app-debug-androidTest.apk build/sec01-standard/app-debug-androidTest.apk
    export ORG_GRADLE_PROJECT_sec01RuntimeHarness=true
    flutter build apk --debug --no-pub --target test_driver/secure_store_runtime.dart
    mkdir -p build/sec01-channel-runtime
    cp build/app/outputs/flutter-apk/app-debug.apk build/sec01-channel-runtime/sec01-channel-app.apk
    cd android
    ./gradlew --no-daemon -Psec01RuntimeHarness=true -Ptarget=test_driver/secure_store_runtime.dart assembleDebugAndroidTest
    cd ..
    cp build/app/outputs/apk/androidTest/debug/app-debug-androidTest.apk build/sec01-channel-runtime/sec01-channel-tests.apk
    aapt_tool=$(find "$ANDROID_HOME/build-tools" -type f -name aapt | sort -V | tail -n 1)
    test -n "$aapt_tool"
    "$aapt_tool" dump badging build/sec01-channel-runtime/sec01-channel-app.apk > build/sec01-channel-runtime/app-manifest.txt
    grep -q "package: name=.com.nuvikoz.edu.sec01test." build/sec01-channel-runtime/app-manifest.txt
    "$aapt_tool" dump permissions build/sec01-channel-runtime/sec01-channel-app.apk > build/sec01-channel-runtime/app-permissions.txt
    if grep -q android.permission.INTERNET build/sec01-channel-runtime/app-permissions.txt; then
      echo "Refusing network-capable runtime harness" >&2; exit 1
    fi
    "$aapt_tool" dump badging build/sec01-channel-runtime/sec01-channel-tests.apk > build/sec01-channel-runtime/test-manifest.txt
    grep -q "package: name=.com.nuvikoz.edu.sec01test.test." build/sec01-channel-runtime/test-manifest.txt
    (cd build/sec01-channel-runtime && sha256sum sec01-channel-app.apk sec01-channel-tests.apk | tee SHA256SUMS)
    git -C "$1" rev-parse HEAD > build/sec01-channel-runtime/SOURCE_COMMIT
    # Keep the existing ordinary APK artifact paired with its original checksum/test APK.
    cp build/sec01-standard/app-debug.apk build/app/outputs/flutter-apk/app-debug.apk
    cp build/sec01-standard/app-debug.apk.sha256 build/app/outputs/flutter-apk/app-debug.apk.sha256
    cp build/sec01-standard/app-debug-androidTest.apk build/app/outputs/apk/androidTest/debug/app-debug-androidTest.apk
    echo "=== CHANNEL HARNESS APKs COMPILED; DEVICE RUNTIME NOT RUN ==="
  ' isolated-flutter "$GITHUB_WORKSPACE"
