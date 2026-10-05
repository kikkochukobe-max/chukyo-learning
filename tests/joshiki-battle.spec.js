// @ts-check
// 常識バトル（生徒の画面 learning/game/game_es_joshiki_battle.html）の回帰テスト。
//
// PHP はテスト環境で動かないので、api/battle_play.php と同じ約束（時刻は start_ms からの計算、
// 正解は締め切り＋猶予のあとでしか返さない）で動く偽サーバーを page.route に置く。
// 時間がかからないよう、1問2秒・正解発表1秒・カウントダウン1.5秒にしてある。
//
// 守りたい仕様:
//   ・部屋番号で入る → 待合室 → スタート → 出題 → 解答 → 正解発表 → 結果
//   ・答えなかった問題は「時間切れ」。解答の正誤は正解発表まで出さない
//   ・対戦中にほかのアプリ・タブを開いたら（visibilitychange）失格を sendBeacon で送る
//   ・フォーカスが1秒外れたまま（PCで別のウィンドウ）なら失格。一瞬なら失格にしない
//   ・対戦中に開き直したら（サーバーが dq を返す）失格の画面
//   ・未ログインならログインを促す
const { test, expect } = require('@playwright/test');

const { makeFake, boot } = require('./fixtures/battle-fake');

async function joinAndStart(page, fake) {
  await expect(page.locator('#scr-join')).toBeVisible();
  await page.fill('#code', '1234');
  await page.click('#join-btn');
  await expect(page.locator('#scr-lobby')).toBeVisible();
  await expect(page.locator('#lb-code')).toHaveText('1234');
  fake.start();
  await expect(page.locator('#scr-play')).toBeVisible({ timeout: 8000 });
  await expect(page.locator('#choices .choice')).toHaveCount(6);
}

test('部屋に入って3問解き、結果まで進む', async ({ page }) => {
  const fake = makeFake({ count: 3 });
  await boot(page, fake);
  await joinAndStart(page, fake);

  // 1問目: 正解を押す。送った直後は正誤を出さない
  await expect(page.locator('#qtext')).toHaveText('問題1のぶん');
  await page.locator('#choices .choice').nth(fake.qs[0].correct).click();
  await expect(page.locator('#status')).toContainText('こたえを送った');
  await expect(page.locator('#status')).not.toContainText('せいかい');
  // 正解発表
  await expect(page.locator('#status')).toContainText('せいかい', { timeout: 5000 });
  await expect(page.locator('#choices .choice').nth(fake.qs[0].correct)).toHaveAttribute('data-divp-mark', 'correct');
  await expect(page.locator('#sc')).toContainText('10点');
  // 対戦中は自分の順位を見せない（点数だけ）
  await expect(page.locator('#sc')).not.toContainText('位');
  await expect(page.locator('#status')).not.toContainText('位');

  // 2問目: 答えない → 時間切れ → 正解が示される
  await expect(page.locator('#qtext')).toHaveText('問題2のぶん', { timeout: 5000 });
  await expect(page.locator('#status')).toContainText('時間切れ', { timeout: 5000 });
  await expect(page.locator('#choices .choice').nth(fake.qs[1].correct)).toHaveAttribute('data-divp-mark', 'answer', { timeout: 5000 });

  // 3問目: まちがいを押す
  await expect(page.locator('#qtext')).toHaveText('問題3のぶん', { timeout: 5000 });
  const wrong = (fake.qs[2].correct + 1) % 6;
  await page.locator('#choices .choice').nth(wrong).click();
  await expect(page.locator('#status')).toContainText('ざんねん', { timeout: 5000 });
  await expect(page.locator('#choices .choice').nth(wrong)).toHaveAttribute('data-divp-mark', 'wrong');

  // 結果
  await expect(page.locator('#scr-result')).toBeVisible({ timeout: 8000 });
  await expect(page.locator('#res-top')).toContainText('1位');
  await expect(page.locator('#res-top')).toContainText('10点');
  await expect(page.locator('#res-review .rv')).toHaveCount(3);
  await expect(page.locator('#res-review .rv .mark.ok')).toHaveCount(1);
  // 失格は送られていない
  expect(await page.evaluate(() => window.__beacons.length)).toBe(0);
});

test('締め切りまでは選び直せて、最後に選んだ答えで採点される', async ({ page }) => {
  const fake = makeFake({ count: 1 });
  await boot(page, fake);
  await joinAndStart(page, fake);
  const ok = fake.qs[0].correct, ng = (ok + 1) % 6;
  // まずまちがいを押し、そのあと正解に選び直す
  await page.locator('#choices .choice').nth(ng).click();
  await expect(page.locator('#status')).toContainText('えらび直せる');
  await expect(page.locator('#choices .choice').nth(ng)).not.toBeDisabled();
  await page.locator('#choices .choice').nth(ok).click();
  await expect(page.locator('#choices .choice').nth(ok)).toHaveClass(/sel/);
  await expect(page.locator('#choices .choice').nth(ng)).not.toHaveClass(/sel/);
  // 正解発表は最後の答え（正解）で出る
  await expect(page.locator('#status')).toContainText('せいかい', { timeout: 5000 });
  await expect(page.locator('#choices .choice').nth(ok)).toHaveAttribute('data-divp-mark', 'correct');
  await expect(page.locator('#choices .choice').nth(ng)).not.toHaveAttribute('data-divp-mark', 'wrong');
  expect(fake.st.answers[0]).toBe(ok);
});

test('計算問題だけ制限時間が長く、次の問題はそのぶん後ろにずれる', async ({ page }) => {
  const fake = makeFake({ count: 2, calc: [0] });   // 1問目が計算問題（4秒）、2問目はふつう（2秒）
  await boot(page, fake);
  await page.fill('#code', '1234');
  await page.click('#join-btn');
  await expect(page.locator('#lb-meta')).toContainText('計算は4秒');
  fake.start();
  await expect(page.locator('#qtext')).toHaveText('問題1のぶん', { timeout: 8000 });
  await expect(page.locator('#qcat')).toContainText('計算問題・4秒');
  // ふつうの問題（2秒）なら締め切っている時刻でも、まだ答えられる
  await page.waitForTimeout(2600);
  await expect(page.locator('#choices .choice').first()).not.toBeDisabled();
  await page.locator('#choices .choice').nth(fake.qs[0].correct).click();
  await expect(page.locator('#status')).toContainText('せいかい', { timeout: 5000 });
  // 2問目はふつうの問題
  await expect(page.locator('#qtext')).toHaveText('問題2のぶん', { timeout: 5000 });
  await expect(page.locator('#qcat')).not.toContainText('計算問題');
  await expect(page.locator('#scr-result')).toBeVisible({ timeout: 10000 });
  expect(fake.st.answers[0]).toBe(fake.qs[0].correct);
});

test('対戦中にほかのアプリ・タブを開いたら失格になる', async ({ page }) => {
  const fake = makeFake({ count: 3 });
  await boot(page, fake);
  await joinAndStart(page, fake);
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect(page.locator('#scr-dq')).toBeVisible();
  await expect(page.locator('#dq-why')).toContainText('ほかのアプリ・タブ');
  const beacons = await page.evaluate(() => window.__beacons);
  expect(beacons.length).toBe(1);
  expect(beacons[0].url).toContain('/api/battle_play.php');
  expect(JSON.parse(beacons[0].body)).toMatchObject({ action: 'dq', room_id: 9, reason: 'hidden' });
});

test('待合室ではアプリを切りかえても失格にならない', async ({ page }) => {
  const fake = makeFake({ count: 3 });
  await boot(page, fake);
  await page.fill('#code', '1234');
  await page.click('#join-btn');
  await expect(page.locator('#scr-lobby')).toBeVisible();
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect(page.locator('#scr-lobby')).toBeVisible();
  expect(await page.evaluate(() => window.__beacons.length)).toBe(0);
});

test('PCで別のウィンドウに1秒以上いたら失格、一瞬なら失格にしない', async ({ page }) => {
  const fake = makeFake({ count: 3 });
  await boot(page, fake);
  await joinAndStart(page, fake);
  // 一瞬（0.4秒）だけ外れる → 失格にしない
  await page.evaluate(() => { window.__realHasFocus = document.hasFocus.bind(document); document.hasFocus = () => false; });
  await page.waitForTimeout(400);
  await page.evaluate(() => { document.hasFocus = window.__realHasFocus; });
  await page.waitForTimeout(800);
  await expect(page.locator('#scr-play')).toBeVisible();
  expect(await page.evaluate(() => window.__beacons.length)).toBe(0);
  // 外れたまま → 失格
  await page.evaluate(() => { document.hasFocus = () => false; });
  await expect(page.locator('#scr-dq')).toBeVisible({ timeout: 3000 });
  const beacons = await page.evaluate(() => window.__beacons);
  expect(JSON.parse(beacons[0].body)).toMatchObject({ action: 'dq', reason: 'blur' });
});

test('対戦中に開き直したら失格の画面になり、終わると結果が出る', async ({ page }) => {
  const fake = makeFake({ count: 1 });
  fake.st.me = 'waiting';
  fake.start();
  fake.st.me = 'dq';           // サーバーが「対戦中に mine が来た＝開き直した」で失格にした状態
  await boot(page, fake);
  await expect(page.locator('#scr-dq')).toBeVisible();
  await expect(page.locator('#dq-why')).toContainText('開き直した');
  await expect(page.locator('#scr-result')).toBeVisible({ timeout: 10000 });
  await expect(page.locator('#res-top')).toContainText('失格');
});

test('未ログインならログインを促す', async ({ page }) => {
  await boot(page, makeFake(), { loggedIn: false });
  await expect(page.locator('#scr-login')).toBeVisible();
});

test('ない部屋番号はその場で知らせる', async ({ page }) => {
  await boot(page, makeFake());
  await page.fill('#code', '9999');
  await page.click('#join-btn');
  await expect(page.locator('#join-err')).toHaveText('その番号の部屋はありません');
});
