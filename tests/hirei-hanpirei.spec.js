/* 比例と反比例マスター（math_es6_hirei_hanpirei）の回帰テスト
   ・11種類の生成関数すべてが、多数の種で
       - 選択肢が重複せず（4つ。見分けるの「比例する／反比例する／どちらでもない」だけ3つ）、
         正解が ansText と一致する
       - 数の答えは 生成時の 答え(chk.ans)・解説の最後の太字と一致し、ほかの選択肢に同じ値が無い
       - 表・グラフの 図（question_figure）が save_answer.php の figure_is_safe() を通る
       - 同じ種から まったく同じ問題が出る（?retry=1 の 種方式の前提）
       - question_text 255バイト・答え100バイトに おさまり、TeX と まちがわれる 文字が無い
   ・図の 文字が viewBox の 外に はみ出さず、文字どうしが 重ならない（実際に 描いて 測る）
   ・メニュー（比例・反比例・ランダム → 中の 種類）と すべての モードが 出題→採点→次の問題 まで通る
   ・誤答の 記録から ?retry=1 で 同じ問題
   ・キラキラの正解エフェクトが結線される
   ・印刷シートが A4 1枚（問題・答え それぞれ）に おさまる                           */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_es6_hirei_hanpirei.html';

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

const KEYS = ['hirei_hyou', 'hirei_shiki', 'hirei_atai', 'hanpi_hyou', 'hanpi_shiki', 'hanpi_atai',
  'hirei_graph', 'hanpi_graph', 'miwake', 'riyou_hirei', 'riyou_hanpi'];
/* 図が無いと 解けない 種類（値・利用・見分けるの 文章題は 図なしで よい） */
const NEED_FIG = ['hirei_hyou', 'hanpi_hyou', 'hirei_graph', 'hanpi_graph'];

test('11種類の生成関数が 選択肢・答え・図・種の再現性を満たす', async ({ page }) => {
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
        const len = q.options.length;
        if (!(len === 4 || (key === 'miwake' && len === 3))) errs.push(at + ': options ' + len);
        const plains = q.options.map(o => o.plain);
        if (new Set(plains).size !== len) errs.push(at + ': duplicate ' + plains.join('/'));
        if (q.correct < 0 || q.correct >= len) errs.push(at + ': correct ' + q.correct);
        q.options.forEach(o => {
          if (!o.ok) errs.push(at + ': option not ok ' + o.plain);
          if (bytes(o.plain) > 100) errs.push(at + ': option too long');
          if (/NaN|undefined|Infinity/.test(o.html + o.plain)) errs.push(at + ': NaN option');
        });
        if (q.ansText !== plains[q.correct]) errs.push(at + ': ansText ' + q.ansText);
        if (bytes(q.plainQ) > 255) errs.push(at + ': plainQ ' + bytes(q.plainQ) + 'B ' + q.plainQ);
        /* 講師画面が 文字列ぜんぶを TeX に まわす 文字（CLAUDE.md 2a） */
        if (/[{}\\^_]/.test(q.plainQ + q.ansText + plains.join(''))) errs.push(at + ': TeX char in plain');
        if (q.chk && typeof q.chk.ans === 'number') {
          if (Math.abs(num(q.ansText) - q.chk.ans) > 1e-6) errs.push(at + ': ans ' + q.ansText + ' != ' + q.chk.ans);
          const b = String(q.expl).match(/<b>([^<]*)<\/b>/g) || [];
          if (!b.length) errs.push(at + ': no bold answer');
          else if (Math.abs(num(b[b.length - 1]) - q.chk.ans) > 1e-6)
            errs.push(at + ': expl ' + b[b.length - 1] + ' != ' + q.chk.ans);
          plains.forEach((p, k) => {
            if (k !== q.correct && Math.abs(num(p) - q.chk.ans) < 1e-9) errs.push(at + ': same value ' + p);
          });
        }
        if (needFig.indexOf(key) >= 0 && !q.fig) errs.push(at + ': no figure');
        if (q.fig) {
          const bad = figBad(q.fig);
          if (bad) errs.push(at + ': FIGURE ' + bad);
        }
        ['qHtml', 'plainQ', 'expl', 'fig', 'tag', 'label'].forEach(f => {
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
  }, { tags: FIG_TAGS, attrs: FIG_ATTRS, n: 400, needFig: NEED_FIG });

  expect(res.keys).toEqual(KEYS);
  expect(res.errs, res.errs.join('\n')).toEqual([]);
  expect(res.total).toBe(0);
  /* 出しわけ（表・何倍・決まった数／読み取り・式・2本・形／利用の場面）が ぜんぶ 出ている */
  expect(res.tagCount.hirei_hyou).toBe(3);
  expect(res.tagCount.hanpi_hyou).toBe(3);
  expect(res.tagCount.hirei_graph).toBe(4);
  expect(res.tagCount.hanpi_graph).toBe(3);
  expect(res.tagCount.miwake).toBe(3);
  expect(res.tagCount.riyou_hirei).toBe(5);
  expect(res.tagCount.riyou_hanpi).toBe(4);
});

test('正解の 選択肢の かたよりが 無い（4択は 4つの 場所に ちらばる）', async ({ page }) => {
  await page.goto(URL);
  const pos = await page.evaluate(() => {
    const out = {};
    /* 見分けるは「比例する／反比例する／どちらでもない」を 決まった 順に 出すので のぞく */
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

test('図の 文字が viewBox の 外に はみ出さず、文字どうしが 重ならない', async ({ page }) => {
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

test('キラキラの正解エフェクトが結線される', async ({ page }) => {
  await page.goto(URL);
  const wired = await page.evaluate(() => ({
    fn: typeof window.DivpKirakira === 'function',
    registered: !!(window.DivpEffects && window.DivpEffects.kirakira),
    correct: window.Divp && window.Divp.correct === window.DivpKirakira
  }));
  expect(wired).toEqual({ fn: true, registered: true, correct: true });
});

/* メニュー：はじめは「比例」「反比例」「ランダム」の 3つ。比例・反比例の 中に 種類ごとの モードと
   その中だけの ランダムが ある */
const MENU = {
  hirei: ['hirei_hyou', 'hirei_shiki', 'hirei_atai', 'hirei_graph', 'riyou_hirei', 'miwake', 'hirei'],
  hanpi: ['hanpi_hyou', 'hanpi_shiki', 'hanpi_atai', 'hanpi_graph', 'riyou_hanpi', 'miwake', 'hanpi'],
};
const card = (page, menu, mode) =>
  page.locator('.m-page[data-page="' + menu + '"] .m-card[data-mode="' + mode + '"]');

test('はじめの メニューは 比例・反比例・ランダムの 3つ／中の メニューを 行き来できる', async ({ page }) => {
  await page.goto(URL);
  const top = page.locator('.m-page[data-page="top"] .m-card');
  await expect(top).toHaveCount(3);
  await expect(top.nth(0)).toHaveAttribute('data-open', 'hirei');
  await expect(top.nth(1)).toHaveAttribute('data-open', 'hanpi');
  await expect(top.nth(2)).toHaveAttribute('data-mode', 'mix');
  await expect(page.locator('.m-page[data-page="hirei"]')).toBeHidden();

  for (const menu of ['hirei', 'hanpi']) {
    await page.click('.m-card[data-open="' + menu + '"]');
    await expect(page.locator('.m-page[data-page="top"]')).toBeHidden();
    const modes = await page.locator('.m-page[data-page="' + menu + '"] .m-card')
      .evaluateAll(els => els.map(e => e.dataset.mode));
    expect(modes).toEqual(MENU[menu]);
    /* 最後が その中だけの ランダム */
    await expect(card(page, menu, menu)).toContainText(menu === 'hirei' ? '比例の ランダム' : '反比例の ランダム');
    /* 問題を 解いて「ホームに戻る」と、開いていた メニューに もどる */
    await card(page, menu, menu + '_hyou').click();
    await page.click('#homeBtn');
    await expect(page.locator('.m-page[data-page="' + menu + '"]')).toBeVisible();
    await page.locator('.m-page[data-page="' + menu + '"] .m-back').click();
    await expect(page.locator('.m-page[data-page="top"]')).toBeVisible();
  }
});

test('比例の ランダムは 比例だけ、反比例の ランダムは 反比例だけ を 出す', async ({ page }) => {
  await page.goto(URL);
  const keys = await page.evaluate(() => ({ hirei: window.MODE_KEYS.hirei, hanpi: window.MODE_KEYS.hanpi, mix: window.MODE_KEYS.mix }));
  keys.hirei.forEach(k => expect(k, k).toMatch(/hirei/));
  keys.hanpi.forEach(k => expect(k, k).toMatch(/hanpi/));
  /* 比例・反比例の 種類が ぜんぶ 入っている */
  expect([...new Set(keys.hirei)].sort()).toEqual(['hirei_atai', 'hirei_graph', 'hirei_hyou', 'hirei_shiki', 'riyou_hirei']);
  expect([...new Set(keys.hanpi)].sort()).toEqual(['hanpi_atai', 'hanpi_graph', 'hanpi_hyou', 'hanpi_shiki', 'riyou_hanpi']);
  /* ランダム（はじめの メニュー）は 見分けるも ふくめて ぜんぶ */
  expect([...new Set(keys.mix)].sort()).toEqual(KEYS.slice().sort());
  /* 利用の 場面も 比例／反比例で 分かれている */
  const tags = await page.evaluate(() => {
    const o = { riyou_hirei: new Set(), riyou_hanpi: new Set() };
    for (let i = 0; i < 300; i++) {
      for (const k of Object.keys(o)) { window.rngSeed(i * 7919 + 3); o[k].add(window.GENS[k]().tag); }
    }
    return { riyou_hirei: [...o.riyou_hirei], riyou_hanpi: [...o.riyou_hanpi] };
  });
  tags.riyou_hirei.forEach(t => expect(t).toMatch(/^比例の 利用/));
  tags.riyou_hanpi.forEach(t => expect(t).toMatch(/^反比例の 利用/));
});

test('すべての モードが 出題→採点→次の問題 まで通る', async ({ page }) => {
  await page.goto(URL);
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  const runs = [['top', 'mix']];
  for (const menu of ['hirei', 'hanpi']) MENU[menu].forEach(m => runs.push([menu, m]));
  for (const [menu, mode] of runs) {
    if (menu !== 'top') await page.click('.m-card[data-open="' + menu + '"]');
    await card(page, menu, mode).click();
    for (let i = 0; i < 4; i++) {
      const n = await page.evaluate(() => window.cur.options.length);
      await expect(page.locator('.optBtn')).toHaveCount(n);
      /* 出た 問題が その モードの 種類で ある */
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

test('誤答の 記録（種・図）から ?retry=1 で 同じ問題が 出る', async ({ page }) => {
  await page.goto(URL);
  await page.evaluate(() => {
    window._sent = [];
    window.Divp.answer = (ok, p) => { window._sent.push({ ok, p }); };
  });
  await page.click('.m-card[data-open="hirei"]');
  await page.click('.m-card[data-mode="hirei_graph"]');
  const shown = await page.evaluate(() => ({ text: window.cur.plainQ, fig: window.cur.fig }));
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ((ci + 1) % 4) + '"]');
  await page.click('#checkBtn');
  const sent = await page.evaluate(() => window._sent);
  expect(sent).toHaveLength(1);
  const p = sent[0].p;
  expect(sent[0].ok).toBe(false);
  expect(p.question_key).toBe('hirei_graph');
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
