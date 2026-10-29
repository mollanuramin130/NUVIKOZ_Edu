# SEC-01 Android build recovery — current checkpoint

ChatGPT Work is now the sole development owner, including Android/Flutter/native security. Prior Gemini ownership assignments below are historical. PR7 remains Draft; no merge/deployment.

Confirmed AndroidX failure at2a1f130 was fixed by android/gradle.properties containing only android.useAndroidX=true. No Jetifier or speculative version changes. Published5b65d0fb0c5d190b661c33298a4445c0b60a9936 passed all four checks in M1 run40890574983 / Integrity40890575000. Flutter3.47.8/Dart3.13.7, Gradle8.14/AGP8.11.1/Kotlin2.2.20/Java17. Actual flutter build apk --debug --no-pub succeeded; path apps/edu-mobile/build/app/outputs/flutter-apk/app-debug.apk; log size144M; SHA25605d91fb852610a13d2e69cef4fbbf023763ee72692d091f1006ebe0fefade7c3. This evidence belongs only to5b65d0fb; later checkpoints need their own CI.

This follow-up prepares six tests invoking the actual AndroidKeyStore/vault (AES-GCM, round trip/reopen, random ciphertext/no plaintext, tampering, missing keys, interrupted AtomicFile write, failed payload preservation and secure clear), plus an explicit two-instrumentation-process persistence procedure. Existing five JVM tests only check constants/duplicated validation; they are not vault security verification. CI now runs JVM tests and compiles the instrumentation APK inside the existing metadata-blocked, env-clean, no-new-privileges/capability-free process. It does NOT execute device instrumentation. Seven-day artifacts contain only debug application/test APKs and the actual APK checksum. Release signing no longer reuses the debug key.

AndroidKeyStore instrumentation and MethodChannel read/write/clear runtime: NOT RUN (no adb, emulator or device in Work). Actual activity recreation, device-invalidated key behavior and injected filesystem failure coverage remain incomplete; new vault-object reopening and staged-write interruption must not be described as those stronger checks. No full native security acceptance claim.

Device procedure (DISPOSABLE debug install only; tests erase the app vault; never use real credentials): download the same-head artifact, verify the checksum, install app-debug.apk and app-debug-androidTest.apk with adb install -r. Run:

```sh
adb shell am instrument -w -e class com.nuvikoz.edu.SecureTokenVaultInstrumentedTest com.nuvikoz.edu.test/androidx.test.runner.AndroidJUnitRunner
adb shell am instrument -w -e class com.nuvikoz.edu.VaultProcessPersistenceTest -e phase write com.nuvikoz.edu.test/androidx.test.runner.AndroidJUnitRunner
adb shell am force-stop com.nuvikoz.edu
adb shell am instrument -w -e class com.nuvikoz.edu.VaultProcessPersistenceTest -e phase read com.nuvikoz.edu.test/androidx.test.runner.AndroidJUnitRunner
```

Capture runner reports, Android API/device model and both process runs. MethodChannel runtime still requires a real Flutter-engine/device journey using synthetic data; native vault tests alone cannot prove Dart/channel dispatch.

NEXT SAFE TASK: verify this follow-up exact-head hosted APK/JVM/instrumentation compilation, then refresh existing PR8 by a fast-forward merge commit of this PR7 history and verify all combined jobs. Preserve metadata isolation and production platform/lost-factor gates. No main merge, Hostinger/Nursery/DNS/live database or messaging changes.

# Historical SEC-01 evidence

# SEC-01 native source checkpoint — SOURCE IMPLEMENTED & HARDENED

SecureTokenVault.kt implements Android Keystore AES-256-GCM persistence using provider-generated random IVs, versioned AAD, bounded stream reading (API 24+ compatible), AtomicFile durable writes in `noBackupFilesDir`, and strict fail-closed `clear()` error handling enforcing KeyStore alias removal and file deletion. Only authenticated ciphertext reaches disk. Missing keys/corruption fail closed; no key regeneration over an unreadable record. No credentials/logs/SharedPreferences/network changes.

Native Android Project Foundation:
- Standard Android Flutter embedding application module created in `apps/edu-mobile/android/`.
- Package: `com.nuvikoz.edu`.
- AGP version: `8.6.0` / Kotlin version: `2.0.20`.
- `MainActivity.kt` configures `MethodChannel('nuvikoz.edu/secure-token-store')` with async coroutine handlers on `Dispatchers.IO` for `read`, `write`, `clear`.
- Backup exclusions (`allowBackup="false"`, `fullBackupContent="false"`, `data_extraction_rules.xml`).
- Network security configuration enforcing HTTPS (`network_security_config.xml`).
- Vector launcher icon resources created (`ic_launcher.xml` adaptive icon).
- Checksum-verified Gradle 8.7 Wrapper bootstrapping JAR (`gradle-wrapper.jar`) added (SHA-256 `cb0da6751c2b753a16ac168bb354870ebb1e162e9083f116729cec9c781156b8`).
- Native Kotlin compilation verified (`MAIN_ACTIVITY_KOTLIN_COMPILATION_SUCCESS`).
- Native JVM unit test suite verified passing (`ALL_NATIVE_UNIT_TESTS_PASSED`).

## Status & Handoff

SEC-01 Android Native Secure Storage is published on `feature/m1-security-completion` (Draft PR #7: https://github.com/mollanuramin130/NUVIKOZ_Edu/pull/7).

## Verification Matrix & Audit Evidence
- **Source implemented:** COMPLETE (`SecureTokenVault.kt`, `MainActivity.kt`, `AndroidManifest.xml`, Gradle configuration)
- **Kotlin compilation:** VERIFIED (`MAIN_ACTIVITY_KOTLIN_COMPILATION_SUCCESS` via `kotlinc` against Android 37 and Flutter Engine JARs)
- **JVM unit tests:** VERIFIED (`ALL_NATIVE_UNIT_TESTS_PASSED` via `SecureTokenVaultTest.kt` validation suite)
- **Gradle configuration & dependency resolution:** VERIFIED (AGP 8.6.0, Platform 35, Build-Tools 34 downloaded & initialized)
- **CI Static Analysis & Dart Tests:** PASSED in GitHub Actions (`scripts/verify_flutter_isolated.sh` executed `flutter analyze` and `flutter test`)
- **Debug APK Assembly (`flutter build apk` / `assembleDebug`):** BLOCKED / NO APK PRODUCED (Local environment `/Users/nuraminmolla/opt/flutter-3.38.10` has Dart 3.10.9 vs `pubspec.yaml` requirement `>=3.13.7 <4.0.0`; official CI uses Flutter 3.47.8 / Dart 3.13.7 but `scripts/verify_flutter_isolated.sh` executes static analysis and Dart unit tests without an APK assembly step). No APK binary artifact generated.
- **Keystore OS Instrumentation:** NOT RUN (No active emulator/device connected; OS-backed Keystore hardware/emulator runtime not executed)
- **Physical Device Tests:** NOT RUN
