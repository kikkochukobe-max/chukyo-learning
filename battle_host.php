<?php
declare(strict_types=1);

// 一般常識バトル（講師の画面）。部屋を作る → 部屋番号を生徒に伝える → スタート → 進行を見る → 結果。
// 生徒の画面は /learning/game/game_es_joshiki_battle.html。API は api/battle_host.php。
// 対戦の進行はサーバーの時刻で決まるので、この画面を閉じても・開き直しても対戦は止まらない。
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$actor = current_actor();

// ---- 未ログイン時: 講師ログインフォーム（report.php と同じ） ----
if (!$actor || $actor['type'] !== 'teacher') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>一般常識バトル（講師） | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6}
  .box{max-width:360px;margin:80px auto;background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);border-top:4px solid var(--ai);padding:28px}
  h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai)}
  p.sub{font-size:12px;color:var(--ink-soft);margin-top:2px}
  label{display:block;font-size:12px;font-weight:700;margin-top:14px}
  input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;margin-top:4px}
  button{margin-top:18px;width:100%;background:var(--ai);color:#fff;border:none;border-radius:8px;
    padding:11px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  .err{color:#c0392b;font-size:12px;margin-top:8px;min-height:16px}
</style>
</head>
<body>
<div class="box">
  <h1>一般常識バトル（講師）</h1>
  <p class="sub">講師アカウントでログインしてください</p>
  <label>ログインID<input type="text" id="lid" autocomplete="username"></label>
  <label>パスワード<input type="password" id="lpw" autocomplete="current-password"></label>
  <button id="lbtn" type="button">ログイン</button>
  <div class="err" id="lerr"></div>
</div>
<script>
document.getElementById('lbtn').addEventListener('click', async () => {
  const err = document.getElementById('lerr');
  err.textContent = '';
  try {
    const res = await fetch('/api/auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        actor_type: 'teacher',
        login_id: document.getElementById('lid').value.trim(),
        password: document.getElementById('lpw').value,
      }),
    });
    const data = await res.json().catch(() => null);
    if (res.ok && data && data.ok) { location.reload(); }
    else if (data && data.error === 'locked') { err.textContent = '失敗が続いたためロック中です。10分後にやり直してください'; }
    else { err.textContent = 'ログインIDかパスワードが違います'; }
  } catch (e) { err.textContent = '通信エラーが発生しました'; }
});
document.getElementById('lpw').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') document.getElementById('lbtn').click();
});
</script>
</body>
</html><?php
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT teacher_name, must_change_password FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$me = $stmt->fetch();
if (!$me) { header('Location: /teacher.php'); exit; }
// 初期パスワードのままなら、変更するまで先に進ませない（teacher.php / admin.php と同じ）
if ((int)$me['must_change_password'] === 1) {
    header('Location: /password.php');
    exit;
}
$viewRoom = (int)($_GET['room'] ?? 0);
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>一般常識バトル（講師） | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--ai-soft:#E8F0F7;
    --shu:#C73E2E;--gold:#C9A227;--ok:#3E8E5A;--ok-bg:#E4F1E9;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6}
  header{display:flex;align-items:center;gap:12px;padding:12px 20px;background:var(--white);
    border-bottom:3px solid var(--ai);flex-wrap:wrap}
  header h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:19px;color:var(--ai)}
  header .who{margin-left:auto;font-size:13px;color:var(--ink-soft)}
  header a{font-size:12px;color:var(--ai);border:1px solid var(--ai);border-radius:999px;padding:4px 12px;text-decoration:none;white-space:nowrap}
  main{max-width:960px;margin:0 auto;padding:20px 16px 60px}
  .card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:20px;margin-bottom:18px}
  .card h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;color:var(--ai);margin-bottom:12px}
  .note{font-size:12px;color:var(--ink-soft)}
  .err{color:var(--shu);font-size:13px;font-weight:700;min-height:18px;margin-top:8px}
  button{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;cursor:pointer}
  .btn{background:var(--ai);color:#fff;border:none;border-radius:10px;padding:12px 22px;font-size:15px}
  .btn:disabled{opacity:.4;cursor:default}
  .btn.big{font-size:20px;padding:16px 40px}
  .btn.ghost{background:transparent;color:var(--ai);border:1.5px solid var(--ai)}
  .btn.danger{background:transparent;color:var(--shu);border:1.5px solid var(--shu)}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .lv{border:2px solid var(--grid);background:var(--white);border-radius:12px;padding:10px 16px;min-width:120px;text-align:left}
  .lv b{display:block;font-size:17px;color:var(--ink)}
  .lv span{font-size:12px;color:var(--ink-soft)}
  .lv.on{border-color:var(--ai);background:var(--ai-soft)}
  .lv:disabled{opacity:.45;cursor:default}
  select{font-size:16px;padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;font-family:inherit}
  .code-box{text-align:center;padding:10px 0 4px}
  .code-label{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;color:var(--ink-soft);font-size:15px}
  .code{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:88px;letter-spacing:.12em;color:var(--ai);line-height:1.1}
  .meta{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin:8px 0}
  .pill{font-size:13px;font-weight:700;border-radius:999px;padding:3px 12px;background:var(--ai-soft);color:var(--ai)}
  .players{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
  .pl{background:var(--paper);border:1px solid var(--grid);border-radius:999px;padding:4px 12px;font-size:14px}
  .pl small{color:var(--ink-soft);margin-left:4px}
  .pl.stale{opacity:.45}
  .big-count{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:22px}
  .progress{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}
  .qno{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:22px;color:var(--ai)}
  .timer{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:28px;margin-left:auto}
  .bar{height:8px;background:var(--grid);border-radius:99px;overflow:hidden;margin:8px 0 14px}
  .bar i{display:block;height:100%;background:var(--ai);width:100%}
  .qtext{font-size:19px;font-weight:700;line-height:1.6}
  .qcat{font-size:12px;color:var(--ink-soft)}
  .choices{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:12px}
  .ch{border:2px solid var(--grid);border-radius:10px;padding:8px 12px;font-size:16px;background:var(--white)}
  .ch b{color:var(--ink-soft);margin-right:6px}
  .ch.ok{border-color:var(--ok);background:var(--ok-bg);font-weight:700}
  .expl{margin-top:10px;font-size:13px;background:var(--paper);border-radius:8px;padding:8px 12px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{padding:7px 8px;border-bottom:1px solid var(--grid);text-align:left}
  th{font-size:12px;color:var(--ink-soft);font-weight:700}
  th.num,td.num{text-align:right;font-variant-numeric:tabular-nums}
  td.rank{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;width:56px}
  tr.top1 td.rank{color:var(--gold)}
  tr.dq td{color:var(--ink-soft)}
  .dqtag{color:var(--shu);font-weight:700;font-size:12px}
  .rq{border-top:1px solid var(--grid);padding:10px 0}
  .rq .ans{color:var(--ok);font-weight:700}
  .hist td a{color:var(--ai)}
  .status{font-size:12px;font-weight:700}
  @media (max-width:600px){.code{font-size:64px}.choices{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
  <h1>一般常識バトル（講師）</h1>
  <span class="who"><?= h($me['teacher_name']) ?> 先生</span>
  <a href="/teacher.php">講師ページへ</a>
</header>
<main>
  <div id="view"></div>
  <div class="card" id="hist-card">
    <h2>これまでの部屋</h2>
    <div id="hist" class="note">読み込み中…</div>
  </div>
</main>
<script>
(function () {
  'use strict';
  var API = '/api/battle_host.php';
  var STUDENT_URL = location.origin + '/learning/game/game_es_joshiki_battle.html';
  var VIEW_ROOM = <?= $viewRoom ?>;
  var view = document.getElementById('view');

  // ---- サーバーの時計に合わせる（端末の時計は使わない。performance.now 基準） ----
  var clock = { off: null, rtt: Infinity };
  function nowMs() { return (window.performance && performance.now) ? performance.now() : Date.now(); }
  function serverNow() { return clock.off === null ? 0 : nowMs() + clock.off; }
  function syncClock(serverMs, t0, t1) {
    var rtt = t1 - t0, off = serverMs - (t0 + t1) / 2;
    // 往復の短い測定ほど正確なので、それを採る（大きくずれたら＝スリープ明けなどは測り直す）
    if (clock.off === null || rtt <= clock.rtt || Math.abs(off - clock.off) > 2000) { clock.off = off; clock.rtt = rtt; }
  }
  function api(params, body) {
    var t0 = nowMs();
    var p = body
      ? fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) })
      : fetch(API + '?' + new URLSearchParams(params).toString(), { credentials: 'same-origin', cache: 'no-store' });
    return p.then(function (res) {
      var t1 = nowMs();
      return res.json().catch(function () { return null; }).then(function (data) {
        if (data && typeof data.now_ms === 'number') syncClock(data.now_ms, t0, t1);
        return { status: res.status, data: data || { ok: false, error: 'bad_response' } };
      });
    }).catch(function () { return { status: 0, data: { ok: false, error: 'network' } }; });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var MARKS = ['ア', 'イ', 'ウ', 'エ', 'オ', 'カ'];
  var ERR = {
    room_open: 'まだ開いている部屋があります。先にその部屋を閉じてください',
    not_enough_questions: 'この難易度の問題が足りません（問題のSQLが流れていない可能性があります）',
    no_players: '待合室で画面を開いている生徒がいません',
    not_lobby: 'この部屋はもうスタートしています',
    network: '通信できませんでした。もう一度押してください',
    unauthenticated: 'ログインが切れました。ページを開き直してください'
  };
  function errText(d) { return ERR[d && d.error] || ('エラー: ' + ((d && d.error) || '不明')); }

  // ---- 状態 ----
  var levels = [], countDef = { min: 10, max: 100, step: 5 };
  var selLevel = 1, selCount = 20;
  var current = null;      // 開いている部屋の room_id
  var last = null;         // 直近の state 応答
  var pollTimer = null, tickTimer = null;

  function stopTimers() {
    if (pollTimer) clearTimeout(pollTimer);
    if (tickTimer) clearInterval(tickTimer);
    pollTimer = tickTimer = null;
  }

  // ---- 部屋一覧（最初とひと区切りごと） ----
  function loadRooms() {
    return api({ action: 'rooms' }).then(function (r) {
      var d = r.data;
      if (!d.ok) { view.innerHTML = '<div class="card"><p class="err">' + esc(errText(d)) + '</p></div>'; return; }
      levels = d.levels; countDef = d.count;
      renderHistory(d.rooms);
      if (VIEW_ROOM) { var id = VIEW_ROOM; VIEW_ROOM = 0; openRoom(id); }
      else if (d.open) openRoom(d.open);
      else renderCreate();
    });
  }

  function renderHistory(rooms) {
    var box = document.getElementById('hist');
    if (!rooms.length) { box.textContent = 'まだありません'; return; }
    var st = { lobby: '待合室', playing: '対戦中', finished: '終了', cancelled: '中止' };
    box.innerHTML = '<table class="hist"><tr><th>作成</th><th>部屋番号</th><th>難易度</th><th>問題数</th><th>参加</th><th>状態</th><th></th></tr>'
      + rooms.map(function (r) {
        return '<tr><td>' + esc(r.created_at.slice(5, 16)) + '</td><td>' + esc(r.code) + '</td><td>' + esc(r.level_label)
          + '</td><td class="num">' + r.count + '問</td><td class="num">' + r.n_players + '人</td><td class="status">' + esc(st[r.status] || r.status)
          + '</td><td>' + (r.status === 'cancelled' ? '' : '<a href="?room=' + r.room_id + '">' + (r.status === 'finished' ? '結果' : '開く') + '</a>') + '</td></tr>';
      }).join('') + '</table>';
  }

  // ---- 部屋を作る ----
  function renderCreate() {
    stopTimers();
    current = null;
    var opts = '';
    for (var n = countDef.min; n <= countDef.max; n += countDef.step) {
      opts += '<option value="' + n + '"' + (n === selCount ? ' selected' : '') + '>' + n + '問</option>';
    }
    view.innerHTML = '<div class="card"><h2>部屋を作る</h2>'
      + '<p class="note">難易度と問題数を決めて部屋を作ると、4桁の部屋番号が出ます。生徒は「学習ツール一覧」の一般常識バトルを開いて、その番号を入れます。</p>'
      + '<p style="margin-top:14px;font-weight:700">難易度</p><div class="row" id="lvs">'
      + levels.map(function (l) {
        return '<button type="button" class="lv' + (l.level === selLevel ? ' on' : '') + '" data-lv="' + l.level + '"' + (l.available < countDef.min ? ' disabled' : '') + '>'
          + '<b>' + esc(l.label) + '</b><span>1問' + l.sec + '秒' + (l.calc_sec !== l.sec ? '（計算は' + l.calc_sec + '秒）' : '')
          + '・' + l.available + '問から出題</span></button>';
      }).join('') + '</div>'
      + '<p style="margin-top:14px;font-weight:700">問題数</p><div class="row"><select id="cnt">' + opts + '</select>'
      + '<span class="note" id="est"></span></div>'
      + '<div style="margin-top:18px"><button type="button" class="btn big" id="mk">部屋を作る</button></div>'
      + '<p class="err" id="mkerr"></p></div>';
    var est = function () {
      var l = levels.filter(function (x) { return x.level === selLevel; })[0];
      if (!l) return;
      // 計算問題がどれだけ混ざるかはくじ引きなので、問題バンクの割合で見こむ
      var ratio = l.available ? l.calc / l.available : 0;
      var per = l.sec + ratio * (l.calc_sec - l.sec);
      var sec = selCount * (per + 5) + 5;
      document.getElementById('est').textContent = '終わるまで 約' + Math.ceil(sec / 60) + '分（1問' + l.sec + '秒'
        + (l.calc_sec !== l.sec ? '・計算問題は' + l.calc_sec + '秒' : '') + '＋正解発表5秒）';
    };
    Array.prototype.forEach.call(document.querySelectorAll('#lvs .lv'), function (b) {
      b.addEventListener('click', function () {
        selLevel = +b.dataset.lv;
        Array.prototype.forEach.call(document.querySelectorAll('#lvs .lv'), function (x) { x.classList.toggle('on', x === b); });
        est();
      });
    });
    document.getElementById('cnt').addEventListener('change', function (e) { selCount = +e.target.value; est(); });
    est();
    document.getElementById('mk').addEventListener('click', function () {
      var btn = this;
      btn.disabled = true;
      api(null, { action: 'create', level: selLevel, count: selCount }).then(function (r) {
        btn.disabled = false;
        if (r.data.ok) { openRoom(r.data.room_id); loadHistoryOnly(); }
        else if (r.data.error === 'room_open') { openRoom(r.data.room_id); }
        else document.getElementById('mkerr').textContent = errText(r.data);
      });
    });
  }

  function loadHistoryOnly() {
    api({ action: 'rooms' }).then(function (r) { if (r.data.ok) renderHistory(r.data.rooms); });
  }

  // ---- 開いている部屋 ----
  function openRoom(id) {
    stopTimers();
    current = id;
    poll();
    tickTimer = setInterval(drawTimer, 200);
  }

  function poll() {
    if (!current) return;
    api({ action: 'state', room_id: current }).then(function (r) {
      if (!current) return;
      if (!r.data.ok) {
        if (r.data.error === 'network') { pollTimer = setTimeout(poll, 2000); return; }
        view.innerHTML = '<div class="card"><p class="err">' + esc(errText(r.data)) + '</p></div>';
        return;
      }
      var prevStatus = last && last.room.status;
      last = r.data;
      render();
      var st = last.room.status;
      if (st === 'finished') { stopTimers(); loadResult(); if (prevStatus !== 'finished') loadHistoryOnly(); return; }
      if (st === 'cancelled') { stopTimers(); loadHistoryOnly(); return; }
      pollTimer = setTimeout(poll, st === 'lobby' ? 2000 : 1000);
    });
  }

  function roomMeta(room) {
    return '<div class="meta"><span class="pill">' + esc(room.level_label) + '</span><span class="pill">' + room.count
      + '問</span><span class="pill">1問' + room.limit_sec + '秒'
      + (room.calc_sec && room.calc_sec !== room.limit_sec ? '（計算は' + room.calc_sec + '秒）' : '')
      + '</span><span class="pill">1問' + room.point + '点</span></div>';
  }

  function render() {
    var d = last, room = d.room;
    if (room.status === 'lobby') return renderLobby(d);
    if (room.status === 'cancelled') {
      view.innerHTML = '<div class="card"><h2>部屋 ' + esc(room.code) + ' は閉じました</h2>'
        + '<button type="button" class="btn" id="again">新しい部屋を作る</button></div>';
      document.getElementById('again').addEventListener('click', renderCreate);
      return;
    }
    if (room.status === 'playing') return renderPlaying(d);
  }

  function renderLobby(d) {
    var room = d.room;
    var alive = d.players.filter(function (p) { return p.alive; });
    view.innerHTML = '<div class="card"><div class="code-box"><div class="code-label">部屋番号</div>'
      + '<div class="code">' + esc(room.code) + '</div>' + roomMeta(room)
      + '<p class="note">生徒は <b>' + esc(STUDENT_URL) + '</b> を開いて、この番号を入れます</p></div></div>'
      + '<div class="card"><h2>待合室 <span class="big-count">' + alive.length + '</span> 人</h2>'
      + '<div class="players">' + (d.players.length ? d.players.map(function (p) {
        return '<span class="pl' + (p.alive ? '' : ' stale') + '">' + esc(p.name) + '<small>' + esc(p.classroom) + '</small></span>';
      }).join('') : '<span class="note">まだだれもいません</span>') + '</div>'
      + '<p class="note" style="margin-top:10px">うすい名前＝いま画面を開いていない生徒（スタートしても参加しません）。'
      + 'スタートすると、そのあと画面を離れた生徒（ほかのアプリ・タブ・画面を閉じる）は失格になります。</p>'
      + '<div class="row" style="margin-top:16px"><button type="button" class="btn big" id="go"' + (alive.length ? '' : ' disabled') + '>スタート</button>'
      + '<button type="button" class="btn danger" id="close">部屋を閉じる</button></div><p class="err" id="lerr"></p></div>';
    document.getElementById('go').addEventListener('click', function () {
      var btn = this;
      btn.disabled = true;
      api(null, { action: 'start', room_id: room.room_id }).then(function (r) {
        if (!r.data.ok) { btn.disabled = false; document.getElementById('lerr').textContent = errText(r.data); return; }
        if (pollTimer) clearTimeout(pollTimer);
        poll();
        loadHistoryOnly();
      });
    });
    document.getElementById('close').addEventListener('click', function () { cancelRoom(room); });
  }

  function cancelRoom(room) {
    if (!confirm('部屋 ' + room.code + ' を閉じますか？' + (room.status === 'playing' ? '\n対戦は中止になり、結果は残りません。' : ''))) return;
    api(null, { action: 'cancel', room_id: room.room_id }).then(function () {
      if (pollTimer) clearTimeout(pollTimer);
      poll();
    });
  }

  function renderPlaying(d) {
    var room = d.room, q = d.question;
    var html = '<div class="card"><div class="progress">';
    if (room.phase === 'countdown') {
      html += '<span class="qno">まもなくスタート</span><span class="timer" id="tm"></span></div>';
    } else if (q) {
      html += '<span class="qno">第' + q.no + '問 / ' + room.count + '</span><span class="pill">部屋 ' + esc(room.code) + '</span>'
        + '<span class="timer" id="tm"></span></div><div class="bar"><i id="tb"></i></div>'
        + '<div class="qcat">' + esc(q.category)
        + ((q.close_ms - q.open_ms) / 1000 > room.limit_sec ? '　｜ 計算問題・' + Math.round((q.close_ms - q.open_ms) / 1000) + '秒' : '')
        + '</div><div class="qtext">' + esc(q.text) + '</div>'
        + '<div class="choices">' + q.choices.map(function (c, i) {
          return '<div class="ch' + (q.correct === i ? ' ok' : '') + '"><b>' + MARKS[i] + '</b>' + esc(c) + '</div>';
        }).join('') + '</div>'
        + '<p style="margin-top:10px;font-weight:700">回答 ' + q.n_answered + ' / ' + d.standings.filter(function (s) { return !s.dq; }).length + '人'
        + (q.correct != null ? '　正解 ' + q.n_correct + '人' : '') + '</p>'
        + (q.correct != null && q.explanation ? '<div class="expl">' + esc(q.explanation) + '</div>' : '');
    } else {
      html += '<span class="qno">集計中…</span></div>';
    }
    html += '<div class="row" style="margin-top:14px"><button type="button" class="btn danger" id="abort">対戦を中止する</button></div></div>';
    html += '<div class="card"><h2>順位（正解発表まで）</h2>' + standingsTable(d.standings) + '</div>';
    view.innerHTML = html;
    document.getElementById('abort').addEventListener('click', function () { cancelRoom(room); });
    drawTimer();
  }

  function drawTimer() {
    if (!last || last.room.status !== 'playing') return;
    var tm = document.getElementById('tm');
    if (!tm) return;
    var now = serverNow(), room = last.room, q = last.question;
    if (room.phase === 'countdown' || !q) {
      tm.textContent = room.start_ms ? Math.max(0, Math.ceil((room.start_ms - now) / 1000)) + '' : '';
      return;
    }
    var left = q.close_ms - now, tb = document.getElementById('tb');
    tm.textContent = left > 0 ? Math.ceil(left / 1000) + '秒' : '正解発表';
    if (tb) tb.style.width = Math.max(0, Math.min(100, left / (q.close_ms - q.open_ms) * 100)) + '%';
  }

  function standingsTable(list) {
    if (!list.length) return '<p class="note">参加者がいません</p>';
    return '<table><tr><th>順位</th><th>名前</th><th>教室</th><th class="num">正解</th><th class="num">点数</th><th></th></tr>'
      + list.map(function (s) {
        return '<tr class="' + (s.dq ? 'dq' : (s.rank === 1 ? 'top1' : '')) + '"><td class="rank">' + (s.dq ? '−' : s.rank + '位') + '</td><td>' + esc(s.name)
          + '</td><td>' + esc(s.classroom) + '</td><td class="num">' + s.correct + '</td><td class="num"><b>' + s.score + '</b></td><td>'
          + (s.dq ? '<span class="dqtag">失格' + (s.dq_no ? '（第' + s.dq_no + '問）' : '') + '</span> <span class="note">' + esc(s.dq_reason) + '</span>' : '') + '</td></tr>';
      }).join('') + '</table>';
  }

  // ---- 結果 ----
  function loadResult() {
    api({ action: 'result', room_id: current }).then(function (r) {
      if (!r.data.ok) { view.innerHTML = '<div class="card"><p class="err">' + esc(errText(r.data)) + '</p></div>'; return; }
      var d = r.data, room = d.room;
      var nPlay = d.standings.filter(function (s) { return !s.dq; }).length + d.standings.filter(function (s) { return s.dq; }).length;
      view.innerHTML = '<div class="card"><h2>結果　部屋 ' + esc(room.code) + '</h2>' + roomMeta(room) + standingsTable(d.standings)
        + '<div class="row" style="margin-top:16px"><button type="button" class="btn" id="again">新しい部屋を作る</button></div></div>'
        + '<div class="card"><details><summary style="cursor:pointer;font-weight:700;color:var(--ai)">出題した ' + d.review.length + ' 問と正解した人数</summary>'
        + d.review.map(function (q) {
          return '<div class="rq"><div class="qcat">第' + q.no + '問・' + esc(q.category) + '　正解 ' + q.n_correct + ' / ' + nPlay + '人</div>'
            + '<div style="font-weight:700">' + esc(q.text) + '</div>'
            + '<div>答え: <span class="ans">' + MARKS[q.correct] + ' ' + esc(q.choices[q.correct]) + '</span></div>'
            + (q.explanation ? '<div class="note">' + esc(q.explanation) + '</div>' : '') + '</div>';
        }).join('') + '</details></div>';
      document.getElementById('again').addEventListener('click', function () { history.replaceState(null, '', location.pathname); renderCreate(); });
    });
  }

  loadRooms();
})();
</script>
</body>
</html>
