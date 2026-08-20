/*!
 * divp-result.js — 「10問ごとの評価（RESULT画面）」共通モジュール
 * -----------------------------------------------------------------
 * ひな型は平方根マスター（math_js3_heihokonmaster.html）の renderResult()。
 * ランクの段階・文言・「10問未満はランクを付けない」判断まで、そこの実装を
 * そのまま共通化したもの。平方根は同じ結果画面を8モード分コピーして持っており、
 * 文言を1つ直すのに8箇所さわる状態だったのが移行の動機。
 *
 * ★ 中学・高校ツール用。小学生（es）ツールは対象外（別モジュールにする予定。
 *   ひらがな主体の文言・ランクを出すかどうかも含めて未決）。
 *
 * 使い方：
 *   <script src="/assets/divp-core.js"></script>
 *   <script src="/assets/divp-result.js"></script>
 *   ...
 *   Divp.resultInit({
 *     total: 10,                       // 1セットの問題数（既定10）
 *     questionKey: mode,               // 連続全問正解カウントの単位（＝モード）
 *     masterLabel: '一次関数マスター',   // SSS のときの呼び名
 *     onNext: startSet,                // 「つぎの10問へ」
 *     onRetryWrong: retryWrongs,       // 「間違えた N 問を解き直す」(省略でボタン無し)
 *     onModes: backToModes,            // 「モード選択に戻る」  (省略でボタン無し)
 *     home: '/learning/'               // 「ホームへ」          (null でボタン無し)
 *   });
 *   // 判定の直後に1行（Divp.answer と同じ場所に足す）
 *   Divp.resultPush(ok, cur);          // 1セット終わると結果画面が出て true を返す
 *
 * ツール固有の情報を混ぜたいとき：
 *   cap   … 見出し（既定 'RESULT'。愛知県大問1は '─ 採点結果 ─'）
 *   extra … 内訳とボタンの間に差し込む HTML／要素／それを返す関数。
 *           タイムや○✕一覧のような「そのツールだけの表示」をここに逃がす。
 *           共通カードに寄せてもツール固有の情報が消えないようにするための口。
 *
 * API：
 *   Divp.resultInit(opts)      設定。ツールの初期化で1回だけ
 *   Divp.resultPush(ok, item)  1問ぶん記録。セットが終わったら結果画面を出し true
 *   Divp.resultStart(opts)     次のセットを始める（カウンタを0に）。
 *                              解き直しラウンドは {total:n, retry:true} を渡す
 *   Divp.resultState()         {done,total,correct,wrong,wrongs,streak,retry}
 *   Divp.resultClose()         結果画面を閉じる（オーバーレイ表示のとき）
 *
 * ランク（平方根と同じ）：
 *   SSS 3セット連続全問正解 / SS 2セット連続 / S 全問正解 /
 *   A 90%以上 / B 70%以上 / C 50%以上 / D それ未満
 *   1セットが10問未満のとき（＝間違えた問題の解き直しラウンド）はランクを付けず
 *   「解き直しおつかれさま！」だけ出す。連続カウントも進めない・減らさない。
 *   ※連続カウントは questionKey（モード）ごとに持つ。平方根はツール全体で1つの
 *     変数を共有していたので、モードを渡り歩くと連続が繋がっていた。
 *
 * 置き場所：mount を渡すとその要素の中に描く（平方根の #quiz-area 方式）。
 *   省略すると画面中央のオーバーレイで出す。既存ツールに後から足すときは
 *   レイアウトをいじらなくて済むオーバーレイのほうが楽。
 *
 * 色：ツール側CSSで変数を差せばパレットを合わせられる（var()のフォールバックで
 *   既定値を持つので、読み込み順に関係なくツール側が勝つ）。
 *     --divp-result-bg / -ink / -sub / -accent / -accent-ink
 *     --divp-result-ok / -ok-bg / -ng / -ng-bg
 *     --divp-result-grade-sss / -ss / -s / -a / -b / -c / -d
 *   ランクの色だけは既定で平方根の派手な配色をそのまま使う（ランクは目立ってよい）。
 *
 * 更新方法：このファイルを /assets/divp-result.js に上書きするだけで、
 * 読み込んでいる全ツールに一斉反映される（URL固定・.htaccessでキャッシュ制御）。
 */
(function (global) {
  'use strict';
  if (global.__divpResultLoaded) return;
  global.__divpResultLoaded = true;

  var Divp = global.Divp = global.Divp || {};

  var CSS = ''
    + '.divp-result-ov{position:fixed;inset:0;z-index:9999;display:flex;'
    + 'align-items:center;justify-content:center;padding:16px;'
    + 'background:rgba(20,16,12,.45);animation:divpResultFade .2s ease-out;}'
    + '.divp-result{box-sizing:border-box;width:100%;max-width:420px;text-align:center;'
    + 'padding:32px 24px;border-radius:20px;'
    + 'background:var(--divp-result-bg,#fff);'
    + 'color:var(--divp-result-ink,#1f2937);'
    + 'box-shadow:0 18px 50px rgba(20,16,12,.28);'
    + 'font-family:"Zen Kaku Gothic New","Noto Sans JP",sans-serif;'
    + 'animation:divpResultUp .4s ease;}'
    + '.divp-result-cap{font-size:13px;letter-spacing:.18em;'
    + 'color:var(--divp-result-sub,#64748b);margin-bottom:8px;}'
    + '.divp-result-grade{font-size:76px;line-height:1;font-weight:900;'
    + 'letter-spacing:.02em;margin-bottom:6px;'
    + 'font-family:var(--divp-result-grade-font,"Zen Maru Gothic","Noto Sans JP",sans-serif);}'
    + '.divp-result-msg{font-size:17px;font-weight:700;margin-bottom:18px;}'
    + '.divp-result-score{font-size:36px;font-weight:900;margin-bottom:10px;}'
    + '.divp-result-score span{font-size:17px;color:var(--divp-result-sub,#64748b);font-weight:700;}'
    + '.divp-result-break{display:flex;justify-content:center;gap:12px;margin-bottom:24px;flex-wrap:wrap;}'
    + '.divp-result-item{padding:10px 18px;border-radius:12px;font-size:13px;font-weight:700;}'
    + '.divp-result-item.c{background:var(--divp-result-ok-bg,#dcfce7);color:var(--divp-result-ok,#166534);}'
    + '.divp-result-item.w{background:var(--divp-result-ng-bg,#fee2e2);color:var(--divp-result-ng,#991b1b);}'
    + '.divp-result-extra{margin:-8px 0 20px;}'
    + '.divp-result-btns{display:flex;flex-direction:column;gap:9px;}'
    + '.divp-result-btn{display:block;width:100%;box-sizing:border-box;padding:14px 12px;'
    + 'border:none;border-radius:14px;cursor:pointer;text-decoration:none;'
    + 'font-family:inherit;font-size:15px;font-weight:700;letter-spacing:.04em;'
    + 'transition:transform .18s,filter .18s;'
    + 'background:var(--divp-result-sub-btn,#EFEAE0);color:var(--divp-result-ink,#1f2937);}'
    + '.divp-result-btn:hover{transform:translateY(-2px);filter:brightness(.97);}'
    + '.divp-result-btn.primary{padding:16px 12px;font-size:16px;'
    + 'background:var(--divp-result-accent,#C9556F);color:var(--divp-result-accent-ink,#fff);}'
    + '.divp-result-btn.wrong{background:var(--divp-result-wrong-btn,#3b82f6);color:#fff;}'
    + '@keyframes divpResultFade{from{opacity:0;}to{opacity:1;}}'
    + '@keyframes divpResultUp{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:none;}}'
    + '@media (prefers-reduced-motion: reduce){'
    + '.divp-result-ov,.divp-result{animation-duration:.01ms;}}';

  function injectStyle() {
    if (document.getElementById('divp-result-style')) return;
    var style = document.createElement('style');
    style.id = 'divp-result-style';
    style.textContent = CSS;
    (document.head || document.documentElement).appendChild(style);
  }

  // ── 設定と状態 ──────────────────────────────────────────────
  function defaults() {
    return {
      total: 10,
      questionKey: '',
      masterLabel: '',
      msgs: null,
      labels: null,
      cap: 'RESULT',
      extra: null,
      mount: null,
      home: '/learning/',
      onNext: null,
      onRetryWrong: null,
      onModes: null
    };
  }
  var cfg = defaults();
  var st = { done: 0, total: 10, correct: 0, wrong: 0, wrongs: [], retry: false, shown: false };
  var streaks = {};          // モードごとの「連続全問正解セット数」
  var overlay = null;

  var DEF_MSG = {
    SSS: '30問連続全問正解！',
    SS: '20問連続全問正解！圧巻！',
    S: '全問正解！完璧！',
    A: 'あと1問！よくできました！',
    B: 'もう少し！復習しよう。',
    C: '復習してもう一度！',
    D: '基礎から復習しよう。',
    retry: '解き直しおつかれさま！'
  };
  var DEF_LABEL = {
    next: 'つぎの%d問へ',
    wrong: '🔁 間違えた %n 問を解き直す',
    modes: 'モード選択にもどる',
    home: 'ホームへ'
  };
  // 既定色は平方根マスターの実値
  var GRADE_COLOR = {
    SSS: 'var(--divp-result-grade-sss,#ff4dff)',
    SS: 'var(--divp-result-grade-ss,#ff9000)',
    S: 'var(--divp-result-grade-s,#f5c842)',
    A: 'var(--divp-result-grade-a,#22c55e)',
    B: 'var(--divp-result-grade-b,#3b82f6)',
    C: 'var(--divp-result-grade-c,#f97316)',
    D: 'var(--divp-result-grade-d,#ef4444)'
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function msg(key) {
    return (cfg.msgs && cfg.msgs[key]) || DEF_MSG[key];
  }
  function label(key) {
    return (cfg.labels && cfg.labels[key]) || DEF_LABEL[key];
  }
  function streakKey() { return cfg.questionKey || '_'; }

  // 設定は毎回まっさらから作り直す（前回のコールバックが残っていて
  // 出ないはずのボタンが出る、という取り違えを起こさないため）
  function resultInit(opts) {
    opts = opts || {};
    cfg = defaults();
    for (var k in cfg) {
      if (Object.prototype.hasOwnProperty.call(opts, k)) cfg[k] = opts[k];
    }
    if (!(cfg.total > 0)) cfg.total = 10;
    injectStyle();
    resultStart({ total: cfg.total, retry: false });
    return Divp;
  }

  // 次のセットを始める。解き直しラウンドは {total:n, retry:true}
  function resultStart(opts) {
    opts = opts || {};
    resultClose();
    st.total = (opts.total > 0) ? opts.total : cfg.total;
    st.retry = !!opts.retry;
    if (opts.questionKey != null) cfg.questionKey = opts.questionKey;
    st.done = 0; st.correct = 0; st.wrong = 0; st.wrongs = []; st.shown = false;
    return Divp;
  }

  // 1問ぶん記録する。セットが終わったら結果画面を出して true を返す
  function resultPush(ok, item) {
    if (st.shown) return false;
    st.done++;
    if (ok) st.correct++;
    else { st.wrong++; if (item !== undefined) st.wrongs.push(item); }
    if (st.done < st.total) return false;
    render();
    return true;
  }

  function resultState() {
    return {
      done: st.done, total: st.total, correct: st.correct, wrong: st.wrong,
      wrongs: st.wrongs.slice(), retry: st.retry, streak: streaks[streakKey()] || 0
    };
  }

  function resultClose() {
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
    overlay = null;
    if (cfg.mount) {
      var m = mountEl();
      if (m) {
        var box = m.querySelector('.divp-result');
        if (box && box.parentNode) box.parentNode.removeChild(box);
      }
    }
  }

  function mountEl() {
    if (!cfg.mount) return null;
    return (typeof cfg.mount === 'string') ? document.querySelector(cfg.mount) : cfg.mount;
  }

  // ── ランク判定（平方根マスターの基準をそのまま） ────────────
  function grade() {
    // 10問未満のセット（＝間違えた問題の解き直し）はランクを付けない。
    // 3問中3問正解でSが出ると、通常セットのSと重みが違ってしまうため。
    if (st.total < 10 || st.retry) return null;
    var key = streakKey();
    var perfect = (st.correct === st.total);
    streaks[key] = perfect ? (streaks[key] || 0) + 1 : 0;
    var run = streaks[key];
    var pct = Math.round(st.correct / st.total * 100);
    if (run >= 3) return { g: 'SSS', m: msg('SSS') + (cfg.masterLabel ? cfg.masterLabel + '！！' : 'すごすぎる！！') };
    if (run >= 2) return { g: 'SS', m: msg('SS') };
    if (perfect) return { g: 'S', m: msg('S') };
    if (pct >= 90) return { g: 'A', m: msg('A') };
    if (pct >= 70) return { g: 'B', m: msg('B') };
    if (pct >= 50) return { g: 'C', m: msg('C') };
    return { g: 'D', m: msg('D') };
  }

  function button(cls, text, onClick, href) {
    var b = document.createElement(href ? 'a' : 'button');
    b.className = 'divp-result-btn' + (cls ? ' ' + cls : '');
    b.textContent = text;
    if (href) b.href = href;
    else {
      b.type = 'button';
      b.addEventListener('click', onClick);
    }
    return b;
  }

  function render() {
    st.shown = true;
    injectStyle();
    var gr = grade();

    var box = document.createElement('div');
    box.className = 'divp-result';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-label', '結果');
    box.innerHTML =
      (cfg.cap ? '<div class="divp-result-cap">' + esc(cfg.cap) + '</div>' : '')
      + (gr
        ? '<div class="divp-result-grade" style="color:' + GRADE_COLOR[gr.g] + ';">' + gr.g + '</div>'
          + '<div class="divp-result-msg">' + esc(gr.m) + '</div>'
        : '<div class="divp-result-msg" style="margin-top:6px;">' + esc(msg('retry')) + '</div>')
      + '<div class="divp-result-score">' + st.correct
      + ' <span>/ ' + st.total + '問正解</span></div>'
      + '<div class="divp-result-break">'
      + '<div class="divp-result-item c">✓ 正解 ' + st.correct + '問</div>'
      + '<div class="divp-result-item w">✗ 不正解 ' + st.wrong + '問</div>'
      + '</div>';

    // ツール固有の追加表示（愛知県大問1のタイム・○✕一覧など）。
    // 文字列でも要素でも、それを返す関数でもよい（関数は描くたびに呼ぶ）。
    var ex = (typeof cfg.extra === 'function') ? cfg.extra() : cfg.extra;
    if (ex) {
      var exBox = document.createElement('div');
      exBox.className = 'divp-result-extra';
      if (ex.nodeType === 1) exBox.appendChild(ex);
      else exBox.innerHTML = String(ex);
      box.appendChild(exBox);
    }

    var btns = document.createElement('div');
    btns.className = 'divp-result-btns';

    // ① つぎの10問へ
    if (cfg.onNext) {
      btns.appendChild(button('primary', label('next').replace('%d', cfg.total), function () {
        resultStart({ total: cfg.total, retry: false });
        cfg.onNext();
      }));
    }
    // ② 間違えた N 問を解き直す（平方根と同じく、間違いがあるときだけ出す）
    if (cfg.onRetryWrong && st.wrongs.length > 0) {
      var wrongs = st.wrongs.slice();
      btns.appendChild(button('wrong', label('wrong').replace('%n', wrongs.length), function () {
        resultStart({ total: wrongs.length, retry: true });
        cfg.onRetryWrong(wrongs);
      }));
    }
    // ③ モード選択にもどる
    if (cfg.onModes) {
      btns.appendChild(button('', label('modes'), function () {
        resultClose();
        cfg.onModes();
      }));
    }
    // ④ ホームへ（学習ツール目次）
    if (cfg.home) btns.appendChild(button('', label('home'), null, cfg.home));

    box.appendChild(btns);

    var m = mountEl();
    if (m) {
      m.innerHTML = '';
      m.appendChild(box);
      if (m.scrollIntoView) m.scrollIntoView({ block: 'center', behavior: 'smooth' });
    } else {
      overlay = document.createElement('div');
      overlay.className = 'divp-result-ov';
      overlay.appendChild(box);
      document.body.appendChild(overlay);
    }
  }

  Divp.resultInit = resultInit;
  Divp.resultPush = resultPush;
  Divp.resultStart = resultStart;
  Divp.resultState = resultState;
  Divp.resultClose = resultClose;
})(window);
