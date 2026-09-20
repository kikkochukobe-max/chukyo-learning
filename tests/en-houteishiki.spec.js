// @ts-check
// 円と方程式マスター（math_hs_en_houteishiki）の回帰テスト。
//
// 守りたい仕様:
//   ・10タイプ×2レベルが例外なく出題でき、答え合わせまで通る
//   ・図モードが3種類とも描ける（SVG が出て、判定の文が出る）
//   ・パラメータを端まで動かしても図が壊れない（接する・離れる・重なるを含む）
//   ・解説の「図で見る」から、その問題の数のまま図モードへ飛べる
//   ・選択肢に**生の「<」を入れない**（api/save_answer.php が
//     question_choices を丸ごと捨てるため。不等号は TeX の \lt で書く）
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TOOL = '/learning/math/math_hs_en_houteishiki.html';
const MODES = ['houteishiki','ippankei','tsukuru','chokusen','kyorigen',
               'sessen_jou','sessen_gai','nien','nien_kouten','houbutsu'];

/** コンソールエラーを拾う（数式や図の組み立てが黙って失敗するのを防ぐ） */
function watchErrors(page) {
  const errs = [];
  page.on('pageerror', (e) => errs.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  return errs;
}

/** いま出ている問題に正解して答え合わせまで進める */
async function answerCorrectly(page) {
  return page.evaluate(() => {
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
}

test.describe('円と方程式マスター', () => {
  test('10タイプ×2レベルが出題でき、答え合わせが通る', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    for (const m of MODES) {
      for (const lv of [1, 2]) {
        await page.click(`#typeChips .chip[data-mode="${m}"]`);
        await page.click(`#lvSeg .lvbtn[data-lv="${lv}"]`);
        await expect(page.locator('#qLead')).not.toBeEmpty();
        const cls = await answerCorrectly(page);
        expect(cls, `${m} lv${lv}`).toContain('ok');
        await expect(page.locator('#explain')).toBeVisible();
        await page.click('#nextBtn');
      }
    }
    expect(errs).toEqual([]);
  });

  test('数式が KaTeX で描かれる（生の TeX が残らない）', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    for (const m of MODES) {
      await page.click(`#typeChips .chip[data-mode="${m}"]`);
      // 問題文・選択肢の .tex がすべて描画済みになっていること
      const raw = await page.evaluate(() => {
        const out = [];
        document.querySelectorAll('#card .tex').forEach((el) => {
          if (!el.querySelector('.katex')) out.push(el.getAttribute('data-tex'));
        });
        return out;
      });
      expect(raw, m).toEqual([]);
    }
    expect(errs).toEqual([]);
  });

  test('選択肢に生の「<」が入らない（save_answer が捨てるため）', async ({ page }) => {
    await page.goto(TOOL);
    const bad = [];
    for (const m of MODES) {
      for (const lv of [1, 2]) {
        await page.click(`#typeChips .chip[data-mode="${m}"]`);
        await page.click(`#lvSeg .lvbtn[data-lv="${lv}"]`);
        for (let i = 0; i < 12; i++) {
          const hit = await page.evaluate(() => {
            // @ts-ignore
            const a = cur.ans;
            if (a.kind !== 'choice') return null;
            const ng = a.choices.filter((c) => String(c).indexOf('<') >= 0);
            // @ts-ignore
            newQuestion();
            return ng.length ? ng[0] : null;
          });
          if (hit) bad.push(`${m} lv${lv}: ${hit}`);
        }
      }
    }
    expect(bad).toEqual([]);
  });

  /* 講師ページ・解き直しプリントに出る3つ（question_text / correct_answer / 選択肢）を
     teacher.php のレンダラに通して確かめる。CLAUDE.md 2a のとおり、
     ・question_text は和文が混ざるので **KaTeX に回してはいけない**（斜体で崩れる）
     ・純粋な式の答え・選択肢は KaTeX で描かれてほしい（生のバックスラッシュを残さない）
     ⚠ renderMathToHTML は mypage.php / retry.php / teacher.php の3つにコピーがある。 */
  test('記録される文字列が講師ページのレンダラで正しく描かれる', async ({ page, browser }) => {
    await page.goto(TOOL);
    const samples = await page.evaluate((modes) => {
      const out = [];
      for (const m of modes) {
        for (const lv of [1, 2]) {
          for (let i = 0; i < 6; i++) {
            // @ts-ignore ツール内のグローバル
            rngSeed(rngPick());
            // @ts-ignore
            const q = GENS[m](lv);
            const a = q.ans;
            const cor = q.plainA || (a.kind === 'choice' ? a.choices[a.correct]
                                                         : String(a.val) + (a.unit || ''));
            out.push({
              m: m,
              t: '【レベル' + lv + '】' + q.plainQ,
              a: cor,
              ch: (a.kind === 'choice') ? a.choices : [],
            });
          }
        }
      }
      return out;
    }, MODES);

    const src = fs.readFileSync(path.resolve(__dirname, '..', 'teacher.php'), 'utf8');
    const start = src.indexOf('function _mescape');
    const end = src.indexOf("document.querySelectorAll('.math')");
    expect(start).toBeGreaterThan(0);
    expect(end).toBeGreaterThan(start);
    const code = src.slice(start, end);

    const p2 = await browser.newPage();
    await p2.setContent('<div id="o"></div>');
    await p2.addScriptTag({ url: 'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js' });
    await p2.addScriptTag({ content: code });

    const bad = await p2.evaluate((rows) => {
      const out = [];
      const jp = /[ぁ-んァ-ヶ一-龠]/;
      const box = document.getElementById('o');
      /* 和文が数式(KaTeX)の中に入っていないかを DOM で見る。
         レンダラは「√や²の混じった和文」をトークン単位で描くので、
         .katex が出ること自体は正しい。まずいのは**和文ごと**式にされた場合。 */
      const jpInsideMath = (h) => {
        box.innerHTML = h;
        return Array.from(box.querySelectorAll('.katex')).some((el) => jp.test(el.textContent || ''));
      };
      for (const r of rows) {
        for (const [kind, s] of [['q', r.t], ['a', r.a]].concat(r.ch.map((c) => ['ch', c]))) {
          if (!s) continue;
          let h;
          try { h = renderMathToHTML(s); } catch (e) { out.push([r.m, kind, s, 'THROW ' + e.message]); continue; }
          if (/undefined|NaN/.test(h)) out.push([r.m, kind, s, 'undefined/NaN']);
          if (/F\(|SYS\(/.test(h)) out.push([r.m, kind, s, 'マーカーが生で残った']);
          if (jp.test(s) && jpInsideMath(h)) out.push([r.m, kind, s, '和文が数式の中に入った（斜体で崩れる）']);
          if (!jp.test(s) && /[\\^_]/.test(s) && !/katex/.test(h)) {
            out.push([r.m, kind, s, '式がKaTeXで描かれない（生のTeXが紙に出る）']);
          }
          // 素のバックスラッシュが紙に出ていないこと
          // ⚠ KaTeX は .katex-mathml の中に元のTeXを annotation として残すので、
          //   そこを取り除いてから「目に見える文字」だけを調べる
          box.innerHTML = h;
          box.querySelectorAll('.katex-mathml').forEach((el) => el.remove());
          if ((box.textContent || '').indexOf('\\') >= 0) {
            out.push([r.m, kind, s, 'バックスラッシュが生のまま残った']);
          }
        }
      }
      return out.slice(0, 20);
    }, samples);
    expect(bad).toEqual([]);
    await p2.close();
  });

  /* ②一般形の標準レベルは「分数の中心」と「円を表す p の範囲」の2本立て。
     片方が出なくなっても画面上は普通に動いてしまうので、両方出ることを見張る。
     範囲の答えは半径の2乗が正になる区間と一致しているはず（境界は等号を含まない）。 */
  test('一般形 標準レベルに「円を表す p の範囲」が出る', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    await page.click('#typeChips .chip[data-mode="ippankei"]');
    await page.click('#lvSeg .lvbtn[data-lv="2"]');
    const seen = await page.evaluate(() => {
      const out = { range: 0, frac: 0, lin: 0, quad: 0, badEq: [] };
      for (let i = 0; i < 120; i++) {
        // @ts-ignore ツール内のグローバル
        newQuestion();
        // @ts-ignore
        const q = cur;
        if (/が円を表すような/.test(q.plainQ)) {
          out.range++;
          if (/-2px/.test(q.plainQ)) out.quad++; else out.lin++;
          // 答えは p の範囲。等号（≦）を含んでいたら誤り（半径0の1点が混ざる）
          const a = q.ans.choices[q.ans.correct];
          if (a.indexOf('\\leqq') >= 0) out.badEq.push(q.plainQ + ' → ' + a);
        } else out.frac++;
      }
      return out;
    });
    expect(seen.range, '範囲問題が出ない').toBeGreaterThan(10);
    expect(seen.frac, '分数の中心の問題が出ない').toBeGreaterThan(10);
    expect(seen.lin, '1次型（右辺が p の1次式）が出ない').toBeGreaterThan(0);
    expect(seen.quad, '2次型（-2px の形）が出ない').toBeGreaterThan(0);
    expect(seen.badEq).toEqual([]);
    expect(errs).toEqual([]);
  });

  test('図モードが3種類とも描ける', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    await page.click('.vtab[data-view="fig"]');
    for (const f of ['line', 'two', 'para']) {
      await page.click(`#figChips .chip[data-fig="${f}"]`);
      await expect(page.locator('#figSvg svg')).toBeVisible();
      await expect(page.locator('#figJudge')).not.toBeEmpty();
      // ＋ボタンを端まで押しても壊れないこと
      const btns = await page.locator('#figCtrl .sbtn[data-dir="1"]').count();
      for (let i = 0; i < btns; i++) {
        for (let n = 0; n < 18; n++) {
          await page.locator('#figCtrl .sbtn[data-dir="1"]').nth(i).click();
        }
      }
      await expect(page.locator('#figSvg svg')).toBeVisible();
      // −ボタンも端まで
      for (let i = 0; i < btns; i++) {
        for (let n = 0; n < 24; n++) {
          await page.locator('#figCtrl .sbtn[data-dir="-1"]').nth(i).click();
        }
      }
      await expect(page.locator('#figSvg svg')).toBeVisible();
      await expect(page.locator('#figJudge')).not.toBeEmpty();
    }
    expect(errs).toEqual([]);
  });

  test('解説の「図で見る」から図モードへ飛べる', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    // 直線との位置関係 lv1 は図リンクが出る問題があるので、出るまで引き直す
    await page.click('#typeChips .chip[data-mode="chokusen"]');
    await page.click('#lvSeg .lvbtn[data-lv="1"]');
    let found = false;
    for (let i = 0; i < 40 && !found; i++) {
      await answerCorrectly(page);
      found = await page.locator('#exBody [data-fig]').count() > 0;
      // 10問目で採点結果のオーバーレイが出るとボタンが隠れるので、JS 側で押す
      if (!found) await page.evaluate(() => document.getElementById('nextBtn').click());
    }
    expect(found, '図リンクの出る問題が40問以内に出ない').toBe(true);
    await page.click('#exBody [data-fig]');
    await expect(page.locator('#view-fig')).toBeVisible();
    await expect(page.locator('#figSvg svg')).toBeVisible();
    expect(errs).toEqual([]);
  });

  /* 練習プリント。別ウィンドウを開く前の「10問ぶんのHTMLを組む」ところまでを確かめる
     （印刷ダイアログは自動では閉じられないので window.open までは行かせない）。
     数式は親ウィンドウの KaTeX で焼いてから流し込む作りなので、
     生の TeX が紙に出ていないことをここで見張る。 */
  test('練習プリントが10問ぶん組める（数式が焼けている）', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    for (const m of MODES) {
      await page.click(`#typeChips .chip[data-mode="${m}"]`);
      const r = await page.evaluate(() => {
        // @ts-ignore ツール内のグローバル
        const items = prtPick(PRT_N);
        // @ts-ignore
        const html = prtHtml(items);
        const d = document.createElement('div');
        // 子ウィンドウに渡す前の本体だけを見る
        d.innerHTML = html.slice(html.indexOf('</head>'));
        d.querySelectorAll('.katex-mathml').forEach((el) => el.remove());
        return { n: items.length, raw: (d.textContent || '').indexOf('\\') >= 0,
                 probs: d.querySelectorAll('.p').length, answers: d.querySelectorAll('.a').length };
      });
      expect(r.n, `${m}: 問題が10問そろわない`).toBe(10);
      expect(r.probs, `${m}: 問題欄`).toBe(10);
      expect(r.answers, `${m}: 解答欄`).toBe(10);
      expect(r.raw, `${m}: 生のTeXが紙に出る`).toBe(false);
    }
    expect(errs).toEqual([]);
  });

  test('10問解くと採点結果が出る', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    await page.click('#typeChips .chip[data-mode="houteishiki"]');
    for (let i = 0; i < 10; i++) {
      await answerCorrectly(page);
      await page.click('#nextBtn');
    }
    // divp-result のオーバーレイが出ていること
    await expect(page.locator('.divp-result')).toBeVisible();
    expect(errs).toEqual([]);
  });
});
