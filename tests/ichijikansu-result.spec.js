// @ts-check
// 一次関数マスターの「10問ごとの評価（RESULT画面 / divp-result.js）」の回帰テスト。
//
// 守りたい仕様:
//   ・10問解くと「次の問題」が「採点結果へ」に変わり、押すと結果カードが出る
//     （解説の上にかぶせないよう、10問目を解いた瞬間には出さない）
//   ・「つぎの10問へ」で次のラウンドが始まり、札が 1 / 10 に戻る
//   ・「まちがえた N 問を解き直す」は同じ種＝同じ問題を出す（ランクは付けない）
//   ・種類をえらび直したらラウンドは数え直し
const { test, expect } = require('@playwright/test');

const TOOL = '/learning/math/math_js2_ichijikansu.html';
const CARD = '.divp-result-ov';

/* ログイン済みの生徒として開く。
   （未ログインだと10問でお試し上限の暗幕が出て結果カードまで進めない＝仕様どおり） */
async function openLoggedIn(page) {
  await page.route('**/api/start_session.php', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, session_id: 1 }) }));
  await page.goto(TOOL);
  await page.click('.chip[data-mode="koten"]');     // 必ず6択になる種類
}

/* n問答える。wrongAt に入れた番号(1始まり)だけわざと誤答する */
async function answerN(page, n, wrongAt) {
  for (let i = 1; i <= n; i++) {
    await page.evaluate((bad) => {
      const btns = Array.from(document.querySelectorAll('#ansArea .choiceBtn'));
      // @ts-ignore ツール内のグローバル
      const c = cur.ans.correct;
      // @ts-ignore わざと誤答した問題文を覚えておく（解き直しで同じ問題か確かめる）
      if (bad) window.__wrongQ = cur.qHtml;
      btns[bad ? (c === 0 ? 1 : 0) : c].click();
      document.getElementById('checkBtn').click();
    }, i === wrongAt);
    if (i < n) await page.click('#nextBtn');
  }
}

test.beforeEach(async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.errors = errors;
});

test('10問解くと「採点結果へ」→ ランク付きの結果カードが出る', async ({ page }) => {
  await openLoggedIn(page);
  await expect(page.locator('#qTag')).toHaveText('1 / 10 問目');

  await answerN(page, 10);
  // 10問目を解いた時点ではまだカードを出さない（解説を隠さない）
  await expect(page.locator(CARD)).toHaveCount(0);
  await expect(page.locator('#nextBtn')).toHaveText('採点結果へ ➜');

  await page.click('#nextBtn');
  await expect(page.locator(CARD)).toBeVisible();
  await expect(page.locator(CARD)).toContainText('10');
  await expect(page.locator('.divp-result-grade')).toHaveText('S');   // 全問正解
  await expect(page.locator('.divp-result-btn', { hasText: 'つぎの10問へ' })).toBeVisible();
  // 全問正解なので「まちがえた N 問」のボタンは出ない
  await expect(page.locator('.divp-result-btn', { hasText: 'まちがえた' })).toHaveCount(0);

  await page.click('.divp-result-btn:has-text("つぎの10問へ")');
  await expect(page.locator(CARD)).toHaveCount(0);
  await expect(page.locator('#qTag')).toHaveText('1 / 10 問目');      // ラウンドは数え直し
  await expect(page.locator('#ansArea .choiceBtn').first()).toBeVisible();
  expect(page.errors).toEqual([]);
});

test('「まちがえた 1 問を解き直す」は同じ問題を出す', async ({ page }) => {
  await openLoggedIn(page);
  // 3問目だけ誤答して10問終える
  await answerN(page, 10, 3);
  await page.click('#nextBtn');
  await expect(page.locator(CARD)).toBeVisible();
  await expect(page.locator('.divp-result-btn', { hasText: 'まちがえた 1 問' })).toBeVisible();

  await page.click('.divp-result-btn:has-text("まちがえた 1 問")');
  await expect(page.locator('#qTag')).toHaveText('まちがえ直し 1 / 1 ・ 交点を求める');
  // 誤答した問題と同じ種＝同じ問題文
  const same = await page.evaluate(() => cur.qHtml === window.__wrongQ);
  expect(same).toBe(true);

  // 1問だけのラウンドなのでランクは付けず「解き直しおつかれさま！」
  await answerN(page, 1);
  await page.click('#nextBtn');
  await expect(page.locator(CARD)).toBeVisible();
  await expect(page.locator(CARD)).toContainText('解き直しおつかれさま');
  await expect(page.locator('.divp-result-grade')).toHaveCount(0);
  expect(page.errors).toEqual([]);
});

test('種類をえらび直すとラウンドは数え直し', async ({ page }) => {
  await openLoggedIn(page);
  await answerN(page, 3);
  await page.click('#nextBtn');
  await expect(page.locator('#qTag')).toHaveText('4 / 10 問目');

  await page.click('.chip[data-mode="tokucho"]');
  await expect(page.locator('#qTag')).toHaveText('1 / 10 問目');
  expect(page.errors).toEqual([]);
});
