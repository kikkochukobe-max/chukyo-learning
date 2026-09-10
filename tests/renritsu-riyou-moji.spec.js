// @ts-check
// 連立方程式マスター 文章題編の「a, b を求める」モード（文字をふくむ連立方程式）の回帰テスト。
//
// 守りたい仕様:
//   ・種類の一覧に「a, b を求める」が出て、STEPが2つある
//   ・問題文の連立方程式が KaTeX で描かれる（生の \begin{cases} が残らない）
//     ＝ .q-tex の中身が空だと「式の場所だけ真っ白」になり、問題が読めない
//   ・KaTeX に和文を裸で入れていない（警告が出ない）
//   ・選択肢は「a=3、b=−2」の形で、負の数の答えがちゃんと出る
//   ・学習記録に送る問題文(plain)に中かっこを入れない
//     ＝講師画面のPHPは { } を見つけると文字列全体をTeXとして描いてしまう
const { test, expect } = require('@playwright/test');

const TOOL = '/learning/math/math_js2_renritsu_riyou.html';

test.beforeEach(async ({ page }) => {
  const problems = [];
  page.on('pageerror', (e) => problems.push('pageerror: ' + e));
  page.on('console', (m) => {
    // KaTeX は和文を math モードに入れると console.warn を出す
    if (m.type() === 'warning' || m.type() === 'error') problems.push(m.type() + ': ' + m.text());
  });
  // @ts-ignore テスト間で持ち回る
  page.problems = problems;
  await page.goto(TOOL);
});

test('種類の一覧に「a, b を求める」が出て、STEPは2つ', async ({ page }) => {
  const card = page.locator('.lv-card', { hasText: 'a, b を求める' });
  await expect(card).toHaveCount(1);
  await expect(card).toContainText('STEP 1〜2');
  await card.click();
  await expect(page.locator('#stepGrid .lv-card')).toHaveCount(2);
  await expect(page.locator('#stepGrid')).toContainText('解から a, b を求める');
  await expect(page.locator('#stepGrid')).toContainText('同じ解をもつ2組');
});

for (const [step, label] of [[0, '解から a, b を求める'], [1, '同じ解をもつ2組']]) {
  test('STEP' + (Number(step) + 1) + '「' + label + '」: 式が描かれ、選択肢がa=・b=の形で出る', async ({ page }) => {
    await page.locator('.lv-card', { hasText: 'a, b を求める' }).click();
    await page.locator('#stepGrid .lv-card').nth(Number(step)).click();
    await expect(page.locator('#view-practice')).toBeVisible();

    // 20問ぶん出題し直して、毎回ちゃんと描けているかを見る
    for (let i = 0; i < 20; i++) {
      const q = page.locator('#qText');
      await expect(q).toContainText('a、b の値を求めなさい。');
      // 埋め込んだ数式が KaTeX で描けている。
      // ⚠ #qText の textContent には KaTeX が数式のTeX原文を
      //   <annotation> として埋めるので「\begin{cases} が無いこと」では判定できない。
      //   描画に失敗すると tex() が素のテキストに落ちて .katex ごと出ないので、そこを見る。
      const texBoxes = await q.locator('.q-tex').all();
      expect(texBoxes.length).toBeGreaterThan(0);
      for (const box of texBoxes) {
        await expect(box.locator('.katex .katex-html')).toHaveCount(1);
        expect(((await box.locator('.katex-html').innerText()) || '').trim().length).toBeGreaterThan(0);
      }
      // 数式を入れた問題文では方眼の罫線を消している
      await expect(q).toHaveClass(/no-rule/);

      const choices = page.locator('#choiceGrid .choice');
      await expect(choices).toHaveCount(6);
      for (const t of await choices.allInnerTexts()) {
        expect(t).toMatch(/^a=−?\d+、b=−?\d+$/);
      }
      // 凡例は選択肢が自分で a= b= と書くので出さない
      await expect(page.locator('#choiceLegend')).toHaveText('');

      await page.evaluate(() => {
        // @ts-ignore ツール内のグローバル関数
        nextQuestion();
      });
    }
    // @ts-ignore
    expect(page.problems).toEqual([]);
  });
}

test('答えるとヒント・解き方が読め、記録に送る問題文に中かっこが入らない', async ({ page }) => {
  await page.locator('.lv-card', { hasText: 'a, b を求める' }).click();
  await page.locator('#stepGrid .lv-card').nth(1).click();

  // ヒントは3段。KaTeX の連立もここで描く
  for (let i = 0; i < 3; i++) await page.click('#btnHint');
  await expect(page.locator('.hint-item')).toHaveCount(3);
  await expect(page.locator('#btnHint')).toBeDisabled();

  // わざと外して解き方を出す（不正解の選択肢を押す）
  const wrong = await page.evaluate(() => {
    // @ts-ignore ツール内の状態
    return state.choices.findIndex((c) => !c.correct);
  });
  await page.locator('#choiceGrid .choice').nth(wrong).click();
  await expect(page.locator('#kaisetsu')).toBeVisible();
  await expect(page.locator('#stepsArea .step.final')).toContainText('答え');

  // 記録に送る「人間用の1行」。中かっこ・バックスラッシュを入れない
  const plain = await page.evaluate(() => {
    // @ts-ignore ツール内の状態
    return state.cur.plain;
  });
  expect(plain).toContain('が同じ解をもつとき');
  expect(plain).not.toMatch(/[{}\\^_]/);

  // @ts-ignore
  expect(page.problems).toEqual([]);
});

test('総合ミックス: 文章題と「a, b を求める」が混ざっても表示が持ち越されない', async ({ page }) => {
  await page.locator('.lv-card', { hasText: '総合ミックス' }).click();
  await expect(page.locator('#view-practice')).toBeVisible();
  let sawAb = 0, sawWord = 0;
  for (let i = 0; i < 40; i++) {
    const isAb = await page.evaluate(() => {
      // @ts-ignore ツール内の状態
      return !!state.cur.labelChoice;
    });
    const texCount = await page.locator('#qText .q-tex').count();
    const legend = (await page.locator('#choiceLegend').innerText()).trim();
    const first = (await page.locator('#choiceGrid .choice').first().innerText()).trim();
    if (isAb) {
      sawAb++;
      expect(texCount).toBeGreaterThan(0);
      await expect(page.locator('#qText')).toHaveClass(/no-rule/);
      expect(legend).toBe('');
      expect(first).toMatch(/^a=−?\d+、b=−?\d+$/);
    } else {
      sawWord++;
      expect(texCount).toBe(0);
      await expect(page.locator('#qText')).not.toHaveClass(/no-rule/);
      expect(legend.length).toBeGreaterThan(0);
      expect(first).not.toMatch(/^a=/);
    }
    await page.evaluate(() => {
      // @ts-ignore ツール内のグローバル関数
      nextQuestion();
    });
  }
  expect(sawAb).toBeGreaterThan(0);
  expect(sawWord).toBeGreaterThan(0);
  // @ts-ignore
  expect(page.problems).toEqual([]);
});

test('文章題の種類は今までどおり（凡例が出て、選択肢は数のまま）', async ({ page }) => {
  await page.locator('.lv-card', { hasText: '数の問題' }).click();
  await page.locator('#stepGrid .lv-card').nth(0).click();
  await expect(page.locator('#choiceLegend')).toContainText('大きい数');
  await expect(page.locator('#qText')).not.toHaveClass(/no-rule/);
  for (const t of await page.locator('#choiceGrid .choice').allInnerTexts()) {
    expect(t).toMatch(/^\d+、\d+$/);
  }
  // @ts-ignore
  expect(page.problems).toEqual([]);
});
