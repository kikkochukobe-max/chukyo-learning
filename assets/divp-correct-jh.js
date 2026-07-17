/*!
 * divp-correct-jh.js — 中学・高校（js/jh）共通 正誤エフェクト「はんこ」
 * -----------------------------------------------------------------
 * ★ 注意：小学生(es)向けの星＋「正解！」エフェクト(divp-correct.js／Divp.correct)
 *   とは別物。es ツールにはこのファイルを使わない。js/jh ツール専用。
 *
 * 使い方：
 *   <script src="/assets/divp-correct-jh.js"></script>
 *   ...
 *   if (isCorrect) { Divp.correct('.muldiv-problem'); }     // 正解 → 「正解」はんこ
 *   else           { Divp.incorrect('.muldiv-problem'); }   // 不正解 → 「ナイスチャレンジ！」バッジ
 *   // 第一引数=カードのCSSセレクタ（position:relative 推奨）
 *
 * 仕組み（correct／incorrect 共通）：
 *  - 呼ぶたびに対象要素の中に半透明のスタンプ／バッジをポップイン表示する。
 *    位置はカード中央よりやや上（top 38%）固定。
 *  - 同じカードに前回の表示が残っていれば自動的に削除してから出す（多重表示防止）。
 *  - correct（正解はんこ）: 直径171pxの朱色二重丸。自動では消えない
 *    （次の問題で card.innerHTML が再描画されるときに一緒に消える想定）。
 *  - incorrect（ナイスチャレンジ バッジ）: 橙色の角丸バッジ。
 *    ★不正解時はカード上に正解・解説が出るツールが多いため、それを隠さないよう
 *    既定で自動フェードする（fadeMs=1300）。「赤バツで責めない」設計思想に沿い、
 *    ✕ではなく挑戦そのものを褒める文言・色（がんばりどころ色 #D89A45）にしている。
 *    ※その場で解き直す仕組みではなく、誤答は後日 retry_queue で再出題されるため、
 *      即時のトーンは「挑戦できたこと」を肯定する方向に振っている。
 *
 * カスタマイズ：
 *   Divp.correct(selector,   { text:'正解',          fadeMs:0 })
 *   Divp.incorrect(selector, { text:'ナイスチャレンジ！', fadeMs:1300 })
 *     text    : 表示する文字
 *     fadeMs  : 指定msで自動フェードアウト（0 = フェードしない）
 *
 * 更新方法：このファイルを /assets/divp-correct-jh.js に上書きするだけで、
 * 読み込んでいる全 js/jh ツールに一斉反映される（URL固定・.htaccessでキャッシュ制御）。
 */
(function () {
  'use strict';
  if (window.__divpCorrectJHLoaded) return;
  window.__divpCorrectJHLoaded = true;

  var CSS = ''
    + '.divp-hanko{position:absolute;top:38%;left:50%;'
    + 'transform:translate(-50%,-50%) rotate(-12deg) scale(1);'
    + 'width:171px;height:171px;border:8px double rgba(200,30,30,0.62);border-radius:50%;'
    + 'display:flex;align-items:center;justify-content:center;'
    + 'color:rgba(200,30,30,0.62);font-size:51px;font-weight:900;'
    + 'font-family:"Noto Sans JP",serif;letter-spacing:0.08em;'
    + 'pointer-events:none;z-index:50;'
    + 'animation:divpHankoIn 0.35s cubic-bezier(0.175,0.885,0.32,1.275) forwards;}'
    + '@keyframes divpHankoIn{'
    + 'from{transform:translate(-50%,-50%) rotate(-12deg) scale(1.9);opacity:0;}'
    + 'to{transform:translate(-50%,-50%) rotate(-12deg) scale(1);opacity:1;}}'
    + '.divp-hanko.divp-hanko-fade{transition:opacity 0.4s ease;opacity:0;}'
    // ── 不正解バッジ（ナイスチャレンジ！）──────────────────────────
    // ✕ではなく挑戦を褒めるトーン。色は「がんばりどころ」の橙 #D89A45。
    + '.divp-nice{position:absolute;top:38%;left:50%;'
    + 'transform:translate(-50%,-50%) rotate(-7deg) scale(1);'
    + 'padding:12px 22px;border:5px solid rgba(216,154,69,0.72);border-radius:16px;'
    + 'background:rgba(216,154,69,0.10);'
    + 'display:flex;align-items:center;justify-content:center;white-space:nowrap;'
    + 'color:rgba(179,120,40,0.92);font-size:30px;font-weight:900;'
    + 'font-family:"Zen Maru Gothic","Noto Sans JP",sans-serif;letter-spacing:0.04em;'
    + 'pointer-events:none;z-index:50;'
    + 'animation:divpNiceIn 0.32s cubic-bezier(0.175,0.885,0.32,1.275) forwards;}'
    + '@keyframes divpNiceIn{'
    + 'from{transform:translate(-50%,-50%) rotate(-7deg) scale(1.6);opacity:0;}'
    + 'to{transform:translate(-50%,-50%) rotate(-7deg) scale(1);opacity:1;}}'
    + '.divp-nice.divp-nice-fade{transition:opacity 0.4s ease;opacity:0;}';

  function injectStyle() {
    if (document.getElementById('divp-correct-jh-style')) return;
    var style = document.createElement('style');
    style.id = 'divp-correct-jh-style';
    style.textContent = CSS;
    document.head.appendChild(style);
  }

  function correct(target, opts) {
    opts = opts || {};
    var card = (typeof target === 'string') ? document.querySelector(target) : target;
    if (!card) return;
    injectStyle();

    // 同じカード内の前回スタンプを消してから出す（多重表示防止）
    var old = card.querySelectorAll('.divp-hanko');
    for (var i = 0; i < old.length; i++) old[i].remove();

    var h = document.createElement('div');
    h.className = 'divp-hanko';
    h.textContent = opts.text || '正解';
    card.appendChild(h);

    if (opts.fadeMs) {
      setTimeout(function () {
        h.classList.add('divp-hanko-fade');
        setTimeout(function () { h.remove(); }, 450);
      }, opts.fadeMs);
    }
  }

  // 不正解時の「ナイスチャレンジ！」バッジ。correct と同型のAPI。
  // 既定で自動フェード（不正解時に出る正解・解説を隠さないため）。
  function incorrect(target, opts) {
    opts = opts || {};
    var card = (typeof target === 'string') ? document.querySelector(target) : target;
    if (!card) return;
    injectStyle();

    // 同じカード内の前回バッジ／正解はんこを消してから出す（多重表示・混在防止）
    var old = card.querySelectorAll('.divp-nice, .divp-hanko');
    for (var i = 0; i < old.length; i++) old[i].remove();

    var b = document.createElement('div');
    b.className = 'divp-nice';
    b.textContent = opts.text || 'ナイスチャレンジ！';
    card.appendChild(b);

    // fadeMs 未指定なら既定1300msで自動フェード。0 を明示すると消えない。
    var fadeMs = (opts.fadeMs != null) ? opts.fadeMs : 1300;
    if (fadeMs) {
      setTimeout(function () {
        b.classList.add('divp-nice-fade');
        setTimeout(function () { b.remove(); }, 450);
      }, fadeMs);
    }
  }

  window.Divp = window.Divp || {};
  window.Divp.correct = correct;
  window.Divp.incorrect = incorrect;
})();
