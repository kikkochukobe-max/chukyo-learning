// @ts-check
// divp-choice-mark.js を組み込んだ各ツールの「配線」が生きていることの回帰テスト。
//
// 正解がどれかはツールごとに画面から読み取り方が違うので、ここでは
// 正解そのものではなく「採点表示の不変条件」を見張る:
//   ・すべての選択肢に data-divp-mark が付く（付け漏れ＝配線ミス）
//   ・correct と answer は合わせてちょうど1つ（単一正解のツールなので）
//   ・押した選択肢は correct か wrong のどちらか
//   ・「正解」バッジは answer にだけ出る
// これが通れば「モジュールが呼ばれていて、選択状態が正しく渡っている」ことは担保できる。
// 表示そのものの仕様は tests/choice-mark.spec.js 側で検証している。
const { test, expect } = require('@playwright/test');

const READ = (s) =>
  Array.from(document.querySelectorAll(s)).map((el) => ({
    mark: el.getAttribute('data-divp-mark'),
    badge: getComputedStyle(el, '::after').content,
  }));

const TOOLS = [
  {
    name: '方程式の利用',
    url: '/learning/math/math_js1_houteishiki_riyou.html',
    start: async (page) => {
      await page.locator('#catGrid button').first().click();
      await page.locator('#stepGrid button').first().click();
    },
    choices: '#choiceGrid .choice',
    grade: null,             // 選択肢を押した時点で採点される
    next: '#btnNext',
  },
  {
    name: '連立方程式の利用',
    url: '/learning/math/math_js2_renritsu_riyou.html',
    start: async (page) => {
      await page.locator('#catGrid button').first().click();
      await page.locator('#stepGrid button').first().click();
    },
    choices: '#choiceGrid .choice',
    grade: null,
    next: '#btnNext',
  },
  {
    name: '計算特集(中2)',
    url: '/learning/math/math_js2_keisan.html',
    start: async (page) => {
      await page.locator('#unitList button').first().click();
      await page.locator('#lvPick button').first().click();
    },
    choices: '#choices .choice',
    grade: '#checkBtn',
    next: '#nextBtn',
  },
  {
    name: '二次方程式',
    url: '/learning/math/math_js3_nijihoteishiki.html',
    start: async (page) => {
      // モード一覧は折りたたまれているので開いてから選ぶ(選ぶとセットが始まる)
      await page.locator("#mode-toggle").click();
      await page.locator("#mode-list button").first().click();
    },
    choices: '#choices .choice',
    grade: '#check-btn',
    next: '#next-btn',
  },
  {
    name: 'イオンのしくみラボ',
    url: '/learning/science/science_js3_ionlab.html',
    start: async (page) => {
      await page.locator('#tabbtn-quiz').click();
      await page.locator('#quizStart button').first().click();
    },
    choices: '#quizChoices .choice',
    // 単一選択は押した時点で採点。複数選択のときだけ「答え合わせ」ボタンが出る
    grade: '#quizCheckBtn',
    next: '#quizNextBtn',
    multiAnswer: true,   // 「あてはまるものを全部えらんでね」があるので正解は複数ありうる
  },
];

for (const t of TOOLS) {
  test(`${t.name}: 採点表示の配線が生きている`, async ({ page }) => {
    await page.goto(t.url);
    await t.start(page);
    const choices = page.locator(t.choices);
    await expect(choices.first()).toBeVisible();

    let sawWrong = 0, sawCorrect = 0;
    for (let n = 0; n < 25 && (sawWrong === 0 || sawCorrect === 0); n++) {
      // 選択肢が出ていない出題形式（記述など）はとばす
      if (await choices.count() < 2) { await page.locator(t.next).click(); continue; }

      await choices.first().click();
      // 採点ボタンがある出題形式のときだけ押す(押した時点で採点されるツールもある)
      if (t.grade) {
        const g = page.locator(t.grade);
        if (await g.isVisible()) await g.click();
      }

      const marks = await page.evaluate(READ, t.choices);
      expect(marks.length, '選択肢が取れていない').toBeGreaterThanOrEqual(2);
      // 付け漏れがない
      expect(marks.filter((m) => m.mark === null), 'data-divp-mark の付け漏れ').toHaveLength(0);
      // 正解はちょうど1つ
      const answers = marks.filter((m) => m.mark === 'correct' || m.mark === 'answer');
      if (t.multiAnswer) expect(answers.length, '正解が1つも無い').toBeGreaterThanOrEqual(1);
      else expect(answers, '正解が1つでない').toHaveLength(1);
      // 押したのは先頭。correct か wrong のどちらかでなければ選択状態が渡っていない
      expect(['correct', 'wrong'], '押した選択肢の状態がおかしい').toContain(marks[0].mark);
      // バッジは answer だけ
      marks.forEach((m, i) => {
        expect(m.badge, `${i}番目のバッジ (${m.mark})`).toBe(m.mark === 'answer' ? '"正解"' : 'none');
      });

      if (marks[0].mark === 'correct') sawCorrect++; else sawWrong++;

      // セットに問題数の上限があるツールは、最後の1問のあと結果画面に切り替わる。
      // その場合は最初からやり直して次のセットに入る。
      const next = page.locator(t.next);
      if (await next.isVisible()) await next.click();
      if (!(await choices.first().isVisible())) {
        await page.goto(t.url);
        await t.start(page);
      }
      await expect(choices.first()).toBeVisible();
    }
    expect(sawWrong, '誤答のケースを踏めなかった').toBeGreaterThan(0);
    expect(sawCorrect, '正解のケースを踏めなかった').toBeGreaterThan(0);
  });
}
