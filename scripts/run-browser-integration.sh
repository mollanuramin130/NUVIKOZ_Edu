#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${RUNNER_TEMP:?Use isolated CI runner}"
export EDU_BROWSER_ORIGINS=https://web.edu.test:3443
export NEXT_PUBLIC_EDU_API_BASE_URL=https://api.edu.test:8443
export CACHE_STORE=database
export EDU_BROWSER_TRUSTED_PROXIES=127.0.0.1
export EDU_TEST_KEY="$RUNNER_TEMP/edu-test.key"
export EDU_TEST_CERT="$RUNNER_TEMP/edu-test.crt"
sudo sh -c 'echo "127.0.0.1 web.edu.test api.edu.test" >> /etc/hosts'
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$EDU_TEST_KEY" -out "$EDU_TEST_CERT" -days 1 -subj /CN=edu.test -addext 'subjectAltName=DNS:web.edu.test,DNS:api.edu.test' >/dev/null 2>&1
(cd apps/edu-api && php artisan key:generate --force)
php scripts/browser-fixtures.php
(cd apps/edu-web && npm ci && npx playwright install --with-deps chromium && npm run build)
pids=()
cleanup() { for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done; rm -f "$EDU_TEST_KEY" "$EDU_TEST_CERT"; }
trap cleanup EXIT
(cd apps/edu-api && exec php artisan serve --host=127.0.0.1 --port=8000) >"$RUNNER_TEMP/edu-api-test.log" 2>&1 & pids+=("$!")
(cd apps/edu-web && exec npm start -- --hostname 127.0.0.1 --port 3000) >"$RUNNER_TEMP/edu-web-test.log" 2>&1 & pids+=("$!")
node scripts/browser-https.mjs >"$RUNNER_TEMP/edu-proxy-test.log" 2>&1 & pids+=("$!")
for attempt in $(seq 1 30); do
  if curl --silent --fail http://127.0.0.1:3000/login >/dev/null && curl --silent --fail http://127.0.0.1:8000/api/v1/health >/dev/null; then break; fi
  sleep 1
done
(cd apps/edu-web && npx playwright test)
