// @ts-check
// 選択肢の答え合わせ表示（assets/divp-choice-mark.js）の回帰テスト。
//
// 守りたい仕様:
//   選んで正解      → data-divp-mark="correct"（バッジなし。お祝い演出が出るので二重に主張しない）
//   選ばなかった正解 → data-divp-mark="answer" ＋ 「正解」バッジ（::after）
//   選んだ誤答      → data-divp-mark="wrong"
//   それ以外        → data-divp-mark="dim"（dimOthers 指定時）
//
// 前半はモジュール単体（page.setContent のフィクスチャ）、
// 後半は実際に組み込んだ「愛知県公立入試 大問1」の画面で検証する。
const { test, expect } = require('@playwright/test');

const MOD = '/assets/divp-choice-mark.js';
const AICHI = '/learning/math/math_js3_aichi_daimon1.html';

/* 各ボタンの mark と バッジ文字（::after の content）を読む。
   バッジは擬似要素なので getComputedStyle の第2引数で取る。 */
const READ = (s) => {
  return Array.from(document.querySelectorAll(s)).map((el) => ({
    mark: el.getAttribute('data-divp-mark'),
    label: el.getAttribute('data-divp-label'),
    // '"正解"' のように引用符付きで返る。none のときは 'none'
    badge: getComputedStyle(el, '::after').content,
    borderColor: getComputedStyle(el).borderTopColor,
    position: getComputedStyle(el).position,
  }));
};

test.describe('モジュール単体', () => {
  // setContent の about:blank では相対URLが解決できないので baseURL を付ける
  test.beforeEach(async ({ page, baseURL }) => {
    await page.setContent(`
      <div id="g">
        <button class="c">1</button><button class="c">2</button>
        <button class="c">3</button><button class="c">4</button>
      </div>`);
    await page.addScriptTag({ url: baseURL + MOD });
  });

  test('誤答: 選んだものが朱、選ばなかった正解に「正解」バッジ', async ({ page }) => {
    await page.evaluate(() => Divp.markChoices('.c', { correct: 3, selected: 1, dimOthers: true }));
    const r = await page.evaluate(READ, '.c');
    expect(r.map((x) => x.mark)).toEqual(['dim', 'wrong', 'dim', 'answer']);
    expect(r[3].label).toBe('正解');
    expect(r[3].badge).toBe('"正解"');
    // バッジは絶対配置なので、対象が position:relative になっている必要がある
    expect(r[3].position).toBe('relative');
    // 正解でも選んでもいないものにバッジは出ない
    expect(r[0].badge).toBe('none');
  });

  test('正解: バッジは出さない（お祝い演出と二重にしない）', async ({ page }) => {
    await page.evaluate(() => Divp.markChoices('.c', { correct: 2, selected: 2 }));
    const r = await page.evaluate(READ, '.c');
    expect(r.map((x) => x.mark)).toEqual([null, null, 'correct', null]);
    expect(r[2].label).toBeNull();
    expect(r[2].badge).toBe('none');
  });

  test('badgeOnSelected:true なら選んで正解にもバッジ', async ({ page }) => {
    await page.evaluate(() => Divp.markChoices('.c', { correct: 0, selected: 0, badgeOnSelected: true }));
    const r = await page.evaluate(READ, '.c');
    expect(r[0].mark).toBe('correct');
    expect(r[0].badge).toBe('"正解"');
  });

  test('複数正解と label の差し替え（小学生向けのひらがな）', async ({ page }) => {
    await page.evaluate(() =>
      Divp.markChoices('.c', { correct: [0, 2], selected: 1, label: 'せいかい' }));
    const r = await page.evaluate(READ, '.c');
    expect(r.map((x) => x.mark)).toEqual(['answer', 'wrong', 'answer', null]);
    expect(r[0].badge).toBe('"せいかい"');
    expect(r[2].badge).toBe('"せいかい"');
  });

  test('無回答（selected 省略）でも正解は示す', async ({ page }) => {
    await page.evaluate(() => Divp.markChoices('.c', { correct: 1 }));
    const r = await page.evaluate(READ, '.c');
    expect(r.map((x) => x.mark)).toEqual([null, 'answer', null, null]);
  });

  test('clearMarks で消える / 想定外の種別は無視する', async ({ page }) => {
    await page.evaluate(() => {
      Divp.markChoices('.c', { correct: 0, selected: 1, dimOthers: true });
      Divp.clearMarks('.c');
    });
    let r = await page.evaluate(READ, '.c');
    expect(r.every((x) => x.mark === null && x.label === null)).toBe(true);

    await page.evaluate(() => Divp.markChoice(document.querySelector('.c'), 'HENNA'));
    r = await page.evaluate(READ, '.c');
    expect(r[0].mark).toBeNull();
  });

  test('色はツール側の :root 指定が勝つ（読み込み順に依存しない）', async ({ page }) => {
    // モジュールを読み込んだ「後」に上書きしても効くこと
    await page.addStyleTag({ content: ':root{--divp-mark-ok:#7ee0a0;}' });
    await page.evaluate(() => Divp.markChoices('.c', { correct: 0, selected: 1 }));
    const r = await page.evaluate(READ, '.c');
    expect(r[0].borderColor).toBe('rgb(126, 224, 160)');   // #7ee0a0（既定の #3E8E5A とは別の色にしてある）
  });
});

test.describe('愛知県公立入試 大問1 に組み込んだ状態', () => {
  const KANA = ['ア', 'イ', 'ウ', 'エ', 'オ', 'カ'];

  test('誤答時も正解時も、採点表示が仕様どおりになる', async ({ page }) => {
    await page.goto(AICHI);
    // ホームの最初のタイプ（出題オンのもの）でフリー演習を始める
    await page.locator('#chips button.chip:not(.chip-off)').first().click();
    await expect(page.locator('#choices .mchoice').first()).toBeVisible();

    let sawWrong = 0, sawCorrect = 0;
    // 出題はランダムなので、正解/誤答の両方を踏むまで回す（上限40問）
    for (let n = 0; n < 40 && (sawWrong === 0 || sawCorrect === 0); n++) {
      const choices = page.locator('#choices .mchoice');
      const count = await choices.count();
      expect(count).toBeGreaterThanOrEqual(2);

      // 何問マークする問題かは ※二つ選びなさい の表示で判断する
      const multi = await page.locator('#multiNote').isVisible() ? 2 : 1;
      for (let i = 0; i < multi; i++) await choices.nth(i).click();
      await page.locator('#btnCheck').click();
      await expect(page.locator('#fb.show')).toBeVisible();

      // 画面の文言から正解の記号を読む（'正解！ (答え: ア・イ)' / 'ざんねん… 正解は ウ'）
      const msg = (await page.locator('#fbMsg').textContent()) || '';
      const ok = msg.startsWith('正解');
      const correctIdx = KANA.map((k, i) => (msg.includes(k) ? i : -1)).filter((i) => i >= 0);
      expect(correctIdx.length).toBe(multi);

      const marks = await page.evaluate(READ, '#choices .mchoice');
      const selectedIdx = Array.from({ length: multi }, (_, i) => i);
      marks.forEach((m, i) => {
        const isC = correctIdx.includes(i), isS = selectedIdx.includes(i);
        const want = isC ? (isS ? 'correct' : 'answer') : (isS ? 'wrong' : 'dim');
        expect(m.mark, `選択肢${KANA[i]} (${msg})`).toBe(want);
        // バッジは「選ばなかった正解」だけ
        expect(m.badge, `選択肢${KANA[i]} のバッジ`).toBe(want === 'answer' ? '"正解"' : 'none');
        // 緑・朱はこのツールのパレット（--midori #3E8E5A / --shu #C73E3A）で描かれる
        if (want === 'correct' || want === 'answer') expect(m.borderColor).toBe('rgb(62, 142, 90)');
        if (want === 'wrong') expect(m.borderColor).toBe('rgb(199, 62, 58)');
      });

      if (ok) sawCorrect++; else sawWrong++;
      await page.locator('#btnNext').click();
      await expect(page.locator('#fb.show')).toBeHidden();
    }
    expect(sawWrong, '誤答のケースを1問も踏めなかった').toBeGreaterThan(0);
    expect(sawCorrect, '正解のケースを1問も踏めなかった').toBeGreaterThan(0);
  });
});
