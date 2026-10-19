// @ts-check
// 気象マスター（science_js2_kishou）の練習プリント（記述式・各モード10問）の回帰テスト。
// ①6モードとも「🖨 印刷（記述10問）」から10問の問題＋解答の紙が開く
// ②紙に選択肢を出さない＝選択肢が無いと解けない問い方（正しいのは？／まちがっている…）が残らない
// ③問題文の言いかえ kijutsuOf() が、どのモードでも10問をそろえられる
const { test, expect } = require('@playwright/test');

const URL = '/learning/science/science_js2_kishou.html';
const MODES = ['taiki', 'kansoku', 'suijouki', 'zensen', 'shiki', 'keisan'];

test('6モードとも記述式10問の練習プリントが開く', async ({ page, context }) => {
  await page.goto(URL);
  for (const m of MODES) {
    await page.locator('.mode-card[data-mode="' + m + '"]').click();
    const [popup] = await Promise.all([
      context.waitForEvent('page'),
      page.locator('#printMode').click(),
    ]);
    await popup.waitForLoadState();
    const info = await popup.evaluate(() => ({
      probs: document.querySelectorAll('.p').length,
      answers: document.querySelectorAll('.a').length,
      boxes: document.querySelectorAll('.p .al').length,
      choiceButtons: document.querySelectorAll('.ch, button').length,
      pageBreak: !!document.querySelector('.pg'),
      text: Array.from(document.querySelectorAll('.p .q')).map(e => e.textContent),
    }));
    expect(info.probs, m).toBe(10);
    expect(info.answers, m).toBe(10);
    expect(info.boxes, m).toBe(10);
    expect(info.choiceButtons, m).toBe(0);
    expect(info.pageBreak, m).toBe(true);
    for (const t of info.text) {
      expect(t, m + ': ' + t).not.toMatch(/正しい|まちがって|あてはまるもの|組み合わせ|undefined|NaN/);
    }
    await popup.close();
  }
});

test('どのモードでも毎回10問そろい、同じ問題が2回出ない', async ({ page }) => {
  await page.goto(URL);
  const bad = await page.evaluate((modes) => {
    const out = [];
    for (const m of modes) {
      for (let i = 0; i < 200; i++) {
        const items = prtPick(m);
        if (items.length !== 10) { out.push(m + ' ' + items.length + '問'); break; }
        const sig = items.map(it => it.q + '|' + it.a + '|' + it.tbl);
        if (new Set(sig).size !== sig.length) { out.push(m + ' 重複'); break; }
        if (items.some(it => CAT_MODE[it.cat] !== m)) { out.push(m + ' 別モードの問題'); break; }
      }
    }
    return out;
  }, MODES);
  expect(bad).toEqual([]);
});

/* 書きかえ台帳（KIJUTSU_SKIP / _Q / _A）は問題の id で引くので、
   BANK の id を変えると黙って効かなくなる（6択向けの問い方のまま紙に出る） */
test('記述式の書きかえ台帳の id がすべて問題バンクにある', async ({ page }) => {
  await page.goto(URL);
  const missing = await page.evaluate(() => {
    const ids = new Set(BANK.map(q => q.id));
    return [KIJUTSU_SKIP, KIJUTSU_Q, KIJUTSU_A]
      .flatMap(o => Object.keys(o)).filter(id => !ids.has(id));
  });
  expect(missing).toEqual([]);
});
