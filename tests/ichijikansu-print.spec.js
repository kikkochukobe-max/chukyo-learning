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
  expect(cls).toBe('gridfig gridfig-r8');     // r8 = 方眼の範囲（解答欄の作図に使う）
  const src = fs.readFileSync(path.resolve(__dirname, '..', 'teacher.php'), 'utf8');
  expect(src).toContain('.q-fig2 svg.gridfig{height:');
});

/* 「グラフをかく」問題は式だけ刷っても見比べられないので、解答（講師用）には
   保存した方眼に正解の直線を引いたものを添える。ツールの座標系と印刷側の
   計算式が合っていないと線がずれるので、実際の保存図で突き合わせる。 */
test('作図問題は解答欄に正解の直線を引いた方眼が付く', async ({ page, browser }) => {
  // ① ツールから「作図モードの保存図」と「記録される正解」を取る
  await page.goto('/learning/math/math_js2_ichijikansu.html');
  const cases = [];
  for (const mode of ['graph', 'graphadv', 'hendomain']) {
    await page.click(`.chip[data-mode="${mode}"]`);
    cases.push(await page.evaluate(() => ({
      mode: curMode,
      figsvg: figureSnapshot(),
      ans: texToPlain(describeAnswer(cur.ans)),
      a: cur.ans.a ? cur.ans.a.n / cur.ans.a.d : null,
      b: cur.ans.b ? cur.ans.b.n / cur.ans.b.d : null,
    })));
  }

  // ② teacher.php の関数を切り出して、その図と答えから解答用の図を作らせる
  const src = fs.readFileSync(path.resolve(__dirname, '..', 'teacher.php'), 'utf8');
  const code = src.slice(src.indexOf('function _mescape'), src.indexOf("document.querySelectorAll('.math')"));
  const p2 = await browser.newPage();
  await p2.setContent('<div id="o"></div>');
  await p2.addScriptTag({ content: code });

  for (const c of cases) {
    const out = await p2.evaluate((cc) => {
      const svg = answerGraphSvg(cc.figsvg, cc.ans);
      if (!svg) return { ok: false };
      const d = document.createElement('div');
      d.innerHTML = svg;
      const lines = Array.from(d.querySelectorAll('line'));
      const red = lines.filter((l) => l.getAttribute('stroke') === '#C73E2E');
      if (red.length !== 1) return { ok: false, red: red.length };
      const L = red[0];
      // 画面と同じ座標系(pad16 / R8 / viewBox360)で、線の両端をグラフの座標に戻す
      const u = (360 - 32) / 16, inv = (px, py) => ({ x: (px - 16) / u - 8, y: 8 - (py - 16) / u });
      return { ok: true,
        p1: inv(+L.getAttribute('x1'), +L.getAttribute('y1')),
        p2: inv(+L.getAttribute('x2'), +L.getAttribute('y2')) };
    }, c);
    expect(out.ok, `${c.mode} / ${c.ans}`).toBe(true);
    // 両端が答えの直線 y=ax+b の上に乗っている（＝式と図が一致している）
    for (const p of [out.p1, out.p2]) {
      expect(Math.abs(c.a * p.x + c.b - p.y), `${c.mode} / ${c.ans} の端点`).toBeLessThan(0.01);
      expect(Math.abs(p.x)).toBeLessThanOrEqual(8.01);
      expect(Math.abs(p.y)).toBeLessThanOrEqual(8.01);
    }
  }
  await p2.close();
});
