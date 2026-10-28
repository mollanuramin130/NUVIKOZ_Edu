import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: './tests/browser', workers: 1, retries: 0, use: {
  baseURL: 'https://web.edu.test:3443', ignoreHTTPSErrors: true,
  launchOptions: { args: ['--no-proxy-server', '--host-resolver-rules=MAP web.edu.test 127.0.0.1, MAP api.edu.test 127.0.0.1'] },
}, reporter: 'list' });
