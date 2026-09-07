/* 立体の体積マスター（math_es6_rittai_taiseki）の回帰テスト
   ・12種類の生成関数すべてが、多数の種で
       - 選択肢4つが重複せず、正解が ansText と一致する
       - 解説の最後の太字が 答えと一致する（＝式と答えがずれていない）
       - 図（question_figure）が save_answer.php の figure_is_safe() を通る
         ＝誤答したとき 解き直しプリントに 図が出る（落ちると気づきにくい）
       - 同じ種から まったく同じ問題が出る（?retry=1 の 種方式の前提）
   ・キラキラの正解エフェクトが Divp.correct に結線され、星が画面下に積もること
   ・5つのモードすべてが 出題→採点→次の問題 まで通ること                      */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_es6_rittai_taiseki.html';

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

test('12種類の生成関数が 選択肢・解説・図・種の再現性を満たす', async ({ page }) => {
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
          if (!/^[0-9]+(\.[0-9]+)? cm[²³]?$/.test(o)) errs.push(at + ': option "' + o + '"');
        });
        if (q.options[q.correct] !== q.ansText) errs.push(at + ': ansText ' + q.ansText);
        /* 解説の最後の太字＝答え（式と答えがずれていないこと） */
        const b = String(q.expl).match(/<b>([^<]*)<\/b>/g) || [];
        if (!b.length) errs.push(at + ': no bold answer');
        else if (Math.abs(num(b[b.length - 1]) - num(q.ansText)) > 1e-6)
          errs.push(at + ': expl ' + b[b.length - 1] + ' != ' + q.ansText);
        /* 図（誤答時に answer_logs.question_figure に入る） */
        const bad = figBad(q.fig);
        if (bad) errs.push(at + ': FIGURE ' + bad);
        /* 数の作り損ない（NaN/undefined が 文章や図に 出ていないこと） */
        ['qHtml', 'plainQ', 'expl', 'fig'].forEach(f => {
          if (/NaN|undefined|Infinity/.test(String(q[f]))) errs.push(at + ': NaN in ' + f);
        });
        /* 種方式：同じ種から まったく同じ問題（?retry=1 の前提） */
        window.rngSeed(seed);
        const q2 = window.GENS[key]();
        if (q2.fig !== q.fig || q2.ansText !== q.ansText || q2.plainQ !== q.plainQ)
          errs.push(at + ': not reproducible from the same seed');
      }
    }
    return { keys: keys.length, errs: errs.slice(0, 20), total: errs.length };
  }, { tags: FIG_TAGS, attrs: FIG_ATTRS, n: 120 });

  expect(res.keys).toBe(12);
  expect(res.errs, res.errs.join('\n')).toEqual([]);
  expect(res.total).toBe(0);
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

  await page.click('.m-card[data-mode="kakuchu"]');
  for (let i = 0; i < 12; i++) {
    const ci = await page.evaluate(() => window.cur.correct);
    await page.click('.optBtn[data-idx="' + ci + '"]');
    await page.click('#checkBtn');
    await page.waitForTimeout(80);
    await page.click('#nextBtn');
  }
  await page.waitForTimeout(3000);
  /* 画面のいちばん下の帯に 星が 描かれていること（canvas 1枚に 積んでいる） */
  const painted = await page.evaluate(() => {
    const c = document.getElementById('divp-kk-pile');
    if (!c) return -1;
    const d = c.getContext('2d').getImageData(0, c.height - 40, c.width, 40).data;
    let n = 0;
    for (let i = 3; i < d.length; i += 4) if (d[i] > 8) n++;
    return n;
  });
  expect(painted).toBeGreaterThan(1000);
});

test('5つのモードが 出題→採点→次の問題 まで通る', async ({ page }) => {
  await page.goto(URL);
  for (const mode of ['menseki', 'kakuchu', 'enchu', 'kufuu', 'mix']) {
    await page.click('.m-card[data-mode="' + mode + '"]');
    for (let i = 0; i < 3; i++) {
      await expect(page.locator('#figArea svg')).toHaveCount(1);
      await expect(page.locator('.optBtn')).toHaveCount(4);
      const ci = await page.evaluate(() => window.cur.correct);
      await page.click('.optBtn[data-idx="' + ci + '"]');
      await page.click('#checkBtn');
      await expect(page.locator('#feedback')).toHaveClass(/show/);
      await page.click('#nextBtn');
    }
    /* ミックス以外は 種類バッヂが 出る（ミックスは 種類名がヒントになるので出さない） */
    if (mode !== 'mix') await expect(page.locator('#qTag')).toBeVisible();
    await page.click('#homeBtn');
  }
});

test('しくみの部屋が 3つの底面で 板を積み上げて 式を出す', async ({ page }) => {
  await page.goto(URL);
  await page.click('#labBtn');
  await page.waitForTimeout(3200);
  await expect(page.locator('#labStage svg')).toHaveCount(1);
  await expect(page.locator('.lab-step')).toHaveCount(4);
  /* 底面積 × 高さ ＝ 体積 の 3つの数が つじつま合うこと */
  for (const i of [0, 1, 2]) {
    await page.click('.lab-chip[data-i="' + i + '"]');
    await page.waitForTimeout(3400);
    const t = (await page.locator('#labShiki').textContent()).replace(/\s+/g, '');
    const m = t.match(/底面積([\d.]+)cm²×高さ(\d+)cm＝([\d.]+)cm³/);
    expect(m, t).not.toBeNull();
    expect(Math.round(Number(m[1]) * Number(m[2]) * 100) / 100).toBe(Number(m[3]));
  }
});
