// @ts-check
// 漸化式マスター（math_hs_zenkashiki）の回帰テスト。
//
// 守りたい仕様:
//   ・10タイプ×2レベルが例外なく出題でき、答え合わせまで通る
//   ・グラフモードが3種類とも描ける（くもの巣図・項の並びの2枚とも SVG が出る）
//   ・特性方程式は「交点α」が図に出て、STEP5 で**新しい軸が交点まで動く**
//     （原点をαへずらす、というこのツールの主役の動き。transform が付くかで見る）
//   ・グラフのパラメータを変えても図が壊れない（発散する組み合わせを含む）
//   ・解説の「グラフで見る」から、その問題の数のままグラフモードへ飛べる
const { test, expect } = require('@playwright/test');

const TOOL = '/learning/math/math_hs_zenkashiki.html';
const MODES = ['tousa','touhi','kaisa','tokusei','shisuu','gyakusuu','slide','kaihi','taisuu','sankou'];

/** コンソールエラーを拾う（数式や図の組み立てが黙って失敗するのを防ぐ） */
function watchErrors(page) {
  const errs = [];
  page.on('pageerror', (e) => errs.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  return errs;
}

test.describe('漸化式マスター', () => {
  test('10タイプ×2レベルが出題でき、答え合わせが通る', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    for (const m of MODES) {
      for (const lv of [1, 2]) {
        await page.click(`#typeChips .chip[data-mode="${m}"]`);
        await page.click(`#lvSeg .lvbtn[data-lv="${lv}"]`);
        // 問題が出ていること
        await expect(page.locator('#qLead')).not.toBeEmpty();
        await expect(page.locator('#qDisp')).toBeVisible();
        // 正解を選んで（数値ならテンキーで入れて）答え合わせ
        const ok = await page.evaluate(() => {
          // @ts-ignore ツール内のグローバル
          const a = cur.ans;
          if (a.kind === 'choice') {
            const b = document.querySelectorAll('#ansArea .choiceBtn');
            if (b.length < 4) return 'choices:' + b.length;
            /** @type {HTMLElement} */ (b[a.correct]).click();
          } else {
            const s = String(a.val);
            for (const ch of s) {
              const key = (ch === '-') ? '-' : ch;
              /** @type {HTMLElement} */ (document.querySelector(`#keypad .key[data-k="${key}"]`)).click();
            }
          }
          /** @type {HTMLElement} */ (document.getElementById('checkBtn')).click();
          return document.getElementById('verdict').className;
        });
        expect(ok, `${m} lv${lv}`).toContain('ok');
        // 解説が開くこと
        await expect(page.locator('#explain')).toBeVisible();
        await page.click('#nextBtn');
      }
    }
    expect(errs).toEqual([]);
  });

  test('グラフモード: 3種類とも2枚の図が描ける', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    await page.click('.vtab[data-view="graph"]');
    for (const g of ['tousa', 'touhi', 'tokusei']) {
      await page.click(`#gTypeChips .chip[data-g="${g}"]`);
      await expect(page.locator('#gCob svg')).toBeVisible();
      await expect(page.locator('#gSeq svg')).toBeVisible();
      // y=x（折り返しの鏡）と漸化式の直線が必ず1本ずつ
      await expect(page.locator('#gCob .gyx')).toHaveCount(1);
      await expect(page.locator('#gCob .gfn')).toHaveCount(1);
      // 一般項が出ている
      await expect(page.locator('#gAns')).toContainText('一般項');
      // 最後のステップまで進める
      const steps = await page.locator('#gDots i').count();
      for (let i = 1; i < steps; i++) await page.click('#gNext');
      await expect(page.locator('#gNo')).toContainText(`/ ${steps}`);
    }
    expect(errs).toEqual([]);
  });

  test('特性方程式: 交点αが出て、STEP5で原点がαまで動く', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    await page.click('.vtab[data-view="graph"]');
    await page.click('#gTypeChips .chip[data-g="tokusei"]');

    // 交点のマーカーとラベル（α = …）がある
    await expect(page.locator('#gCob .fix')).toHaveCount(1);
    await expect(page.locator('#gCob .fixlb')).toContainText('α');

    // STEP1 では新しい軸はまだ動いていない（原点に重なっている）
    const before = await page.locator('#newAxG').evaluate((el) => getComputedStyle(el).transform);
    // STEP5（index 4）まで進めると、交点ぶんだけ translate が入る
    for (let i = 0; i < 4; i++) await page.click('#gNext');
    await page.waitForTimeout(1200);            // すべっていくアニメーションの終わりを待つ
    const after = await page.locator('#newAxG').evaluate((el) => getComputedStyle(el).transform);
    expect(after).not.toBe(before);
    expect(after).not.toBe('none');
    // 動く量は「αが原点からどれだけ離れているか」＝0ではない
    const dx = await page.locator('#newAxG').evaluate((el) => Math.abs(parseFloat(el.getAttribute('data-dx'))));
    expect(dx).toBeGreaterThan(1);
    expect(errs).toEqual([]);
  });

  test('グラフモード: パラメータを変えても図が壊れない', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    await page.click('.vtab[data-view="graph"]');
    await page.click('#gTypeChips .chip[data-g="tokusei"]');
    // p を一周（1/2 のような分数も、-3 のような大きく発散する値も通す）
    for (let i = 0; i < 9; i++) {
      await page.click('#gCtrl .sbtn[data-p="p"][data-dir="1"]');
      await expect(page.locator('#gCob svg')).toBeVisible();
      await expect(page.locator('#gCob .gfn')).toHaveCount(1);
    }
    // q と a1 も動かす
    for (let i = 0; i < 5; i++) await page.click('#gCtrl .sbtn[data-p="q"][data-dir="-1"]');
    for (let i = 0; i < 5; i++) await page.click('#gCtrl .sbtn[data-p="a1"][data-dir="1"]');
    await expect(page.locator('#gSeq svg')).toBeVisible();
    // SVG の座標に NaN が出ていないこと（窓の計算が壊れるとここに出る）
    const html = await page.locator('#gCob').innerHTML();
    expect(html).not.toContain('NaN');
    expect(errs).toEqual([]);
  });

  test('解説の「グラフで見る」でその問題の数のままグラフへ飛ぶ', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    await page.click('#typeChips .chip[data-mode="tokusei"]');
    // わざと誤答して解説を開く（正解でも解説は出るが、ここはボタンの導線だけ見る）
    await page.evaluate(() => {
      // @ts-ignore
      const a = cur.ans;
      const b = document.querySelectorAll('#ansArea .choiceBtn');
      /** @type {HTMLElement} */ (b[a.correct === 0 ? 1 : 0]).click();
      /** @type {HTMLElement} */ (document.getElementById('checkBtn')).click();
    });
    const link = page.locator('#exBody [data-graph]');
    await expect(link).toHaveCount(1);
    // その問題の p, q, a1 がグラフ側に入ること
    const want = JSON.parse(await link.getAttribute('data-graph'));
    await link.click();
    await expect(page.locator('#view-graph')).toBeVisible();
    const got = await page.evaluate(() => {
      // @ts-ignore
      return { t: gType, p: gPar.tokusei.p, q: gPar.tokusei.q };
    });
    expect(got.t).toBe('tokusei');
    expect(got.q).toBe(want.q);
    expect(got.p.n / got.p.d).toBe(want.p);
    expect(errs).toEqual([]);
  });
});
