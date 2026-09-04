// @ts-check
// 数列完全マスター（math_hs_suuretsu）の回帰テスト。
// 守りたい仕様:
//   ・12タイプ×3レベルすべてが描画され、選択式・テンキーの両方で採点できる
//   ・種によって枝分かれする出題を多数描いても KaTeX が警告を出さない
//     （TeX に和文や ①② を裸で入れると警告になり、和文が斜体の数式で組まれる）
//   ・誤答時の答え合わせ表示（divp-choice-mark）が正解=緑・選んだ誤答=朱になる
//   ・10問で「採点結果へ」→ 結果カード（divp-result）
//   ・証明モード8本を全ステップ送れて、解説の「証明を見る」から該当の証明へ飛べる
const { test, expect } = require('@playwright/test');

const TOOL = '/learning/math/math_hs_suuretsu.html';

/* ログイン済みの生徒として開く
   （未ログインだと10問でお試し上限の暗幕が出て結果カードまで進めない＝仕様どおり） */
async function open(page, errors) {
  page.on('pageerror', (e) => errors.push('pageerror: ' + e));
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') errors.push(m.type() + ': ' + m.text()); });
  await page.route('**/api/start_session.php', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, session_id: 1 }) }));
  await page.goto(TOOL);
  await page.waitForFunction(() => !!window.katex);
}

test('12タイプ×3レベルすべてが描画され、答えられる', async ({ page }) => {
  const errors = [];
  await open(page, errors);

  const keys = await page.evaluate(() => MODES.map((m) => m.k));
  expect(keys.length).toBe(12);

  for (const k of keys) {
    for (const lv of [1, 2, 3]) {
      await page.click(`.chip[data-mode="${k}"]`);
      await page.click(`.lvbtn[data-lv="${lv}"]`);
      // 問題文とタイプ札が出ている
      await expect(page.locator('#qLead')).not.toBeEmpty();
      await expect(page.locator('#qLv')).toHaveText('LV.' + lv);
      // 解答欄は6択かテンキーのどちらか
      const kind = await page.evaluate(() => cur.ans.kind);
      if (kind === 'choice') {
        const n = await page.locator('#ansArea .choiceBtn').count();
        expect(n, `${k} lv${lv} の選択肢の数`).toBeGreaterThanOrEqual(5);
      } else {
        await expect(page.locator('#keypad')).toBeVisible();
      }
      // 正解して採点まで通す
      await page.evaluate(() => {
        if (cur.ans.kind === 'choice') {
          document.querySelectorAll('#ansArea .choiceBtn')[cur.ans.correct].click();
        } else {
          const v = String(cur.ans.val);
          for (const ch of v) document.querySelector(`#keypad .key[data-k="${ch === '-' ? '-' : ch}"]`).click();
        }
      });
      await page.click('#checkBtn');
      await expect(page.locator('#verdict'), `${k} lv${lv} の判定`).toHaveText('正解');
      await expect(page.locator('#explain')).toBeVisible();
      // 解説に数式が組まれている（KaTeXが落ちていない）
      expect(await page.locator('#exBody .katex').count(), `${k} lv${lv} の解説の数式`).toBeGreaterThan(0);
    }
  }
  expect(errors, 'コンソールエラー').toEqual([]);
});

test('全タイプ×全レベル×多数の種を描いてもKaTeXが警告を出さない', async ({ page }) => {
  const errors = [];
  await open(page, errors);
  // レベル3は種によって出題の型が枝分かれするので、1通りだけでは踏み抜けない。
  // 問題文と解説の両方を描かせる（TeXの大半は解説側にある）。
  const n = await page.evaluate((reps) => {
    let count = 0;
    for (const k of MODES.map((m) => m.k)) {
      for (const L of [1, 2, 3]) {
        mode = k; lv = L;
        for (let i = 0; i < reps; i++) {
          newQuestion();
          if (cur.ans.kind === 'choice') selChoice = cur.ans.correct;
          else numStr = String(cur.ans.val);
          check();                       // 判定 → 解説を描く
          count++;
        }
      }
    }
    return count;
  }, 12);
  expect(n).toBe(12 * 3 * 12);
  expect(errors, 'KaTeXの警告・コンソールエラー').toEqual([]);
});

test('誤答すると正解が緑・選んだ誤答が朱になる（divp-choice-mark）', async ({ page }) => {
  const errors = [];
  await open(page, errors);
  await page.click('.chip[data-mode="touhi"]');
  await page.click('.lvbtn[data-lv="2"]');       // 必ず6択になるレベル
  await page.evaluate(() => {
    const c = cur.ans.correct;
    document.querySelectorAll('#ansArea .choiceBtn')[c === 0 ? 1 : 0].click();
  });
  await page.click('#checkBtn');
  await expect(page.locator('#verdict')).toContainText('正解は');
  expect(await page.locator('#ansArea .choiceBtn[data-divp-mark="answer"]').count()).toBe(1);
  expect(await page.locator('#ansArea .choiceBtn[data-divp-mark="wrong"]').count()).toBe(1);
  expect(errors).toEqual([]);
});

test('10問解くと「採点結果へ」→ 結果カードが出る', async ({ page }) => {
  const errors = [];
  await open(page, errors);
  await page.click('.chip[data-mode="touhi"]');
  await page.click('.lvbtn[data-lv="1"]');
  await expect(page.locator('#qNum')).toHaveText('1 / 10');

  for (let i = 1; i <= 10; i++) {
    await page.evaluate(() => {
      const v = String(cur.ans.val);
      for (const ch of v) document.querySelector(`#keypad .key[data-k="${ch}"]`).click();
      document.getElementById('checkBtn').click();
    });
    if (i < 10) await page.click('#nextBtn');
  }
  await expect(page.locator('#nextBtn')).toContainText('採点結果へ');
  await page.click('#nextBtn');
  await expect(page.locator('.divp-result-ov')).toBeVisible();
  expect(errors).toEqual([]);
});

test('証明モード: 8本すべてを最後のステップまで送れる', async ({ page }) => {
  const errors = [];
  await open(page, errors);
  await page.click('.vtab[data-view="proof"]');
  const ids = await page.evaluate(() => PROOFS.map((p) => p.id));
  expect(ids.length).toBe(8);

  for (const id of ids) {
    await page.click(`.pcard[data-proof="${id}"]`);
    const steps = await page.evaluate((x) => PROOFMAP[x].steps.length, id);
    for (let s = 0; s < steps; s++) {
      await expect(page.locator('#pvNo')).toHaveText(`STEP ${s + 1} / ${steps}`);
      await expect(page.locator('#pvTxt')).not.toBeEmpty();
      // ステージが実際に描かれている（SVG か 式の行）
      expect(await page.locator('#pvStage svg, #pvStage .mrows').count(), id + ' のステージ').toBeGreaterThan(0);
      if (s < steps - 1) await page.click('#pvNext');
    }
    // 最終ステップのボタンは「最初から」
    await expect(page.locator('#pvNext')).toContainText('最初から');
    await page.click('#pvBack');
  }
  expect(errors).toEqual([]);
});

test('解説の「証明を見る」から該当する証明が開く', async ({ page }) => {
  const errors = [];
  await open(page, errors);
  await page.click('.chip[data-mode="touhiwa"]');
  await page.click('.lvbtn[data-lv="1"]');
  await page.evaluate(() => {
    const v = String(cur.ans.val);
    for (const ch of v) document.querySelector(`#keypad .key[data-k="${ch === '-' ? '-' : ch}"]`).click();
    document.getElementById('checkBtn').click();
  });
  await page.click('#exBody .proofLink');
  await expect(page.locator('#pview')).toBeVisible();
  await expect(page.locator('#pvTtl')).toContainText('等差数列の和');
  expect(errors).toEqual([]);
});
