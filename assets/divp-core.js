/*! divp-core.js — 学習記録システム共通クライアント
 * -----------------------------------------------------------------
 * 使い方:
 *   <script src="/assets/divp-core.js"></script>
 *   <script>Divp.init('math_js3_heihokon');</script>   // ページ読み込み時に1回
 *
 *   正誤判定の直後に1行だけ挿入:
 *   Divp.answer(isCorrect, {
 *     question_key: 'truefalse',
 *     question_params: { ... },     // 再生成用（JSONにできるオブジェクト）
 *     question_text: '...',         // 講師・保護者画面向けの問題文
 *     correct_answer: '...',
 *     wrong_answer: '...',          // 生徒が実際に選んだ/入力した答え
 *   });
 *
 * 未ログイン・ローカル起動(file://)・API未疎通の環境では黙って何もしない
 * （safeDivpCorrect()と同じ思想。組み込んでもツールが壊れない）。
 *
 * 更新方法：このファイルを /assets/divp-core.js に上書きするだけで、
 * 読み込んでいる全ツールに一斉反映される（URL固定・.htaccessでキャッシュ制御）。
 */
(function (global) {
  'use strict';
  var Divp = global.Divp = global.Divp || {};
  if (Divp.answer && Divp.answer._divpCore) return;

  var API_BASE = '/api/';
  var unitKey = null;
  var enabled = false;
  var sessionId = null;
  var sessionStarting = null;
  // 'in' = ログイン済 / 'out' = サーバーは応答したが未ログイン(401) /
  // 'unknown' = API未到達など判別不能（この時は案内を出さない）
  var authState = 'unknown';
  var NUDGE_KEY = 'divp_login_nudge_shown';   // 同一タブで一度だけ出す目印(sessionStorage)

  function isLocal() {
    return !!(global.location && global.location.protocol === 'file:');
  }

  function postJSON(path, body) {
    return fetch(API_BASE + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    });
  }

  function startSession() {
    if (sessionStarting) return sessionStarting;
    sessionStarting = postJSON('start_session.php', { unit_key: unitKey })
      .then(function (res) {
        if (!res.ok) {
          // サーバーは応答した（例: 401 未ログイン）→ 案内対象
          enabled = false;
          authState = 'out';
          return;
        }
        return res.json().then(function (data) {
          if (data && data.ok) {
            sessionId = data.session_id;
            enabled = true;
            authState = 'in';
          } else {
            enabled = false;
            authState = 'out';
          }
        });
      })
      .catch(function () { enabled = false; authState = 'unknown'; });
    return sessionStarting;
  }

  function endSession() {
    if (!enabled || !sessionId) return;
    var body = JSON.stringify({ session_id: sessionId });
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(API_BASE + 'end_session.php', new Blob([body], { type: 'application/json' }));
        return;
      }
    } catch (e) {}
    postJSON('end_session.php', { session_id: sessionId }).catch(function () {});
  }

  function init(key) {
    unitKey = key;
    if (isLocal()) return;
    startSession();
    global.addEventListener('pagehide', endSession);
    global.addEventListener('beforeunload', endSession);

    // 学習時間の積算用ハートビート（タブ表示中のみ1分ごと）。
    // ページを閉じた時のend_sessionが届かなくても誤差は最大1分強に収まる
    setInterval(function () {
      if (!enabled || !sessionId) return;
      if (document.visibilityState !== 'visible') return;
      postJSON('heartbeat.php', { session_id: sessionId }).catch(function () {});
    }, 60 * 1000);
  }

  function answer(ok, info) {
    info = info || {};
    if (!unitKey || isLocal()) return;

    var send = function () {
      if (!enabled) {
        // 未ログイン（サーバー応答あり）なら、このタブで初回1問だけそっと案内
        if (authState === 'out') maybeNudgeLogin();
        return;
      }
      postJSON('save_answer.php', {
        session_id: sessionId,
        unit_key: unitKey,
        question_key: info.question_key,
        question_params: info.question_params || null,
        question_text: info.question_text != null ? String(info.question_text) : null,
        correct_answer: info.correct_answer != null ? String(info.correct_answer) : null,
        student_answer: info.wrong_answer != null ? String(info.wrong_answer) : null,
        is_correct: !!ok,
        time_taken_sec: info.time_taken_sec || null,
      }).catch(function () {});
    };

    if (sessionStarting) { sessionStarting.then(send); } else { send(); }
  }

  // 解き直し(pending)一覧を取得する。未ログイン等では空配列を返す
  function getRetries() {
    if (!unitKey || isLocal()) return Promise.resolve([]);
    return fetch(API_BASE + 'list_retries.php?unit_key=' + encodeURIComponent(unitKey), {
      credentials: 'same-origin',
    })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) { return (data && data.ok) ? data.items : []; })
      .catch(function () { return []; });
  }

  // 未ログインで問題を解いたとき、そのタブで一度だけ出すログイン案内。
  // しつこくしない思想：sessionStorageで「見た」印を付け、以後そのタブでは出さない。
  function maybeNudgeLogin() {
    try {
      if (sessionStorage.getItem(NUDGE_KEY)) return;
      sessionStorage.setItem(NUDGE_KEY, '1');
    } catch (e) {
      // プライベートモード等でsessionStorage不可 → その場合は出さない（しつこさ回避を優先）
      return;
    }
    if (!global.document || document.getElementById('divp-login-nudge')) return;

    var bar = document.createElement('div');
    bar.id = 'divp-login-nudge';
    bar.setAttribute('role', 'status');
    bar.style.cssText = [
      'position:fixed', 'left:12px', 'right:12px', 'bottom:12px',
      'z-index:2147483000', 'margin:0 auto', 'max-width:520px',
      'background:#FBFAF6', 'color:#33312B',
      'border:1px solid #E4E0D5', 'border-left:5px solid #C73E2E',
      'border-radius:12px', 'box-shadow:0 6px 24px rgba(0,0,0,.16)',
      'padding:12px 14px', 'display:flex', 'align-items:center', 'gap:10px',
      "font-family:'Zen Kaku Gothic New',system-ui,sans-serif", 'font-size:14px',
      'line-height:1.5', 'animation:divpNudgeIn .28s ease-out'
    ].join(';');

    var msg = document.createElement('div');
    msg.style.cssText = 'flex:1;min-width:0;';
    msg.innerHTML = 'ログインすると、解いた記録がマイページに残るよ📒'
      + '<br><span style="color:#8B877C;font-size:12px;">今は記録されていません</span>';

    var loginBtn = document.createElement('button');
    loginBtn.type = 'button';
    loginBtn.textContent = 'ログイン';
    loginBtn.style.cssText = [
      'flex:0 0 auto', 'background:#C73E2E', 'color:#fff', 'border:0',
      'border-radius:9px', 'padding:8px 14px', 'font-size:14px',
      "font-family:inherit", 'font-weight:700', 'cursor:pointer'
    ].join(';');
    loginBtn.addEventListener('click', function () {
      close();
      // 共通ヘッダーのログイン窓を開く。ヘッダー未読込なら上部へ誘導のみ。
      var hdrBtn = document.getElementById('divp-login-btn');
      if (hdrBtn) {
        try { global.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { global.scrollTo(0, 0); }
        hdrBtn.click();
      } else {
        try { global.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { global.scrollTo(0, 0); }
      }
    });

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'とじる');
    closeBtn.textContent = '×';
    closeBtn.style.cssText = [
      'flex:0 0 auto', 'background:transparent', 'color:#8B877C', 'border:0',
      'font-size:22px', 'line-height:1', 'padding:2px 4px', 'cursor:pointer'
    ].join(';');
    closeBtn.addEventListener('click', close);

    function close() {
      if (bar.parentNode) bar.parentNode.removeChild(bar);
    }

    if (!document.getElementById('divp-nudge-anim')) {
      var st = document.createElement('style');
      st.id = 'divp-nudge-anim';
      st.textContent = '@keyframes divpNudgeIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}';
      document.head.appendChild(st);
    }

    bar.appendChild(msg);
    bar.appendChild(loginBtn);
    bar.appendChild(closeBtn);
    (document.body || document.documentElement).appendChild(bar);

    // 12秒で自動的に引っ込む（sessionStorageの印は残るので再表示はしない）
    setTimeout(close, 12000);
  }

  answer._divpCore = true;
  Divp.init = init;
  Divp.answer = answer;
  Divp.getRetries = getRetries;
})(window);
