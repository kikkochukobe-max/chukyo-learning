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
        if (!res.ok) { enabled = false; return; }
        return res.json().then(function (data) {
          if (data && data.ok) {
            sessionId = data.session_id;
            enabled = true;
          } else {
            enabled = false;
          }
        });
      })
      .catch(function () { enabled = false; });
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
      if (!enabled) return;
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

  answer._divpCore = true;
  Divp.init = init;
  Divp.answer = answer;
  Divp.getRetries = getRetries;
})(window);
