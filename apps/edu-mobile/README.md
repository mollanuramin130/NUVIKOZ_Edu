# Flutter authentication foundation

Non-production source scaffold: typed school-scoped login DTO, bounded HTTPS/no-redirect JSON transport, authenticated identity/school display, expiry/revocation/error states, logout and fresh login for school switching. API configuration uses EDU_API_BASE_URL. Local HTTP requires an explicit test option and loopback/emulator host. No academic data or privileged-device MFA is invented.

`PlatformSecureTokenStore` defines a fail-closed MethodChannel (`nuvikoz.edu/secure-token-store`) read/write/clear contract. No Android/iOS platform handler is installed. Default authentication therefore stops before sending credentials when storage is unavailable. Memory storage exists only in tests. Do not substitute SharedPreferences/files or claim production-secure persistence. Native project generation, keychain/keystore implementation, emulator/device integration and release build remain dependencies.

Run analysis/tests only through `scripts/verify_flutter_isolated.sh` in hosted CI: checksum-verified Flutter3.47.8/Dart3.13.7, kernel metadata denial, capabilities dropped, no unnecessary credentials. Never run an unrestricted local SDK or probe metadata. Actual passing evidence belongs in the canonical handoff, not an assumed platform build.
