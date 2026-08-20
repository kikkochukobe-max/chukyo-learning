// @ts-check
// 一次関数マスターに入れた「共通処理」の回帰テスト。
// Divp を偽物に差し替えて、判定直後に飛ぶ内容を検証する
// （PHPは動かせないので、送っている中身が仕様どおりかを見る）。
//
// 守りたい仕様:
//   ①学習記録  question_key=モード名 / question_params={m,s} / 問題文はプレーンテキスト
//   ②正解エフェクト  Divp.correct('#card') が呼ばれる（誤答は incorrect）
//   ③解き直し  ?retry=1 で question_params の種から**同じ問題**が出る
//   ④図        図が要る問題は question_figure に出題直後のSVG（CSS変数は実色に解決）
const { test, expect } = require('@playwright/test');

const TOOL = '/learning/math/math_js2_ichijikansu.html';

/* 本物の共通モジュールを黙らせて記録用の偽 Divp を置く。
   divp-core は answer._divpCore を見て「もう入っている」と判断して抜けるので、
   その印を付けておくと差し替えが上書きされない。 */
async function stubDivp(page, retries) {
  await page.addInitScript((items) => {
    const calls = { answer: [], correct: [], incorrect: [], init: [] };
    const answer = function (ok, info) { calls.answer.push({ ok: ok, info: info }); };
    answer._divpCore = true;                       // divp-core.js に早期 return させる
    window.__divpCorrectJHLoaded = true;           // divp-correct-jh.js も上書きさせない
    window.Divp = {
      init: function (k) { calls.init.push(k); },
      answer: answer,
      correct: function (sel) { calls.correct.push(sel); },
      incorrect: function (sel) { calls.incorrect.push(sel); },
      getRetries: function () { return Promise.resolve(items || []); },
    };
    window.__calls = calls;
  }, retries);
}

/* 正解の選択肢(またはわざと誤答)を押して答え合わせする */
async function answerCurrent(page, wrong) {
  await page.evaluate((bad) => {
    const btns = Array.from(document.querySelectorAll('#ansArea .choiceBtn'));
    // @ts-ignore ツール内のグローバル
    const c = cur.ans.correct;
    btns[bad ? (c === 0 ? 1 : 0) : c].click();
    document.getElementById('checkBtn').click();
  }, !!wrong);
}

test.beforeEach(async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.errors = errors;
});

test('①④ 判定直後に飛ぶ内容（モード名・種・プレーンな問題文・図）', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL);
  await page.click('.chip[data-mode="yomitori"]');      // 図が必須のモード
  await answerCurrent(page);

  const calls = await page.evaluate(() => window.__calls);
  expect(calls.init).toEqual(['math_js2_ichijikansu']);
  expect(calls.answer).toHaveLength(1);
  const { ok, info } = calls.answer[0];
  expect(ok).toBe(true);
  expect(info.question_key).toBe('yomitori');           // カタログのキー＝モード名
  expect(Object.keys(info.question_params).sort()).toEqual(['m', 's']);
  expect(info.question_params.m).toBe('yomitori');
  expect(typeof info.question_params.s).toBe('number');

  // 問題文は講師画面向けのプレーンテキスト（HTMLタグもTeXの \ も残さない）
  expect(info.question_text.length).toBeGreaterThan(3);
  expect(info.question_text).not.toContain('<');
  expect(info.question_text).not.toContain('\\');

  // 図は出題直後のSVG。CSS変数は実際の色に解決してから保存する
  expect(info.question_figure.startsWith('<svg')).toBe(true);
  expect(info.question_figure).not.toContain('var(--');
  expect(info.question_figure).toContain('#2E5077');    // --indigo
  // 採点後に重ねる「正解の緑」は写っていない（答えが見えてしまう）
  expect(info.question_figure).not.toContain('#3E8E5A');

  expect(info.correct_answer.length).toBeGreaterThan(0);
  expect(page.errors).toEqual([]);
});

test('① 全モードの問題文がプレーンテキストになる（タグ・不等号・TeXの残りが無い）', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL);
  const bad = await page.evaluate(() => {
    const out = [];
    // @ts-ignore
    for (const m of Object.keys(GENS)) {
      for (let i = 0; i < 400; i++) {
        // @ts-ignore
        const t = qTextPlain(GENS[m]());
        // 不等号の < > は数式として出てよい。HTMLの残骸・TeXの \ ・実体参照は出てはいけない
        if (/<\s*[a-zA-Z/!]/.test(t) || t.includes('">') || t.indexOf(String.fromCharCode(92)) >= 0
            || /undefined|NaN|&[a-z]+;/.test(t) || t.length < 5) { out.push([m, t]); break; }
      }
    }
    return out;
  });
  expect(bad).toEqual([]);
  expect(page.errors).toEqual([]);
});

test('① 分数の答えは F(分子/分母) で記録する（講師画面の規約）', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL);
  // 交点モードの3種類のうち1つは必ず分数の交点。出るまで引き直す
  await page.click('.chip[data-mode="koten"]');
  await page.evaluate(() => {
    for (let i = 0; i < 60; i++) {
      // @ts-ignore
      if (cur.qkey === 'koten_v3') return;
      // @ts-ignore
      newQuestion();
    }
  });
  await answerCurrent(page);
  const info = (await page.evaluate(() => window.__calls)).answer[0].info;
  expect(info.correct_answer).toMatch(/F\(-?\d+\/\d+\)/);
  expect(page.errors).toEqual([]);
});

/* 「式の特徴」は並んだ式から選ぶ問題＝選択肢が無いと紙で解けないので選択肢も保存する。
   ほかの種類は答えが値や式そのもの（記述式で解ける）ので保存しない。 */
test('④ 選択肢が問題の中身になる種類だけ question_choices を送る', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL);

  await page.click('.chip[data-mode="tokucho"]');
  await page.evaluate(() => {
    // 選択式(6択)の出題形式になるまで引く（並べかえの回もあるが選択肢は同じく保存する）
    for (let i = 0; i < 40; i++) {
      // @ts-ignore
      if (cur.ans.kind === 'choice') return;
      // @ts-ignore
      newQuestion();
    }
  });
  await answerCurrent(page, true);           // 誤答のときだけサーバーが保存する
  let info = (await page.evaluate(() => window.__calls)).answer.slice(-1)[0].info;
  expect(info.question_choices.length).toBeGreaterThanOrEqual(2);
  info.question_choices.forEach((c) => {
    expect(c.t).toBe('tex');
    expect(c.v).toMatch(/^y=/);              // 記録の規約どおり（分数は F(a/b)）
    expect(c.v).not.toContain('\\');
  });

  await page.click('.chip[data-mode="koten"]');
  await answerCurrent(page, true);
  info = (await page.evaluate(() => window.__calls)).answer.slice(-1)[0].info;
  expect(info.question_choices).toBeNull();
  expect(page.errors).toEqual([]);
});

test('② 正解ははんこ、誤答はバッジ（対象は #card）', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL);
  await page.click('.chip[data-mode="koten"]');
  await answerCurrent(page);
  await page.click('#nextBtn');
  await answerCurrent(page, true);
  const calls = await page.evaluate(() => window.__calls);
  expect(calls.correct).toEqual(['#card']);
  expect(calls.incorrect).toEqual(['#card']);
  expect(calls.answer.map((c) => c.ok)).toEqual([true, false]);
  expect(page.errors).toEqual([]);
});

test('③ 同じ種からは同じ問題が作られる', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL);
  const same = await page.evaluate(() => {
    const out = [];
    for (const m of ['hantei', 'zoukaryo', 'yomitori', 'tooru', 'shikiadv', 'koten', 'menseki']) {
      // @ts-ignore
      const seed = rngPick();
      // @ts-ignore
      rngSeed(seed); const a = JSON.stringify(GENS[m]().qHtml);
      // @ts-ignore
      rngSeed(seed); const b = JSON.stringify(GENS[m]().qHtml);
      out.push([m, a === b]);
    }
    return out;
  });
  for (const [m, eq] of same) expect(eq, m).toBe(true);
  expect(page.errors).toEqual([]);
});

test('③ ?retry=1 は保存した種で同じ問題を出し直す', async ({ page }) => {
  const pending = [
    { retry_id: 1, question_key: 'koten', question_params: { m: 'koten', s: 123456789 }, replay: null,
      params_hash: 'x'.repeat(64), wrong_count: 1, correct_streak: 0 },
    { retry_id: 2, question_key: 'tooru', question_params: { m: 'tooru', s: 987654321 }, replay: null,
      params_hash: 'y'.repeat(64), wrong_count: 2, correct_streak: 1 },
  ];
  await stubDivp(page, pending);
  await page.goto(TOOL + '?retry=1');

  // 1問目は pending の先頭。札に進み具合が出る
  await expect(page.locator('#qTag')).toHaveText('解き直し 1 / 2 ・ 交点を求める');
  // いま出ている問題文が、同じ種で作り直したものと1文字も違わない（=まったく同じ問題）
  const same = await page.evaluate(() => {
    // @ts-ignore
    const shown = cur.qHtml;
    // @ts-ignore
    rngSeed(123456789);
    // @ts-ignore
    return shown === GENS.koten().qHtml;
  });
  expect(same).toBe(true);

  // 答えると同じ question_key / params で飛ぶ（params_hash が一致＝2連続正解でmastered）
  await answerCurrent(page);
  let calls = await page.evaluate(() => window.__calls);
  expect(calls.answer[0].info.question_key).toBe('koten');
  expect(calls.answer[0].info.question_params).toEqual({ m: 'koten', s: 123456789 });

  // 2問目 → 使い切ったら通常出題に戻る
  await page.click('#nextBtn');
  await expect(page.locator('#qTag')).toHaveText('解き直し 2 / 2 ・ 通る座標');
  await answerCurrent(page);
  await page.click('#nextBtn');
  await expect(page.locator('#retryBanner')).toBeVisible();
  await expect(page.locator('#qTag')).toBeHidden();
  calls = await page.evaluate(() => window.__calls);
  expect(calls.answer.map((c) => c.info.question_key)).toEqual(['koten', 'tooru']);
  expect(page.errors).toEqual([]);
});

test('③ pending が無ければ案内だけ出して通常出題を続ける', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL + '?retry=1');
  await expect(page.locator('#retryBanner')).toContainText('まだありません');
  await expect(page.locator('#checkBtn')).toBeVisible();
  expect(page.errors).toEqual([]);
});

test('① ミックス出題でも記録は「その問題の種類」で残る（mix は飛ばさない）', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL);
  await page.click('.chip.mix');
  await page.click('#mixNone');
  await page.check('#mixList input[data-mode="koten"]');
  await answerCurrent(page);
  const info = (await page.evaluate(() => window.__calls)).answer[0].info;
  expect(info.question_key).toBe("koten");
  expect(info.question_params.m).toBe("koten");
  expect(page.errors).toEqual([]);
});

/* XPはサーバー(save_answer.php)が確定し、レベルはマイページが累計から算出する。
   ツール側に独自のXP・レベルを持たせない（同じ生徒に2つの数字が出てしまう）。 */
test('① 独自のレベル・XPを持たない（HUDにも端末保存にも出さない）', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL);
  await expect(page.locator('#lvVal')).toHaveCount(0);
  await expect(page.locator('#xpVal')).toHaveCount(0);
  await expect(page.locator('.stats')).not.toContainText('XP');
  await expect(page.locator('.stats')).not.toContainText('LEVEL');
  await expect(page.locator('#rankVal')).toBeVisible();        // RANK と連続正解は残す

  await answerCurrent(page);
  const keys = await page.evaluate(() => Object.keys(localStorage));
  // 端末に残すのはミックスの選択だけ（XPの保存キーを作らない）
  expect(keys.filter((k) => /xp|_v1$/i.test(k))).toEqual([]);
  expect(page.errors).toEqual([]);
});

test('設定パネルはとじられる（初期は開いている）', async ({ page }) => {
  await stubDivp(page, []);
  await page.goto(TOOL);
  await page.click('.chip.mix');
  await expect(page.locator('#mixBody')).toBeVisible();
  await expect(page.locator('#mixFold')).toHaveText('とじる');

  await page.click('#mixFold');
  await expect(page.locator('#mixBody')).toBeHidden();
  await expect(page.locator('#mixFold')).toHaveText('ひらく');
  await expect(page.locator('#mixCount')).toBeVisible();      // 種類数は畳んでも見える
  await expect(page.locator('#mixNone')).toBeHidden();        // 見えない選択を変えるボタンは隠す

  await page.click('#mixFold');
  await expect(page.locator('#mixBody')).toBeVisible();
  // 開き直すと初期状態に戻る
  await page.reload();
  await page.click('.chip.mix');
  await expect(page.locator('#mixBody')).toBeVisible();
  expect(page.errors).toEqual([]);
});
