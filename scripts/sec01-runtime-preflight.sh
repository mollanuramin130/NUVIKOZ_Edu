#!/usr/bin/env bash
# Capability investigation only. Never downloads or launches an Android runtime.
set -euo pipefail
: "${GITHUB_ACTIONS:?GitHub hosted CI required}"
: "${GITHUB_WORKSPACE:?Checkout required}"
test "${RUNNER_ENVIRONMENT:-}" = github-hosted

# Same approved host metadata boundary as the build; never probe a metadata URL.
for range in 169.254.0.0/16 100.100.100.200/32; do
  sudo iptables -I OUTPUT 1 -d "$range" -j REJECT
  sudo iptables -C OUTPUT -d "$range" -j REJECT
done
for range in fe80::/10 fc00::/7; do
  sudo ip6tables -I OUTPUT 1 -d "$range" -j REJECT
  sudo ip6tables -C OUTPUT -d "$range" -j REJECT
done

# No device chmod, group changes, modules, ADB, SDK or emulator command is permitted.
sudo setpriv --no-new-privs --bounding-set=-all --inh-caps=-all --ambient-caps=-all \
  --reuid="$(id -u)" --regid="$(id -g)" --init-groups \
  env -i PATH=/usr/bin:/bin PYTHONDONTWRITEBYTECODE=1 \
  python3 "$GITHUB_WORKSPACE/scripts/sec01-runtime-preflight.py"
