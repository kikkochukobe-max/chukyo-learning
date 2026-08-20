// @ts-check
// 一次関数マスターの「ミックス出題」(えらんだ種類だけをランダムに出す)の回帰テスト。
//
// 守りたい仕様:
//   ・ミックスのチップを押すと種類えらびのパネルが出る（他モードでは出ない）
//   ・出るのはチェックした種類だけ。同じ種類が2回続かない
//   ・0種類のときは問題を出さずに案内を出す（答え合わせボタンも消す）
//   ・選択はこの端末(localStorage)に保存され、開き直しても復元される
const { test, expect } = require('@playwright/test');

const TOOL = '/learning/math/math_js2_ichijikansu.html';

/* newQuestion() はインラインスクリプト直下の関数＝window から呼べる。
   1問ずつ答えなくても出題だけを何回も回せるので、ばらつきの検証に使う */
async function drawTags(page, n) {
  return await page.evaluate((count) => {
    const out = [];
    for (let i = 0; i < count; i++) {
      // @ts-ignore ツール内のグローバル関数
      newQuestion();
      out.push(document.getElementById('qTag').textContent);
    }
    return out;
  }, n);
}

test.beforeEach(async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.errors = errors;
  await page.goto(TOOL);
  await page.evaluate(() => localStorage.clear());
  await page.reload();
});

test('ミックスのパネルは全モード分のチェックボックスを持ち、既定は基本モードだけ', async ({ page }) => {
  await expect(page.locator('#mixBox')).toBeHidden();
  await page.click('.chip.mix');
  await expect(page.locator('#mixBox')).toBeVisible();

  const chips = await page.locator('#modeNav .chip:not(.mix)').count();
  const boxes = await page.locator('#mixList input').count();
  expect(boxes).toBe(chips);                       // 台帳はチップそのもの

  // 既定は「応用(=キーが adv で終わる)」以外がオン
  const state = await page.evaluate(() =>
    Array.from(document.querySelectorAll('#mixList input')).map((el) => [el.dataset.mode, el.checked]));
  for (const [key, checked] of state) {
    expect(checked, key).toBe(!/adv$/.test(key));
  }
  await expect(page.locator('#mixCount')).toContainText('10 / 13');
  expect(page.errors).toEqual([]);
});

test('チェックした種類だけが出て、同じ種類は2回続かない', async ({ page }) => {
  await page.click('.chip.mix');
  await page.click('#mixNone');
  await page.check('#mixList input[data-mode="koten"]');
  await page.check('#mixList input[data-mode="menseki"]');

  const tags = await drawTags(page, 40);
  const uniq = [...new Set(tags)];
  expect(uniq.sort()).toEqual(['三角形の面積', '交点を求める']);   // 他の種類は出ない
  for (let i = 1; i < tags.length; i++) {
    expect(tags[i], '同じ種類が続いている').not.toBe(tags[i - 1]);
  }
  expect(page.errors).toEqual([]);
});

test('1種類だけなら、その種類が続いても出し続ける', async ({ page }) => {
  await page.click('.chip.mix');
  await page.click('#mixNone');
  await page.check('#mixList input[data-mode="zoukaryo"]');
  const tags = await drawTags(page, 12);
  expect([...new Set(tags)]).toEqual(['増加量']);
  expect(page.errors).toEqual([]);
});

test('0種類のときは問題を出さずに案内を出す', async ({ page }) => {
  await page.click('.chip.mix');
  await page.click('#mixNone');
  await expect(page.locator('#qText')).toContainText('チェックを入れてね');
  await expect(page.locator('#checkBtn')).toBeHidden();
  await expect(page.locator('#qTag')).toBeHidden();
  // チェックを1つ入れたら、その場で1問目が出る
  await page.check('#mixList input[data-mode="tooru"]');
  await expect(page.locator('#checkBtn')).toBeVisible();
  await expect(page.locator('#qTag')).toHaveText('通る座標');
  expect(page.errors).toEqual([]);
});

test('選択は端末に保存され、開き直しても復元される', async ({ page }) => {
  await page.click('.chip.mix');
  await page.click('#mixNone');
  await page.check('#mixList input[data-mode="shikiadv"]');
  await page.check('#mixList input[data-mode="hendomainadv"]');

  await page.reload();
  await page.click('.chip.mix');
  const on = await page.evaluate(() =>
    Array.from(document.querySelectorAll('#mixList input')).filter((el) => el.checked).map((el) => el.dataset.mode));
  expect(on.sort()).toEqual(['hendomainadv', 'shikiadv']);
  const tags = await drawTags(page, 10);
  expect([...new Set(tags)].sort()).toEqual(['変域 応用', '式を求める 応用']);
  expect(page.errors).toEqual([]);
});

test('ふつうのモードに戻すとパネルは隠れ、種類の札も消える', async ({ page }) => {
  await page.click('.chip.mix');
  await expect(page.locator('#qTag')).toBeVisible();
  await page.click('.chip[data-mode="hantei"]');
  await expect(page.locator('#mixBox')).toBeHidden();
  await expect(page.locator('#qTag')).toBeHidden();
  await expect(page.locator('#checkBtn')).toBeVisible();
  expect(page.errors).toEqual([]);
});
