// @ts-check
// 「円周率チャレンジ」の回帰テスト。
// 円周率の桁はツール側で spigot アルゴリズムから計算しているので、
// ここでは既知の値（テスト側に直書きした40桁）を独立した答え合わせに使う。
const { test, expect } = require('@playwright/test');

const URL = '/learning/math/math_es_enshuritsu.html';
// 小数第1位から第40位まで
const PI40 = '1415926535897932384626433832795028841971';

async function start(page) {
  await page.goto(URL);
  await page.getByRole('button', { name: 'スタート' }).click();
  await expect(page.locator('#scr-play')).toBeVisible();
}
async function typeDigits(page, s) {
  for (const c of s) await page.keyboard.press(c);
}

// トップ画面には答え（円周率の並び）を出さない
test('トップ画面に円周率の桁を出さない', async ({ page }) => {
  await page.goto(URL);
  expect(await page.locator('#scr-start').innerText()).not.toContain('14159');
});

test('正しい桁を打つと桁数が伸び、マスに数字が並ぶ', async ({ page }) => {
  await start(page);
  await typeDigits(page, PI40.slice(0, 12));
  await expect(page.locator('#count')).toHaveText('12');
  const shown = await page.locator('#rows .cell.filled').allTextContents();
  expect(shown.join('')).toBe(PI40.slice(0, 12));
  // 次の目標と進捗バー
  await expect(page.locator('#nextTarget')).toHaveText('20');
});

// 左端に行番号（1, 11, 21…）を置くと「3.14…」の1桁目と読み違えるため、
// 左は1行目の「3.」だけ・位置は右端に単位つきで出す、という約束の回帰テスト。
test('行の左に数字を置かず、右端に「◯桁」を打ち終えた行だけ出す', async ({ page }) => {
  await start(page);
  await expect(page.locator('#rows .idx').first()).toHaveText('3.');
  await expect(page.locator('#rows .cnt').first()).toHaveText('');

  await typeDigits(page, PI40.slice(0, 10));
  await expect(page.locator('#rows .cnt').nth(0)).toHaveText('10桁');
  await expect(page.locator('#rows .cnt').nth(1)).toHaveText('');   // 途中の行には出さない
  await expect(page.locator('#rows .idx').nth(1)).toHaveText('');   // 2行目の左は空

  await typeDigits(page, PI40.slice(10, 20));
  await expect(page.locator('#rows .cnt').nth(1)).toHaveText('20桁');

  // 左端に数字だけの行ラベルが1つも無いこと
  const idxTexts = await page.locator('#rows .idx').allTextContents();
  expect(idxTexts.filter((t) => /\d/.test(t) && t !== '3.')).toHaveLength(0);
});

test('20桁ごとにごほうびコメントが出る', async ({ page }) => {
  await start(page);
  await typeDigits(page, PI40.slice(0, 19));
  await expect(page.locator('#toast .ttxt')).toHaveCount(0);
  await typeDigits(page, PI40.slice(19, 20));
  await expect(page.locator('#toast .ttxt')).toHaveText('いい調子！');
  await expect(page.locator('#toast .tnum')).toHaveText('20 DIGITS');
  // 20桁を超えると次の目標は40桁
  await expect(page.locator('#nextTarget')).toHaveText('40');
  // 出しっぱなしにはしない
  await expect(page.locator('#toast .ttxt')).toHaveCount(0, { timeout: 4000 });

  await typeDigits(page, PI40.slice(20, 40));
  await expect(page.locator('#toast .ttxt')).toHaveText('すごい！');
});

test('1回まちがえたら即終了し、結果に到達桁数と正解が出る', async ({ page }) => {
  await start(page);
  await typeDigits(page, PI40.slice(0, 5)); // 14159
  await typeDigits(page, '0');              // 第6位は 2 なので誤り
  await expect(page.locator('#scr-result')).toBeVisible();
  await expect(page.locator('#scr-play')).toBeHidden();
  await expect(page.locator('#rCount')).toHaveText('5');
  const miss = await page.locator('#rMiss').innerText();
  expect(miss).toContain('第 6 位');
  expect(miss).toContain('正解は');
  expect(await page.locator('#rMiss .you').innerText()).toBe('0');
  expect(await page.locator('#rMiss .ans').innerText()).toBe('2');
  // 終了後は打鍵を受け付けない
  await typeDigits(page, '2');
  await expect(page.locator('#rCount')).toHaveText('5');
});

test('称号トーストが結果画面に残らない', async ({ page }) => {
  await start(page);
  await typeDigits(page, PI40.slice(0, 20));   // 20桁でトーストが出る
  await expect(page.locator('#toast .ttxt')).toBeVisible();
  await typeDigits(page, '0');                 // 消える前にミスで終了
  await expect(page.locator('#scr-result')).toBeVisible();
  await expect(page.locator('#toast .ttxt')).toHaveCount(0);
});

test('自己ベストが残り、更新時だけ「更新！」になる', async ({ page }) => {
  await start(page);
  await typeDigits(page, PI40.slice(0, 5) + '0');
  await expect(page.locator('#rBest')).toContainText('自己ベスト更新！');
  await expect(page.locator('#rBest')).toContainText('5');

  await page.getByRole('button', { name: 'もう一度チャレンジ' }).click();
  await typeDigits(page, '0');   // 第1位は 1 なので即終了
  await expect(page.locator('#rCount')).toHaveText('0');
  await expect(page.locator('#rBest')).toHaveText('自己ベスト 5 桁');

  // タイトルに戻ってもベストが出ている
  await page.getByRole('button', { name: 'タイトルへ' }).click();
  await expect(page.locator('#startBest')).toContainText('5');
});

test('結果画面の「円周率を見る」に120桁が正しく並ぶ', async ({ page }) => {
  await start(page);
  await typeDigits(page, '0');
  const peek = (await page.locator('#peekRows .cell').allTextContents()).join('');
  expect(peek).toHaveLength(120);
  expect(peek.slice(0, 40)).toBe(PI40);
  await expect(page.locator('#peekRows .cnt').last()).toHaveText('120桁');
  // 「100桁」以降が2行に折り返さない幅があること
  const overflowed = await page.locator('#peekRows .cnt').evaluateAll(
    (els) => els.filter((el) => el.scrollWidth > el.clientWidth + 1).map((el) => el.textContent)
  );
  expect(overflowed).toEqual([]);
});

test('キーパッドのタップは1回で1桁だけ入る', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'タッチ端末のみ');
  await start(page);
  await page.tap('.key[data-d="1"]');
  await expect(page.locator('#count')).toHaveText('1');
  await page.tap('.key[data-d="4"]');
  await expect(page.locator('#count')).toHaveText('2');
  // 誤タップは即終了
  await page.tap('.key[data-d="9"]'); // 第3位は 1
  await expect(page.locator('#rCount')).toHaveText('2');
});

test('マウスのクリックでも1回で1桁だけ入る', async ({ page, isMobile }) => {
  test.skip(!!isMobile, 'デスクトップのみ');
  await start(page);
  await page.click('.key[data-d="1"]');
  await page.click('.key[data-d="4"]');
  await page.click('.key[data-d="1"]');
  await expect(page.locator('#count')).toHaveText('3');
});
