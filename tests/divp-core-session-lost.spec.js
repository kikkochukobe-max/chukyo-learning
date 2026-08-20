// @ts-check
// divp-core.js：解いている途中でログインが切れたときに知らせることの回帰テスト。
//
// PHPセッションは actor（生徒／講師／保護者）を1組しか持てないので、同じブラウザで
// 講師ログインすると生徒は黙ってログアウトされ、以後の解答は1問も残らない。
// 以前は save_answer.php の 401 を .catch() で捨てていたため画面は何も変わらず、
// 「記録されているつもりで解き続ける」状態になっていた。
//
// 守りたい仕様:
//   ・401 を受けたら画面上部に帯を出す（ログインし直す導線つき）
//   ・帯はページごとに1回だけ（閉じたら出し直さない＝しつこくしない）
//   ・未ログインで始めた人には従来どおり「お試し」の案内（帯ではない）
//   ・500 など一時的な失敗では帯を出さない（次の解答で再送を試みる）
const { test, expect } = require('@playwright/test');

const BAR = '#divp-session-lost';

/* divp-core だけを読み込んだ素のページ（サーバー越し。about:blank では
   相対URLの fetch ができないので fixture を置いてある）でAPI応答をこちらで決める。
   startOk=true → ログイン済みで開始 / saveStatus → save_answer.php の応答 */
const FIXTURE = '/tests/fixtures/core-blank.html';

async function boot(page, opts) {
  await page.route('**/api/start_session.php', (route) =>
    opts.startOk
      ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, session_id: 7 }) })
      : route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ ok: false }) }));
  await page.route('**/api/save_answer.php', (route) =>
    route.fulfill({ status: opts.saveStatus, contentType: 'application/json', body: JSON.stringify({ ok: false }) }));
  await page.goto(FIXTURE);
  await page.evaluate(() => Divp.init('math_js2_ichijikansu'));
  await page.evaluate(() => Divp.ready());
}

async function answerOnce(page) {
  await page.evaluate(() => Divp.answer(true, { question_key: 'koten', question_params: { m: 'koten', s: 1 } }));
}

test('途中でログインが切れたら帯で知らせる（401）', async ({ page }) => {
  await boot(page, { startOk: true, saveStatus: 401 });
  await expect(page.locator(BAR)).toHaveCount(0);

  await answerOnce(page);
  await expect(page.locator(BAR)).toBeVisible();
  await expect(page.locator(BAR)).toContainText('記録が止まっています');
  await expect(page.locator(BAR + ' button', { hasText: 'ログインし直す' })).toBeVisible();

  // 閉じたら出し直さない（しつこくしない）
  await page.locator(BAR + ' button[aria-label="とじる"]').click();
  await expect(page.locator(BAR)).toHaveCount(0);
  await answerOnce(page);
  await expect(page.locator(BAR)).toHaveCount(0);
});

test('一時的な失敗(500)では帯を出さない', async ({ page }) => {
  await boot(page, { startOk: true, saveStatus: 500 });
  await answerOnce(page);
  await answerOnce(page);
  await expect(page.locator(BAR)).toHaveCount(0);
});

test('未ログインで始めた人には帯ではなく従来のお試し案内', async ({ page }) => {
  await boot(page, { startOk: false, saveStatus: 401 });
  await answerOnce(page);
  await expect(page.locator(BAR)).toHaveCount(0);
  await expect(page.locator('#divp-login-nudge')).toBeVisible();
});
