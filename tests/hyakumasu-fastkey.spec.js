// @ts-check
// 「スマホde100マス」テンキーの高速入力に取りこぼし・二重入力がないことの回帰テスト。
// 実装の要点（touch=touchstart 主経路 / pointerdown はマウス・ペン専用 / HIT_SLOP 吸着 /
// 近接コアレス廃止＝両手同時押しを捨てない / touchstart が届かなかった指を
// touchmove・touchend から拾い直す）が壊れていないかを機械的に見張る。
//
// 打鍵は page.tap() ではなく in-page の dispatch で行う（間隔をmsで厳密に制御するため）。
// mobile プロジェクトだけは touchscreen.tap() による実タッチエミュレーションも通す。
//
// ⚠ 画面の書き換えは requestAnimationFrame にまとめてある（passive:false の touchstart の
//   中で DOM を書くと、その間に来た次の接地をブラウザが落とすため）。__ans/__idx/__disp は
//   どれも DOM を読むので、打鍵の直後に読むと1フレーム前の値が返る。
//   打ってすぐ読む検査は必ず await window.__frame() を挟むこと。
const { test, expect } = require('@playwright/test');

const URL = '/learning/math/math_es_hyakumasu.html';

// ページ側に打鍵ドライバを注入する。
// mode='touch' … Touch/TouchEvent を組んで #tenkey の touchstart 経路へ
// mode='mouse' … PointerEvent(pointerType:'mouse') で pointerdown 経路へ
const DRIVER = () => {
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const btnOf = (k) => document.querySelector('#tenkey button[data-k="' + k + '"]');
  const centerOf = (b) => {
    const r = b.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  };

  window.__hit = function (k, mode) {
    const b = btnOf(k);
    if (!b) throw new Error('no key: ' + k);
    const { x, y } = centerOf(b);
    if (mode === 'touch') {
      const t = new Touch({ identifier: Date.now() % 100000, target: b, clientX: x, clientY: y });
      b.dispatchEvent(new TouchEvent('touchstart', {
        bubbles: true, cancelable: true, touches: [t], targetTouches: [t], changedTouches: [t],
      }));
    } else {
      b.dispatchEvent(new PointerEvent('pointerdown', {
        bubbles: true, cancelable: true, pointerType: 'mouse', clientX: x, clientY: y,
      }));
    }
  };

  // 同じ接地を pointerdown(touch) でも撃つ＝合成イベント相当の二重発火を模擬する
  window.__hitTouchThenPointer = function (k) {
    window.__hit(k, 'touch');
    const b = btnOf(k);
    const { x, y } = centerOf(b);
    b.dispatchEvent(new PointerEvent('pointerdown', {
      bubbles: true, cancelable: true, pointerType: 'touch', clientX: x, clientY: y,
    }));
  };

  // 描画フレームを1回待つ。DOM を読む前に必ず挟む（ファイル先頭の注意書き参照）。
  window.__frame = function () {
    return new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
  };

  // touchstart が届かなかった指の模擬。
  // iOS が素早い2打目をジェスチャー審査で保留すると接地イベントが来ず、
  // 指を動かした／離した時点で初めて届く。その時に入力が成立することを見る。
  window.__hitLate = function (k, via) {
    const b = btnOf(k);
    if (!b) throw new Error('no key: ' + k);
    const { x, y } = centerOf(b);
    const id = (Date.now() % 100000) + 500;
    const t = new Touch({ identifier: id, target: b, clientX: x, clientY: y });
    const mk = (type, touches) => new TouchEvent(type, {
      bubbles: true, cancelable: true,
      touches: touches, targetTouches: touches, changedTouches: [t],
    });
    if (via === 'move') b.dispatchEvent(mk('touchmove', [t]));
    b.dispatchEvent(mk('touchend', []));
  };

  // 接地→わずかな移動→離す、を同じ identifier で通す普通のタップ。
  // 拾い直し(recover)が「もう処理した指」を二重に入力しないことの確認用。
  window.__tapFull = function (k) {
    const b = btnOf(k);
    if (!b) throw new Error('no key: ' + k);
    const { x, y } = centerOf(b);
    const id = (Date.now() % 100000) + 900;
    const at = (dx) => new Touch({ identifier: id, target: b, clientX: x + dx, clientY: y });
    const fire = (type, t, touches) => b.dispatchEvent(new TouchEvent(type, {
      bubbles: true, cancelable: true,
      touches: touches, targetTouches: touches, changedTouches: [t],
    }));
    const t0 = at(0), t1 = at(1), t2 = at(1);
    fire('touchstart', t0, [t0]);
    fire('touchmove', t1, [t1]);
    fire('touchend', t2, []);
  };

  window.__ans = function () {
    return Number(document.getElementById('qM').textContent) +
           Number(document.getElementById('qN').textContent);
  };
  window.__idx = function () {
    return Number(String(document.getElementById('countLabel').textContent).split('/')[0].trim());
  };
  window.__disp = function () {
    const t = document.getElementById('ansBox').textContent;
    return t === ' ' ? '' : t;
  };

  // 答えを打つ。digits 間の間隔は gap ms。
  window.__type = async function (str, gap, mode) {
    for (let i = 0; i < str.length; i++) {
      if (i) await sleep(gap);
      window.__hit(str[i], mode);
    }
  };

  // 答えが2桁になる問題まで、1桁の問題は正答して進める。
  window.__seekTwoDigit = async function (mode) {
    for (let guard = 0; guard < 30; guard++) {
      const a = window.__ans();
      if (a >= 10) return a;
      window.__hit(String(a), mode);
      await sleep(120);   // ANS_HOLD_MS(90ms) を跨いで次問の描画を確定させる
    }
    throw new Error('2桁の答えの問題に到達できなかった');
  };

  // 正解直後（答えを見せている ANS_HOLD_MS の保留中）に1発打ち、それが次問の1桁目として
  // 残るかを見る。次問の答えが1桁だとその1発自体が判定を起こして測れないので、
  // 次問が2桁になるまで引き直す。
  window.__probeAfterCorrect = async function (mode) {
    for (let guard = 0; guard < 40; guard++) {
      const a = await window.__seekTwoDigit(mode);
      const before = window.__idx();
      await window.__type(String(a), 0, mode);
      window.__hit('7', mode);                  // ← 保留中の1打
      await window.__frame();                   // 描画は次フレームにまとめている
      const advanced = window.__idx() === before + 1;
      // press() 冒頭の flushQ() で次問が描画済みのはず
      if (String(window.__ans()).length === 2) {
        return { ok: true, advanced: advanced, disp: window.__disp() };
      }
      await sleep(120);
    }
    return { ok: false };
  };

  // 100問を完走する。gap は「同じ答えの1桁目→2桁目」の間隔。
  // 問題と問題の間は、次の問題が実際に描画される（countLabel が進む）のを待ってから打つ。
  // 正解直後は ANS_HOLD_MS のあいだ答えを見せている＝生徒にも次の問題はまだ見えていないため。
  window.__runAll = async function (gap, mode) {
    let steps = 0;
    while (document.getElementById('scrPlay').classList.contains('show') && steps < 400) {
      const before = window.__idx();
      await window.__type(String(window.__ans()), gap, mode);
      steps++;
      for (let w = 0; w < 100; w++) {                       // 次問の描画待ち（最大500ms）
        if (!document.getElementById('scrPlay').classList.contains('show')) break;
        if (window.__idx() !== before) break;
        await sleep(5);
      }
    }
    return steps;
  };
};

async function open(page, query) {
  await page.addInitScript(DRIVER);
  await page.goto(URL + (query || ''));
  await page.click('#btnStart');
  // カウントダウン3秒。消えた＝running=true
  await expect(page.locator('#countdown')).not.toHaveClass(/show/, { timeout: 10_000 });
}

function modeFor(testInfo) {
  return testInfo.project.name === 'iphone' ? 'touch' : 'mouse';
}

test.describe('100マス テンキー高速入力', () => {
  // T1 入力欠落ゼロ（間隔スイープ）
  for (const gap of [100, 50, 20, 10, 5, 0]) {
    test(`T1 2桁を${gap}ms間隔で打っても欠落しない`, async ({ page }, testInfo) => {
      const mode = modeFor(testInfo);
      await open(page);
      for (let round = 0; round < 5; round++) {
        const ans = await page.evaluate((m) => window.__seekTwoDigit(m), mode);
        const before = await page.evaluate(() => window.__idx());
        await page.evaluate(([a, g, m]) => window.__type(String(a), g, m), [ans, gap, mode]);
        // 2桁とも入った＝正答成立＝問題が1つ進む。1桁でも落ちれば進まない。
        await expect
          .poll(() => page.evaluate(() => window.__idx()), { timeout: 2000 })
          .toBe(before + 1);
        await page.waitForTimeout(120);
      }
    });
  }

  // T2 100問完走（ミス0・欠落0）
  test('T2 10ms間隔で100問を完走しミス0', async ({ page }, testInfo) => {
    const mode = modeFor(testInfo);
    test.setTimeout(120_000);
    await open(page);
    const steps = await page.evaluate((m) => window.__runAll(10, m), mode);
    expect(steps).toBe(100);                                   // 打ち直し（欠落・誤答）ゼロ
    await expect(page.locator('#scrEnd')).toHaveClass(/show/, { timeout: 10_000 });
    await expect(page.locator('#resMiss')).toHaveText('0 回');
  });

  // T3 不正解直後の打鍵が捨てられない
  test('T3 不正解の直後に打った数字が次の1桁目になる', async ({ page }, testInfo) => {
    const mode = modeFor(testInfo);
    await open(page);
    const ans = await page.evaluate((m) => window.__seekTwoDigit(m), mode);
    const wrong = String(ans === 19 ? 18 : ans + 1);   // 2桁を保ったまま外す
    const before = await page.evaluate(() => window.__idx());
    await page.evaluate(([w, m]) => window.__type(String(w), 0, m), [wrong, mode]);
    await page.evaluate((m) => window.__hit('7', m), mode);
    await page.evaluate(() => window.__frame());
    expect(await page.evaluate(() => window.__disp())).toBe('7');
    expect(await page.evaluate(() => window.__idx())).toBe(before);   // 進んでいない
  });

  // T4 正解遷移中（答えを見せている90ms）の打鍵が捨てられない
  test('T4 正解直後に打った数字が次問の1桁目になる', async ({ page }, testInfo) => {
    const mode = modeFor(testInfo);
    await open(page);
    const r = await page.evaluate((m) => window.__probeAfterCorrect(m), mode);
    expect(r.ok).toBe(true);
    expect(r.advanced).toBe(true);      // 正解で問題は進んでいる
    expect(r.disp).toBe('7');           // 保留中に打った1発が次問の1桁目として残っている
  });

  // T5 二重発火なし
  test('T5 1接地で数字は1つしか入らない', async ({ page }, testInfo) => {
    test.skip(modeFor(testInfo) !== 'touch', 'タッチのある環境のみ');
    await open(page);
    await page.evaluate(() => window.__seekTwoDigit('touch'));
    await page.waitForTimeout(120);
    await page.evaluate(() => window.__hitTouchThenPointer('7'));
    await page.evaluate(() => window.__frame());
    expect(await page.evaluate(() => window.__disp())).toBe('7');
  });

  // T5b touchstart が届かなかった接地を touchmove / touchend から拾い直す
  // （iOS が素早い2打目をジェスチャー審査で保留する現象への保険。ここが効かないと
  //   「2桁目だけ入らない」が再発する）
  for (const via of ['move', 'end']) {
    test(`T5b touchstart 無しでも ${via} で2桁目が入る`, async ({ page }, testInfo) => {
      test.skip(modeFor(testInfo) !== 'touch', 'タッチのある環境のみ');
      await open(page);
      const ans = await page.evaluate(() => window.__seekTwoDigit('touch'));
      await page.waitForTimeout(120);
      const before = await page.evaluate(() => window.__idx());
      const s = String(ans);
      await page.evaluate(([d]) => window.__hit(d, 'touch'), [s[0]]);        // 1桁目は通常経路
      await page.evaluate(([d, v]) => window.__hitLate(d, v), [s[1], via]);  // 2桁目は接地が来ない
      await expect
        .poll(() => page.evaluate(() => window.__idx()), { timeout: 2000 })
        .toBe(before + 1);
    });
  }

  // T5c 接地が届いた指は二重に入らない（拾い直しが誤爆しないこと）
  test('T5c 通常のタップは touchend で二重入力にならない', async ({ page }, testInfo) => {
    test.skip(modeFor(testInfo) !== 'touch', 'タッチのある環境のみ');
    await open(page);
    await page.evaluate(() => window.__seekTwoDigit('touch'));
    await page.waitForTimeout(120);
    await page.evaluate(() => window.__tapFull('7'));
    await page.evaluate(() => window.__frame());
    expect(await page.evaluate(() => window.__disp())).toBe('7');   // '77' になっていない
  });

  // T5d passive 実験経路（?tap=p）でも入力が成立する
  // 実機の iOS でしか本題（ジェスチャー審査の待ちが消えるか）は検証できないが、
  // 「preventDefault をやめても入力・拾い直し・二重入力なしが保たれる」ことはここで見られる。
  test('T5d ?tap=p でも2桁が両方入り二重入力もしない', async ({ page }, testInfo) => {
    const mode = modeFor(testInfo);
    await open(page, '?tap=p');
    // 経路の切替が効いていること（passive:true のリスナーで preventDefault は呼べない）
    const warned = [];
    page.on('console', (m) => { if (/passive/i.test(m.text())) warned.push(m.text()); });
    for (let round = 0; round < 3; round++) {
      const ans = await page.evaluate((m) => window.__seekTwoDigit(m), mode);
      const before = await page.evaluate(() => window.__idx());
      await page.evaluate(([a, m]) => window.__type(String(a), 0, m), [ans, mode]);
      await expect
        .poll(() => page.evaluate(() => window.__idx()), { timeout: 2000 })
        .toBe(before + 1);
      await page.waitForTimeout(120);
    }
    if (mode === 'touch') {
      // 答えが1桁の問題だと '7' の1打で判定が走り入力欄が空に戻る＝残り方を見られない
      await page.evaluate(() => window.__seekTwoDigit('touch'));
      await page.waitForTimeout(120);
      await page.evaluate(() => window.__tapFull('7'));
      await page.evaluate(() => window.__frame());
      expect(await page.evaluate(() => window.__disp())).toBe('7');   // '77' になっていない
    }
    expect(warned, 'passive リスナー内で preventDefault を呼んでいる').toEqual([]);
  });

  // T6 物理キーボード（同じ press() を通る）
  test('T6 キーボードで10ms間隔でも欠落しない', async ({ page }, testInfo) => {
    const mode = modeFor(testInfo);
    await open(page);
    for (let round = 0; round < 5; round++) {
      const ans = await page.evaluate((m) => window.__seekTwoDigit(m), mode);
      const before = await page.evaluate(() => window.__idx());
      const s = String(ans);
      await page.keyboard.press(s[0]);
      await page.waitForTimeout(10);
      await page.keyboard.press(s[1]);
      await expect
        .poll(() => page.evaluate(() => window.__idx()), { timeout: 2000 })
        .toBe(before + 1);
      await page.waitForTimeout(120);
    }
  });

  // T6b IME変換中のキーを数字として拾わない
  test('T6b IME変換中(isComposing / keyCode 229)のキーは無視される', async ({ page }, testInfo) => {
    const mode = modeFor(testInfo);
    await open(page);
    await page.evaluate((m) => window.__seekTwoDigit(m), mode);
    // 1発ずつ確かめる（2発まとめて撃つと2桁そろって判定が走り、
    // ガードが無くても入力欄が空に戻ってしまい検査にならない）
    await page.evaluate(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '3', isComposing: true, bubbles: true }));
    });
    expect(await page.evaluate(() => window.__disp())).toBe('');
    await page.evaluate(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '5', keyCode: 229, bubbles: true }));
    });
    expect(await page.evaluate(() => window.__disp())).toBe('');
  });

  // T7 実タッチエミュレーション（dispatch ではなくブラウザ由来のタッチ）
  test('T7 実タッチでも2桁が両方入る', async ({ page }, testInfo) => {
    if (modeFor(testInfo) !== 'touch') test.skip();
    await open(page);
    const ans = await page.evaluate(() => window.__seekTwoDigit('touch'));
    const before = await page.evaluate(() => window.__idx());
    const s = String(ans);
    for (const d of s) {
      const box = await page.locator(`#tenkey button[data-k="${d}"]`).boundingBox();
      await page.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2);
    }
    await expect
      .poll(() => page.evaluate(() => window.__idx()), { timeout: 2000 })
      .toBe(before + 1);
  });
});
