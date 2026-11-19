/* 円周角マスター（math_js3_enshuukaku）の回帰テスト
   ・6種類の生成関数すべてが、多数の種で
       - 選択肢が4つで重複せず、正解が ansText と一致する
       - 数の答えは生成時の答え(chk.ans)・解説の最後の太字と一致し、ほかの選択肢に同じ値が無い
       - 図に書いた角（○°・x）が、図の上の実際の角の大きさと一致する
         （点を円周上の角度で置いて描いているので、ここがずれると「図と答えが合わない問題」になる）
       - 図（question_figure）が save_answer.php の figure_is_safe() を通る
       - 同じ種からまったく同じ問題が出る（?retry=1 の種方式の前提）
       - question_text 255バイト・答え100バイトにおさまり、TeX とまちがわれる文字や生の「<」が無い
   ・図の文字が viewBox の外にはみ出さず、文字どうしが重ならない（実際に描いて測る）
   ・すべてのモードが出題→採点→次の問題まで通る／正解の偏りが無い
   ・誤答の記録から ?retry=1 で同じ問題
   ・中学用の「正解」はんこ（divp-correct-jh）が出て、次の問題で消える
   ・印刷シートが A4 1枚（問題・答えそれぞれ）におさまる                           */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_js3_enshuukaku.html';

/* api/save_answer.php の figure_is_safe() と同じ検証。
   ⚠ FIG_TAGS / FIG_ATTRS を PHP 側で増やしたらここも合わせること */
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

const KEYS = ['chuushin', 'onaji', 'chokkei', 'ko', 'gyaku', 'oyou'];

test('6種類の生成関数が 選択肢・答え・図の角・種の再現性を満たす', async ({ page }) => {
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
    const bytes = (s) => new Blob([String(s)]).size;
    const num = s => Number(String(s).replace(/<[^>]*>/g, '').replace(/[^0-9.\-]/g, ''));
    const errs = [], kinds = {};
    let geoChecked = 0;
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
        const len = q.options.length;
        if (len !== 4) errs.push(at + ': options ' + len);
        const plains = q.options.map(o => o.plain), keys = q.options.map(o => o.key);
        if (new Set(plains).size !== len) errs.push(at + ': duplicate ' + plains.join('/'));
        if (new Set(keys).size !== len) errs.push(at + ': duplicate key ' + keys.join('/'));
        if (q.correct < 0 || q.correct >= len) errs.push(at + ': correct ' + q.correct);
        q.options.forEach(o => {
          if (!o.ok) errs.push(at + ': option not ok ' + o.plain);
          if (bytes(o.plain) > 100) errs.push(at + ': option too long');
          if (/NaN|undefined|Infinity/.test(o.html + o.plain)) errs.push(at + ': NaN option');
        });
        if (q.ansText !== plains[q.correct]) errs.push(at + ': ansText ' + q.ansText);
        if (bytes(q.plainQ) > 255) errs.push(at + ': plainQ ' + bytes(q.plainQ) + 'B ' + q.plainQ);
        /* 講師画面が文字列ぜんぶを TeX にまわす文字・生の不等号（CLAUDE.md 2a・2f） */
        if (/[{}\\^_<]/.test(q.plainQ + q.ansText + plains.join(''))) errs.push(at + ': TeX char or < in plain');
        const c = q.chk || {};
        if (c.key && keys[q.correct] !== c.key) errs.push(at + ': chk.key ' + c.key + ' != ' + keys[q.correct]);
        if (typeof c.ans === 'number') {
          if (!Number.isInteger(c.ans) || c.ans <= 0) errs.push(at + ': answer not a positive integer ' + c.ans);
          if (Math.abs(num(q.ansText) - c.ans) > 1e-6) errs.push(at + ': ans ' + q.ansText + ' != ' + c.ans);
          const b = String(q.expl).match(/<b>([\s\S]*?)<\/b>/g) || [];
          if (!b.length) errs.push(at + ': no bold answer');
          else if (Math.abs(num(b[b.length - 1]) - c.ans) > 1e-6)
            errs.push(at + ': expl ' + b[b.length - 1] + ' != ' + c.ans);
          plains.forEach((p, k) => {
            if (k !== q.correct && Math.abs(num(p) - c.ans) < 1e-9) errs.push(at + ': same value ' + p);
          });
          /* かならず入れるひっかけ（円周角が鈍角になるときの「中心角の半分」など） */
          (c.must || []).forEach(v => {
            if (v !== c.ans && !plains.some(p => Math.abs(num(p) - v) < 1e-9)) errs.push(at + ': must ' + v + ' missing');
          });
        } else if (!c.key) errs.push(at + ': no chk');
        /* 図に書いた角＝図の上の実際の角 */
        (c.geo || []).forEach(([real, want]) => {
          geoChecked++;
          if (!(Math.abs(real - want) < 0.01)) errs.push(at + ': figure angle ' + real + ' != ' + want);
          if (!(real >= 10)) errs.push(at + ': angle too small to draw ' + real);
        });
        if (!q.fig) errs.push(at + ': no figure');
        else {
          const bad = figBad(q.fig);
          if (bad) errs.push(at + ': FIGURE ' + bad);
        }
        ['qHtml', 'plainQ', 'expl', 'fig', 'tag', 'label'].forEach(f => {
          if (/NaN|undefined|Infinity|\[object/.test(String(q[f]))) errs.push(at + ': NaN in ' + f);
        });
        /* 種方式：同じ種からまったく同じ問題 */
        window.rngSeed(seed);
        const q2 = window.GENS[key]();
        if (q2.fig !== q.fig || q2.ansText !== q.ansText || q2.plainQ !== q.plainQ ||
            q2.options.map(o => o.plain).join('|') !== plains.join('|') || q2.correct !== q.correct)
          errs.push(at + ': not reproducible from the same seed');
      }
    }
    const tagCount = {};
    Object.keys(kinds).forEach(k => { tagCount[k] = Object.keys(kinds[k]).length; });
    return { keys: Object.keys(window.GENS), errs: errs.slice(0, 25), total: errs.length, tagCount, geoChecked };
  }, { tags: FIG_TAGS, attrs: FIG_ATTRS, n: 600 });

  expect(res.keys).toEqual(KEYS);
  expect(res.errs, res.errs.join('\n')).toEqual([]);
  expect(res.total).toBe(0);
  expect(res.geoChecked).toBeGreaterThan(5000);
  /* 出しわけがぜんぶ出ている */
  expect(res.tagCount).toEqual({ chuushin: 7, onaji: 6, chokkei: 3, ko: 4, gyaku: 2, oyou: 5 });
});

test('正解の選択肢のかたよりが無い（4択は4つの場所にちらばる）', async ({ page }) => {
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

test('図の文字が viewBox の外にはみ出さず、文字どうしが重ならない', async ({ page }) => {
  await page.goto(URL);
  const out = await page.evaluate(() => {
    const host = document.createElement('div');
    host.style.cssText = 'position:absolute;left:0;top:0;width:300px';
    document.body.appendChild(host);
    const errs = [], laps = [];
    let checked = 0;
    for (const key of Object.keys(window.GENS)) {
      for (let i = 0; i < 150; i++) {
        const seed = (i * 2246822519 + 7) >>> 0 || 1;
        window.rngSeed(seed);
        const q = window.GENS[key]();
        if (!q.fig) continue;
        host.innerHTML = q.fig;
        const svg = host.querySelector('svg');
        svg.style.width = '300px';
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
  expect(out.checked).toBeGreaterThan(3000);
  expect(out.errs, out.errs.join('\n')).toEqual([]);
  expect(out.laps, out.lapTotal + ' overlaps\n' + out.laps.join('\n')).toEqual([]);
});

test('中学用の「正解」はんこが結線され、次の問題で消える', async ({ page }) => {
  await page.goto(URL);
  const wired = await page.evaluate(() => ({
    jh: !!window.__divpCorrectJHLoaded,
    kirakira: typeof window.DivpKirakira === 'function',
  }));
  expect(wired).toEqual({ jh: true, kirakira: false });
  await page.click('.m-card[data-mode="chuushin"]');
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ci + '"]');
  await page.click('#checkBtn');
  await expect(page.locator('#qcard .divp-hanko')).toHaveCount(1);
  await page.click('#nextBtn');
  await expect(page.locator('#qcard .divp-hanko')).toHaveCount(0);
});

test('メニューの7枚と、ランダムがぜんぶの種類を出すこと', async ({ page }) => {
  await page.goto(URL);
  const modes = await page.locator('.m-card').evaluateAll(els => els.map(e => e.dataset.mode));
  expect(modes).toEqual([...KEYS, 'mix']);
  const keys = await page.evaluate(() => window.MODE_KEYS);
  expect([...new Set(keys.mix)].sort()).toEqual([...KEYS].sort());
});

test('すべてのモードが 出題→採点→次の問題 まで通る', async ({ page }) => {
  await page.goto(URL);
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  for (const mode of [...KEYS, 'mix']) {
    await page.click('.m-card[data-mode="' + mode + '"]');
    for (let i = 0; i < 4; i++) {
      await expect(page.locator('.optBtn')).toHaveCount(4);
      await expect(page.locator('#figArea svg')).toHaveCount(1);
      const k = await page.evaluate(() => window.curKey);
      expect(await page.evaluate(m => window.MODE_KEYS[m], mode)).toContain(k);
      const ci = await page.evaluate(() => window.cur.correct);
      const pick = i === 3 ? (ci + 1) % 4 : ci;
      await page.click('.optBtn[data-idx="' + pick + '"]');
      await page.click('#checkBtn');
      await expect(page.locator('#feedback')).toHaveClass(/show/);
      await expect(page.locator('.optBtn[data-divp-mark="' + (i === 3 ? 'answer' : 'correct') + '"]')).toHaveCount(1);
      await page.click('#nextBtn');
    }
    if (mode !== 'mix') await expect(page.locator('#qTag')).toBeVisible();
    else await expect(page.locator('#qTag')).toBeHidden();
    await page.click('#homeBtn');
  }
  expect(errors).toEqual([]);
});

test('誤答の記録（種・図）から ?retry=1 で同じ問題が出る', async ({ page }) => {
  await page.goto(URL);
  await page.evaluate(() => {
    window._sent = [];
    window.Divp.answer = (ok, p) => { window._sent.push({ ok, p }); };
  });
  await page.click('.m-card[data-mode="onaji"]');
  const shown = await page.evaluate(() => ({ text: window.cur.plainQ, fig: window.cur.fig }));
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ((ci + 1) % 4) + '"]');
  await page.click('#checkBtn');
  const sent = await page.evaluate(() => window._sent);
  expect(sent).toHaveLength(1);
  const p = sent[0].p;
  expect(sent[0].ok).toBe(false);
  expect(p.question_key).toBe('onaji');
  expect(Object.keys(p.question_params).sort()).toEqual(['m', 's']);
  expect(p.question_text).toBe(shown.text);
  expect(p.question_figure).toBe(shown.fig);
  expect(p.question_figure).toContain('<svg');
  expect(p.wrong_answer).not.toBe(p.correct_answer);

  await page.click('#homeBtn');
  await page.evaluate((params) => {
    window.Divp.getRetries = () => Promise.resolve([{ question_params: params }]);
    window.initRetry();
  }, p.question_params);
  await expect(page.locator('#qTag')).toContainText('解き直し 1 / 1');
  const again = await page.evaluate(() => ({ text: window.cur.plainQ, fig: window.cur.fig }));
  expect(again).toEqual(shown);
  /* 1周したらモード選択にもどって案内が出る */
  await page.click('.optBtn[data-idx="0"]');
  await page.click('#checkBtn');
  await page.click('#nextBtn');
  await expect(page.locator('#retryBanner')).toContainText('おつかれさま');
});

test('印刷シートが 問題・答え とも A4 1枚におさまる', async ({ page }) => {
  /* 紙の中身のはば：210mm − 左右 11mm ＝ 188mm ≒ 711px、高さ 297 − 20 ＝ 277mm ≒ 1047px */
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
