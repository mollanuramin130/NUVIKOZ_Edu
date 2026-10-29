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
mkdir -p "$sdk_root" "$verify_home"
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
  env -i HOME="$verify_home" PATH="$sdk_root/flutter/bin:$java_home/bin:/usr/bin:/bin" \
  ANDROID_HOME="$android_home" JAVA_HOME="$java_home" \
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
    flutter build apk --debug --no-pub
    echo "=== DEBUG APK ASSEMBLY EVIDENCE ==="
    ls -lh build/app/outputs/flutter-apk/app-debug.apk
    sha256sum build/app/outputs/flutter-apk/app-debug.apk
  ' isolated-flutter "$GITHUB_WORKSPACE"
