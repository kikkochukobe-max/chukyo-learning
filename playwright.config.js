// @ts-check
const { defineConfig, devices } = require('@playwright/test');

// テストはリポジトリ直下を公開ルートに見立てた静的サーバー越しに実行する
// （ツールが /assets/... を絶対パスで読むため file:// では動かない）。
module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: false,   // 100問完走テストが重いので直列
  timeout: 60_000,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:4173',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    // 実機相当（hasTouch/isMobile）。ブラウザは chromium 固定
    // （webkit を別途インストールしなくても走るようにするため）。
    { name: 'iphone', use: { ...devices['iPhone 13'], browserName: 'chromium' } },
  ],
  webServer: {
    command: 'node tests/static-server.js',
    url: 'http://127.0.0.1:4173/learning/math/math_es_hyakumasu.html',
    reuseExistingServer: !process.env.CI,
    stdout: 'ignore',
  },
});
