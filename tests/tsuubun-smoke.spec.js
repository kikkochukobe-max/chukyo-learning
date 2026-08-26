/* 通分マスター（math_es5_tsuubun_kagen）の回帰テスト
   ・11モードすべてが 出題→入力→採点→次の問題 まで通ること
   ・画面の式から 計算した 正しい答えを 入れると「せいかい」になること
     （＝ 判定（既約・帯分数の受け入れ）と 生成関数の 答えが 一致していること）
   ・印刷シートが 表12問＋裏こたえ の2ページで 作られること              */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_es5_tsuubun_kagen.html';

/* 画面の式（.expr）を読んで 分数として 計算する */
async function readExpr(page) {
  return await page.evaluate(() => {
    const g = (a, b) => { a = Math.abs(a); b = Math.abs(b); while (b) { const t = a % b; a = b; b = t; } return a || 1; };
    const red = f => { const k = g(f.n, f.d); return { n: f.n / k, d: f.d / k }; };
    const root = document.querySelector('#qText .expr');
    const terms = [], ops = [];
    for (const el of root.children) {
      if (el.classList.contains('op')) { const t = el.textContent.trim(); if (t === '＋') ops.push('+'); else if (t === '－') ops.push('-'); continue; }
      if (el.classList.contains('qbox')) continue;
      if (el.classList.contains('fr')) {
        terms.push(red({ n: +el.querySelector('.fn').textContent, d: +el.querySelector('.fd').textContent }));
      } else if (el.classList.contains('mx')) {
        const w = +el.querySelector('.wn').textContent;
        const n = +el.querySelector('.fn').textContent, d = +el.querySelector('.fd').textContent;
        terms.push(red({ n: w * d + n, d }));
      } else if (el.classList.contains('wn')) {
        const s = el.textContent.trim();
        const dec = (s.split('.')[1] || '').length, D = Math.pow(10, dec);
        terms.push(red({ n: Math.round(parseFloat(s) * D), d: D }));
      }
    }
    let v = terms[0];
    for (let i = 1; i < terms.length; i++) {
      const t = terms[i];
      v = red(ops[i - 1] === '+' ? { n: v.n * t.d + t.n * v.d, d: v.d * t.d } : { n: v.n * t.d - t.n * v.d, d: v.d * t.d });
    }
    return { terms, ops, ans: v };
  });
}
async function typeInto(page, slot, num) {
  await page.click(`.nbox[data-slot="${slot}"]`);
  for (const ch of String(num)) await page.click(`.key[data-k="${ch}"]`);
}

test('11モードすべて 出題→入力→採点→次の問題 が通る', async ({ page }) => {
  const errs = [];
  page.on('pageerror', e => errs.push(String(e)));
  page.on('console', m => { if (m.type() === 'error') errs.push('console:' + m.text()); });
  await page.goto(URL);
  const modes = await page.$$eval('.m-card', els => els.map(e => e.dataset.mode));
  expect(modes.length).toBe(11);
  for (const m of modes) {
    await page.click(`.m-card[data-mode="${m}"]`);
    await expect(page.locator('#qText .expr')).toBeVisible();
    const slots = await page.$$eval('.nbox', els => els.map(e => e.dataset.slot));
    expect(slots.length).toBeGreaterThan(1);
    for (const s of slots) await typeInto(page, s, 1);
    await page.click('#checkBtn');
    await expect(page.locator('#feedback')).toHaveClass(/show/);
    await expect(page.locator('#expl')).not.toBeEmpty();
    await page.click('#nextBtn');
    await expect(page.locator('#qText .expr')).toBeVisible();
    await page.click('#homeBtn');
  }
  expect(errs).toEqual([]);
});

test('計算10モード: 正しい答えを入れると せいかい になる（各3問）', async ({ page }) => {
  await page.goto(URL);
  const modes = ['add2', 'add3', 'add_tai', 'add_shou', 'sub2', 'sub3', 'sub_tai', 'sub_shou', 'mix_bun', 'mix_all'];
  for (const m of modes) {
    await page.click(`.m-card[data-mode="${m}"]`);
    for (let i = 0; i < 3; i++) {
      const { ans } = await readExpr(page);
      const w = Math.floor(ans.n / ans.d), n = ans.n - w * ans.d;   // 帯分数で入れる
      if (w) await typeInto(page, 'w', w);
      if (n) { await typeInto(page, 'n', n); await typeInto(page, 'd', ans.d); }
      await page.click('#checkBtn');
      await expect(page.locator('#fbMsg'), `${m} #${i + 1}`).toHaveClass(/good/);
      await page.click('#nextBtn');
    }
    await page.click('#homeBtn');
  }
});

test('通分モード: 最小公倍数で通分すると せいかい / 円の絵は採点後に線が増える', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="tsuubun"]');
  await expect(page.locator('#pieWrap .pie')).toHaveCount(2);
  await expect(page.locator('#pieWrap')).not.toHaveClass(/on/);   // 先に答えは見せない
  for (let i = 0; i < 4; i++) {
    const { terms } = await readExpr(page);
    const g = (a, b) => { while (b) { const t = a % b; a = b; b = t; } return a; };
    const L = terms[0].d / g(terms[0].d, terms[1].d) * terms[1].d;
    await typeInto(page, 'n1', terms[0].n * (L / terms[0].d));
    await typeInto(page, 'd1', L);
    await typeInto(page, 'n2', terms[1].n * (L / terms[1].d));
    await typeInto(page, 'd2', L);
    await page.click('#checkBtn');
    await expect(page.locator('#fbMsg')).toHaveClass(/good/);
    await expect(page.locator('#pieWrap')).toHaveClass(/on/);      // 採点後に線が増える
    await page.click('#nextBtn');
  }
});

test('未約分・分母をそろえ忘れは 専用のメッセージで 不正解になる', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="add2"]');
  await page.click('#checkBtn');
  await expect(page.locator('#toast')).toContainText('答えを 入れてね');
  // 値は合っているが 約分できる形（分子・分母を2倍）で入れる
  const { ans } = await readExpr(page);
  await typeInto(page, 'n', ans.n * 2);
  await typeInto(page, 'd', ans.d * 2);
  await page.click('#checkBtn');
  await expect(page.locator('#fbMsg')).toHaveClass(/bad/);
  await expect(page.locator('#fbMsg')).toContainText('約分');
});

test('印刷シートが 表12問＋裏こたえ で作られる', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="add2"]');
  await page.evaluate(() => { window.print = () => {}; });
  await page.click('#printBtn');
  await expect(page.locator('#printArea .sheet')).toHaveCount(2);
  await expect(page.locator('#printArea .sheet').first().locator('.p-item')).toHaveCount(12);
  await expect(page.locator('#printArea .sheet.ans .p-item')).toHaveCount(12);
  await expect(page.locator('#printArea .sheet').first().locator('.p-blank')).toHaveCount(12);
});
