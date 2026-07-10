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

  // ── 未ログインお試し上限（体験授業への誘導）─────────────────────────
  // 未ログインで解けるのはツール(unit_key)ごとに TRIAL_LIMIT 問まで。
  // 上限に達したら全画面ロックを出し、体験授業の申込ページへ誘導する。
  // ログイン済(authState==='in')は無制限。API未到達(authState==='unknown')や
  // file:// では一切カウントもロックもしない（ツールを壊さない思想）。
  var TRIAL_LIMIT = 10;                     // ツールごとの無料お試し問題数
  var TRIAL_KEY_PREFIX = 'divp_trial_';     // localStorage: divp_trial_<unitKey> に累計解答数
  // 体験授業・お問い合わせページ
  var TRIAL_APPLY_URL = 'https://chukyokobetsu.com/contact';
  var trialCountMem = {};                   // localStorage不可時のフォールバック（このページ限り）
  var trialWallShown = false;               // ロックの二重表示防止

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
    // 認証状態が判明したら、再訪時も既に上限超過なら即ロック復元
    startSession().then(function () {
      if (authState === 'out' && getTrialCount() >= TRIAL_LIMIT) showTrialWall();
    });
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
        // 未ログイン（サーバー応答あり）→ お試し回数をカウント。
        // 上限に達したら全画面ロック、まだなら初回だけそっとログイン案内。
        if (authState === 'out') {
          var used = bumpTrialCount();
          if (used >= TRIAL_LIMIT) showTrialWall();
          else maybeNudgeLogin();
        }
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
      'position:fixed', 'top:50%', 'left:50%', 'transform:translate(-50%,-50%)',
      'z-index:2147483000', 'width:min(460px,90vw)', 'min-height:44vh',
      'background:#FBFAF6', 'color:#33312B',
      'border:1px solid #E4E0D5', 'border-top:6px solid #C73E2E',
      'border-radius:18px', 'box-shadow:0 12px 48px rgba(0,0,0,.28)',
      'padding:28px 24px', 'display:flex', 'flex-direction:column',
      'align-items:center', 'justify-content:center', 'text-align:center', 'gap:16px',
      "font-family:'Zen Kaku Gothic New',system-ui,sans-serif",
      'line-height:1.5', 'animation:divpNudgeIn .28s ease-out'
    ].join(';');

    var msg = document.createElement('div');
    msg.style.cssText = 'flex:0 0 auto;';
    msg.innerHTML = '<div style="font-size:44px;line-height:1;margin-bottom:6px;">📒</div>'
      + '<div style="font-family:\'Zen Maru Gothic\',sans-serif;font-weight:900;font-size:22px;">ログインすると 記録が残るよ</div>'
      + '<div style="color:#8B877C;font-size:14px;margin-top:10px;">今は記録されていません。<br>ログインすると、この問題もマイページに残せます。</div>';

    var loginBtn = document.createElement('button');
    loginBtn.type = 'button';
    loginBtn.textContent = 'ログイン';
    loginBtn.style.cssText = [
      'flex:0 0 auto', 'width:100%', 'max-width:280px',
      'background:#C73E2E', 'color:#fff', 'border:0',
      'border-radius:12px', 'padding:14px', 'font-size:17px',
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
      'position:absolute', 'top:10px', 'right:14px',
      'background:transparent', 'color:#8B877C', 'border:0',
      'font-size:26px', 'line-height:1', 'padding:4px 8px', 'cursor:pointer'
    ].join(';');
    closeBtn.addEventListener('click', close);

    function close() {
      if (bar.parentNode) bar.parentNode.removeChild(bar);
    }

    if (!document.getElementById('divp-nudge-anim')) {
      var st = document.createElement('style');
      st.id = 'divp-nudge-anim';
      st.textContent = '@keyframes divpNudgeIn{from{opacity:0;transform:translate(-50%,-50%) scale(.92)}to{opacity:1;transform:translate(-50%,-50%) scale(1)}}';
      document.head.appendChild(st);
    }

    bar.appendChild(msg);
    bar.appendChild(loginBtn);
    bar.appendChild(closeBtn);
    (document.body || document.documentElement).appendChild(bar);

    // 12秒で自動的に引っ込む（sessionStorageの印は残るので再表示はしない）
    setTimeout(close, 12000);
  }

  // ── お試し上限のカウント（ツール=unit_keyごと・端末保存）─────────────
  function trialKey() { return TRIAL_KEY_PREFIX + (unitKey || ''); }

  function getTrialCount() {
    try {
      var v = global.localStorage.getItem(trialKey());
      return v ? (parseInt(v, 10) || 0) : 0;
    } catch (e) {
      return trialCountMem[unitKey] || 0;   // プライベートモード等 → このページ限りで数える
    }
  }

  function bumpTrialCount() {
    var n = getTrialCount() + 1;
    try { global.localStorage.setItem(trialKey(), String(n)); }
    catch (e) { trialCountMem[unitKey] = n; }
    return n;
  }

  // 動作確認・不具合時のリセット用（コンソールから Divp.resetTrial() で0に戻す）
  function resetTrial() {
    try { global.localStorage.removeItem(trialKey()); } catch (e) {}
    trialCountMem[unitKey] = 0;
  }

  // 上限到達時の全画面ロック（閉じられない）。体験授業の申込ページへ誘導。
  // 塾生を締め出さないよう、ヘッダーのログイン窓を開く小さな抜け道だけ用意する。
  function showTrialWall() {
    if (trialWallShown || !global.document) return;
    if (document.getElementById('divp-trial-wall')) { trialWallShown = true; return; }
    trialWallShown = true;

    // 背後のツールを操作・スクロールできないようにする
    try {
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
    } catch (e) {}

    var overlay = document.createElement('div');
    overlay.id = 'divp-trial-wall';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.style.cssText = [
      'position:fixed', 'inset:0', 'z-index:2147483600',
      'background:rgba(51,49,43,.55)',
      'backdrop-filter:blur(3px)', '-webkit-backdrop-filter:blur(3px)',
      'display:flex', 'align-items:center', 'justify-content:center',
      'padding:20px', 'box-sizing:border-box',
      "font-family:'Zen Kaku Gothic New',system-ui,sans-serif",
      'animation:divpWallIn .3s ease-out'
    ].join(';');

    var card = document.createElement('div');
    card.style.cssText = [
      'width:min(480px,94vw)', 'max-height:90vh', 'overflow:auto',
      'background:#FBFAF6', 'color:#33312B',
      'border-radius:20px', 'border-top:7px solid #C73E2E',
      'box-shadow:0 18px 60px rgba(0,0,0,.4)',
      'padding:32px 26px', 'text-align:center', 'box-sizing:border-box'
    ].join(';');
    card.innerHTML =
        '<div style="font-size:52px;line-height:1;margin-bottom:8px;">💮</div>'
      + '<div style="font-family:\'Zen Maru Gothic\',sans-serif;font-weight:900;font-size:23px;line-height:1.4;">ここまで よくがんばったね！</div>'
      + '<div style="color:#5c584f;font-size:15px;margin-top:14px;line-height:1.7;">'
      +   'おためしで解ける<b style="color:#C73E2E;">' + TRIAL_LIMIT + '問</b>が おわりました。<br>'
      +   'もっと解きたい人は、<b>無料体験授業</b>で<br>先生といっしょに勉強してみよう！'
      + '</div>';

    var cta = document.createElement('a');
    cta.href = TRIAL_APPLY_URL;
    cta.textContent = '無料体験授業を申し込む';
    cta.style.cssText = [
      'display:block', 'margin:22px auto 0', 'max-width:320px',
      'background:#C73E2E', 'color:#fff', 'text-decoration:none',
      'border-radius:13px', 'padding:15px 18px', 'font-size:17px',
      "font-family:'Zen Maru Gothic',sans-serif", 'font-weight:700',
      'box-shadow:0 4px 0 #9e2f22'
    ].join(';');

    // 塾生の抜け道：ヘッダーのログイン窓を開く（ログイン成功→reloadで自然に解除）
    var loginLink = document.createElement('button');
    loginLink.type = 'button';
    loginLink.textContent = '塾生の方はこちら（ログイン）';
    loginLink.style.cssText = [
      'display:block', 'margin:16px auto 0', 'background:transparent',
      'border:0', 'color:#2C5F8A', 'font-size:13px',
      'text-decoration:underline', 'cursor:pointer', 'font-family:inherit'
    ].join(';');
    loginLink.addEventListener('click', function () {
      overlay.style.display = 'none';
      try {
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
      } catch (e) {}
      try { global.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { global.scrollTo(0, 0); }
      var hdrBtn = document.getElementById('divp-login-btn');
      if (hdrBtn) hdrBtn.click();
    });

    if (!document.getElementById('divp-wall-anim')) {
      var st = document.createElement('style');
      st.id = 'divp-wall-anim';
      st.textContent = '@keyframes divpWallIn{from{opacity:0}to{opacity:1}}';
      document.head.appendChild(st);
    }

    card.appendChild(cta);
    card.appendChild(loginLink);
    overlay.appendChild(card);
    (document.body || document.documentElement).appendChild(overlay);
  }

  answer._divpCore = true;
  Divp.init = init;
  Divp.answer = answer;
  Divp.getRetries = getRetries;
  Divp.resetTrial = resetTrial;
})(window);
