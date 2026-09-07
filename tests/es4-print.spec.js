// @ts-check
// 小4算数まるごとパック（math_es4_all）の回帰テスト。
// 見張るのは「印刷」と「わり算の筆算の入力」の2点。どちらも壊れても
// エラーが出ずに紙・画面がおかしくなるだけなので、数で押さえておく。
//
// ① 練習プリントは別ウィンドウに組む。問題6問=1面、こたえ1面が セットで、
//    12問なら 問題→こたえ→問題→こたえ（両面印刷で表=問題・裏=こたえになる順）。
// ② その1面が A4（たて・上下余白11mm）に収まること。図の max-height を
//    ゆるめたり 1面の問題数を増やすと あふれて 空きページが出る（CLAUDE.md/印刷の教訓）。
// ③ わり算の筆算は 商だけでなく とちゅうの計算（かける・ひく・おろす・あまり）まで
//    1マスずつ 書かせる。入力は 商 → 積 → 差 → 次の商 … の順で だんを またいで進み、
//    たし算の筆算（一の位から左へ）とは 別の エンジン（grid.order/need）で 動く。
const { test, expect } = require('@playwright/test');

const URL = '/learning/math/math_es4_all.html';
// A4たて 297mm − 上下余白11mm×2 を 96dpi の px に直した「1面に使える高さ」
const USABLE_H = Math.round(1122.5 - 2 * (11 * 96 / 25.4));
const PRINT_W = Math.round((210 - 20) * 96 / 25.4);   // 左右余白10mm を除いた印刷幅

/** プリントのポップアップを開いて返す（印刷ダイアログは出さない） */
async function openPrint(page, context, { unit, n, withAns = true }) {
  await context.addInitScript(() => { window.print = () => {}; });
  await page.goto(URL);
  await page.selectOption('#psel', unit);
  await page.locator('#pnum .pchip', { hasText: `${n}問` }).click();
  if (!withAns) await page.uncheck('#pwa');
  const [pop] = await Promise.all([
    context.waitForEvent('page'),
    page.click('#pgo'),
  ]);
  await pop.waitForSelector('.psheet');
  return pop;
}

test('メニューに16単元＋ミックスが並び、プリントの単元えらびも同じ数だけある', async ({ page }) => {
  await page.goto(URL);
  await expect(page.locator('#grid .chip')).toHaveCount(17);      // 16単元 + ぜんぶミックス
  await expect(page.locator('#psel option')).toHaveCount(17);
  await expect(page.locator('#tot')).toHaveText('0');
});

test('練習プリント6問: 問題1面＋こたえ1面 で作られる', async ({ page, context }) => {
  const pop = await openPrint(page, context, { unit: 'menseki', n: 6 });
  await expect(pop.locator('.psheet')).toHaveCount(2);
  await expect(pop.locator('.psheet').first().locator('.pit')).toHaveCount(6);
  await expect(pop.locator('.psheet').nth(1).locator('.pa')).toHaveCount(6);
  await expect(pop.locator('.psheet').first()).toContainText('面積');
  // 単元プリントは 単元名が 見出しに1回出るだけ（ミックスのときだけ 各問に付く）
  await expect(pop.locator('.pit .put')).toHaveCount(0);
  const body = await pop.locator('body').innerText();
  expect(body).not.toMatch(/undefined|NaN/);
  await pop.close();
});

test('練習プリント12問: 問題→こたえ→問題→こたえ の順（両面印刷で表裏になる）', async ({ page, context }) => {
  const pop = await openPrint(page, context, { unit: 'mix', n: 12 });
  await expect(pop.locator('.psheet')).toHaveCount(4);
  const kinds = await pop.locator('.psheet .plb').allInnerTexts();
  expect(kinds.map((s) => /こたえ/.test(s))).toEqual([false, true, false, true]);
  // ミックスは 1問ごとに どの単元かを 出す
  await expect(pop.locator('.psheet').first().locator('.pit .put')).toHaveCount(6);
  await pop.close();
});

test('こたえのページを外すと 問題の面だけになる', async ({ page, context }) => {
  const pop = await openPrint(page, context, { unit: 'kaku', n: 6, withAns: false });
  await expect(pop.locator('.psheet')).toHaveCount(1);
  await expect(pop.locator('.plb.gy')).toHaveCount(0);
  await pop.close();
});

test('出題中の🖨ボタンは その単元のプリントを作る', async ({ page, context }) => {
  await context.addInitScript(() => { window.print = () => {}; });
  await page.goto(URL);
  await page.locator('.chip', { hasText: '折れ線グラフと表' }).click();
  const [pop] = await Promise.all([
    context.waitForEvent('page'),
    page.click('#pnow'),
  ]);
  await pop.waitForSelector('.psheet');
  await expect(pop.locator('.psheet').first().locator('.plb')).toContainText('折れ線グラフと表');
  await pop.close();
});

// 図の大きさや1面の問題数を変えたときに あふれを 見つけるための実測。
// 単元ごとに図の背が違うので、背の高いものを ひととおり 見る。
test('どの単元のプリントも1面がA4（たて）に収まる', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name === 'iphone', '紙のサイズはデスクトップ側で見れば足りる');
  for (const unit of ['wari1', 'kaku', 'graph', 'hako', 'bai', 'bunsuu', 'mix']) {
    const pop = await openPrint(page, context, { unit, n: 6 });
    await pop.setViewportSize({ width: PRINT_W, height: USABLE_H });
    const heights = await pop.evaluate(() =>
      Array.from(document.querySelectorAll('.psheet')).map((s) => s.getBoundingClientRect().height));
    for (const [i, h] of heights.entries()) {
      expect(Math.round(h), `${unit} の ${i + 1}面目`).toBeLessThanOrEqual(USABLE_H);
    }
    await pop.close();
  }
});

/* 単元チップは tapChip の きらめき演出ぶん（210ms）おくれて 出題が 始まる。
   押した直後に 画面を 読むと 前の画面のままで、原因の わかりにくい
   「rows[2] が undefined」で こける（実際に こけた） */
async function startUnit(page, name) {
  await page.goto(URL);
  await page.locator('.chip', { hasText: name }).click();
  await page.waitForSelector('#quiz:not([hidden])');
  await page.waitForFunction(() => {
    const f = document.getElementById('fig');
    return document.getElementById('ansarea').children.length > 0 && f !== null;
  });
}

/* わり算の筆算は 商だけでなく「かける・ひく・おろす・あまり」まで 1マスずつ 書かせる
   （表示・入力順は わり算のひっ算マスター math_es4_warizan_hissan と同じ形）。
   テスト側でも 筆算を もう一度 組み立てて、
   ・入力の じゅんばん（商 → 積 → 差 → 次の商 …）
   ・どのマスに 何が 入るか
   ・商が 立たない位に × を 書かせること
   を 画面の フォーカス移動と つき合わせる。 */
function replayHissan(dividend, divisor) {
  const digits = String(dividend).split('').map(Number);
  const n = digits.length;
  const need = {};                 // "だん,列" -> 入る文字
  const order = [];                // 入力の じゅんばん
  const steps = [];
  let rem = 0, started = false;
  for (let i = 0; i < n; i++) {
    const cur = rem * 10 + digits[i];
    const q = Math.floor(cur / divisor);
    if (q > 0) started = true;
    if (started) steps.push({ i, q, prod: q * divisor, rem: cur - q * divisor });
    rem = cur - q * divisor;
  }
  const firstCol = steps.length ? steps[0].i : n;
  const put = (r, right, v) => {
    const s = String(v), cols = [];
    for (let k = 0; k < s.length; k++) {
      const c = right - (s.length - 1) + k;
      need[`${r},${c}`] = s[k];
      cols.push(`${r},${c}`);
    }
    return cols;
  };
  for (let c = 0; c < firstCol; c++) { need[`0,${c}`] = '×'; order.push(`0,${c}`); }
  steps.forEach((s, k) => {
    need[`0,${s.i}`] = String(s.q);
    order.push(`0,${s.i}`);
    order.push(...put(2 + k * 2, s.i, s.prod));                 // かけた数
    if (k < steps.length - 1) {
      if (s.rem !== 0) order.push(...put(3 + k * 2, s.i, s.rem)); // ひいた数（0のときは書かない）
    } else {
      order.push(...put(3 + k * 2, n - 1, rem));                 // あまり
    }
  });
  return { need, order };
}

test('わり算の筆算: 商→かける→ひく の順に1マスずつ書いて正解になる', async ({ page }) => {
  let info = null;
  for (let t = 0; t < 12 && !info; t++) {
    await startUnit(page, 'わり算の筆算①');
    info = await page.evaluate(() => {
      const wari = document.querySelector('#fig .wari');
      if (!wari) return null;                      // 筆算以外の型が出たら引き直す
      const rows = wari.querySelectorAll('.wbox .wrow');
      return {
        divisor: Number(wari.querySelector('.wdrow').textContent.trim()),
        dividend: Number(Array.from(rows[0].querySelectorAll('.c'))
          .map((c) => c.textContent.trim()).join('')),
        cells: wari.querySelectorAll('.ic').length,
      };
    });
  }
  expect(info, 'わり算の筆算の問題が出なかった').not.toBeNull();
  const { need, order } = replayHissan(info.dividend, info.divisor);
  expect(info.cells, '入力マスの数（とちゅうの計算ぶんも ある）').toBe(order.length);

  // ×キーは「×入らない位」と2行に なっているので aria-label で つかむ
  const tap = async (d) => (d === '×'
    ? page.locator('#padarea .key[aria-label*="×"]')
    : page.locator('#padarea .key').filter({ hasText: new RegExp(`^${d}$`) })).click();
  for (const key of order) {
    const foc = await page.evaluate(() => {
      const el = document.querySelector('#fig .ic.foc');
      return el && `${el.dataset.r},${el.dataset.c}`;
    });
    expect(foc, '入力の じゅんばん').toBe(key);
    await tap(need[key]);
  }
  await page.click('#go');
  await expect(page.locator('#fbt')).toHaveText('せいかい！');
  await expect(page.locator('#fbe')).toContainText(`${info.dividend}÷${info.divisor}`);
  // 全マスに ○が つく（1マスずつ 採点している）
  await expect(page.locator('#fig .ic.ok')).toHaveCount(order.length);
  await expect(page.locator('#fig .ic.ng')).toHaveCount(0);
});

test('わり算の筆算: まちがえたマスだけ赤くなり、こたえは商とあまりで出る', async ({ page }) => {
  let info = null;
  for (let t = 0; t < 12 && !info; t++) {
    await startUnit(page, 'わり算の筆算①');
    info = await page.evaluate(() => {
      const wari = document.querySelector('#fig .wari');
      if (!wari) return null;
      const rows = wari.querySelectorAll('.wbox .wrow');
      return {
        divisor: Number(wari.querySelector('.wdrow').textContent.trim()),
        dividend: Number(Array.from(rows[0].querySelectorAll('.c'))
          .map((c) => c.textContent.trim()).join('')),
      };
    });
  }
  expect(info).not.toBeNull();
  const { need, order } = replayHissan(info.dividend, info.divisor);
  const tap = async (d) => (d === '×'
    ? page.locator('#padarea .key[aria-label*="×"]')
    : page.locator('#padarea .key').filter({ hasText: new RegExp(`^${d}$`) })).click();
  // 最後の1マスだけ わざと ちがう数字にする
  for (const [i, key] of order.entries()) {
    const v = need[key];
    await tap(i === order.length - 1 ? (v === '9' || v === '×' ? '8' : '9') : v);
  }
  await page.click('#go');
  await expect(page.locator('#fig .ic.ng')).toHaveCount(1);
  await expect(page.locator('#fig .ic.ok')).toHaveCount(order.length - 1);
  const q = Math.floor(info.dividend / info.divisor);
  await expect(page.locator('#fbt'))
    .toHaveText(`おしい！ 答えは 商 ${q}　あまり ${info.dividend % info.divisor}`);
});

/* 小数のひっ算は わり算とは 逆に「右の小さい位から 左へ」入力する。
   小数点の列は 打ってあって とばされること、整数（7 など）も 混ざることを 確かめる。 */
test('小数のひっ算: 小さい位から入れて正解になり、小数点の列はとばされる', async ({ page }) => {
  await startUnit(page, '小数のひっ算');
  const info = await page.evaluate(() => {
    const rows = document.querySelectorAll('#fig .hissan .hrow');
    const read = (row) => Array.from(row.querySelectorAll('.c'))
      .map((c) => c.textContent.trim()).join('');
    const ansCells = Array.from(rows[2].querySelectorAll('.c'));
    const dotIdx = ansCells.findIndex((c) => c.classList.contains('dot'));
    return {
      a: Number(read(rows[0])),
      b: Number(read(rows[1])),
      sign: rows[1].querySelector('.hop').textContent.trim(),
      decBoxes: ansCells.length - dotIdx - 1,
      dotIsFixed: !ansCells[dotIdx].classList.contains('ic') && ansCells[dotIdx].textContent.trim() === '.',
      focusIsRightmost: ansCells[ansCells.length - 1].classList.contains('foc'),
    };
  });
  expect(info.dotIsFixed, '小数点の列は入力させない').toBe(true);
  expect(info.focusIsRightmost, '書きはじめはいちばん小さい位').toBe(true);
  const ans = (info.sign === '+') ? info.a + info.b : info.a - info.b;
  const digits = ans.toFixed(info.decBoxes).replace('.', '');
  const tap = async (d) => page.locator('#padarea .key')
    .filter({ hasText: new RegExp(`^${d}$`) }).click();
  for (const d of digits.split('').reverse()) await tap(d);   // 右から左へ
  await page.click('#go');
  await expect(page.locator('#fbt')).toHaveText('せいかい！');
});
