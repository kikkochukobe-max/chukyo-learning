// @ts-check
// 解き直しプリント（teacher.php が刷る紙）側の回帰テスト。
// 紙に出るのは question_text / question_figure / question_choices の3つだけなので、
// ①数式マーカー(F(分子/分母))が teacher.php のレンダラで縦組みに戻ること
// ②方眼SVGが紙で読める大きさになる目印を持っていること を見張る。
// ⚠ renderMathToHTML は mypage.php / retry.php / teacher.php に同じものがコピーされている。
//    表記を変えたら3ファイルすべてを直すこと（CLAUDE.md 2a）。
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('解き直しプリントの数式レンダラが一次関数の記録を読める', async ({ page, browser }) => {
  // ① ツールから question_text / correct_answer / 選択肢 のサンプルを集める
  await page.goto('/learning/math/math_js2_ichijikansu.html');
  const samples = await page.evaluate(() => {
    const out = [];
    for (const m of Object.keys(GENS)) {
      for (let i = 0; i < 3; i++) {
        const q = GENS[m]();
        const ans = q.ans.kind === 'input' ? inputToChoice(q.ans) : q.ans;
        out.push({ m: m, t: qTextPlain(q),
          a: texToPlain(describeAnswer(ans)),
          ch: (ans.plain || []).slice(0, 2).map(texToPlain) });
      }
    }
    return out;
  });

  // ② teacher.php の数式レンダラだけを切り出して、KaTeX入りの素のページで動かす
  const src = fs.readFileSync(path.resolve(__dirname, '..', 'teacher.php'), 'utf8');
  const start = src.indexOf('function _mescape');
  const end = src.indexOf("document.querySelectorAll('.math')");
  expect(start).toBeGreaterThan(0); expect(end).toBeGreaterThan(start);
  const code = src.slice(start, end);

  const p2 = await browser.newPage();
  await p2.setContent('<div id="o"></div>');
  await p2.addScriptTag({ url: 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js' });
  await p2.addScriptTag({ content: code });

  const bad = await p2.evaluate((rows) => {
    const out = [];
    for (const r of rows) {
      for (const [kind, s] of [['q', r.t], ['a', r.a]].concat(r.ch.map(c => ['ch', c]))) {
        if (!s) continue;
        let html;
        try { html = renderMathToHTML(s); } catch (e) { out.push([r.m, kind, s, 'THROW ' + e.message]); continue; }
        if (/F\(|SYS\(/.test(html)) out.push([r.m, kind, s, 'マーカーが生で残った']);
        if (/undefined|NaN/.test(html)) out.push([r.m, kind, s, 'undefined/NaN']);
        if (s.indexOf('F(') >= 0 && !/katex/.test(html)) out.push([r.m, kind, s, '分数がKaTeXで描かれない']);
      }
    }
    return out;
  }, samples);
  expect(bad).toEqual([]);
  await p2.close();
});

/* 方眼の図は「共通の54mm」だと目盛りが読めないので、ツールが class="gridfig" を付け、
   teacher.php 側にその高さ指定がある。片方だけ直すと紙で小さくなるので対で見張る。 */
test('方眼SVGの目印と印刷側の高さ指定が対になっている', async ({ page }) => {
  await page.goto('/learning/math/math_js2_ichijikansu.html');
  await page.click('.chip[data-mode="yomitori"]');
  const cls = await page.getAttribute('#figWrap svg', 'class');
  expect(cls).toBe('gridfig');
  const src = fs.readFileSync(path.resolve(__dirname, '..', 'teacher.php'), 'utf8');
  expect(src).toContain('.q-fig2 svg.gridfig{height:');
});
