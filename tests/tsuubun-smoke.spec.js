/* 通分マスター（math_es5_tsuubun_kagen）の回帰テスト
   ・11モードすべてが 出題→入力→採点→次の問題 まで通ること
   ・画面の式から 計算した 正しい答えを 入れると「せいかい」になること
     （＝ 判定（既約・帯分数の受け入れ）と 生成関数の 答えが 一致していること）
   ・印刷シートが 表12問＋裏こたえ の2ページで 作られること              */
const { test, expect } = require('@playwright/test');
const URL = '/learning/math/math_es5_tsuubun_kagen.html';

/* 画面の式（.expr）を読んで 分数として 計算する */
async function readExpr(page) {
  return await page.evaluate(() => {
    const g = (a, b) => { a = Math.abs(a); b = Math.abs(b); while (b) { const t = a % b; a = b; b = t; } return a || 1; };
    const red = f => { const k = g(f.n, f.d); return { n: f.n / k, d: f.d / k }; };
    const root = document.querySelector('#qText .expr');
    // ①通分モードは カードに 式を 出さないので、円の絵の ラベル（もとの分数）から 読む
    if (!root) {
      const ts = [...document.querySelectorAll('#pieWrap .pieBox .pieLab')].map(l => {
        const f = l.querySelector('.fr');   // 先頭が もとの分数（後ろの .after は 通分後）
        return red({ n: +f.querySelector('.fn').textContent, d: +f.querySelector('.fd').textContent });
      });
      return { terms: ts, ops: [], ans: ts[0] };
    }
    const terms = [], ops = [];
    for (const el of root.children) {
      if (el.classList.contains('op')) { const t = el.textContent.trim(); if (t === '＋') ops.push('+'); else if (t === '－') ops.push('-'); continue; }
      if (el.classList.contains('qbox')) continue;
      if (el.classList.contains('fr')) {
        terms.push(red({ n: +el.querySelector('.fn').textContent, d: +el.querySelector('.fd').textContent }));
      } else if (el.classList.contains('mx')) {
        const w = +el.querySelector('.wn').textContent;
        const n = +el.querySelector('.fn').textContent, d = +el.querySelector('.fd').textContent;
        terms.push(red({ n: w * d + n, d }));
      } else if (el.classList.contains('wn')) {
        const s = el.textContent.trim();
        const dec = (s.split('.')[1] || '').length, D = Math.pow(10, dec);
        terms.push(red({ n: Math.round(parseFloat(s) * D), d: D }));
      }
    }
    let v = terms[0];
    for (let i = 1; i < terms.length; i++) {
      const t = terms[i];
      v = red(ops[i - 1] === '+' ? { n: v.n * t.d + t.n * v.d, d: v.d * t.d } : { n: v.n * t.d - t.n * v.d, d: v.d * t.d });
    }
    return { terms, ops, ans: v };
  });
}
async function typeInto(page, slot, num) {
  await page.click(`.nbox[data-slot="${slot}"]`);
  for (const ch of String(num)) await page.click(`.key[data-k="${ch}"]`);
}

test('12モードすべて 出題→入力→採点→次の問題 が通る', async ({ page }) => {
  const errs = [];
  page.on('pageerror', e => errs.push(String(e)));
  page.on('console', m => { if (m.type() === 'error') errs.push('console:' + m.text()); });
  await page.goto(URL);
  const modes = await page.$$eval('.m-card', els => els.map(e => e.dataset.mode));
  expect(modes.length).toBe(12);
  for (const m of modes) {
    await page.click(`.m-card[data-mode="${m}"]`);
    // ①だけは カードに 式を 出さない（下のアニメーションを 初期表示に 入れるため）＝円の絵で 出題を 確認する
    const shown = m === 'tsuubun' ? page.locator('#pieArea .pieWrap') : page.locator('#qText .expr');
    await expect(shown).toBeVisible();
    const slots = await page.$$eval('.nbox', els => els.map(e => e.dataset.slot));
    expect(slots.length).toBeGreaterThan(1);
    for (const s of slots) await typeInto(page, s, 1);
    // ②大小くらべは マスが メモらんなので、不等号を えらばないと 採点できない
    if (m === 'compare') await page.click('.signBtn[data-sign=">"]');
    await page.click('#checkBtn');
    await expect(page.locator('#feedback')).toHaveClass(/show/);
    await expect(page.locator('#expl')).not.toBeEmpty();
    await page.click('#nextBtn');
    await expect(shown).toBeVisible();
    await page.click('#homeBtn');
  }
  expect(errs).toEqual([]);
});

/* 通分した途中式（①）と こたえ（②）の両方を 正しく入れる */
async function answerCorrectly(page) {
  const { terms, ans } = await readExpr(page);
  const g = (a, b) => { a = Math.abs(a); b = Math.abs(b); while (b) { const t = a % b; a = b; b = t; } return a || 1; };
  const L = terms.reduce((a, t) => a / g(a, t.d) * t.d, 1);
  for (let i = 0; i < terms.length; i++) {
    await typeInto(page, `m${i}n`, terms[i].n * (L / terms[i].d));
    await typeInto(page, `m${i}d`, L);
  }
  const w = Math.floor(ans.n / ans.d), n = ans.n - w * ans.d;   // こたえは帯分数で入れる
  if (w) await typeInto(page, 'w', w);
  if (n) { await typeInto(page, 'n', n); await typeInto(page, 'd', ans.d); }
  return { L, ans };
}

test('計算10モード: 途中式とこたえを正しく入れると せいかい になる（各3問）', async ({ page }) => {
  await page.goto(URL);
  const modes = ['add2', 'add3', 'add_tai', 'add_shou', 'sub2', 'sub3', 'sub_tai', 'sub_shou', 'mix_bun', 'mix_all'];
  for (const m of modes) {
    await page.click(`.m-card[data-mode="${m}"]`);
    for (let i = 0; i < 3; i++) {
      await answerCorrectly(page);
      await page.click('#checkBtn');
      await expect(page.locator('#fbMsg'), `${m} #${i + 1}`).toHaveClass(/good/);
      await page.click('#nextBtn');
    }
    await page.click('#homeBtn');
  }
});

test('途中式のマスは 空のままだと 答えあわせできない', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="add2"]');
  const { ans } = await readExpr(page);
  await typeInto(page, 'n', ans.n);   // こたえだけ 入れる
  await typeInto(page, 'd', ans.d);
  await page.click('#checkBtn');
  await expect(page.locator('#toast')).toContainText('通分した 式');
  await expect(page.locator('#feedback')).not.toHaveClass(/show/);
});

test('途中式をまちがえると 答えが合っていても 不正解になる', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="add2"]');
  const { terms, ans } = await readExpr(page);
  // 分母だけ そろえて 分子を 直し忘れた形（よくあるまちがい）
  const g = (a, b) => { while (b) { const t = a % b; a = b; b = t; } return a; };
  const L = terms[0].d / g(terms[0].d, terms[1].d) * terms[1].d;
  for (let i = 0; i < 2; i++) { await typeInto(page, `m${i}n`, terms[i].n); await typeInto(page, `m${i}d`, L); }
  const w = Math.floor(ans.n / ans.d), n = ans.n - w * ans.d;
  if (w) await typeInto(page, 'w', w);
  if (n) { await typeInto(page, 'n', n); await typeInto(page, 'd', ans.d); }
  await page.click('#checkBtn');
  await expect(page.locator('#fbMsg')).toHaveClass(/bad/);
  await expect(page.locator('#fbMsg')).toContainText('①');
});

test('通分モード: 最小公倍数で通分すると せいかい / 円の絵は採点後に線が増える', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="tsuubun"]');
  await expect(page.locator('#pieWrap .pie')).toHaveCount(2);
  await expect(page.locator('#pieWrap')).not.toHaveClass(/on/);   // 先に答えは見せない
  // スタートを押すまでは 何も動かない（アニメーションは 出しっぱなしにしない）
  await expect(page.locator('#pieStart')).toBeVisible();
  await expect(page.locator('#addWrap')).toHaveCount(0);
  await expect(page.locator('#pieNote')).toHaveCount(0);          // まとめの ひとことは まだ出さない
  for (let i = 0; i < 4; i++) {
    const { terms } = await readExpr(page);
    const g = (a, b) => { while (b) { const t = a % b; a = b; b = t; } return a; };
    const L = terms[0].d / g(terms[0].d, terms[1].d) * terms[1].d;
    await typeInto(page, 'n1', terms[0].n * (L / terms[0].d));
    await typeInto(page, 'd1', L);
    await typeInto(page, 'n2', terms[1].n * (L / terms[1].d));
    await typeInto(page, 'd2', L);
    await page.click('#checkBtn');
    await expect(page.locator('#fbMsg')).toHaveClass(/good/);
    await expect(page.locator('#pieWrap')).toHaveClass(/on/);      // 採点後に線が増える
    // 「たしてみよう」の2枚も採点後に出て、右（通分すれば たせる）は分子をたした形になる
    await expect(page.locator('#addWrap .addBox')).toHaveCount(2);
    const boxes = await page.$$eval('#addWrap .addBox', els => els.map(e => ({
      ok: e.classList.contains('ok'),
      grid: e.querySelectorAll('.pl.base').length,
      frs: [...e.querySelectorAll('.addExpr .fr')].map(f => f.querySelector('.fn').textContent + '/' + f.querySelector('.fd').textContent)
    })));
    const ng = boxes[0], okBox = boxes[1];
    expect(ng.ok).toBe(false);
    expect(okBox.ok).toBe(true);
    // 左は「そろっていない」＝めもり数が通分後の分母より少ない / 右はぴったり通分後の分母
    expect(okBox.grid).toBe(L);
    expect(ng.grid).toBeLessThan(L);
    // 右の式は a/L ＋ b/L ＝ (a+b)/L
    const [p1, p2, p3] = okBox.frs.map(t => t.split('/').map(Number));
    expect(p1[1]).toBe(L); expect(p2[1]).toBe(L); expect(p3[1]).toBe(L);
    expect(p3[0]).toBe(p1[0] + p2[0]);
    await page.click('#nextBtn');
  }
});

test('こたえの約分もれは 専用のメッセージで 不正解になる', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="add2"]');
  const { terms, ans } = await readExpr(page);
  const g = (a, b) => { while (b) { const t = a % b; a = b; b = t; } return a; };
  const L = terms[0].d / g(terms[0].d, terms[1].d) * terms[1].d;
  for (let i = 0; i < 2; i++) {
    await typeInto(page, `m${i}n`, terms[i].n * (L / terms[i].d));
    await typeInto(page, `m${i}d`, L);
  }
  // 値は合っているが 約分できる形（分子・分母を2倍）で入れる
  await typeInto(page, 'n', ans.n * 2);
  await typeInto(page, 'd', ans.d * 2);
  await page.click('#checkBtn');
  await expect(page.locator('#fbMsg')).toHaveClass(/bad/);
  await expect(page.locator('#fbMsg')).toContainText('約分');
});

/* ②大小くらべ: こたえるのは 不等号だけ。メモらんは 空でも 採点できる。
   くらべる 2つは 分数どうし の ことも、整数と 仮分数 の ことも ある */
async function readCompare(page) {
  return await page.$$eval('.cmpRow > *', els => {
    const out = [];
    for (const e of els) {
      if (e.classList.contains('fr')) {
        out.push({ n: +e.querySelector('.fn').textContent, d: +e.querySelector('.fd').textContent });
      } else if (e.classList.contains('wn')) {
        out.push({ n: +e.textContent, d: 1 });     // 整数
      }
    }
    return out;
  });
}

test('大小くらべ: 正しい不等号を えらぶと せいかい（各8問）', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="compare"]');
  for (let i = 0; i < 8; i++) {
    const [a, b] = await readCompare(page);
    expect(a && b, `compare #${i + 1} の 2つが 読めた`).toBeTruthy();
    const want = a.n * b.d > b.n * a.d ? '>' : '<';
    await page.click(`.signBtn[data-sign="${want}"]`);
    await page.click('#checkBtn');
    await expect(page.locator('#fbMsg'), `compare #${i + 1}`).toHaveClass(/good/);
    await expect(page.locator('.signBtn.ok')).toHaveCount(1);
    await page.click('#nextBtn');
  }
});

/* 出題そのものの 検証は 種を 固定して まとめて 回す（画面の 乱数だと まぐれで 通る）*/
test('大小くらべ: 300通りの種で 不等号が 正しく、整数との くらべも 出る', async ({ page }) => {
  await page.goto(URL);
  const st = await page.evaluate(() => {
    const r = { n: 0, wrong: 0, withInt: 0, equal: 0, gt: 0, intLeft: 0, badPie: 0, noPie: 0, samples: [] };
    for (let i = 1; i <= 300; i++) {
      rngSeed((i * 2654435761) >>> 0);
      const q = gen_compare();
      const c = q.cmp, va = c.a.f.n / c.a.f.d, vb = c.b.f.n / c.b.f.d;
      r.n++;
      if ((va > vb ? '>' : '<') !== c.sign) r.wrong++;
      if (va === vb) r.equal++;
      if (c.sign === '>') r.gt++;
      if (c.a.f.d === 1 || c.b.f.d === 1) {
        r.withInt++;
        if (c.a.f.d === 1) r.intLeft++;
        if (q.pies) r.badPie++;              // 整数・仮分数で 円の絵を 出すと うそに なる
      } else if (!q.pies) {
        r.noPie++;                           // 分数どうしは 採点後に 円の絵を 出す
      }
      if (r.samples.length < 6 && (c.a.f.d === 1 || c.b.f.d === 1)) r.samples.push(q.plainQ + ' -> ' + q.ansText);
    }
    return r;
  });
  console.log('compare stats ' + JSON.stringify(st));
  expect(st.wrong).toBe(0);            // 不等号の 向きが 実際の 大小と 合っている
  expect(st.equal).toBe(0);            // イコールに なる 問題は 出さない
  expect(st.badPie).toBe(0);           // 整数の 回は 円の絵を 出さない
  expect(st.noPie).toBe(0);            // 分数どうしの 回は 円の絵を 出す
  expect(st.withInt).toBeGreaterThan(60);   // 整数との くらべが だいたい 3回に1回
  expect(st.withInt).toBeLessThan(140);
  expect(st.intLeft).toBeGreaterThan(20);   // 整数は 左にも 右にも 出る
  expect(st.withInt - st.intLeft).toBeGreaterThan(20);
  expect(st.gt).toBeGreaterThan(90);        // ＞と＜が かたよらない
  expect(st.gt).toBeLessThan(210);
});

test('大小くらべ: 不等号を えらばないと 答えあわせできない / メモらんは 空でも 採点される', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="compare"]');
  await page.click('#checkBtn');
  await expect(page.locator('#toast')).toContainText('えらんでね');
  await expect(page.locator('#feedback')).not.toHaveClass(/show/);
  // メモらん（c1n…）は 空のまま でも 不等号だけで 採点まで 進む
  const [a, b] = await readCompare(page);
  const wrong = a.n * b.d > b.n * a.d ? '<' : '>';
  await page.click(`.signBtn[data-sign="${wrong}"]`);
  await page.click('#checkBtn');
  await expect(page.locator('#fbMsg')).toHaveClass(/bad/);
  await expect(page.locator('.signBtn.ng')).toHaveCount(1);   // えらんだ まちがい＝朱
  await expect(page.locator('.signBtn.ansmark')).toHaveCount(1); // えらばなかった 正解＝緑
});

test('印刷シートが 表12問＋裏こたえ で作られる', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="add2"]');
  await page.evaluate(() => { window.print = () => {}; });
  await page.click('#printBtn');
  await expect(page.locator('#printArea .sheet')).toHaveCount(2);
  await expect(page.locator('#printArea .sheet').first().locator('.p-item')).toHaveCount(12);
  await expect(page.locator('#printArea .sheet.ans .p-item')).toHaveCount(12);
  await expect(page.locator('#printArea .sheet').first().locator('.p-blank')).toHaveCount(12);
});

/* 帯分数の途中式は「帯分数のまま」でも「仮分数」でも正解にする（両方の書き方を試す） */
for (const style of ['帯分数のまま', '仮分数']) {
  test(`帯分数モード: 途中式を ${style} で書いても せいかい になる（各3問）`, async ({ page }) => {
    await page.goto(URL);
    for (const m of ['add_tai', 'sub_tai']) {
      await page.click(`.m-card[data-mode="${m}"]`);
      for (let i = 0; i < 3; i++) {
        const { terms, ans } = await readExpr(page);
        const g = (a, b) => { a = Math.abs(a); b = Math.abs(b); while (b) { const t = a % b; a = b; b = t; } return a || 1; };
        const L = terms.reduce((a, t) => a / g(a, t.d) * t.d, 1);
        for (let k = 0; k < terms.length; k++) {
          const num = terms[k].n * (L / terms[k].d);
          const hasW = await page.$(`.nbox[data-slot="m${k}w"]`);
          if (style === '帯分数のまま' && hasW) {
            const w = Math.floor(num / L);
            if (w) await typeInto(page, `m${k}w`, w);
            await typeInto(page, `m${k}n`, num - w * L);
          } else {
            await typeInto(page, `m${k}n`, num);          // 仮分数のまま（整数マスは空）
          }
          await typeInto(page, `m${k}d`, L);
        }
        const w = Math.floor(ans.n / ans.d), n = ans.n - w * ans.d;
        if (w) await typeInto(page, 'w', w);
        if (n) { await typeInto(page, 'n', n); await typeInto(page, 'd', ans.d); }
        await page.click('#checkBtn');
        await expect(page.locator('#fbMsg'), `${m} ${style} #${i + 1}`).toHaveClass(/good/);
        await page.click('#nextBtn');
      }
      await page.click('#homeBtn');
    }
  });
}

/* ①は「参考書」あつかい: 入力する前に しくみを 見られる / 学習記録は飛ばさない */
test('①: スタートを押すと、しくみが さいごまで ひとつづきで 流れる', async ({ page }) => {
  await page.goto(URL);
  await page.click('.m-card[data-mode="tsuubun"]');
  await expect(page.locator('#pieStart')).toBeVisible();
  await expect(page.locator('#addWrap')).toHaveCount(0);           // 押すまでは 何も出さない
  await expect(page.locator('#pieWrap')).not.toHaveClass(/on/);

  await page.click('#pieStart');
  await expect(page.locator('#pieStart')).toHaveCount(0);          // ボタンは消える
  await expect(page.locator('#addWrap .addBox')).toHaveCount(2);   // 「たせない」と「たせる」
  await expect(page.locator('#pieNote')).toHaveCount(1);           // まとめの ひとことも出る
  // 途中で押すボタンは無く、じゅんばんに ひとりでに 進む
  const ng = page.locator('#addWrap .addBox.ng'), ok = page.locator('#addWrap .addBox.ok');
  await expect(ng).toHaveClass(/on/);                              // ①そのままでは たせない
  await expect(ok).not.toHaveClass(/\bon\b/);                      // ③はまだ
  await expect(page.locator('#pieWrap')).toHaveClass(/on/);        // ②線が増える
  await expect(ok).toHaveClass(/\bon\b/);                          // ③通分すれば たせる
  await expect(page.locator('#addLead')).toHaveClass(/on/);        // ④書いてみよう

  // 見たあとでも ふつうに答えられる（採点は動く）
  const { terms } = await readExpr(page);
  const g = (a, b) => { while (b) { const t = a % b; a = b; b = t; } return a; };
  const L = terms[0].d / g(terms[0].d, terms[1].d) * terms[1].d;
  await typeInto(page, 'n1', terms[0].n * (L / terms[0].d));
  await typeInto(page, 'd1', L);
  await typeInto(page, 'n2', terms[1].n * (L / terms[1].d));
  await typeInto(page, 'd2', L);
  await page.click('#checkBtn');
  await expect(page.locator('#fbMsg')).toHaveClass(/good/);
  // 次の問題では また スタート待ちから 始まる
  await page.click('#nextBtn');
  await expect(page.locator('#pieStart')).toBeVisible();
  await expect(page.locator('#addWrap')).toHaveCount(0);
});

test('①は学習記録を飛ばさない / 計算モードは飛ばす', async ({ page }) => {
  await page.goto(URL);
  await page.evaluate(() => {
    window.__answers = [];
    window.Divp = window.Divp || {};
    window.Divp.answer = (ok, data) => { window.__answers.push(data.question_key); };
  });
  // ① を2問こなす（正解でも誤答でも記録は飛ばない）
  await page.click('.m-card[data-mode="tsuubun"]');
  for (let i = 0; i < 2; i++) {
    for (const s of ['n1', 'd1', 'n2', 'd2']) await typeInto(page, s, 1);
    await page.click('#checkBtn');
    await page.click('#nextBtn');
  }
  expect(await page.evaluate(() => window.__answers)).toEqual([]);

  // 計算モードは これまでどおり飛ぶ
  await page.click('#homeBtn');
  await page.click('.m-card[data-mode="add2"]');
  const slots = await page.$$eval('.nbox', els => els.map(e => e.dataset.slot));
  for (const s of slots) await typeInto(page, s, 1);
  await page.click('#checkBtn');
  expect(await page.evaluate(() => window.__answers)).toEqual(['add2']);
});
