# Flutter metadata investigation and verification boundary

Date: 2026-10-28. Original attempted command: `flutter --version` during SDK bootstrap. Its saved local log ends after building the tool and resolving its dependencies; the runtime attempt was stopped by automatic approval review for `169.254.169.254`. No credentials or metadata responses were deliberately requested, retained or used by the project.

## Static attribution

The downloaded official Flutter checkout is `737ba8f885dc774b53b442759999a312c9461e4e`, stable Flutter 3.47.8, engine `cf636b1dfb818dd6a3ff16e390e93b1a036969c8`. The official release manifest reports Dart 3.13.7 and Linux archive SHA-256 `baf7dcc5a3dbba60fe863bf493a6cf203195621f7786eaa5a1bba02cd0d87dd2`.

At that source revision:

- `packages/flutter_tools/lib/src/context_runner.dart`, `runInContext` initializes `globals.isRunningOnBot` before running the command.
- `packages/flutter_tools/lib/src/base/bot_detector.dart`, `BotDetector.isRunningOnBot`, falls back to `AzureDetector.isRunningOnAzure` when explicit CI indicators are absent.
- `AzureDetector` creates an HTTP request to `http://169.254.169.254/metadata/instance`, with a `Metadata` header, to detect Azure. This is the address and operation matching the warning.

This identifies the tool code path, rather than an application dependency fetching student data. No packet capture/process trace was preserved, so exact on-wire attribution beyond the command, warning and matching source cannot be proved retrospectively. The detector's intent does not justify permitting metadata access. Do not probe the endpoint, including a supposed harmless connectivity test.

## Newly authorized safe verification

The owner explicitly requested metadata-blocked isolated verification in this review. `scripts/verify_flutter_isolated.sh` runs only on a fresh GitHub-hosted Ubuntu runner and fails closed if that environment marker is absent. Before any SDK command it installs and checks OUTPUT rejection rules for IPv4 link-local, the Alibaba metadata address, IPv6 link-local and IPv6 ULA (including AWS IPv6 IMDS). Rule verification examines local kernel configuration; it sends no metadata request.

The SDK is downloaded from the official HTTPS archive and SHA-256 checked. Flutter executes as the ordinary runner user with all capabilities dropped and `NoNewPrivs=1`; privilege escalation cannot remove the rules. An empty environment is populated only with required paths, isolated HOME/pub cache and CI/analytics settings. No GitHub token, cloud credential, proxy credential or repository secret is passed. Checkout does not persist credentials. CI/BOT indicators additionally short-circuit the Azure detector; they are not the security boundary. The kernel rejection rules remain essential.

This is a disposable development runner, not a production server/network configuration change. Do not copy the firewall script to Hostinger or a shared host. Do not retry the original unrestricted local command. If firewall setup, privilege dropping, SDK integrity or execution fails, report that exact step and mark subsequent Flutter checks NOT RUN. See `docs/testing/M1_SECURITY_REVIEW.md` for observed results; workflow presence alone is not a pass.

## Observed resolution

Safe hosted verification succeeded at code commit `5ad94647949089dfe1360470ba1c770ff87d56c5`: archive hash OK, CapEff zero, NoNewPrivs 1, Flutter 3.47.8 / Dart 3.13.7, dependency resolution, analysis with no issues and 3 passing tests. Evidence: https://github.com/mollanuramin130/NUVIKOZ_Edu/actions/runs/40693663225/job/122834687155?pr=1 . This resolves the runtime-verification blocker without permitting metadata access. Unrestricted local execution remains prohibited.

The resolved dependency lock was downloaded from the subsequent reviewed CI artifact. Its ZIP digest matched `27d9e0805ce04bcba5d63bf53b411b3ab64c73e7e1ae7f209dfef4115798ea6e` before extracting only `pubspec.lock`. The committed lock and SDK-matching Dart minimum now support `pub get --enforce-lockfile`. The temporary artifact-export action was removed after retrieval; it is not a permanent runtime dependency.
