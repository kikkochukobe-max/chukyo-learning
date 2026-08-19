/*!
 * divp-choice-mark.js — 選択肢の「答え合わせ表示」共通モジュール
 * -----------------------------------------------------------------
 * ★ 注意：divp-correct.js / divp-correct-jh.js / divp-correct-firework.js
 *   （正解した瞬間のお祝い演出＝Divp.correct）とは別物・別の関心事。
 *   こちらは「どれが正解だったか」を選択肢ボタンに表示する採点表示で、
 *   誤答したときこそ必要になる（選ばなかった正解を緑＋「正解」バッジで示す）。
 *   Divp.correct を差し替えたりはしないので、どのエフェクトと併用してもよい。
 *
 * 使い方：
 *   <script src="/assets/divp-choice-mark.js"></script>
 *   ...
 *   // ① まとめて振る（単一選択のツールはこれだけで済む）
 *   Divp.markChoices(".choiceBtn", { correct: ans.correct, selected: selChoice });
 *
 *   // ② 1つずつ振る（順番に選ぶ・複数正解など、判定が複雑なツール用）
 *   Divp.markChoice(btn, "correct");  // 選んで正解      → 緑
 *   Divp.markChoice(btn, "answer");   // 選ばなかった正解 → 緑＋「正解」バッジ
 *   Divp.markChoice(btn, "wrong");    // 選んで誤答      → 朱
 *   Divp.markChoice(btn, "dim");      // 関係ない選択肢   → 薄く
 *   Divp.markChoice(btn, null);       // 表示を消す
 *
 *   // ③ 次の問題へ進むときにまとめて消す（innerHTML を作り直すなら不要）
 *   Divp.clearMarks(".choiceBtn");
 *
 * markChoices(list, opts) の引数：
 *   list     : CSSセレクタ文字列 / NodeList / 配列（並び順 = 選択肢のindex）
 *   correct  : 正解のindex。配列で複数正解も可
 *   selected : 生徒が選んだindex。配列可。未選択・無回答なら -1 か省略
 *   dimOthers: true で「正解でも選んでもいない選択肢」を薄くする（既定 false）
 *   label    : バッジの文字（既定 "正解"。小学生ツールでは "せいかい" を渡す）
 *   badgeOnSelected: true で「選んで正解」にもバッジを出す
 *              （既定 false。正解時はお祝い演出が出るので二重に主張させない）
 *
 * 設計：クラス名ではなく data-divp-mark 属性で状態を持つ。
 *   ツール側の既存クラス（.hit / .sel-ok / .judge-ok など流派がバラバラ）を
 *   消さずに上に乗せられるので、1本ずつ移行でき、途中で混在しても壊れない。
 *
 * 色の上書き：ツール側CSSで変数を差すだけでパレットを合わせられる。
 *   :root{ --divp-mark-ok:#3E8E5A; --divp-mark-ok-bg:#EDF6F0; }
 *   既定色は var() のフォールバックで持っているので、defer で後から読み込まれても
 *   ツール側の指定が勝つ(読み込み順に依存しない)。
 *   変数一覧 → --divp-mark-ok / --divp-mark-ok-bg / --divp-mark-ok-bg-soft
 *              --divp-mark-ng / --divp-mark-ng-bg / --divp-mark-dim-opacity
 *   --divp-mark-ok-text は「正解」の文字色だけを分けたいとき用(既定は枠と同色)。
 *   ダークテーマのツールは枠を明るい緑にしても文字が読めるよう、ここに薄い色を指定する。
 *
 * 注意：バッジは絶対配置なので、対象要素に position:relative が必要。
 *   このモジュールが [data-divp-mark] に position:relative を当てているが、
 *   もともと static だった要素の中に絶対配置の子がいると採点の瞬間に
 *   位置の基準が変わる。その場合はツール側で最初から position:relative にしておく。
 *
 * 更新方法：このファイルを /assets/divp-choice-mark.js に上書きするだけで、
 * 読み込んでいる全ツールに一斉反映される（URL固定・.htaccessでキャッシュ制御）。
 */
(function () {
  'use strict';
  if (window.__divpChoiceMarkLoaded) return;
  window.__divpChoiceMarkLoaded = true;

  // 既定色は一次関数マスター（この表示の元になったツール）の実値。
  // 緑は既存トークンに無かったので苔色 #5E7B4E を採用、朱はCLAUDE.mdの #C73E2E。
  var CSS = ''
    // 属性セレクタを2重にして詳細度を 0,2,0 に上げている。
    // ツール側CSSの読み込み順に関係なく、単一クラスの既存ルールには勝てるようにするため。
    + '[data-divp-mark][data-divp-mark]{position:relative;}'
    // 選んで正解
    + '[data-divp-mark="correct"][data-divp-mark]{'
    + 'background:var(--divp-mark-ok-bg-soft,#E8F1E4);'
    + 'border-color:var(--divp-mark-ok,#5E7B4E);}'
    // 選ばなかった正解 ─ 緑ではっきり示す（どれが正解だったか一目でわかるように）
    + '[data-divp-mark="answer"][data-divp-mark]{'
    + 'background:var(--divp-mark-ok-bg,#DCEBD3);'
    + 'border-color:var(--divp-mark-ok,#5E7B4E);'
    + 'box-shadow:inset 0 0 0 2px var(--divp-mark-ok,#5E7B4E);'
    + 'color:var(--divp-mark-ok-text,var(--divp-mark-ok,#5E7B4E));font-weight:700;}'
    // KaTeX は自前で色を持つので明示的に継がせる
    + '[data-divp-mark="answer"][data-divp-mark] .katex,'
    + '[data-divp-mark="answer"][data-divp-mark] .katex *{color:inherit;}'
    // 選んで誤答
    + '[data-divp-mark="wrong"][data-divp-mark]{'
    + 'background:var(--divp-mark-ng-bg,#FBE7EA);'
    + 'border-color:var(--divp-mark-ng,#C73E2E);}'
    // 正解でも選んでもいない選択肢
    + '[data-divp-mark="dim"][data-divp-mark]{opacity:var(--divp-mark-dim-opacity,0.55);}'
    // 「正解」バッジ。文字は data-divp-label から取るので学年で語を変えられる
    + '[data-divp-mark][data-divp-label]::after{'
    + 'content:attr(data-divp-label);position:absolute;right:0;top:0;'
    + 'background:var(--divp-mark-ok,#5E7B4E);color:#fff;'
    + 'font-size:0.58rem;font-weight:700;letter-spacing:0.08em;line-height:1.5;'
    + 'font-family:"Zen Maru Gothic","Noto Sans JP",sans-serif;'
    + 'padding:2px 6px;border-bottom-left-radius:8px;border-top-right-radius:7px;'
    + 'pointer-events:none;'
    + 'animation:divpMarkBadgeIn 0.22s ease-out;}'
    + '@keyframes divpMarkBadgeIn{'
    + 'from{opacity:0;transform:translate(4px,-4px);}'
    + 'to{opacity:1;transform:none;}}';

  function injectStyle() {
    if (document.getElementById('divp-choice-mark-style')) return;
    var style = document.createElement('style');
    style.id = 'divp-choice-mark-style';
    style.textContent = CSS;
    (document.head || document.documentElement).appendChild(style);
  }

  var KINDS = { correct: 1, answer: 1, wrong: 1, dim: 1 };

  // セレクタ文字列 / NodeList / 配列 のどれで渡されても配列にそろえる
  function toList(list) {
    if (!list) return [];
    if (typeof list === 'string') list = document.querySelectorAll(list);
    if (list.nodeType === 1) return [list];
    return Array.prototype.slice.call(list);
  }

  // number / 配列 / null をどれも「含むか判定できるもの」にそろえる
  function toSet(v) {
    if (v == null) return [];
    return (Object.prototype.toString.call(v) === '[object Array]') ? v : [v];
  }
  function has(arr, i) {
    for (var k = 0; k < arr.length; k++) { if (arr[k] === i) return true; }
    return false;
  }

  function markChoice(el, kind, opts) {
    if (!el || el.nodeType !== 1) return;
    opts = opts || {};
    if (!kind) {
      delete el.dataset.divpMark;
      delete el.dataset.divpLabel;
      return;
    }
    if (!KINDS[kind]) return;   // 想定外の種別は黙って無視（未組み込みツールと混在しても壊れない）
    injectStyle();
    el.dataset.divpMark = kind;
    // バッジは「選ばなかった正解」だけに出す。選んで正解のときはお祝い演出が出るため。
    var wantBadge = (kind === 'answer') || (kind === 'correct' && opts.badgeOnSelected);
    if (wantBadge) el.dataset.divpLabel = opts.label || '正解';
    else delete el.dataset.divpLabel;
  }

  function markChoices(list, opts) {
    opts = opts || {};
    var els = toList(list);
    var corr = toSet(opts.correct), sel = toSet(opts.selected);
    els.forEach(function (el, i) {
      var isC = has(corr, i), isS = has(sel, i);
      var kind = isC ? (isS ? 'correct' : 'answer')
                     : (isS ? 'wrong' : (opts.dimOthers ? 'dim' : null));
      markChoice(el, kind, opts);
    });
  }

  function clearMarks(list) {
    toList(list).forEach(function (el) { markChoice(el, null); });
  }

  // 読み込み時にCSSを入れておく。既定色は var() のフォールバックで持っているので、
  // ツールが :root で --divp-mark-* を差せば読み込み順に関係なくそちらが効く
  // (defer 付きで後から読み込まれても色が戻らない)。
  if (document.head) injectStyle();
  else document.addEventListener("DOMContentLoaded", injectStyle);

  window.Divp = window.Divp || {};
  window.Divp.markChoice = markChoice;
  window.Divp.markChoices = markChoices;
  window.Divp.clearMarks = clearMarks;
})();
