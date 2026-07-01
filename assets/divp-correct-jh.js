/*!
 * divp-correct-jh.js — 中学・高校（js/jh）共通 正解エフェクト「はんこ」
 * -----------------------------------------------------------------
 * ★ 注意：小学生(es)向けの星＋「正解！」エフェクト(divp-correct.js／Divp.correct)
 *   とは別物。es ツールにはこのファイルを使わない。js/jh ツール専用。
 *
 * 使い方：
 *   <script src="/assets/divp-correct-jh.js"></script>
 *   ...
 *   if (isCorrect) { Divp.correct('.muldiv-problem'); }   // 第一引数=カードのCSSセレクタ
 *
 * 仕組み：
 *  - 呼ぶたびに対象要素（position:relative 推奨）の中に半透明の「正解」スタンプを
 *    ポップイン表示する。位置はカード中央よりやや上（top 38%）固定、サイズは
 *    カードの内容に重なってもよい大きめ表示（直径171px）。
 *  - 同じカードに前回のスタンプが残っていれば自動的に削除してから出す（多重表示防止）。
 *  - 自動では消えない（次の問題で card.innerHTML が再描画されるときに一緒に消える想定）。
 *    自動フェードアウトが必要なツールは fadeMs オプションで指定可。
 *
 * カスタマイズ：
 *   Divp.correct(selector, { text:'正解', fadeMs:0 })
 *     text    : スタンプの文字（既定 '正解'）
 *     fadeMs  : 指定msで自動フェードアウト（既定 0 = フェードしない）
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
    + '.divp-hanko.divp-hanko-fade{transition:opacity 0.4s ease;opacity:0;}';

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

  window.Divp = window.Divp || {};
  window.Divp.correct = correct;
})();
