/* 拡大図と縮図マスター（math_es6_kakudai_shukuzu）の回帰テスト
   ・9種類の生成関数すべてが、多数の種で
       - 選択肢4つが重複せず、正解が ansText と一致する
       - 数の答えは 生成時の 答え(chk.ans)・解説の最後の太字と一致する
       - 方眼の 4択は 正解だけが もとの 図と 相似で、倍率も 合っている
       - 中心を 決めて かく 問題は、正解の 点が 中心＋k×(頂点−中心)
       - 図（question_figure）が save_answer.php の figure_is_safe() を通る
       - 同じ種から まったく同じ問題が出る（?retry=1 の 種方式の前提）
       - question_text 255バイト・答え100バイトに おさまり、TeX と まちがわれる 文字が無い
   ・図の 文字が viewBox の 外に はみ出さない（実際に 描いて 測る）
   ・6つのモードが 出題→採点→次の問題 まで通る／誤答の 記録から ?retry=1 で 同じ問題
   ・キラキラの正解エフェクトが結線される
   ・印刷シートが A4 1枚（問題・答え それぞれ）に おさまる                           */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_es6_kakudai_shukuzu.html';

/* api/save_answer.php の figure_is_safe() と同じ検証。
   ⚠ FIG_TAGS / FIG_ATTRS を PHP 側で増やしたら ここも合わせること */
const FIG_TAGS = ['svg', 'g', 'line', 'polyline', 'polygon', 'rect', 'circle', 'ellipse', 'path',
  'text', 'tspan', 'defs', 'lineargradient', 'radialgradient', 'stop',
  'table', 'thead', 'tbody', 'tr', 'th', 'td', 'caption', 'div', 'span', 'br',
  'b', 'strong', 'i', 'em', 'u', 'small', 'sub', 'sup'];
const FIG_ATTRS = ['x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points',
  'width', 'height', 'viewbox', 'xmlns', 'preserveaspectratio', 'fill', 'stroke', 'stroke-width',
  'stroke-dasharray', 'stroke-linecap', 'stroke-linejoin', 'font-size', 'font-family',
  'font-weight', 'font-style', 'text-anchor', 'dominant-baseline', 'transform', 'opacity',
  'fill-opacity', 'class', 'style', 'colspan', 'rowspan', 'id', 'offset', 'stop-color',
  'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod',
  'role', 'aria-label', 'paint-order'];

const KEYS = ['mitsukeru', 'nanbai', 'hen', 'kaku', 'chushin',
  'shuku_jissai', 'shuku_chizu', 'shuku_motomeru', 'riyou'];
/* 図が無いと 解けない 種類（縮尺の 文章題は 図なしで よい） */
const NEED_FIG = ['mitsukeru', 'nanbai', 'hen', 'kaku', 'chushin', 'riyou'];

test('9種類の生成関数が 選択肢・答え・図・種の再現性を満たす', async ({ page }) => {
  await page.goto(URL);
  const res = await page.evaluate(({ tags, attrs, n, needFig }) => {
    function figBad(s) {
      if (new Blob([s]).size > 20000) return 'too big ' + new Blob([s]).size;
      if (/<!--|<!\[|<\?/.test(s)) return 'comment';
      const found = s.match(/<[^>]*>/g);
      if (!found) return 'no tags';
      for (const tag of found) {
        const t = tag.match(/^<\s*\/?\s*([a-zA-Z][a-zA-Z0-9-]*)/);
        if (!t) return 'bad tag ' + tag;
        if (tags.indexOf(t[1].toLowerCase()) < 0) return 'tag ' + t[1];
        let inner = tag.slice(t[0].length).replace(/\/?\s*>$/, '').trim();
        while (inner !== '') {
          const a = inner.match(/^([a-zA-Z_:][a-zA-Z0-9_.:-]*)\s*=\s*"([^"]*)"\s*/);
          if (!a) return 'bad attr ' + tag;
          if (attrs.indexOf(a[1].toLowerCase()) < 0) return 'attr ' + a[1];
          if (/javascript:|vbscript:|data:|expression\s*\(|url\s*\(|&#/i.test(a[2])) return 'value ' + a[1];
          inner = inner.slice(a[0].length);
        }
      }
      if (s.replace(/<[^>]*>/g, '').indexOf('<') >= 0) return 'raw <';
      return null;
    }
    const bytes = (s) => new Blob([String(s)]).size;
    const num = s => Number(String(s).replace(/<[^>]*>/g, '').replace(/[^0-9.\-]/g, ''));
    const errs = [], kinds = {};
    for (const key of Object.keys(window.GENS)) {
      kinds[key] = {};
      for (let i = 0; i < n; i++) {
        const seed = (i * 2654435761 + 12345) >>> 0 || 1;
        window.rngSeed(seed);
        const q = window.GENS[key]();
        const at = key + ' seed=' + seed;
        if (!q) { errs.push(at + ': null'); continue; }
        kinds[key][q.tag] = 1;
        if (q.key !== key) errs.push(at + ': question_key ' + q.key);
        if (q.options.length !== 4) errs.push(at + ': options ' + q.options.length);
        const plains = q.options.map(o => o.plain);
        if (new Set(plains).size !== 4) errs.push(at + ': duplicate ' + plains.join('/'));
        if (q.correct < 0 || q.correct > 3) errs.push(at + ': correct ' + q.correct);
        q.options.forEach(o => {
          if (!o.ok) errs.push(at + ': option not ok ' + o.plain);
          if (bytes(o.plain) > 100) errs.push(at + ': option too long');
          if (/NaN|undefined|Infinity/.test(o.html + o.plain)) errs.push(at + ': NaN option');
        });
        if (q.ansText !== plains[q.correct]) errs.push(at + ': ansText ' + q.ansText);
        if (bytes(q.plainQ) > 255) errs.push(at + ': plainQ ' + bytes(q.plainQ) + 'B ' + q.plainQ);
        /* 講師画面が 文字列ぜんぶを TeX に まわす 文字（CLAUDE.md 2a） */
        if (/[{}\\^_]/.test(q.plainQ + q.ansText + plains.join(''))) errs.push(at + ': TeX char in plain');
        /* 数の 答えは 生成時の 値・解説の 最後の 太字と 一致 */
        if (q.chk && typeof q.chk.ans === 'number') {
          if (Math.abs(num(q.ansText) - q.chk.ans) > 1e-6) errs.push(at + ': ans ' + q.ansText + ' != ' + q.chk.ans);
          const b = String(q.expl).match(/<b>([^<]*)<\/b>/g) || [];
          if (!b.length) errs.push(at + ': no bold answer');
          else if (Math.abs(num(b[b.length - 1]) - q.chk.ans) > 1e-6)
            errs.push(at + ': expl ' + b[b.length - 1] + ' != ' + q.chk.ans);
          /* ほかの 選択肢に 同じ 値が 無いこと */
          plains.forEach((p, k) => {
            if (k !== q.correct && Math.abs(num(p) - q.chk.ans) < 1e-9) errs.push(at + ': same value ' + p);
          });
        }
        /* 方眼の 4択：正解だけが 相似で、倍率も 合う */
        if (key === 'mitsukeru') {
          const c = q.chk, bb = (P) => {
            const xs = P.map(p => p[0]), ys = P.map(p => p[1]);
            return { w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys) };
          };
          c.cands.forEach((P, k) => {
            const sim = window.isSimilar(P, c.base);
            if (sim !== (k === q.correct)) errs.push(at + ': similar ' + k + '=' + sim + ' correct=' + q.correct);
          });
          const B = bb(c.base), R = bb(c.cands[q.correct]), r = c.up ? c.big : 1 / c.big;
          if (Math.abs(R.w - B.w * r) > 1e-9 || Math.abs(R.h - B.h * r) > 1e-9) errs.push(at + ': wrong scale');
        }
        if (key === 'chushin' && q.chk && q.chk.O) {
          const c = q.chk, want = [c.O[0] + c.v[0] * c.k, c.O[1] + c.v[1] * c.k];
          if (want[0] !== c.pick[0] || want[1] !== c.pick[1]) errs.push(at + ': chushin point');
          /* ア〜エの 4点が そろい、たがいに 2マス 以上 はなれている */
          if (c.pts.length !== 4) errs.push(at + ': chushin points ' + c.pts.length);
          for (let a = 0; a < c.pts.length; a++) for (let b = a + 1; b < c.pts.length; b++) {
            const d = Math.max(Math.abs(c.pts[a][0] - c.pts[b][0]), Math.abs(c.pts[a][1] - c.pts[b][1]));
            if (d < 2) errs.push(at + ': chushin points too close');
          }
        }
        if (key === 'shuku_motomeru' && !new RegExp('(/|：)' + q.chk.N + '\\)?$').test(q.ansText))
          errs.push(at + ': motomeru ' + q.ansText);
        /* 図 */
        if (needFig.indexOf(key) >= 0 && !q.fig) errs.push(at + ': no figure');
        if (q.fig) {
          const bad = figBad(q.fig);
          if (bad) errs.push(at + ': FIGURE ' + bad);
        }
        ['qHtml', 'plainQ', 'expl', 'fig'].forEach(f => {
          if (/NaN|undefined|Infinity/.test(String(q[f]))) errs.push(at + ': NaN in ' + f);
        });
        /* 種方式：同じ種から まったく同じ問題 */
        window.rngSeed(seed);
        const q2 = window.GENS[key]();
        if (q2.fig !== q.fig || q2.ansText !== q.ansText || q2.plainQ !== q.plainQ ||
            q2.options.map(o => o.plain).join('|') !== plains.join('|') || q2.correct !== q.correct)
          errs.push(at + ': not reproducible from the same seed');
      }
    }
    const tagCount = {};
    Object.keys(kinds).forEach(k => { tagCount[k] = Object.keys(kinds[k]).length; });
    return { keys: Object.keys(window.GENS), errs: errs.slice(0, 25), total: errs.length, tagCount };
  }, { tags: FIG_TAGS, attrs: FIG_ATTRS, n: 300, needFig: NEED_FIG });

  expect(res.keys).toEqual(KEYS);
  expect(res.errs, res.errs.join('\n')).toEqual([]);
  expect(res.total).toBe(0);
  /* 種類の 出しわけ（縮尺の 線・高さ／川はば／池 など）が ぜんぶ 出ている */
  expect(res.tagCount.chushin).toBe(2);
  expect(res.tagCount.riyou).toBe(3);
});

test('正解の 選択肢の かたよりが 無い（4つの 場所に ちらばる）', async ({ page }) => {
  await page.goto(URL);
  const pos = await page.evaluate(() => {
    const out = {};
    for (const key of Object.keys(window.GENS)) {
      const c = [0, 0, 0, 0];
      for (let i = 0; i < 400; i++) {
        window.rngSeed((i * 40503 + 99) >>> 0 || 1);
        c[window.GENS[key]().correct]++;
      }
      out[key] = c;
    }
    return out;
  });
  for (const key of Object.keys(pos)) {
    pos[key].forEach(c => expect(c, key + ' ' + pos[key].join(',')).toBeGreaterThan(50));
  }
});

test('図の 文字が viewBox の 外に はみ出さず、文字どうしが 重ならない', async ({ page }) => {
  await page.goto(URL);
  const out = await page.evaluate(() => {
    const host = document.createElement('div');
    host.style.cssText = 'position:absolute;left:0;top:0;width:330px';
    document.body.appendChild(host);
    const errs = [], laps = [];
    let checked = 0;
    for (const key of Object.keys(window.GENS)) {
      for (let i = 0; i < 80; i++) {
        const seed = (i * 2246822519 + 7) >>> 0 || 1;
        window.rngSeed(seed);
        const q = window.GENS[key]();
        if (!q.fig) continue;
        host.innerHTML = q.fig;
        const svg = host.querySelector('svg');
        svg.style.width = '330px';
        const R = svg.getBoundingClientRect();
        const boxes = [];
        host.querySelectorAll('text').forEach(t => {
          const r = t.getBoundingClientRect();
          checked++;
          if (r.left < R.left - 0.5 || r.right > R.right + 0.5 || r.top < R.top - 0.5 || r.bottom > R.bottom + 0.5)
            errs.push(key + ' seed=' + seed + ' "' + t.textContent + '"');
          boxes.push({ r, s: t.textContent });
        });
        /* 文字どうしの 重なり（小さい 方の 2割 より 多く 重なったら 読めない） */
        for (let a = 0; a < boxes.length; a++) for (let b = a + 1; b < boxes.length; b++) {
          const A = boxes[a].r, B = boxes[b].r;
          const w = Math.min(A.right, B.right) - Math.max(A.left, B.left);
          const h = Math.min(A.bottom, B.bottom) - Math.max(A.top, B.top);
          if (w <= 0 || h <= 0) continue;
          const small = Math.min(A.width * A.height, B.width * B.height);
          if (w * h > small * 0.2) laps.push(key + ' seed=' + seed + ' "' + boxes[a].s + '" × "' + boxes[b].s + '"');
        }
      }
    }
    host.remove();
    return { errs: errs.slice(0, 15), total: errs.length, checked, laps: laps.slice(0, 30), lapTotal: laps.length };
  });
  expect(out.checked).toBeGreaterThan(2000);
  expect(out.errs, out.errs.join('\n')).toEqual([]);
  expect(out.laps, out.lapTotal + ' overlaps\n' + out.laps.join('\n')).toEqual([]);
});

test('キラキラの正解エフェクトが結線される', async ({ page }) => {
  await page.goto(URL);
  const wired = await page.evaluate(() => ({
    fn: typeof window.DivpKirakira === 'function',
    registered: !!(window.DivpEffects && window.DivpEffects.kirakira),
    correct: window.Divp && window.Divp.correct === window.DivpKirakira
  }));
  expect(wired).toEqual({ fn: true, registered: true, correct: true });
});

test('6つのモードが 出題→採点→次の問題 まで通る', async ({ page }) => {
  await page.goto(URL);
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  for (const mode of ['mitsukeru', 'henkaku', 'chushin', 'shukushaku', 'riyou', 'mix']) {
    await page.click('.m-card[data-mode="' + mode + '"]');
    for (let i = 0; i < 4; i++) {
      await expect(page.locator('.optBtn')).toHaveCount(4);
      const ci = await page.evaluate(() => window.cur.correct);
      const pick = i === 3 ? (ci + 1) % 4 : ci;
      await page.click('.optBtn[data-idx="' + pick + '"]');
      await page.click('#checkBtn');
      await expect(page.locator('#feedback')).toHaveClass(/show/);
      await expect(page.locator('.optBtn[data-divp-mark="' + (i === 3 ? 'answer' : 'correct') + '"]')).toHaveCount(1);
      await page.click('#nextBtn');
    }
    if (mode !== 'mix') await expect(page.locator('#qTag')).toBeVisible();
    await page.click('#homeBtn');
  }
  expect(errors).toEqual([]);
});

test('誤答の 記録（種・図）から ?retry=1 で 同じ問題が 出る', async ({ page }) => {
  await page.goto(URL);
  await page.evaluate(() => {
    window._sent = [];
    window.Divp.answer = (ok, p) => { window._sent.push({ ok, p }); };
  });
  await page.click('.m-card[data-mode="henkaku"]');
  const shown = await page.evaluate(() => ({ text: window.cur.plainQ, fig: window.cur.fig }));
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ((ci + 1) % 4) + '"]');
  await page.click('#checkBtn');
  const sent = await page.evaluate(() => window._sent);
  expect(sent).toHaveLength(1);
  const p = sent[0].p;
  expect(sent[0].ok).toBe(false);
  expect(['hen', 'kaku']).toContain(p.question_key);
  expect(Object.keys(p.question_params).sort()).toEqual(['m', 's']);
  expect(p.question_text).toBe(shown.text);
  expect(p.question_figure).toBe(shown.fig);
  expect(p.wrong_answer).not.toBe(p.correct_answer);

  await page.click('#homeBtn');
  await page.evaluate((params) => {
    window.Divp.getRetries = () => Promise.resolve([{ question_params: params }]);
    window.initRetry();
  }, p.question_params);
  await expect(page.locator('#qTag')).toContainText('解き直し 1 / 1');
  const again = await page.evaluate(() => ({ text: window.cur.plainQ, fig: window.cur.fig }));
  expect(again).toEqual(shown);
});

test('印刷シートが 問題・答え とも A4 1枚に おさまる', async ({ page }) => {
  /* 紙の 中身の はば：210mm − 左右 11mm ＝ 188mm ≒ 711px、高さ 297 − 20 ＝ 277mm ≒ 1047px */
  await page.setViewportSize({ width: 711, height: 1047 });
  await page.goto(URL);
  await page.emulateMedia({ media: 'print' });
  const res = await page.evaluate(() => {
    const out = {};
    for (const mode of Object.keys(window.PRINT_KEYS)) {
      let maxQ = 0, maxA = 0;
      for (let t = 0; t < 60; t++) {
        document.getElementById('printArea').innerHTML = window.buildPrint(mode);
        const sh = document.querySelectorAll('#printArea .sheet');
        maxQ = Math.max(maxQ, sh[0].getBoundingClientRect().height);
        maxA = Math.max(maxA, sh[1].getBoundingClientRect().height);
      }
      out[mode] = { q: Math.round(maxQ), a: Math.round(maxA) };
    }
    return out;
  });
  for (const mode of Object.keys(res)) {
    expect(res[mode].q, mode + ' 問題 ' + JSON.stringify(res)).toBeLessThanOrEqual(1040);
    expect(res[mode].a, mode + ' 答え ' + JSON.stringify(res)).toBeLessThanOrEqual(1040);
  }
});
