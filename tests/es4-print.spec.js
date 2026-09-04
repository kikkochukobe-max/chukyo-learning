// @ts-check
// 小4算数まるごとパック（math_es4_all）の回帰テスト。
// 見張るのは「印刷」と「わり算の筆算の入力」の2点。どちらも壊れても
// エラーが出ずに紙・画面がおかしくなるだけなので、数で押さえておく。
//
// ① 練習プリントは別ウィンドウに組む。問題6問=1面、こたえ1面が セットで、
//    12問なら 問題→こたえ→問題→こたえ（両面印刷で表=問題・裏=こたえになる順）。
// ② その1面が A4（たて・上下余白11mm）に収まること。図の max-height を
//    ゆるめたり 1面の問題数を増やすと あふれて 空きページが出る（CLAUDE.md/印刷の教訓）。
// ③ わり算の筆算は「商が立つ位」だけを入力マスにして 上の位から右へ進む。
//    たし算の筆算（一の位から左へ）と向きが逆で、そろえると手順と合わなくなる。
const { test, expect } = require('@playwright/test');

const URL = '/learning/math/math_es4_all.html';
// A4たて 297mm − 上下余白11mm×2 を 96dpi の px に直した「1面に使える高さ」
const USABLE_H = Math.round(1122.5 - 2 * (11 * 96 / 25.4));
const PRINT_W = Math.round((210 - 20) * 96 / 25.4);   // 左右余白10mm を除いた印刷幅

/** プリントのポップアップを開いて返す（印刷ダイアログは出さない） */
async function openPrint(page, context, { unit, n, withAns = true }) {
  await context.addInitScript(() => { window.print = () => {}; });
  await page.goto(URL);
  await page.selectOption('#psel', unit);
  await page.locator('#pnum .pchip', { hasText: `${n}問` }).click();
  if (!withAns) await page.uncheck('#pwa');
  const [pop] = await Promise.all([
    context.waitForEvent('page'),
    page.click('#pgo'),
  ]);
  await pop.waitForSelector('.psheet');
  return pop;
}

test('メニューに16単元＋ミックスが並び、プリントの単元えらびも同じ数だけある', async ({ page }) => {
  await page.goto(URL);
  await expect(page.locator('#grid .chip')).toHaveCount(17);      // 16単元 + ぜんぶミックス
  await expect(page.locator('#psel option')).toHaveCount(17);
  await expect(page.locator('#tot')).toHaveText('0');
});

test('練習プリント6問: 問題1面＋こたえ1面 で作られる', async ({ page, context }) => {
  const pop = await openPrint(page, context, { unit: 'menseki', n: 6 });
  await expect(pop.locator('.psheet')).toHaveCount(2);
  await expect(pop.locator('.psheet').first().locator('.pit')).toHaveCount(6);
  await expect(pop.locator('.psheet').nth(1).locator('.pa')).toHaveCount(6);
  await expect(pop.locator('.psheet').first()).toContainText('面積');
  // 単元プリントは 単元名が 見出しに1回出るだけ（ミックスのときだけ 各問に付く）
  await expect(pop.locator('.pit .put')).toHaveCount(0);
  const body = await pop.locator('body').innerText();
  expect(body).not.toMatch(/undefined|NaN/);
  await pop.close();
});

test('練習プリント12問: 問題→こたえ→問題→こたえ の順（両面印刷で表裏になる）', async ({ page, context }) => {
  const pop = await openPrint(page, context, { unit: 'mix', n: 12 });
  await expect(pop.locator('.psheet')).toHaveCount(4);
  const kinds = await pop.locator('.psheet .plb').allInnerTexts();
  expect(kinds.map((s) => /こたえ/.test(s))).toEqual([false, true, false, true]);
  // ミックスは 1問ごとに どの単元かを 出す
  await expect(pop.locator('.psheet').first().locator('.pit .put')).toHaveCount(6);
  await pop.close();
});

test('こたえのページを外すと 問題の面だけになる', async ({ page, context }) => {
  const pop = await openPrint(page, context, { unit: 'kaku', n: 6, withAns: false });
  await expect(pop.locator('.psheet')).toHaveCount(1);
  await expect(pop.locator('.plb.gy')).toHaveCount(0);
  await pop.close();
});

test('出題中の🖨ボタンは その単元のプリントを作る', async ({ page, context }) => {
  await context.addInitScript(() => { window.print = () => {}; });
  await page.goto(URL);
  await page.locator('.chip', { hasText: '折れ線グラフと表' }).click();
  const [pop] = await Promise.all([
    context.waitForEvent('page'),
    page.click('#pnow'),
  ]);
  await pop.waitForSelector('.psheet');
  await expect(pop.locator('.psheet').first().locator('.plb')).toContainText('折れ線グラフと表');
  await pop.close();
});

// 図の大きさや1面の問題数を変えたときに あふれを 見つけるための実測。
// 単元ごとに図の背が違うので、背の高いものを ひととおり 見る。
test('どの単元のプリントも1面がA4（たて）に収まる', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name === 'iphone', '紙のサイズはデスクトップ側で見れば足りる');
  for (const unit of ['wari1', 'kaku', 'graph', 'hako', 'bai', 'bunsuu', 'mix']) {
    const pop = await openPrint(page, context, { unit, n: 6 });
    await pop.setViewportSize({ width: PRINT_W, height: USABLE_H });
    const heights = await pop.evaluate(() =>
      Array.from(document.querySelectorAll('.psheet')).map((s) => s.getBoundingClientRect().height));
    for (const [i, h] of heights.entries()) {
      expect(Math.round(h), `${unit} の ${i + 1}面目`).toBeLessThanOrEqual(USABLE_H);
    }
    await pop.close();
  }
});

/* わり算の筆算は「商が立つ位」だけが入力マスで、上の位から右へ進む。
   画面に出ている わる数・わられる数から 商とあまりを計算して 打ち込み、
   マスの数・入力の向き・採点までを ひととおり 確かめる。 */
test('わり算の筆算: 商のマスは商のけた数ぶんで、上の位から入れて正解になる', async ({ page }) => {
  let info = null;
  for (let t = 0; t < 12 && !info; t++) {
    await page.goto(URL);
    await page.locator('.chip', { hasText: 'わり算の筆算①' }).click();
    info = await page.evaluate(() => {
      const wari = document.querySelector('#fig .wari');
      if (!wari) return null;                      // 筆算以外の型が出たら引き直す
      const rows = wari.querySelectorAll('.wrow');
      const divisor = (rows[1].querySelector('.wl') || { textContent: '' }).textContent.trim();
      const dividend = Array.from(rows[1].querySelectorAll('.dgrid .c'))
        .map((c) => c.textContent.trim()).join('');
      return {
        divisor: Number(divisor),
        dividend: Number(dividend),
        qBoxes: wari.querySelectorAll('.ic:not(.no)').length,
        rBoxes: document.querySelectorAll('#fig .wam .ic').length,
        focusIsLeftmost: (() => {
          const on = wari.querySelectorAll('.ic:not(.no)');
          return on.length > 0 && on[0].classList.contains('foc');
        })(),
      };
    });
  }
  expect(info, 'わり算の筆算の問題が出なかった').not.toBeNull();
  const q = Math.floor(info.dividend / info.divisor);
  const r = info.dividend % info.divisor;
  expect(info.qBoxes, '商のマスの数').toBe(String(q).length);
  expect(info.rBoxes, 'あまりのマスの数').toBe(String(info.divisor).length);
  expect(info.focusIsLeftmost, '書きはじめは商のいちばん上の位').toBe(true);

  const tap = async (d) => page.locator('#padarea .key')
    .filter({ hasText: new RegExp(`^${d}$`) }).click();
  for (const d of String(q)) await tap(d);        // 商（上の位から右へ）
  for (const d of String(r)) await tap(d);        // 続けて あまり
  await page.click('#go');
  await expect(page.locator('#fbt')).toHaveText('せいかい！');
  await expect(page.locator('#fbe')).toContainText(`${info.dividend}÷${info.divisor}`);
});

/* 小数のひっ算は わり算とは 逆に「右の小さい位から 左へ」入力する。
   小数点の列は 打ってあって とばされること、整数（7 など）も 混ざることを 確かめる。 */
test('小数のひっ算: 小さい位から入れて正解になり、小数点の列はとばされる', async ({ page }) => {
  await page.goto(URL);
  await page.locator('.chip', { hasText: '小数のひっ算' }).click();
  const info = await page.evaluate(() => {
    const rows = document.querySelectorAll('#fig .hissan .hrow');
    const read = (row) => Array.from(row.querySelectorAll('.c'))
      .map((c) => c.textContent.trim()).join('');
    const ansCells = Array.from(rows[2].querySelectorAll('.c'));
    const dotIdx = ansCells.findIndex((c) => c.classList.contains('dot'));
    return {
      a: Number(read(rows[0])),
      b: Number(read(rows[1])),
      sign: rows[1].querySelector('.hop').textContent.trim(),
      decBoxes: ansCells.length - dotIdx - 1,
      dotIsFixed: !ansCells[dotIdx].classList.contains('ic') && ansCells[dotIdx].textContent.trim() === '.',
      focusIsRightmost: ansCells[ansCells.length - 1].classList.contains('foc'),
    };
  });
  expect(info.dotIsFixed, '小数点の列は入力させない').toBe(true);
  expect(info.focusIsRightmost, '書きはじめはいちばん小さい位').toBe(true);
  const ans = (info.sign === '+') ? info.a + info.b : info.a - info.b;
  const digits = ans.toFixed(info.decBoxes).replace('.', '');
  const tap = async (d) => page.locator('#padarea .key')
    .filter({ hasText: new RegExp(`^${d}$`) }).click();
  for (const d of digits.split('').reverse()) await tap(d);   // 右から左へ
  await page.click('#go');
  await expect(page.locator('#fbt')).toHaveText('せいかい！');
});
