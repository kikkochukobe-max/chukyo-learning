/* 座標・比例・反比例マスター（math_js1_hirei_hanpirei）の回帰テスト
   ・17種類（発展2種類をふくむ）の生成関数すべてが、多数の種で
       - 選択肢が重複せず（4つ。見分けるの「比例する／反比例する／どちらでもない」だけ3つ）、
         正解が ansText と一致する
       - 数の答えは生成時の答え(chk.ans)・解説の最後の太字と一致し、ほかの選択肢に同じ値が無い
       - 式の答えは問題の点を通り、同じ形（比例どうし・反比例どうし）のまちがいの式は通らない
       - 変域の答えは比例定数と x の変域から計算しなおした値と一致する
       - 座標平面・表・グラフの図（question_figure）が save_answer.php の figure_is_safe() を通る
       - 同じ種からまったく同じ問題が出る（?retry=1 の種方式の前提）
       - question_text 255バイト・答え100バイトにおさまり、TeX とまちがわれる文字や生の「<」が無い
   ・図の文字が viewBox の外にはみ出さず、文字どうしが重ならない（実際に描いて測る）
   ・メニュー（座標・比例・反比例・ランダム → 中の種類）とすべてのモードが出題→採点→次の問題まで通る
   ・誤答の記録から ?retry=1 で同じ問題
   ・中学用の「正解」はんこ（divp-correct-jh）が出て、次の問題で消える
   ・印刷シートが A4 1枚（問題・答えそれぞれ）におさまる                           */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_js1_hirei_hanpirei.html';

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

const KEYS = ['zahyou_yomu', 'zahyou_idou', 'zahyou_menseki',
  'hirei_shiki', 'hirei_hyou', 'hirei_atai', 'hirei_graph', 'hirei_heniki', 'riyou_hirei',
  'hirei_heniki_hatten', 'hirei_zukei',
  'hanpi_shiki', 'hanpi_hyou', 'hanpi_atai', 'hanpi_graph', 'riyou_hanpi', 'miwake'];
/* 図が無いと解けない種類（グラフの「通る点」・反比例の「整数の点」は式だけで解くので図なし） */
const NEED_FIG = ['zahyou_yomu', 'zahyou_menseki', 'hirei_hyou', 'hanpi_hyou', 'hirei_zukei'];
/* 発展（製本プリントの問題）は比例のランダム・ランダムに入れない */
const HATTEN = ['hirei_heniki_hatten', 'hirei_zukei'];

test('17種類の生成関数が 選択肢・答え・図・種の再現性を満たす', async ({ page }) => {
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
    /* 画面の負の数は「−」(U+2212) */
    const num = s => Number(String(s).replace(/<[^>]*>/g, '').replace(/−/g, '-').replace(/[^0-9.\-]/g, ''));
    /* 選択肢の key から式を読む（P n/d：y＝(n/d)x、I a：y＝a/x） */
    function eqOf(key) {
      if (key[0] === 'P') { const [p, d] = key.slice(1).split('/').map(Number); return x => p * x / d; }
      if (key[0] === 'I') { const a = Number(key.slice(1)); return x => a / x; }
      return null;
    }
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
        const len = q.options.length;
        if (!(len === 4 || (key === 'miwake' && len === 3))) errs.push(at + ': options ' + len);
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
          if (Math.abs(num(q.ansText) - c.ans) > 1e-6) errs.push(at + ': ans ' + q.ansText + ' != ' + c.ans);
          const b = String(q.expl).match(/<b>([\s\S]*?)<\/b>/g) || [];
          if (!b.length) errs.push(at + ': no bold answer');
          else if (Math.abs(num(b[b.length - 1]) - c.ans) > 1e-6)
            errs.push(at + ': expl ' + b[b.length - 1] + ' != ' + c.ans);
          plains.forEach((p, k) => {
            if (k !== q.correct && Math.abs(num(p) - c.ans) < 1e-9) errs.push(at + ': same value ' + p);
          });
        }
        if (c.thru) {
          q.options.forEach((o, k) => {
            const f = eqOf(o.key);
            if (!f) return;
            const pass = c.thru.every(([x, y]) => Math.abs(f(x) - y) < 1e-9);
            if (k === q.correct && !pass) errs.push(at + ': correct eq misses the point ' + o.plain);
            if (k !== q.correct && pass && o.key[0] === keys[q.correct][0]) errs.push(at + ': 2 answers ' + o.plain);
          });
        }
        if (c.line) {
          q.options.forEach((o, k) => {
            const [x, y] = o.key.slice(1).split(',').map(Number);
            if ((k === q.correct) !== (y * c.line.d === c.line.n * x)) errs.push(at + ': point ' + o.plain);
          });
        }
        if (c.hen) {
          const h = c.hen, a = h.a.n / h.a.d, ya = a * h.x1, yb = a * h.x2;
          const e = h.mode === 'y' ? (a > 0 ? [ya, yb, h.s1, h.s2] : [yb, ya, h.s2, h.s1]) : [h.x1, h.x2, h.s1, h.s2];
          const k = 'h' + h.mode + e[0] + ',' + e[1] + ',' + (e[2] ? 1 : 0) + (e[3] ? 1 : 0);
          if (k !== c.key) errs.push(at + ': heniki ' + k + ' != ' + c.key);
        }
        if (needFig.indexOf(key) >= 0 && !q.fig) errs.push(at + ': no figure');
        if (q.fig) {
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
    return { keys: Object.keys(window.GENS), errs: errs.slice(0, 25), total: errs.length, tagCount };
  }, { tags: FIG_TAGS, attrs: FIG_ATTRS, n: 400, needFig: NEED_FIG });

  expect(res.keys).toEqual(KEYS);
  expect(res.errs, res.errs.join('\n')).toEqual([]);
  expect(res.total).toBe(0);
  /* 出しわけがぜんぶ出ている */
  expect(res.tagCount).toEqual({
    zahyou_yomu: 3, zahyou_idou: 3, zahyou_menseki: 2,
    hirei_shiki: 3, hirei_hyou: 3, hirei_atai: 2, hirei_graph: 3, hirei_heniki: 3, riyou_hirei: 7,
    hirei_heniki_hatten: 5, hirei_zukei: 4,
    hanpi_shiki: 3, hanpi_hyou: 3, hanpi_atai: 2, hanpi_graph: 4, riyou_hanpi: 5, miwake: 4,
  });
});

test('正解の選択肢のかたよりが無い（4択は4つの場所にちらばる）', async ({ page }) => {
  await page.goto(URL);
  const pos = await page.evaluate(() => {
    const out = {};
    /* 見分けるは「比例する／反比例する／どちらでもない」を決まった順に出すのでのぞく */
    for (const key of Object.keys(window.GENS).filter(k => k !== 'miwake')) {
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
    host.style.cssText = 'position:absolute;left:0;top:0;width:330px';
    document.body.appendChild(host);
    const errs = [], laps = [];
    let checked = 0;
    for (const key of Object.keys(window.GENS)) {
      for (let i = 0; i < 100; i++) {
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
  await page.click('.m-card[data-open="hirei"]');
  await page.click('.m-card[data-mode="hirei_atai"]');
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ci + '"]');
  await page.click('#checkBtn');
  await expect(page.locator('#qcard .divp-hanko')).toHaveCount(1);
  await page.click('#nextBtn');
  await expect(page.locator('#qcard .divp-hanko')).toHaveCount(0);
});

/* メニュー：はじめは「座標」「比例」「反比例」「ランダム」の4つ。座標・比例・反比例の中に
   種類ごとのモードとその中だけのランダムがある */
const MENU = {
  zahyou: ['zahyou_yomu', 'zahyou_idou', 'zahyou_menseki', 'zahyou'],
  hirei: ['hirei_shiki', 'hirei_hyou', 'hirei_atai', 'hirei_graph', 'hirei_heniki', 'riyou_hirei', 'miwake', 'hirei',
    'hirei_heniki_hatten', 'hirei_zukei'],
  hanpi: ['hanpi_shiki', 'hanpi_hyou', 'hanpi_atai', 'hanpi_graph', 'riyou_hanpi', 'miwake', 'hanpi'],
};
const RANDOM_LABEL = { zahyou: '座標のランダム', hirei: '比例のランダム', hanpi: '反比例のランダム' };
const card = (page, menu, mode) =>
  page.locator('.m-page[data-page="' + menu + '"] .m-card[data-mode="' + mode + '"]');

test('はじめのメニューは 座標・比例・反比例・ランダムの4つ／中のメニューを行き来できる', async ({ page }) => {
  await page.goto(URL);
  const top = page.locator('.m-page[data-page="top"] .m-card');
  await expect(top).toHaveCount(4);
  await expect(top.nth(0)).toHaveAttribute('data-open', 'zahyou');
  await expect(top.nth(1)).toHaveAttribute('data-open', 'hirei');
  await expect(top.nth(2)).toHaveAttribute('data-open', 'hanpi');
  await expect(top.nth(3)).toHaveAttribute('data-mode', 'mix');
  await expect(page.locator('.m-page[data-page="zahyou"]')).toBeHidden();

  for (const menu of Object.keys(MENU)) {
    await page.click('.m-card[data-open="' + menu + '"]');
    await expect(page.locator('.m-page[data-page="top"]')).toBeHidden();
    const modes = await page.locator('.m-page[data-page="' + menu + '"] .m-card')
      .evaluateAll(els => els.map(e => e.dataset.mode));
    expect(modes).toEqual(MENU[menu]);
    /* 最後がその中だけのランダム */
    await expect(card(page, menu, menu)).toContainText(RANDOM_LABEL[menu]);
    /* 問題を解いて「ホームに戻る」と、開いていたメニューにもどる */
    await card(page, menu, MENU[menu][0]).click();
    await page.click('#homeBtn');
    await expect(page.locator('.m-page[data-page="' + menu + '"]')).toBeVisible();
    await page.locator('.m-page[data-page="' + menu + '"] .m-back').click();
    await expect(page.locator('.m-page[data-page="top"]')).toBeVisible();
  }
});

test('座標・比例・反比例のランダムはそれぞれの種類だけ、ランダムはぜんぶを出す', async ({ page }) => {
  await page.goto(URL);
  const keys = await page.evaluate(() => window.MODE_KEYS);
  keys.zahyou.forEach(k => expect(k, k).toMatch(/^zahyou_/));
  keys.hirei.forEach(k => expect(k, k).toMatch(/hirei/));
  keys.hanpi.forEach(k => expect(k, k).toMatch(/hanpi/));
  expect([...new Set(keys.zahyou)].sort()).toEqual(['zahyou_idou', 'zahyou_menseki', 'zahyou_yomu']);
  expect([...new Set(keys.hirei)].sort()).toEqual(['hirei_atai', 'hirei_graph', 'hirei_heniki', 'hirei_hyou', 'hirei_shiki', 'riyou_hirei']);
  expect([...new Set(keys.hanpi)].sort()).toEqual(['hanpi_atai', 'hanpi_graph', 'hanpi_hyou', 'hanpi_shiki', 'riyou_hanpi']);
  /* ランダム（はじめのメニュー）は見分けるもふくめてぜんぶ（発展はのぞく） */
  expect([...new Set(keys.mix)].sort()).toEqual(KEYS.filter(k => HATTEN.indexOf(k) < 0).sort());
  HATTEN.forEach(k => expect(keys.hirei).not.toContain(k));
});

test('すべてのモードが 出題→採点→次の問題 まで通る', async ({ page }) => {
  await page.goto(URL);
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  const runs = [['top', 'mix']];
  for (const menu of Object.keys(MENU)) MENU[menu].forEach(m => runs.push([menu, m]));
  for (const [menu, mode] of runs) {
    if (menu !== 'top') await page.click('.m-card[data-open="' + menu + '"]');
    await card(page, menu, mode).click();
    for (let i = 0; i < 4; i++) {
      const n = await page.evaluate(() => window.cur.options.length);
      await expect(page.locator('.optBtn')).toHaveCount(n);
      /* 出た問題がそのモードの種類である */
      const k = await page.evaluate(() => window.curKey);
      expect(await page.evaluate(m => window.MODE_KEYS[m], mode)).toContain(k);
      const ci = await page.evaluate(() => window.cur.correct);
      const pick = i === 3 ? (ci + 1) % n : ci;
      await page.click('.optBtn[data-idx="' + pick + '"]');
      await page.click('#checkBtn');
      await expect(page.locator('#feedback')).toHaveClass(/show/);
      await expect(page.locator('.optBtn[data-divp-mark="' + (i === 3 ? 'answer' : 'correct') + '"]')).toHaveCount(1);
      await page.click('#nextBtn');
    }
    if (mode !== 'mix') await expect(page.locator('#qTag')).toBeVisible();
    await page.click('#homeBtn');
    if (menu !== 'top') await page.locator('.m-page[data-page="' + menu + '"] .m-back').click();
  }
  expect(errors).toEqual([]);
});

test('誤答の記録（種・図）から ?retry=1 で同じ問題が出る', async ({ page }) => {
  await page.goto(URL);
  await page.evaluate(() => {
    window._sent = [];
    window.Divp.answer = (ok, p) => { window._sent.push({ ok, p }); };
  });
  await page.click('.m-card[data-open="zahyou"]');
  await page.click('.m-card[data-mode="zahyou_yomu"]');
  const shown = await page.evaluate(() => ({ text: window.cur.plainQ, fig: window.cur.fig }));
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ((ci + 1) % 4) + '"]');
  await page.click('#checkBtn');
  const sent = await page.evaluate(() => window._sent);
  expect(sent).toHaveLength(1);
  const p = sent[0].p;
  expect(sent[0].ok).toBe(false);
  expect(p.question_key).toBe('zahyou_yomu');
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
