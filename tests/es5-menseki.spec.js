/* 図形の面積マスター（math_es5_menseki）の回帰テスト
   ・9種類の生成関数すべてが、多数の種で
       - 選択肢4つが重複せず、正解が ansText と一致する
       - 解説の最後の太字が 答えと一致する（＝式と答えがずれていない）
       - 図（question_figure）が save_answer.php の figure_is_safe() を通る
         ＝誤答したとき 解き直しプリントに 図が出る（落ちると気づきにくい）
       - 図の 文字が viewBox の 外に はみ出さない（紙でも 画面でも 切れる）
       - 同じ種から まったく同じ問題が出る（?retry=1 の 種方式の前提）
       - question_text 255バイト・答え100バイトに おさまる（save_answer.php が切る）
   ・キラキラの正解エフェクトが Divp.correct に結線され、星が画面下に積もること
   ・7つのモードすべてが 出題→採点→次の問題 まで通ること
   ・形ごとの モードは 面積だけ、長さを 求める モードは 長さだけを 出すこと
   ・公式の ひみつ（しくみの部屋）が 4つの形で うごいて 公式を 出すこと         */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_es5_menseki.html';

/* api/save_answer.php の figure_is_safe() と同じ検証をブラウザ側で行う。
   ⚠ FIG_TAGS / FIG_ATTRS を PHP 側で増やしたら ここも合わせること
      （合っていないと「テストは通るが本番で図だけ落ちる」状態になる） */
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

test('9種類の生成関数が 選択肢・解説・図・種の再現性を満たす', async ({ page }) => {
  await page.goto(URL);
  const res = await page.evaluate(({ tags, attrs, n }) => {
    function figBad(s) {
      if (!s) return 'empty';
      if (new Blob([s]).size > 20000) return 'too big';
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
    /* 文字が viewBox の 中に おさまっているか（はばは 図と同じ めやす） */
    function textOut(s) {
      const vb = s.match(/viewBox="0 0 ([\d.]+) ([\d.]+)"/);
      if (!vb) return 'no viewBox';
      const W = +vb[1], H = +vb[2];
      const re = /<text x="([-\d.]+)" y="([-\d.]+)" text-anchor="(\w+)"[^>]*>([^<]*)<\/text>/g;
      let m;
      while ((m = re.exec(s))) {
        const x = +m[1], y = +m[2], w = window.twEst(m[4], 13);
        const x0 = m[3] === 'start' ? x : m[3] === 'end' ? x - w : x - w / 2;
        if (x0 < 0 || x0 + w > W || y < 7 || y > H - 7) return 'text "' + m[4] + '" at ' + x + ',' + y + ' / ' + W + 'x' + H;
      }
      return null;
    }
    const bytes = (s) => new Blob([String(s)]).size;
    const num = s => Number(String(s).replace(/[^0-9.\-]/g, ''));
    const errs = [];
    const keys = Object.keys(window.GENS);
    for (const key of keys) {
      for (let i = 0; i < n; i++) {
        const seed = (i * 2654435761 + 12345) >>> 0 || 1;
        window.rngSeed(seed);
        const q = window.GENS[key]();
        const at = key + ' seed=' + seed;
        if (q.key !== key) errs.push(at + ': question_key ' + q.key);
        if (q.options.length !== 4) errs.push(at + ': options ' + q.options.length);
        if (new Set(q.options).size !== 4) errs.push(at + ': duplicate ' + q.options.join('/'));
        q.options.forEach(o => {
          if (!/^[0-9]+(\.[0-9]+)? (cm|cm²|m²)$/.test(o)) errs.push(at + ': option "' + o + '"');
          if (bytes(o) > 100) errs.push(at + ': option too long');
        });
        if (q.options[q.correct] !== q.ansText) errs.push(at + ': ansText ' + q.ansText);
        if (bytes(q.plainQ) > 255) errs.push(at + ': plainQ ' + bytes(q.plainQ) + 'B');
        /* 解説の最後の太字＝答え（式と答えがずれていないこと） */
        const b = String(q.expl).match(/<b>([^<]*)<\/b>/g) || [];
        if (!b.length) errs.push(at + ': no bold answer');
        else if (Math.abs(num(b[b.length - 1]) - num(q.ansText)) > 1e-6)
          errs.push(at + ': expl ' + b[b.length - 1] + ' != ' + q.ansText);
        /* 図（誤答時に answer_logs.question_figure に入る） */
        const bad = figBad(q.fig);
        if (bad) errs.push(at + ': FIGURE ' + bad);
        const out = textOut(q.fig);
        if (out) errs.push(at + ': ' + out);
        /* 面積から 長さを もとめる 問題は、もとめる 長さが 図の上で □ に なっている
           （図に 書いた 数は □ 以外の 2つ＋面積は 問題文＝図だけ 見て 解き直せる） */
        if (/_gyaku$/.test(key) && (q.fig.match(/>□cm</g) || []).length !== 1)
          errs.push(at + ': □ not in figure');
        /* 数の作り損ない（NaN/undefined が 文章や図に 出ていないこと） */
        ['qHtml', 'plainQ', 'expl', 'fig'].forEach(f => {
          if (/NaN|undefined|Infinity/.test(String(q[f]))) errs.push(at + ': NaN in ' + f);
        });
        /* 種方式：同じ種から まったく同じ問題（?retry=1 の前提） */
        window.rngSeed(seed);
        const q2 = window.GENS[key]();
        if (q2.fig !== q.fig || q2.ansText !== q.ansText || q2.plainQ !== q.plainQ ||
            q2.options.join('|') !== q.options.join('|'))
          errs.push(at + ': not reproducible from the same seed');
      }
    }
    return { keys: keys.length, errs: errs.slice(0, 20), total: errs.length };
  }, { tags: FIG_TAGS, attrs: FIG_ATTRS, n: 300 });

  expect(res.keys).toBe(9);
  expect(res.errs, res.errs.join('\n')).toEqual([]);
  expect(res.total).toBe(0);
});

test('形の 種類が ぜんぶ 出て、ひっかけの 長さが 図に 入る', async ({ page }) => {
  await page.goto(URL);
  const res = await page.evaluate(() => {
    const kinds = {}, noTrap = [];
    for (const key of Object.keys(window.GENS)) {
      kinds[key] = {};
      for (let i = 0; i < 400; i++) {
        window.rngSeed((i * 2654435761 + 77) >>> 0 || 1);
        const q = window.GENS[key]();
        kinds[key][q.kind] = 1;
        /* つかわない 長さ（ななめの 辺など）が 1つも 無いのは、
           正方形（対角線だけで 解く）と 4すみの 三角形（長さが 8つ 並ぶ）だけ */
        const exempt = (key === 'hishi' && q.kind === 2) || (key === 'kufuu' && q.kind === 7);
        if (!exempt && !(q.trap > 0)) noTrap.push(key + ' kind=' + q.kind);
      }
    }
    const count = {};
    Object.keys(kinds).forEach(k => { count[k] = Object.keys(kinds[k]).length; });
    return { count, noTrap: noTrap.slice(0, 10) };
  });
  expect(res.count).toEqual({
    sankaku: 4, sankaku_gyaku: 1, heikou: 3, heikou_gyaku: 1,
    daikei: 3, daikei_gyaku: 1, hishi: 2, hishi_gyaku: 1, kufuu: 7
  });
  expect(res.noTrap).toEqual([]);
});

test('キラキラの正解エフェクトが結線され、星が画面下に積もる', async ({ page }) => {
  await page.goto(URL);
  const wired = await page.evaluate(() => ({
    fn: typeof window.DivpKirakira === 'function',
    registered: !!(window.DivpEffects && window.DivpEffects.kirakira),
    /* data-effect="kirakira" のとき Divp.correct を差し替える取り決め */
    correct: window.Divp && window.Divp.correct === window.DivpKirakira
  }));
  expect(wired).toEqual({ fn: true, registered: true, correct: true });

  await page.click('.m-card[data-mode="sankaku"]');
  for (let i = 0; i < 12; i++) {
    const ci = await page.evaluate(() => window.cur.correct);
    await page.click('.optBtn[data-idx="' + ci + '"]');
    await page.click('#checkBtn');
    await page.waitForTimeout(80);
    await page.click('#nextBtn');
  }
  await page.waitForTimeout(3000);
  /* 星が「こたえあわせ」の帯より 上に 見えていること（帯は 不透明なので、
     山の床を 帯の上に していないと 生徒には「積もらない」ように 見える） */
  const seen = await page.evaluate(() => {
    const c = document.getElementById('divp-kk-pile');
    if (!c) return { err: 'no canvas' };
    const dpr = c.width / window.innerWidth;
    const barTop = document.getElementById('bottom').getBoundingClientRect().top;
    const y = Math.max(0, Math.round((barTop - 40) * dpr));
    const h = Math.max(1, Math.round(40 * dpr));
    const d = c.getContext('2d').getImageData(0, y, c.width, h).data;
    let n = 0;
    for (let i = 3; i < d.length; i += 4) if (d[i] > 8) n++;
    return { aboveBar: n };
  });
  expect(seen.aboveBar, JSON.stringify(seen)).toBeGreaterThan(1000);
});

test('7つのモードが 出題→採点→次の問題 まで通る', async ({ page }) => {
  await page.goto(URL);
  for (const mode of ['sankaku', 'heikou', 'daikei', 'hishi', 'kufuu', 'nagasa', 'mix']) {
    await page.click('.m-card[data-mode="' + mode + '"]');
    for (let i = 0; i < 3; i++) {
      await expect(page.locator('#figArea svg')).toHaveCount(1);
      await expect(page.locator('.optBtn')).toHaveCount(4);
      /* 3問目は わざと まちがえる（答え合わせ表示と 解説が 出ること） */
      const ci = await page.evaluate(() => window.cur.correct);
      const pick = i === 2 ? (ci + 1) % 4 : ci;
      await page.click('.optBtn[data-idx="' + pick + '"]');
      await page.click('#checkBtn');
      await expect(page.locator('#feedback')).toHaveClass(/show/);
      await expect(page.locator('.optBtn[data-divp-mark="' + (i === 2 ? 'answer' : 'correct') + '"]')).toHaveCount(1);
      await page.click('#nextBtn');
    }
    /* ミックス以外は 種類バッヂが 出る（ミックスは 種類名がヒントになるので出さない） */
    if (mode !== 'mix') await expect(page.locator('#qTag')).toBeVisible();
    await page.click('#homeBtn');
  }
});

test('誤答の 記録（種・図）から ?retry=1 で 同じ問題が 出る', async ({ page }) => {
  await page.goto(URL);
  /* Divp.answer に わたる 中身を 横どりする（サーバーには 送らない） */
  await page.evaluate(() => {
    window._sent = [];
    window.Divp.answer = (ok, p) => { window._sent.push({ ok, p }); };
  });
  await page.click('.m-card[data-mode="kufuu"]');
  const shown = await page.evaluate(() => ({ text: window.cur.plainQ, fig: window.cur.fig }));
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ((ci + 1) % 4) + '"]');
  await page.click('#checkBtn');
  const sent = await page.evaluate(() => window._sent);
  expect(sent).toHaveLength(1);
  const p = sent[0].p;
  expect(sent[0].ok).toBe(false);
  expect(p.question_key).toBe('kufuu');
  expect(Object.keys(p.question_params).sort()).toEqual(['m', 's']);
  expect(p.question_text).toBe(shown.text);
  expect(p.question_figure).toBe(shown.fig);
  expect(p.wrong_answer).not.toBe(p.correct_answer);

  /* 解き直し：保存された params を そのまま 返すと、同じ 問題が 出る */
  await page.click('#homeBtn');
  await page.evaluate((params) => {
    window.Divp.getRetries = () => Promise.resolve([{ question_params: params }]);
    window.initRetry();
  }, p.question_params);
  await expect(page.locator('#qTag')).toContainText('解き直し 1 / 1');
  const again = await page.evaluate(() => ({ text: window.cur.plainQ, fig: window.cur.fig }));
  expect(again).toEqual(shown);
});

test('公式の ひみつが 4つの形で うごいて 公式を 出す', async ({ page }) => {
  await page.goto(URL);
  await page.click('#labBtn');
  await expect(page.locator('.lab-chip')).toHaveCount(4);
  const want = ['底辺×高さ', '底辺×高さ÷2', '(上底＋下底)×高さ÷2', '対角線×対角線÷2'];
  for (const i of [0, 1, 2, 3]) {
    await page.click('.lab-chip[data-i="' + i + '"]');
    await expect(page.locator('#labStage svg')).toHaveCount(1);
    await expect(page.locator('.lab-step')).toHaveCount(3);
    await page.click('#labPlay');
    await page.waitForTimeout(2000);
    const t = (await page.locator('#labShiki').textContent()).replace(/\s+/g, '');
    expect(t).toContain(want[i]);
    await expect(page.locator('.lab-step.now .no')).toHaveText('3');
  }
  /* 解説の「なぜ？」ボタンから その形の 部屋に 入れる */
  await page.click('#labBack');
  await page.click('.m-card[data-mode="daikei"]');
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ci + '"]');
  await page.click('#checkBtn');
  const naze = page.locator('#expl .naze');
  if (await naze.count()) {
    await naze.click();
    await expect(page.locator('.lab-chip.on')).toHaveText('台形');
  }
});

test('形ごとの モードは 面積だけ、長さを 求める モードは 長さだけ', async ({ page }) => {
  await page.goto(URL);
  const res = await page.evaluate(() => {
    const out = {};
    for (const m of Object.keys(window.MODE_KEYS)) {
      const keys = window.MODE_KEYS[m];
      out[m] = {
        gyaku: keys.filter(k => /_gyaku$/.test(k)).length,
        area: keys.filter(k => !/_gyaku$/.test(k)).length,
        unknown: keys.filter(k => !window.GENS[k]).length
      };
    }
    return out;
  });
  for (const m of ['sankaku', 'heikou', 'daikei', 'hishi', 'kufuu'])
    expect(res[m].gyaku, m + ' に 長さの 問題が まざっている').toBe(0);
  expect(res.nagasa.area, '長さの モードに 面積の 問題').toBe(0);
  expect(res.nagasa.gyaku).toBe(4);
  expect(res.mix.gyaku).toBeGreaterThan(0);
  expect(res.mix.area).toBeGreaterThan(0);
  Object.keys(res).forEach(m => expect(res[m].unknown, m).toBe(0));

  /* 長さの モードは 答えが cm、形の モードは 答えが cm²（実際の 画面で） */
  for (const [mode, re] of [['nagasa', /^\d+(\.\d+)? cm$/], ['heikou', /^\d+(\.\d+)? cm²$/]]) {
    await page.click('.m-card[data-mode="' + mode + '"]');
    for (let i = 0; i < 6; i++) {
      expect(await page.evaluate(() => window.cur.ansText)).toMatch(re);
      const ci = await page.evaluate(() => window.cur.correct);
      await page.click('.optBtn[data-idx="' + ci + '"]');
      await page.click('#checkBtn');
      await page.click('#nextBtn');
    }
    await page.click('#homeBtn');
  }
});
