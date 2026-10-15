// @ts-check
// 2次不等式マスター（math_hs_niji_futoushiki）の回帰テスト。
//
// 守りたい仕様:
//   ・11タイプ（レベル数はタイプごとに2〜3）が例外なく出題でき、答え合わせまで通る
//   ・**答えそのものが正しい**。各問題が持つ chk()（解の集合を、もとの不等式を数値で
//     直接調べた結果と突き合わせる）が全タイプ×全レベルで空文字を返す
//   ・選択肢は4つ以上・重複なし・生の「<」なし（api/save_answer.php が question_choices を
//     丸ごと捨てる）。和文を含む選択肢に TeX 記号を混ぜない
//   ・記録に送る文字列がサーバーの上限（問題文255バイト・答え100バイト）に収まる
//   ・同じ種(seed)から同じ問題が出る（解き直しの前提。CLAUDE.md 2d）
//   ・記録される文字列が講師ページのレンダラで崩れない（CLAUDE.md 2a）
//   ・図モードが4種類とも描ける／練習プリントで KaTeX の選択肢が崩れない
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TOOL = '/learning/math/math_hs_niji_futoushiki.html';
// [question_key, レベル数]
const MODES = [
  ['inshi', 3], ['koushiki', 3], ['tokushu', 3], ['renritsu', 3],
  ['kettei', 3], ['hanbetsu', 3], ['tsuneni', 3], ['haichi', 3],
  ['bunsho', 2], ['zettai', 2], ['moji', 3],
];

/** コンソールエラーを拾う（数式や図の組み立てが黙って失敗するのを防ぐ） */
function watchErrors(page) {
  const errs = [];
  page.on('pageerror', (e) => errs.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  return errs;
}

/** いま出ている問題に答えて答え合わせまで進める（ok=false なら正解の隣を選ぶ） */
async function answer(page, ok) {
  return page.evaluate((ok) => {
    // @ts-ignore ツール内のグローバル
    const a = cur.ans;
    if (a.kind === 'choice') {
      const b = document.querySelectorAll('#ansArea .choiceBtn');
      if (b.length < 4) return 'choices:' + b.length;
      const i = ok ? a.correct : (a.correct + 1) % b.length;
      /** @type {HTMLElement} */ (b[i]).click();
    } else {
      const s = String(ok ? a.val : a.val + 1);
      for (const ch of s) {
        /** @type {HTMLElement} */ (document.querySelector(`#keypad .key[data-k="${ch}"]`)).click();
      }
    }
    /** @type {HTMLElement} */ (document.getElementById('checkBtn')).click();
    return document.getElementById('verdict').className;
  }, ok);
}

test.describe('2次不等式マスター', () => {
  test('全タイプ×レベルが出題でき、答え合わせが通る', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    for (const [m, n] of MODES) {
      for (let lv = 1; lv <= n; lv++) {
        await page.click(`#typeChips .chip[data-mode="${m}"]`);
        await page.click(`#lvSeg .lvbtn[data-lv="${lv}"]`);
        await expect(page.locator('#qLv')).toHaveText(`LV.${lv}`);
        expect(await answer(page, true), `${m} lv${lv} 正解`).toContain('ok');
        await expect(page.locator('#explain')).toBeVisible();
        await page.click('#nextBtn');
        expect(await answer(page, false), `${m} lv${lv} 誤答`).toContain('ng');
        await page.click('#nextBtn');
      }
    }
    expect(errs).toEqual([]);
  });

  test('発展を持たないタイプでは LEVEL 3 が押せない', async ({ page }) => {
    await page.goto(TOOL);
    for (const [m, n] of MODES) {
      await page.click(`#typeChips .chip[data-mode="${m}"]`);
      const disabled = await page.locator('#lvSeg .lvbtn[data-lv="3"]').isDisabled();
      expect(disabled, `${m} のLV3`).toBe(n < 3);
    }
    await page.click('#typeChips .chip[data-mode="inshi"]');
    await page.click('#lvSeg .lvbtn[data-lv="3"]');
    await page.click('#typeChips .chip[data-mode="zettai"]');
    await expect(page.locator('#qLv')).toHaveText('LV.2');
  });

  /* いちばん大事なテスト。答えは「集合」として作っていて、chk() はその集合を
     もとの不等式（判別式・解の配置・整数解の個数なら、その条件そのもの）を数値で
     直接調べた結果と、刻み 0.01 の全点と端点の両側で突き合わせる。
     生成関数のどこかで符号や「＝」の付け方を1つまちがえると、ここで落ちる。 */
  test('答えが正しい（全タイプ×全レベル×各80問の検算）', async ({ page }) => {
    await page.goto(TOOL);
    const bad = await page.evaluate((modes) => {
      const out = [];
      for (const [m, n] of modes) {
        for (let lv = 1; lv <= n; lv++) {
          for (let i = 0; i < 80; i++) {
            // @ts-ignore ツール内のグローバル
            const s = rngPick(); rngSeed(s);
            // @ts-ignore
            const q = GENS[m](lv);
            const e = q.chk ? q.chk() : 'chk が無い';
            if (e) out.push(`${m} lv${lv} seed${s}: ${e} / ${q.plainQ}`);
          }
        }
      }
      return out.slice(0, 10);
    }, MODES);
    expect(bad).toEqual([]);
  });

  test('選択肢・記録用の文字列の決まりを守る', async ({ page }) => {
    await page.goto(TOOL);
    const bad = await page.evaluate((modes) => {
      const out = [];
      const enc = new TextEncoder();
      const B = (s) => enc.encode(String(s)).length;
      for (const [m, n] of modes) {
        for (let lv = 1; lv <= n; lv++) {
          for (let i = 0; i < 60; i++) {
            // @ts-ignore
            rngSeed(rngPick()); const q = GENS[m](lv);
            const a = q.ans;
            const qt = '【レベル' + lv + '】' + q.plainQ;
            if (/[\\^_{}]/.test(qt)) out.push(`${m}${lv} 問題文にTeX記号: ${qt}`);
            if (B(qt) > 255) out.push(`${m}${lv} 問題文が255バイト超: ${B(qt)}`);
            const cor = q.plainA || (a.kind === 'choice' ? a.choices[a.correct] : String(a.val) + (a.unit || ''));
            if (B(cor) > 100) out.push(`${m}${lv} 正解が100バイト超: ${cor}`);
            if (a.kind !== 'choice') continue;
            if (a.choices.length < 4) out.push(`${m}${lv} 選択肢が${a.choices.length}個: ${qt}`);
            if (new Set(a.choices).size !== a.choices.length) out.push(`${m}${lv} 選択肢が重複`);
            for (const c of a.choices) {
              if (c.indexOf('<') >= 0) out.push(`${m}${lv} 生の < : ${c}`);
              // 非ASCIIを含む＝平文。平文に TeX 記号を混ぜない
              if (/[^\x00-\x7F]/.test(c) && /[\\^_{}]/.test(c)) out.push(`${m}${lv} 和文にTeX: ${c}`);
              if (B(c) > 100) out.push(`${m}${lv} 選択肢が100バイト超: ${c}`);
            }
          }
        }
      }
      return out.slice(0, 15);
    }, MODES);
    expect(bad).toEqual([]);
  });

  test('数式が KaTeX で描かれる（問題・選択肢・解説）', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    for (const [m, n] of MODES) {
      for (let lv = 1; lv <= n; lv++) {
        await page.click(`#typeChips .chip[data-mode="${m}"]`);
        await page.click(`#lvSeg .lvbtn[data-lv="${lv}"]`);
        await answer(page, true);
        const bad = await page.evaluate(() => {
          const out = { raw: [], err: [] };
          document.querySelectorAll('#card .tex').forEach((el) => {
            if (!el.querySelector('.katex')) out.raw.push(el.getAttribute('data-tex'));
            if (el.querySelector('.katex-error')) out.err.push(el.getAttribute('data-tex'));
          });
          return out;
        });
        expect(bad.raw, `${m} lv${lv} 未描画`).toEqual([]);
        expect(bad.err, `${m} lv${lv} KaTeXエラー`).toEqual([]);
      }
    }
    expect(errs).toEqual([]);
  });

  test('同じ種から同じ問題が出る（解き直しの前提）', async ({ page }) => {
    await page.goto(TOOL);
    const bad = await page.evaluate((modes) => {
      const out = [];
      for (const [m, n] of modes) {
        for (let lv = 1; lv <= n; lv++) {
          for (let i = 0; i < 20; i++) {
            // @ts-ignore
            const s = rngPick();
            // @ts-ignore
            rngSeed(s); const q1 = GENS[m](lv);
            // @ts-ignore
            rngSeed(s); const q2 = GENS[m](lv);
            const k = (q) => JSON.stringify([q.lead, q.disp, q.plainQ, q.plainA, q.ans]);
            if (k(q1) !== k(q2)) out.push(`${m} lv${lv} seed${s}`);
          }
        }
      }
      return out.slice(0, 10);
    }, MODES);
    expect(bad).toEqual([]);
  });

  /* 講師ページ・解き直しプリントに出る3つ（question_text / correct_answer / 選択肢）を
     teacher.php のレンダラに通して確かめる（CLAUDE.md 2a）。
     ⚠ renderMathToHTML は mypage.php / retry.php / teacher.php の3つにコピーがある。 */
  test('記録される文字列が講師ページのレンダラで正しく描かれる', async ({ page, browser }) => {
    await page.goto(TOOL);
    const samples = await page.evaluate((modes) => {
      const out = [];
      for (const [m, n] of modes) {
        for (let lv = 1; lv <= n; lv++) {
          for (let i = 0; i < 8; i++) {
            // @ts-ignore
            rngSeed(rngPick()); const q = GENS[m](lv);
            const a = q.ans;
            out.push({
              m,
              t: '【レベル' + lv + '】' + q.plainQ,
              a: q.plainA || (a.kind === 'choice' ? a.choices[a.correct] : String(a.val) + (a.unit || '')),
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
    const p2 = await browser.newPage();
    await p2.setContent('<div id="o"></div>');
    await p2.addScriptTag({ url: 'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js' });
    await p2.addScriptTag({ content: src.slice(start, end) });
    const bad = await p2.evaluate((rows) => {
      const out = [];
      const jp = /[ぁ-んァ-ヶ一-龠]/;
      const box = document.getElementById('o');
      for (const r of rows) {
        for (const [kind, s] of [['q', r.t], ['a', r.a]].concat(r.ch.map((c) => ['ch', c]))) {
          // @ts-ignore teacher.php から切り出した関数
          let h; try { h = renderMathToHTML(s); } catch (e) { out.push([r.m, kind, s, 'THROW']); continue; }
          box.innerHTML = h;
          if (/undefined|NaN/.test(h)) out.push([r.m, kind, s, 'undefined/NaN']);
          if (/F\(|SYS\(/.test(box.textContent || '')) out.push([r.m, kind, s, 'マーカーが生で残った']);
          if (jp.test(s) && Array.from(box.querySelectorAll('.katex')).some((el) => jp.test(el.textContent || ''))) {
            out.push([r.m, kind, s, '和文が数式の中に入った']);
          }
          if (!jp.test(s) && /[\\^_]/.test(s) && !/katex/.test(h)) out.push([r.m, kind, s, 'KaTeXで描かれない']);
          box.querySelectorAll('.katex-mathml').forEach((el) => el.remove());
          if ((box.textContent || '').indexOf('\\') >= 0) out.push([r.m, kind, s, 'バックスラッシュが残った']);
        }
      }
      return out.slice(0, 20);
    }, samples);
    expect(bad).toEqual([]);
    await p2.close();
  });

  test('図モードが4種類とも描ける', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    await page.click('.vtab[data-view="fig"]');
    for (const f of ['kai', 'tsuneni', 'haichi', 'moji']) {
      await page.click(`#figChips .chip[data-fig="${f}"]`);
      await expect(page.locator('#figSvg svg')).toBeVisible();
      const btns = await page.locator('#figCtrl .sbtn[data-dir="1"]').count();
      for (const dir of ['1', '-1']) {
        for (let i = 0; i < btns; i++) {
          for (let n = 0; n < 34; n++) {
            await page.locator(`#figCtrl .sbtn[data-dir="${dir}"]`).nth(i).click();
          }
        }
        const html = await page.locator('#view-fig').innerHTML();
        expect(/NaN|undefined|Infinity|katex-error/.test(html), `${f} dir${dir}`).toBe(false);
      }
    }
    expect(errs).toEqual([]);
  });

  test('解説から図モードへ飛べる', async ({ page }) => {
    await page.goto(TOOL);
    await page.click('#typeChips .chip[data-mode="haichi"]');
    await page.click('#lvSeg .lvbtn[data-lv="1"]');
    await answer(page, true);
    await page.click('#exBody .figLink');
    await expect(page.locator('#view-fig')).toBeVisible();
    await expect(page.locator('#figJudge')).toContainText('2つの解がともに');
  });

  /* 「.ch span{display:flex}」だと KaTeX の内側の span まで flex になり、
     紙の選択肢が縦にばらばらに崩れる（子セレクタ「.ch>span」にしてある） */
  test('練習プリントの選択肢が KaTeX を崩さない', async ({ page }) => {
    await page.goto(TOOL);
    const html = await page.evaluate(() => {
      let h = '';
      // @ts-ignore
      window.open = () => ({ document: { write: (x) => { h += x; }, close() {} }, focus() {}, print() {} });
      // @ts-ignore
      printSheet();
      return h;
    });
    expect(html).toContain('.ch>span{display:flex');
    expect(html).not.toMatch(/\.ch span\{display:flex/);
    expect(html).not.toMatch(/katex-error|NaN|undefined/);
  });
});
