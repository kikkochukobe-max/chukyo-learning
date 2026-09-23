// @ts-check
// 2次関数マスター（math_hs_nijikansu）の回帰テスト。
//
// 守りたい仕様:
//   ・11タイプ（レベル数はタイプごとに2〜3）が例外なく出題でき、答え合わせまで通る
//   ・**和文を TeX に入れない**（KaTeX が「軸」「原点」を描けず赤字になる）
//   ・選択肢に生の「<」を入れない（api/save_answer.php が question_choices を丸ごと捨てる。
//     不等号は全角の「＜」か TeX の \lt で書く）
//   ・発展レベルを持たないタイプでは LEVEL 3 が押せず、選択中のレベルは自動で下がる
//   ・場合分けの答え（⑥動く最大・最小）が、本当の最大・最小と数値で一致する
//   ・同じ種(seed)から同じ問題が出る（解き直しの前提。CLAUDE.md 2d）
//   ・図モードが4種類とも描ける（つまみを端まで動かしても壊れない）
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TOOL = '/learning/math/math_hs_nijikansu.html';
// [question_key, レベル数]
const MODES = [
  ['kansuu', 3], ['heihei', 3], ['heikou', 3], ['taisho', 2],
  ['saidai', 3], ['ugoku', 3], ['kettei', 3], ['keisu', 2],
  ['bunsho', 2], ['zettai', 2], ['joken', 2],
];

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

test.describe('2次関数マスター', () => {
  test('全タイプ×レベルが出題でき、答え合わせが通る', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    for (const [m, n] of MODES) {
      for (let lv = 1; lv <= n; lv++) {
        await page.click(`#typeChips .chip[data-mode="${m}"]`);
        await page.click(`#lvSeg .lvbtn[data-lv="${lv}"]`);
        await expect(page.locator('#qLv')).toHaveText(`LV.${lv}`);
        const cls = await answerCorrectly(page);
        expect(cls, `${m} lv${lv}`).toContain('ok');
        await expect(page.locator('#explain')).toBeVisible();
        await page.click('#nextBtn');
      }
    }
    expect(errs).toEqual([]);
  });

  /* レベルの数はタイプごとに違う（発展を持たないタイプは2つまで）。
     押せてしまうと lvCount を超えたレベルで出題され、黙って基本に落ちる。 */
  test('発展を持たないタイプでは LEVEL 3 が押せない', async ({ page }) => {
    await page.goto(TOOL);
    for (const [m, n] of MODES) {
      await page.click(`#typeChips .chip[data-mode="${m}"]`);
      const disabled = await page.locator('#lvSeg .lvbtn[data-lv="3"]').isDisabled();
      expect(disabled, `${m} のLV3`).toBe(n < 3);
    }
    // レベル3のまま2レベルのタイプへ移ったら、選択中のレベルが2に下がる
    await page.click('#typeChips .chip[data-mode="kansuu"]');
    await page.click('#lvSeg .lvbtn[data-lv="3"]');
    await page.click('#typeChips .chip[data-mode="zettai"]');
    await expect(page.locator('#lvSeg .lvbtn[data-lv="2"]')).toHaveClass(/on/);
    await expect(page.locator('#qLv')).toHaveText('LV.2');
  });

  test('数式が KaTeX で描かれる（和文を TeX に入れていない）', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    for (const [m] of MODES) {
      await page.click(`#typeChips .chip[data-mode="${m}"]`);
      const bad = await page.evaluate(() => {
        const out = { raw: [], err: [] };
        document.querySelectorAll('#card .tex').forEach((el) => {
          if (!el.querySelector('.katex')) out.raw.push(el.getAttribute('data-tex'));
          // KaTeX は描けない文字（和文など）を .katex-error として赤字で出す
          if (el.querySelector('.katex-error')) out.err.push(el.getAttribute('data-tex'));
        });
        return out;
      });
      expect(bad.raw, `${m} 未描画`).toEqual([]);
      expect(bad.err, `${m} KaTeXエラー`).toEqual([]);
    }
    expect(errs).toEqual([]);
  });

  test('選択肢に生の「<」が入らない（save_answer が捨てるため）', async ({ page }) => {
    await page.goto(TOOL);
    const bad = [];
    for (const [m, n] of MODES) {
      for (let lv = 1; lv <= n; lv++) {
        await page.click(`#typeChips .chip[data-mode="${m}"]`);
        await page.click(`#lvSeg .lvbtn[data-lv="${lv}"]`);
        for (let i = 0; i < 12; i++) {
          const hit = await page.evaluate(() => {
            // @ts-ignore
            const a = cur.ans;
            // @ts-ignore
            newQuestion();
            if (a.kind !== 'choice') return null;
            const ng = a.choices.filter((c) => String(c).indexOf('<') >= 0);
            return ng.length ? ng[0] : null;
          });
          if (hit) bad.push(`${m} lv${lv}: ${hit}`);
        }
      }
    }
    expect(bad).toEqual([]);
  });

  /* ⑥動く最大・最小は答えが「場合分けの組」そのもの。
     式や境目が1文字ずれても画面は普通に動いてしまうので、
     a を細かく振って「本当の最大・最小」と数値で突き合わせる。 */
  test('場合分けの答えが本当の最大・最小と一致する', async ({ page }) => {
    await page.goto(TOOL);
    const bad = await page.evaluate(() => {
      const out = [];
      const evalA = (expr, A) => {
        let s = String(expr).replace(/²/g, '^2').replace(/a\^2/g, '(A*A)').replace(/a/g, 'A');
        s = s.replace(/(\d)\s*\(/g, '$1*(').replace(/(\d)A/g, '$1*A');
        try { return Function('A', 'return (' + s + ');')(A); } catch (e) { return NaN; }
      };
      const condOk = (cond, A) => {
        const c = String(cond).replace(/\s/g, '');
        let m = c.match(/^(-?\d+)(＜|≦)a(＜|≦)(-?\d+)$/);
        if (m) return (m[2] === '＜' ? A > +m[1] : A >= +m[1]) && (m[3] === '＜' ? A < +m[4] : A <= +m[4]);
        m = c.match(/^a(＜|≦)(-?\d+)$/);
        if (m) return m[1] === '＜' ? A < +m[2] : A <= +m[2];
        m = c.match(/^(-?\d+)(＜|≦)a$/);
        if (m) return m[2] === '＜' ? A > +m[1] : A >= +m[1];
        return null;
      };
      const extreme = (f, lo, hi, wantMax) => {
        let best = null;
        for (let i = 0; i <= 2000; i++) {
          const y = f(lo + (hi - lo) * i / 2000);
          if (best === null || (wantMax ? y > best : y < best)) best = y;
        }
        return best;
      };
      for (const lv of [1, 2, 3]) {
        for (let i = 0; i < 60; i++) {
          // @ts-ignore ツール内のグローバル
          rngSeed(rngPick());
          // @ts-ignore
          const q = GENS.ugoku(lv);
          const wantMax = /最大値/.test(q.plainQ);
          const cases = q.plainA.split('、').map((p) => {
            const s = p.split(' のとき ');
            return (s.length === 2) ? { c: s[0], e: s[1] } : null;
          });
          if (cases.indexOf(null) >= 0) { out.push('case parse: ' + q.plainA); continue; }
          let mkF; let dom;
          if (lv === 2) {
            const mc = q.plainQ.match(/y=x²-2ax([+-]\d+)?/);
            const mt = q.plainQ.match(/\(0≦x≦(\d+)\)/);
            if (!mc || !mt) { out.push('lv2 parse: ' + q.plainQ); continue; }
            const C = mc[1] ? +mc[1] : 0; const T = +mt[1];
            mkF = (A) => (x) => x * x - 2 * A * x + C;
            dom = () => [0, T];
          } else {
            const mk = q.plainQ.match(/y=x²([+-]\d+)x([+-]\d+)?/);
            if (!mk) { out.push('parse: ' + q.plainQ); continue; }
            const B = +mk[1]; const C = mk[2] ? +mk[2] : 0;
            mkF = () => (x) => x * x + B * x + C;
            if (lv === 1) { dom = (A) => [0, A]; } else {
              const mw = q.plainQ.match(/\(a≦x≦a\+(\d+)\)/);
              if (!mw) { out.push('lv3 parse: ' + q.plainQ); continue; }
              const W = +mw[1];
              dom = (A) => [A, A + W];
            }
          }
          for (let t = -60; t <= 100; t++) {
            const A = t / 10;
            if (lv === 1 && A <= 0) continue;
            let hit = null;
            for (const cs of cases) {
              const ok = condOk(cs.c, A);
              if (ok === null) { out.push('cond parse: ' + cs.c); break; }
              if (ok) { hit = cs; break; }
            }
            if (!hit) continue;
            const d = dom(A);
            const truth = extreme(mkF(A), d[0], d[1], wantMax);
            const got = evalA(hit.e, A);
            if (!isFinite(got) || Math.abs(truth - got) > 1e-4) {
              out.push(`lv${lv} a=${A} 正=${truth.toFixed(3)} 答=${got} / ${q.plainQ} / ${q.plainA}`);
              break;
            }
          }
          if (out.length > 8) return out;
        }
      }
      return out;
    });
    expect(bad).toEqual([]);
  });

  /* 解き直し(?retry=1)は question_params の {m,lv,s} で同じ問題を作り直す。
     生成関数のどこかに Math.random が混ざると、ここで落ちる。 */
  test('同じ種から同じ問題が出る（解き直しの前提）', async ({ page }) => {
    await page.goto(TOOL);
    const bad = await page.evaluate((modes) => {
      const out = [];
      for (const [m, n] of modes) {
        for (let lv = 1; lv <= n; lv++) {
          for (let i = 0; i < 20; i++) {
            // @ts-ignore ツール内のグローバル
            const s = rngPick();
            // @ts-ignore
            rngSeed(s); const q1 = GENS[m](lv);
            // @ts-ignore
            rngSeed(s); const q2 = GENS[m](lv);
            const k = (q) => JSON.stringify([q.lead, q.disp, q.plainQ, q.ans]);
            if (k(q1) !== k(q2)) out.push(`${m} lv${lv} seed${s}`);
          }
        }
      }
      return out.slice(0, 10);
    }, MODES);
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
      for (const [m, n] of modes) {
        for (let lv = 1; lv <= n; lv++) {
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

  test('図モードが4種類とも描ける', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    await page.click('.vtab[data-view="fig"]');
    for (const f of ['idou', 'kukan', 'ugoku', 'zettai']) {
      await page.click(`#figChips .chip[data-fig="${f}"]`);
      await expect(page.locator('#figSvg svg')).toBeVisible();
      await expect(page.locator('#figJudge')).not.toBeEmpty();
      const btns = await page.locator('#figCtrl .sbtn[data-dir="1"]').count();
      // ＋を端まで、−も端まで押して壊れないこと
      for (const dir of ['1', '-1']) {
        for (let i = 0; i < btns; i++) {
          for (let n = 0; n < 20; n++) {
            await page.locator(`#figCtrl .sbtn[data-dir="${dir}"]`).nth(i).click();
          }
        }
        await expect(page.locator('#figSvg svg')).toBeVisible();
        const svg = await page.locator('#figSvg').innerHTML();
        expect(/NaN|undefined/.test(svg), `${f} dir${dir}`).toBe(false);
      }
    }
    expect(errs).toEqual([]);
  });

  /* 解説の「▶ この問題を図で見る」から、その問題の数のまま図モードへ飛べること */
  test('解説から図モードへ飛べる', async ({ page }) => {
    await page.goto(TOOL);
    await page.click('#typeChips .chip[data-mode="saidai"]');
    await page.click('#lvSeg .lvbtn[data-lv="2"]');
    await answerCorrectly(page);
    await page.click('#exBody .figLink');
    await expect(page.locator('#view-fig')).toBeVisible();
    await expect(page.locator('#figSvg svg')).toBeVisible();
    // 定義域は問題と同じ値が入っている
    const same = await page.evaluate(() => {
      // @ts-ignore ツール内のグローバル
      return figV.ks === cur.fig.s && figV.ke === cur.fig.e && figV.kk === cur.fig.k;
    });
    expect(same).toBe(true);
  });

  /* ②平方完成は「答えを言う」だけでなく、平方完成の手順そのものが解説に出ること。
     途中式が無いと、頂点がどこから出てきたのか生徒が追えない。
     lv2（一般形）= くくる/たしてひく → 頂点形 の2式以上、
     lv3（分数が出る）= くくる → たしてひく → かっこをはずす → まとめる の4式以上。 */
  test('平方完成の解説に途中式が出る', async ({ page }) => {
    const errs = watchErrors(page);
    await page.goto(TOOL);
    for (const [lv, least] of [[2, 2], [3, 4]]) {
      await page.click('#typeChips .chip[data-mode="heihei"]');
      await page.click(`#lvSeg .lvbtn[data-lv="${lv}"]`);
      for (let i = 0; i < 6; i++) {
        await answerCorrectly(page);
        const got = await page.evaluate(() => {
          const box = document.getElementById('explain');
          const tex = Array.from(box.querySelectorAll('.tex'));
          return {
            // 「y=…」の形で出ている式の本数（= 変形のステップ数）
            steps: tex.map((el) => el.getAttribute('data-tex') || '')
              .filter((s) => /^y=/.test(s) && s.indexOf('x') >= 0).length,
            addsub: /たしてひく/.test(box.textContent || ''),
            vertex: /頂点/.test(box.textContent || ''),
            raw: tex.filter((el) => !el.querySelector('.katex')).length,
            err: tex.filter((el) => el.querySelector('.katex-error')).length,
          };
        });
        expect(got.steps, `lv${lv} 途中式の本数`).toBeGreaterThanOrEqual(least);
        expect(got.addsub, `lv${lv} 「たしてひく」の説明`).toBe(true);
        expect(got.vertex, `lv${lv} 頂点`).toBe(true);
        expect(got.raw, `lv${lv} 解説の未描画TeX`).toBe(0);
        expect(got.err, `lv${lv} 解説のKaTeXエラー`).toBe(0);
        await page.click('#nextBtn');
      }
    }
    expect(errs).toEqual([]);
  });
});
