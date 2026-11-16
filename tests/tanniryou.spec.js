/* 単位量あたりマスター（math_es5_tanniryou）の回帰テスト
   ・13種類の生成関数すべてが、多数の種で
       - 選択肢が重複せず（数の答えは4つ、くらべる問題は A・B・C の3つ）、正解が ansText と一致する
       - 数の答えは 生成時の 答え(chk.ans)・解説の最後の太字と一致し、ほかの選択肢に同じ値が無い
       - くらべる問題は 解説の 1あたりの 値を 実際に 計算しなおして、正解が ほんとうに いちばん
       - 同じ種から まったく同じ問題が出る（?retry=1 の 種方式の前提）
       - question_text 255バイト・答え100バイトに おさまり、TeX と まちがわれる 文字が無い
   ・解説の 2本の数直線の 文字が viewBox の 外に はみ出さず、文字どうしが 重ならない
   ・すべての モードが 出題→採点→次の問題 まで通る
   ・誤答の 記録から ?retry=1 で 同じ問題
   ・キラキラの正解エフェクトが結線される
   ・印刷シートが A4 1枚（問題・答え それぞれ）に おさまる                           */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_es5_tanniryou.html';

const KEYS = ['komi', 'komi_hikaku', 'jinko', 'jinko_hikaku',
  'iroiro_tanni', 'iroiro_zentai', 'iroiro_ikutsu', 'iroiro_hikaku',
  'hayasa_motomeru', 'hayasa_michinori', 'hayasa_jikan', 'hayasa_hikaku', 'hayasa_kansan'];
const HIKAKU = KEYS.filter(k => /hikaku$/.test(k));

test('13種類の生成関数が 選択肢・答え・種の再現性を満たす', async ({ page }) => {
  await page.goto(URL);
  const res = await page.evaluate(({ n, hikaku }) => {
    const bytes = (s) => new Blob([String(s)]).size;
    const num = s => Number(String(s).replace(/<[^>]*>/g, '').replace(/[²]/g, '').replace(/[^0-9.\-]/g, ''));
    const errs = [];
    for (const key of Object.keys(window.GENS)) {
      for (let i = 0; i < n; i++) {
        const seed = (i * 2654435761 + 12345) >>> 0 || 1;
        window.rngSeed(seed);
        const q = window.GENS[key]();
        const at = key + ' seed=' + seed;
        if (!q) { errs.push(at + ': null'); continue; }
        if (q.key !== key) errs.push(at + ': question_key ' + q.key);
        const isH = hikaku.indexOf(key) >= 0;
        const len = q.options.length;
        if (len !== (isH ? 3 : 4)) errs.push(at + ': options ' + len);
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
        if (/[{}\\^_]/.test(q.plainQ + q.ansText + plains.join(''))) errs.push(at + ': TeX char in plain');
        ['qHtml', 'plainQ', 'expl', 'tag', 'label'].forEach(f => {
          if (/NaN|undefined|Infinity/.test(String(q[f]))) errs.push(at + ': NaN in ' + f);
        });
        if (!isH) {
          if (!q.chk || typeof q.chk.ans !== 'number') errs.push(at + ': no chk');
          else {
            if (Math.abs(num(q.ansText) - q.chk.ans) > 1e-6) errs.push(at + ': ans ' + q.ansText + ' != ' + q.chk.ans);
            const b = String(q.expl).match(/<b>([^<]*)<\/b>/g) || [];
            if (!b.length) errs.push(at + ': no bold answer');
            else if (Math.abs(num(b[b.length - 1]) - q.chk.ans) > 1e-6)
              errs.push(at + ': expl ' + b[b.length - 1] + ' != ' + q.chk.ans);
            plains.forEach((p, k) => {
              if (k !== q.correct && Math.abs(num(p) - q.chk.ans) < 1e-9) errs.push(at + ': same value ' + p);
            });
          }
        } else {
          /* 解説の「A： 12 ÷ 8 ＝ 1.5 人」を 計算しなおし、わり算が 正しいか・正解が いちばんか */
          const box = document.createElement('div');
          box.innerHTML = q.expl;
          const vals = [];
          box.querySelectorAll('.step').forEach(s => {
            const m = s.textContent.match(/^([ABC])： ([\d,.]+) ÷ ([\d,.]+) ＝ \D*([\d.]+)/);
            if (!m) return;
            const a = Number(m[2].replace(/,/g, '')), b = Number(m[3].replace(/,/g, '')), v = Number(m[4]);
            if (Math.abs(a / b - v) > 1e-6) errs.push(at + ': calc ' + s.textContent);
            vals.push(v);
          });
          if (q.fallback !== false) errs.push(at + ': fallback table used');
          if (vals.length !== 3) { errs.push(at + ': steps ' + vals.length); continue; }
          if (new Set(vals).size !== 3) errs.push(at + ': same per ' + vals.join(','));
          const big = /いちばん (大きい|高い|多い|長い|速い)のは/.test(box.textContent);
          const small = /いちばん (小さい|低い|安い|おそい)のは/.test(box.textContent);
          if (big === small) errs.push(at + ': which? ' + box.textContent);
          const want = big ? Math.max(...vals) : Math.min(...vals);
          if (vals[q.correct] !== want) errs.push(at + ': not best ' + vals.join(',') + ' correct=' + q.correct);
          /* 問題文の 表にも 3行 */
          const t = document.createElement('div');
          t.innerHTML = q.qHtml;
          if (t.querySelectorAll('tr').length !== 4) errs.push(at + ': table rows');
        }
        /* 種方式：同じ種から まったく同じ問題 */
        window.rngSeed(seed);
        const q2 = window.GENS[key]();
        if (q2.ansText !== q.ansText || q2.plainQ !== q.plainQ || q2.expl !== q.expl ||
            q2.options.map(o => o.plain).join('|') !== plains.join('|') || q2.correct !== q.correct)
          errs.push(at + ': not reproducible from the same seed');
      }
    }
    return { keys: Object.keys(window.GENS), errs: errs.slice(0, 25), total: errs.length };
  }, { n: 400, hikaku: HIKAKU });

  expect(res.keys).toEqual(KEYS);
  expect(res.errs, res.errs.join('\n')).toEqual([]);
  expect(res.total).toBe(0);
});

test('出しわけ（場面・向き・四捨五入・単位の かえ方）が ぜんぶ 出る', async ({ page }) => {
  await page.goto(URL);
  const seen = await page.evaluate(() => {
    const o = {};
    for (const key of Object.keys(window.GENS)) {
      o[key] = new Set();
      for (let i = 0; i < 400; i++) {
        window.rngSeed((i * 7919 + 3) >>> 0 || 1);
        const q = window.GENS[key]();
        /* 場面は 問題文の 数字を # に した 形、向き・種類は ラベルで 見分ける */
        o[key].add(key === 'hayasa_kansan' ? q.plainQ.replace(/[\d.]+/g, '#')
                                           : q.tag + '|' + q.label + '|' + q.u);
      }
      o[key] = o[key].size;
    }
    return o;
  });
  /* こみぐあい: 4場面 × 2方向（花だんは 1方向）→ ラベル×単位で 5種類以上 */
  expect(seen.komi).toBeGreaterThanOrEqual(5);
  expect(seen.jinko).toBe(2);                   /* ふつう／四捨五入 */
  expect(seen.hayasa_kansan).toBe(6);           /* 6とおりの 変え方 */
  expect(seen.iroiro_tanni).toBeGreaterThanOrEqual(5);
  expect(seen.hayasa_motomeru).toBe(3);         /* 時速・分速・秒速 */
});

test('正解の 選択肢の かたよりが 無い', async ({ page }) => {
  await page.goto(URL);
  const pos = await page.evaluate(() => {
    const out = {};
    for (const key of Object.keys(window.GENS)) {
      const c = [0, 0, 0, 0];
      for (let i = 0; i < 600; i++) {
        window.rngSeed((i * 40503 + 99) >>> 0 || 1);
        c[window.GENS[key]().correct]++;
      }
      out[key] = c;
    }
    return out;
  });
  for (const key of Object.keys(pos)) {
    const n = HIKAKU.indexOf(key) >= 0 ? 3 : 4;
    for (let k = 0; k < n; k++) expect(pos[key][k], key + ' ' + pos[key].join(',')).toBeGreaterThan(600 / n * 0.5);
  }
});

test('解説の 数直線の 文字が はみ出さず、重ならない', async ({ page }) => {
  await page.goto(URL);
  const out = await page.evaluate(() => {
    const host = document.createElement('div');
    host.className = 'expl';
    host.style.cssText = 'position:absolute;left:0;top:0;width:360px';
    document.body.appendChild(host);
    const errs = [], laps = [];
    let checked = 0;
    for (const key of Object.keys(window.GENS)) {
      for (let i = 0; i < 80; i++) {
        const seed = (i * 2246822519 + 7) >>> 0 || 1;
        window.rngSeed(seed);
        const q = window.GENS[key]();
        host.innerHTML = q.expl;
        const svg = host.querySelector('svg');
        if (!svg) continue;
        svg.style.width = '330px';
        const R = svg.getBoundingClientRect();
        const boxes = [];
        svg.querySelectorAll('text').forEach(t => {
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
          const sm = Math.min(A.width * A.height, B.width * B.height);
          if (w * h > sm * 0.2) laps.push(key + ' seed=' + seed + ' "' + boxes[a].s + '" × "' + boxes[b].s + '"');
        }
      }
    }
    host.remove();
    return { errs: errs.slice(0, 15), total: errs.length, checked, laps: laps.slice(0, 20), lapTotal: laps.length };
  });
  expect(out.checked).toBeGreaterThan(5000);
  expect(out.errs, out.errs.join('\n')).toEqual([]);
  expect(out.laps, out.lapTotal + ' overlaps\n' + out.laps.join('\n')).toEqual([]);
});

test('解説の 式（a ÷ b ＝ c、a × b ＝ c、a ＋ b ＝ c、a − b ＝ c）が ぜんぶ 正しい', async ({ page }) => {
  await page.goto(URL);
  const res = await page.evaluate(() => {
    const errs = [];
    let checked = 0;
    const N = s => Number(s.replace(/,/g, ''));
    for (const key of Object.keys(window.GENS)) {
      for (let i = 0; i < 300; i++) {
        const seed = (i * 1103515245 + 4321) >>> 0 || 1;
        window.rngSeed(seed);
        const q = window.GENS[key]();
        const box = document.createElement('div');
        box.innerHTML = q.expl;
        box.querySelectorAll('.fig, .cap').forEach(e => e.remove());
        /* 数の 前後に 単位や 文字が あっても、「数 演算 数 ＝ 数」の ならびだけ 拾う */
        /* 「70 × 8 ＋ 75 × 8 ＝ 1160」の 後ろ半分だけを 拾わない ように、前が 演算子の 数は とばす。
           「1955.8…」の ような 四捨五入する 前の 値（…つき）も とばす */
        const tx = box.textContent.replace(/\s+/g, ' ');
        const re = /(?<![\d.,]|[÷×＋−] )([\d,]+(?:\.\d+)?) ([÷×＋−]) ([\d,]+(?:\.\d+)?) ＝ (?:[^\d\s]+ )?([\d,]+(?:\.\d+)?)(?!\d*(?:\.\d|…))/g;
        let m;
        while ((m = re.exec(tx))) {
          const a = N(m[1]), b = N(m[3]), c = N(m[4]);
          const v = m[2] === '÷' ? a / b : m[2] === '×' ? a * b : m[2] === '＋' ? a + b : a - b;
          checked++;
          if (Math.abs(v - c) > 1e-9) errs.push(key + ' seed=' + seed + ': ' + m[0]);
        }
        /* 0.666… を 0.67 と 丸めた ような 数は 出さない（3けた以上の 小数も 出さない） */
        if (/\d\.\d{3,}/.test(tx)) errs.push(key + ' seed=' + seed + ': long decimal ' + tx.match(/[\d.]*\d\.\d{3,}/)[0]);
      }
    }
    return { errs: errs.slice(0, 20), total: errs.length, checked };
  });
  expect(res.checked).toBeGreaterThan(2000);
  expect(res.errs, res.total + '\n' + res.errs.join('\n')).toEqual([]);
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

test('すべての モードが 出題→採点→次の問題 まで通る', async ({ page }) => {
  await page.goto(URL);
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  const modes = await page.locator('.m-card').evaluateAll(els => els.map(e => e.dataset.mode));
  expect(modes).toEqual(['komi', 'jinko', 'iroiro', 'hayasa', 'kansan', 'mix']);
  for (const mode of modes) {
    await page.click('.m-card[data-mode="' + mode + '"]');
    for (let i = 0; i < 5; i++) {
      const n = await page.evaluate(() => window.cur.options.length);
      await expect(page.locator('.optBtn')).toHaveCount(n);
      const k = await page.evaluate(() => window.curKey);
      expect(await page.evaluate(m => window.MODE_KEYS[m], mode)).toContain(k);
      const ci = await page.evaluate(() => window.cur.correct);
      const pick = i === 4 ? (ci + 1) % n : ci;
      await page.click('.optBtn[data-idx="' + pick + '"]');
      await page.click('#checkBtn');
      await expect(page.locator('#feedback')).toHaveClass(/show/);
      await expect(page.locator('.optBtn[data-divp-mark="' + (i === 4 ? 'answer' : 'correct') + '"]')).toHaveCount(1);
      await page.click('#nextBtn');
    }
    if (mode !== 'mix') await expect(page.locator('#qTag')).toBeVisible();
    await page.click('#homeBtn');
  }
  expect(errors).toEqual([]);
});

test('誤答の 記録（種）から ?retry=1 で 同じ問題が 出る', async ({ page }) => {
  await page.goto(URL);
  await page.evaluate(() => {
    window._sent = [];
    window.Divp.answer = (ok, p) => { window._sent.push({ ok, p }); };
  });
  await page.click('.m-card[data-mode="hayasa"]');
  const shown = await page.evaluate(() => ({ text: window.cur.plainQ, ans: window.cur.ansText, key: window.curKey }));
  const n = await page.evaluate(() => window.cur.options.length);
  const ci = await page.evaluate(() => window.cur.correct);
  await page.click('.optBtn[data-idx="' + ((ci + 1) % n) + '"]');
  await page.click('#checkBtn');
  const sent = await page.evaluate(() => window._sent);
  expect(sent).toHaveLength(1);
  const p = sent[0].p;
  expect(sent[0].ok).toBe(false);
  expect(p.question_key).toBe(shown.key);
  expect(Object.keys(p.question_params).sort()).toEqual(['m', 's']);
  expect(p.question_text).toBe(shown.text);
  expect(p.correct_answer).toBe(shown.ans);
  expect(p.wrong_answer).not.toBe(p.correct_answer);

  await page.click('#homeBtn');
  await page.evaluate((params) => {
    window.Divp.getRetries = () => Promise.resolve([{ question_params: params }]);
    window.initRetry();
  }, p.question_params);
  await expect(page.locator('#qTag')).toContainText('解き直し 1 / 1');
  const again = await page.evaluate(() => ({ text: window.cur.plainQ, ans: window.cur.ansText, key: window.curKey }));
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
  console.log('print heights', JSON.stringify(res));
  for (const mode of Object.keys(res)) {
    expect(res[mode].q, mode + ' 問題 ' + JSON.stringify(res)).toBeLessThanOrEqual(1040);
    expect(res[mode].a, mode + ' 答え ' + JSON.stringify(res)).toBeLessThanOrEqual(1040);
  }
});
