/* 線対称・点対称マスター（math_es6_taisho_zukei）の回帰テスト
   ・9種類の生成関数すべてが、多数の種で
       - 選択肢4つが重複せず、正解が ansText と一致する
       - 数の答えは 生成時の 答え(chk.ans)・解説の最後の太字と一致する
       - 方眼の 4択は 正解だけが 線対称（点対称）で、対称の軸・中心の 4択も 正解だけが 本物
       - 対応する点・辺・角の 図は、頂点の 対応（mate）が 本当に 折り返し／180°回転に なっている
       - 方眼に かく 問題は、正解の 点だけが P の 対応する 点で、4点が 2マス 以上 はなれる
       - 図（question_figure）が save_answer.php の figure_is_safe() を通る
       - 同じ種から まったく同じ問題が出る（?retry=1 の 種方式の前提）
       - question_text 255バイト・答え100バイトに おさまり、TeX と まちがわれる 文字が無い
   ・多角形の 台帳（SHAPES）の 軸の 本数・点対称かが 判定関数と 一致する
   ・図の 文字が viewBox の 外に はみ出さず、重ならない（実際に 描いて 測る）
   ・5つのモードが 出題→採点→次の問題 まで通る／誤答の 記録から ?retry=1 で 同じ問題
   ・キラキラの正解エフェクトが結線される
   ・印刷シートが A4 1枚（問題・答え それぞれ）に おさまる                           */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_es6_taisho_zukei.html';

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

const KEYS = ['sen_hantei', 'sen_taiou', 'sen_seishitsu', 'sen_kaku',
  'ten_hantei', 'ten_taiou', 'ten_seishitsu', 'ten_kaku', 'takakkei'];

test('9種類の生成関数が 選択肢・答え・図・種の再現性を満たす', async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto(URL);
  const res = await page.evaluate(({ tags, attrs, n }) => {
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
    const W = window;
    const bytes = (s) => new Blob([String(s)]).size;
    const num = s => Number(String(s).replace(/<[^>]*>/g, '').replace(/[^0-9.\-]/g, ''));
    const errs = [], kinds = {};
    for (const key of Object.keys(W.GENS)) {
      kinds[key] = {};
      for (let i = 0; i < n; i++) {
        const seed = (i * 2654435761 + 12345) >>> 0 || 1;
        W.rngSeed(seed);
        const q = W.GENS[key]();
        const at = key + ' seed=' + seed;
        if (!q) { errs.push(at + ': null'); continue; }
        kinds[key][q.tag] = 1;
        if (q.key !== key) errs.push(at + ': question_key ' + q.key);
        if (q.options.length !== 4) errs.push(at + ': options ' + q.options.length);
        const plains = q.options.map(o => o.plain);
        if (new Set(plains).size !== 4) errs.push(at + ': duplicate ' + plains.join('/'));
        q.options.forEach(o => {
          if (!o.ok) errs.push(at + ': option not ok ' + o.plain);
          if (bytes(o.plain) > 100) errs.push(at + ': option too long');
          if (/NaN|undefined|Infinity/.test(o.html + o.plain)) errs.push(at + ': NaN option');
        });
        if (q.ansText !== plains[q.correct]) errs.push(at + ': ansText ' + q.ansText);
        if (bytes(q.plainQ) > 255) errs.push(at + ': plainQ ' + bytes(q.plainQ) + 'B');
        if (/[{}\\^_]/.test(q.plainQ + q.ansText + plains.join(''))) errs.push(at + ': TeX char in plain');
        ['qHtml', 'plainQ', 'expl', 'fig'].forEach(f => {
          if (/NaN|undefined|Infinity/.test(String(q[f]))) errs.push(at + ': NaN in ' + f);
        });
        const c = q.chk;
        if (c && typeof c.ans === 'number') {
          if (Math.abs(num(q.ansText) - c.ans) > 1e-6) errs.push(at + ': ans ' + q.ansText + ' != ' + c.ans);
          const b = String(q.expl).match(/<b>([^<]*)<\/b>/g) || [];
          if (!b.length || Math.abs(num(b[b.length - 1]) - c.ans) > 1e-6) errs.push(at + ': expl answer');
          plains.forEach((p, k) => {
            if (k !== q.correct && Math.abs(num(p) - c.ans) < 1e-9) errs.push(at + ': same value ' + p);
          });
        }
        if (c && c.type === 'hantei') {
          const want = c.kind === 'sen' ? W.isLineSym : W.isPointSym;
          c.cands.forEach((P, k) => { if (want(P) !== (k === q.correct)) errs.push(at + ': hantei ' + k); });
        }
        if (c && c.type === 'jiku') {
          c.lines.forEach((L, k) => { if (W.isAxisOf(c.P, L.a, L.d) !== (k === q.correct)) errs.push(at + ': jiku ' + k); });
        }
        if (c && c.type === 'chushin') {
          c.pts.forEach((p, k) => { if (W.isCenterOf(c.P, p) !== (k === q.correct)) errs.push(at + ': chushin ' + k); });
        }
        if (c && c.type === 'taiou') {
          c.pts.forEach((p, j) => {
            const m = c.pts[c.mate[j]];
            const w = c.kind === 'sen' ? [-p[0], p[1]] : [2 * c.O[0] - p[0], 2 * c.O[1] - p[1]];
            if (!W.samePt(m, w)) errs.push(at + ': mate ' + j);
          });
          /* 対応する 辺：答えは 対応する 点の 順。文字を 逆に した ひっかけが 必ず ある */
          if (c.edge) {
            const nm = c.names, a = c.edge[0], b = c.edge[1];
            const want = '辺' + nm[c.mate[a]] + nm[c.mate[b]], trap = '辺' + nm[c.mate[b]] + nm[c.mate[a]];
            if (q.ansText !== want) errs.push(at + ': edge answer ' + q.ansText + ' != ' + want);
            if (plains.indexOf(trap) < 0) errs.push(at + ': no reversed trap ' + trap);
            if (q.plainQ.indexOf('辺' + nm[a] + nm[b]) < 0) errs.push(at + ': edge question');
          }
        }
        if (c && c.type === 'kaku') {
          const refl = c.kind === 'ten' ? [2 * c.O[0] - c.P[0], 2 * c.O[1] - c.P[1]]
            : W.reflPt(c.P, c.axis[0], W.sub(c.axis[1], c.axis[0]));
          if (!W.samePt(refl, c.cor)) errs.push(at + ': kaku answer');
          c.pts.forEach((p, k) => { if (W.samePt(p, refl) !== (k === q.correct)) errs.push(at + ': kaku point ' + k); });
          for (let a = 0; a < 4; a++) for (let b = a + 1; b < 4; b++)
            if (W.cheb(c.pts[a], c.pts[b]) < 2) errs.push(at + ': kaku points too close');
        }
        /* 図が 要らないのは「あてはまる 図形の 名前」だけ */
        if (key !== 'takakkei' && !q.fig) errs.push(at + ': no figure');
        if (q.fig) { const bad = figBad(q.fig); if (bad) errs.push(at + ': FIGURE ' + bad); }
        W.rngSeed(seed);
        const q2 = W.GENS[key]();
        if (q2.fig !== q.fig || q2.ansText !== q.ansText || q2.plainQ !== q.plainQ ||
            q2.options.map(o => o.plain).join('|') !== plains.join('|') || q2.correct !== q.correct)
          errs.push(at + ': not reproducible from the same seed');
      }
    }
    /* 多角形の 台帳 */
    W.SHAPES.forEach(s => {
      if (s.circle) return;
      if (W.axesOf(s.pts) !== s.ax) errs.push('SHAPES ' + s.nm + ' axes ' + W.axesOf(s.pts));
      if (W.isPointSym(s.pts) !== s.pt) errs.push('SHAPES ' + s.nm + ' point');
    });
    const tagCount = {};
    Object.keys(kinds).forEach(k => { tagCount[k] = Object.keys(kinds[k]).length; });
    return { keys: Object.keys(W.GENS), errs: errs.slice(0, 25), total: errs.length, tagCount };
  }, { tags: FIG_TAGS, attrs: FIG_ATTRS, n: 300 });

  expect(res.keys).toEqual(KEYS);
  expect(res.errs, res.errs.join('\n')).toEqual([]);
  expect(res.total).toBe(0);
  /* 種類の 出しわけが ぜんぶ 出ている */
  expect(res.tagCount.sen_hantei).toBe(2);
  expect(res.tagCount.ten_hantei).toBe(2);
  expect(res.tagCount.sen_seishitsu).toBe(4);
  expect(res.tagCount.ten_seishitsu).toBe(4);
  expect(res.tagCount.sen_kaku).toBe(2);
  expect(res.tagCount.takakkei).toBe(3);
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
  test.setTimeout(120_000);
  await page.goto(URL);
  const out = await page.evaluate(() => {
    const host = document.createElement('div');
    host.style.cssText = 'position:absolute;left:0;top:0;width:330px';
    document.body.appendChild(host);
    const errs = [], laps = [];
    let checked = 0;
    for (const key of Object.keys(window.GENS)) {
      for (let i = 0; i < 200; i++) {
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
  expect(out.checked).toBeGreaterThan(5000);
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

test('5つのモードが 出題→採点→次の問題 まで通る', async ({ page }) => {
  await page.goto(URL);
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  for (const mode of ['sen', 'ten', 'kaku', 'takakkei', 'mix']) {
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
  await page.click('.m-card[data-mode="kaku"]');
  const shown = await page.evaluate(() => ({ text: window.cur.plainQ, fig: window.cur.fig }));
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ((ci + 1) % 4) + '"]');
  await page.click('#checkBtn');
  const sent = await page.evaluate(() => window._sent);
  expect(sent).toHaveLength(1);
  const p = sent[0].p;
  expect(sent[0].ok).toBe(false);
  expect(['sen_kaku', 'ten_kaku']).toContain(p.question_key);
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
      for (let t = 0; t < 40; t++) {
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
