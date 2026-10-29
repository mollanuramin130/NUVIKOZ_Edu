# SEC-01 native source checkpoint — PARTIAL

SecureTokenVault.kt implements one Android Keystore AES-256-GCM persistence mechanism using provider-generated random IVs, versioned AAD and AtomicFile durable writes in noBackupFilesDir. Only authenticated ciphertext reaches disk. Missing keys/corruption fail closed; no key regeneration over an unreadable record. Clear destroys the key before ciphertext deletion. No credentials/logs/SharedPreferences/network changes.

This file is NOT wired into MainActivity or the existing MethodChannel yet. The default Dart adapter still fails closed. No Android Gradle project, SDK/JDK, instrumentation runner or executable native validation is installed in this checkpoint. Android compilation, debug/release builds, instrumentation, process restart, corruption/key invalidation and device tests: NOT RUN. Source is not production validated. Do not enable it before tests and integration.

NEXT: establish compatible Android project/build configuration in the approved metadata-blocked hosted environment; replace readNBytes with an Android-compatible bounded read if required by minSdk; add native read/write/clear, tampering, missing-key, crash/backup and interrupted-write tests, then wire MethodChannel with generic redacted errors and backup exclusion/network policy. Preserve unsupported-platform denial. SEC-02/03 have not started.

## Owner-assigned handoff

SEC-01 is PARTIAL and assigned exclusively to Android Studio Gemini on `feature/m1-security-completion`. Saved source checkpoint: `a4a54e3a83e5413961fdf78fb8c4b62f882ac1af`, based on main `c9ae3cd5d5cc2dc7683fe61576fbc851f49b85ff`. Source: `apps/edu-mobile/android/app/src/main/kotlin/com/nuvikoz/edu/SecureTokenVault.kt`. Preserve the Dart interface in `lib/auth/secure_token_store.dart` and channel `nuvikoz.edu/secure-token-store` (read/write/clear, confirmed write/clear). Gemini owns native project/toolchain, Keystore review, MethodChannel integration and all native tests described above. ChatGPT Work must not concurrently edit SEC-01 files.

Native tests/builds remain NOT RUN; repository integrity alone is not native validation. Missing handler stays fail closed until actual integration is tested; no plaintext fallback. Future ChatGPT SEC-02/03 backend/web work must use a separate branch from latest main. No PR is authorized by this publication task; no deployment/merge.
