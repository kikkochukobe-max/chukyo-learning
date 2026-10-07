<?php
declare(strict_types=1);

// 一般常識バトルの賞状（A4縦・縦書き）。講師画面（/battle_host.php）の結果・合算から開く。
//   ?room=12        … その回の優勝チームのメンバー全員と、個人の1〜3位
//   ?total=3,5,8    … 合算の優勝チームのメンバー全員と、合算の個人1〜3位
// だれに何位を出すかはサーバーが決める（battle_standings / battle_team_standings / battle_total_standings
// の順位をそのまま使う＝講師画面の表と食い違わない）。大会名・日付・敬称・枠の有無・印刷する人は
// 画面の上の欄で変えてから印刷する（その場の調整なので保存しない）。
// 同点は全員に出す（3位が2人なら2人とも3位）。優勝チームが同点で2つあれば両方のメンバーに出す。
// 失格した生徒は個人の部には入らない（順位が無い）。優勝チームのメンバーには入れるが、最初はチェックを外しておく。
// 用紙は A4縦。紙の高さぴったりに器を作ると空白のページが出るので、.sheet の高さは数mm余裕を残す。
require_once __DIR__ . '/api/battle_common.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function award_fail(string $msg): void
{
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>賞状の印刷</title></head>'
        . '<body style="font-family:sans-serif;padding:40px"><p>' . h($msg) . '</p>'
        . '<p><a href="/battle_host.php">一般常識バトル（講師）へもどる</a></p></body></html>';
    exit;
}

$actor = current_actor();
if (!$actor || $actor['type'] !== 'teacher') {
    header('Location: /battle_host.php');
    exit;
}
$teacherId = (int)$actor['id'];
$pdo = db();
$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :t AND is_active = 1');
$stmt->execute(['t' => $teacherId]);
$role = $stmt->fetchColumn();
if ($role === false) {
    header('Location: /battle_host.php');
    exit;
}
$isSuper = $role === 'super_admin';
$now = battle_now_ms();

// 終了した部屋を読む（作った講師と統括だけ。api/battle_host.php の load_my_room と同じ権限）
function award_room(PDO $pdo, int $roomId, int $teacherId, bool $isSuper, int $now): array
{
    $room = $roomId > 0 ? battle_load_room($pdo, $roomId) : null;
    if (!$room) {
        award_fail('その部屋は見つかりません。');
    }
    if ((int)$room['host_teacher_id'] !== $teacherId && !$isSuper) {
        award_fail('ほかの先生の部屋の賞状は印刷できません。');
    }
    $room = battle_tick($pdo, $room, $now);
    if ($room['status'] !== 'finished') {
        award_fail('部屋 ' . $room['room_code'] . ' はまだ終わっていません。終わってから開いてください。');
    }
    return $room;
}

$teams = [];        // 優勝チーム [{name, color, members:[{name, classroom, dq, note}]}]
$individuals = [];  // 個人の1〜3位 [{rank, name, classroom, score}]

if (isset($_GET['total'])) {
    $ids = [];
    foreach (explode(',', (string)$_GET['total']) as $v) {
        $id = (int)trim($v);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
    if (!$ids) {
        award_fail('合算する回が選ばれていません。');
    }
    if (count($ids) > BATTLE_TOTAL_MAX) {
        award_fail('一度に合算できるのは' . BATTLE_TOTAL_MAX . '回までです。');
    }
    sort($ids);
    $rooms = [];
    foreach ($ids as $id) {
        $room = award_room($pdo, $id, $teacherId, $isSuper, $now);
        if (battle_is_practice($room)) {
            award_fail('部屋 ' . $room['room_code'] . ' は練習の回なので、合算に入れられません。');
        }
        $rooms[] = $room;
    }
    $tot = battle_total_standings($pdo, $rooms);
    $n = count($rooms);
    foreach ($tot['team_standings'] ?? [] as $t) {
        if ($t['rank'] !== 1) {
            continue;
        }
        // そのチームで1回以上出た生徒（回ごとに顔ぶれが変わってよい）。何回そのチームで出たかを添える
        $members = [];
        foreach ($tot['standings'] as $s) {
            $k = 0;
            foreach ($s['per'] as $p) {
                if ($p !== null && $p['team'] === $t['team']) {
                    $k++;
                }
            }
            if ($k > 0) {
                $members[] = [
                    'name'      => $s['name'],
                    'classroom' => $s['classroom'],
                    'dq'        => false,
                    'note'      => $t['name'] . 'チームで ' . $k . '/' . $n . '回' . ($s['n_dq'] ? '・失格' . $s['n_dq'] . '回' : ''),
                ];
            }
        }
        $teams[] = ['name' => $t['name'], 'color' => $t['color'], 'members' => $members];
    }
    foreach ($tot['standings'] as $s) {
        if ($s['rank'] <= 3) {
            $individuals[] = ['rank' => $s['rank'], 'name' => $s['name'], 'classroom' => $s['classroom'], 'score' => $s['total']];
        }
    }
    $last = $tot['rounds'][$n - 1];
    $award = [
        'mode'     => 'total',
        'rounds'   => $n,
        'label'    => '全' . $n . '回の合算',
        'date'     => substr($last['date'], 0, 10),
        'practice' => false,
    ];
} else {
    $room = award_room($pdo, (int)($_GET['room'] ?? 0), $teacherId, $isSuper, $now);
    $standings = battle_standings($pdo, (int)$room['room_id']);
    foreach (battle_team_standings($room, $standings) as $t) {
        if ($t['rank'] !== 1) {
            continue;
        }
        $members = [];
        foreach ($standings as $s) {
            if ($s['team'] === $t['team']) {
                $members[] = ['name' => $s['name'], 'classroom' => $s['classroom'], 'dq' => $s['dq'], 'note' => $s['dq'] ? '失格' : ''];
            }
        }
        $teams[] = ['name' => $t['name'], 'color' => $t['color'], 'members' => $members];
    }
    foreach ($standings as $s) {
        if (!$s['dq'] && $s['rank'] !== null && $s['rank'] <= 3) {
            $individuals[] = ['rank' => $s['rank'], 'name' => $s['name'], 'classroom' => $s['classroom'], 'score' => $s['score']];
        }
    }
    $award = [
        'mode'     => 'room',
        'rounds'   => 1,
        'label'    => '部屋 ' . $room['room_code'],
        'date'     => substr((string)($room['started_at'] ?? $room['created_at']), 0, 10),
        'practice' => battle_is_practice($room),
    ];
}
$award['teams'] = $teams;
$award['individuals'] = $individuals;
$json = json_encode($award, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>賞状の印刷 | 一般常識バトル</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Zen+Old+Mincho:wght@400;700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  @page{size:A4 portrait;margin:0}
  *{box-sizing:border-box}
  html,body{margin:0}
  body{background:#E9E6DD;color:#1E1C18;-webkit-print-color-adjust:exact;print-color-adjust:exact;
    font-family:'Zen Old Mincho','Yu Mincho','YuMincho','Hiragino Mincho ProN','MS PMincho',serif}

  /* ---- 画面の上の設定欄（印刷には出ない） ---- */
  .toolbar{font-family:'Zen Kaku Gothic New',sans-serif;background:#fff;border-bottom:3px solid #2C5F8A;
    padding:14px 20px;position:sticky;top:0;z-index:5;box-shadow:0 2px 8px rgba(0,0,0,.06)}
  .toolbar h1{font-size:18px;color:#2C5F8A;margin:0 0 8px}
  .toolbar h1 small{font-size:13px;color:#8B877C;margin-left:8px;font-weight:400}
  .opts{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;font-size:14px}
  .opts label{display:flex;align-items:center;gap:6px}
  .opts input[type=text]{width:22em;font-size:14px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font-family:inherit}
  .opts input[type=date],.opts select{font-size:14px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px;font-family:inherit}
  .who{display:flex;flex-wrap:wrap;gap:6px 18px;margin-top:10px;font-size:14px}
  .who .grp{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center}
  .who .grp b{color:#2C5F8A;margin-right:4px}
  .who label{display:flex;align-items:center;gap:4px;white-space:nowrap}
  .who small{color:#8B877C}
  .who .dq{color:#C73E2E}
  .act{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:12px}
  .act button{font-family:inherit;font-weight:700;font-size:15px;background:#2C5F8A;color:#fff;border:none;border-radius:8px;padding:9px 22px;cursor:pointer}
  .act button:disabled{opacity:.4;cursor:default}
  .act .note{font-size:12px;color:#8B877C}
  .warn{font-size:13px;color:#8A5A00;background:#FFF1D6;border-radius:8px;padding:6px 10px;margin-top:8px}
  .empty{font-family:'Zen Kaku Gothic New',sans-serif;text-align:center;padding:60px 20px;color:#8B877C}

  /* ---- 賞状（A4縦・縦書き） ---- */
  .sheets{padding:20px 0 40px}
  .sheet{position:relative;width:210mm;height:291mm;margin:0 auto 12mm;background:#fff;overflow:hidden;
    box-shadow:0 2px 12px rgba(0,0,0,.18);break-after:page;page-break-after:always;break-inside:avoid}
  .sheet.lastp{break-after:auto;page-break-after:auto}
  /* 枠は3種類（画面の上の「枠」で選ぶ。body のクラスで出し分ける）:
     deco = 細い金の二重線＋四隅の飾り（SVG。DECO_SVG）
     gold = 太い金の二重線＋内側の細線
     none = 枠なし（枠つきの賞状用紙に刷るとき） */
  .frame{position:absolute;top:11mm;bottom:11mm;left:11mm;right:11mm;border:2.6mm double #B08A2E;display:none}
  .frame::after{content:'';position:absolute;top:2.4mm;bottom:2.4mm;left:2.4mm;right:2.4mm;border:.35mm solid #B08A2E}
  .deco{position:absolute;top:0;left:0;width:100%;height:100%;display:none}
  body.frame-gold .frame{display:block}
  body.frame-deco .deco{display:block}
  /* 水玉もよう（画面の上の「水玉」で消せる） */
  .dots{position:absolute;top:0;left:0;width:100%;height:100%}
  body.no-dots .dots{display:none}
  /* 本文。縦書きで右から左へ「賞状 → 賞の名前 → 名前 → 本文 → 日付 → 塾名」と並べる。
     列どうしの間は flex の space-between で紙の幅に合わせて均等にあける（名前の長さで崩れない） */
  .content{position:absolute;top:27mm;bottom:27mm;left:26mm;right:26mm;writing-mode:vertical-rl;
    display:flex;flex-direction:column;justify-content:space-between;line-height:1.5}
  /* 上下の位置（padding-top＝縦書きの行頭からの下げ）。紙の上半分に寄らないよう、名前は中ほど、
     日付と塾名は下半分に置く。本文の最長行（約20字）・塾名（8字）が下の枠に当たらない値にしてある */
  .c-title{font-weight:900;font-size:54pt;letter-spacing:.8em;padding-top:8mm}
  .c-award{font-weight:700;font-size:24pt;letter-spacing:.1em;padding-top:30mm}
  .c-name{font-weight:700;padding-top:74mm;letter-spacing:.14em;white-space:nowrap}
  .c-name .hon{font-size:24pt;margin-top:10mm;letter-spacing:0}
  .c-body{font-size:22pt;line-height:1.9;padding-top:6mm;letter-spacing:.06em}
  .c-body p{margin:0}
  .c-date{font-size:17pt;padding-top:96mm;letter-spacing:.08em}
  .c-from{font-weight:700;font-size:27pt;padding-top:128mm;letter-spacing:.16em}

  @media print{
    body{background:none}
    .toolbar,.empty{display:none}
    .sheets{padding:0}
    /* 器(291mm)は紙(297mm)より少し小さい。上に3mmあけて枠を紙の真ん中に寄せる */
    .sheet{margin:3mm 0 0;box-shadow:none}
  }
</style>
</head>
<body>
<div class="toolbar" id="toolbar">
  <h1>賞状の印刷<small id="tb-label"></small></h1>
  <div class="opts">
    <label>大会名 <input type="text" id="o-event"></label>
    <label>日付 <input type="date" id="o-date"></label>
    <label>敬称 <select id="o-hon"><option value="殿">殿</option><option value="さん">さん</option></select></label>
    <label>枠 <select id="o-frame">
      <option value="deco">金の飾り枠</option>
      <option value="gold">太い金の線</option>
      <option value="none">なし（枠つきの賞状用紙に刷るとき）</option>
    </select></label>
    <label><input type="checkbox" id="o-dots" checked> 水玉もよう</label>
  </div>
  <div class="who" id="who"></div>
  <div id="warn"></div>
  <div class="act">
    <button type="button" id="print">印刷する</button>
    <span class="note">A4縦・倍率100%で印刷してください。枠つきの賞状用紙に印刷するときは枠を「なし」にします。</span>
  </div>
</div>
<div class="sheets" id="sheets"></div>
<script>
(function () {
  'use strict';
  var D = <?= $json ?>;

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  // 漢数字（縦書きで「第一位」「令和八年十月七日」と書くため）。0〜999
  function kan(n) {
    var d = '〇一二三四五六七八九';
    if (n < 10) return d.charAt(n);
    if (n < 100) {
      var t = Math.floor(n / 10), o = n % 10;
      return (t > 1 ? d.charAt(t) : '') + '十' + (o ? d.charAt(o) : '');
    }
    if (n < 1000) {
      var h = Math.floor(n / 100), r = n % 100;
      return (h > 1 ? d.charAt(h) : '') + '百' + (r ? kan(r) : '');
    }
    return String(n);
  }
  // 'YYYY-MM-DD' → 「令和八年十月七日」（令和元年＝2019）
  function wareki(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    if (!m) return '';
    var y = +m[1], ry = y - 2018;
    var year = y >= 2019 ? '令和' + (ry === 1 ? '元' : kan(ry)) : kan(y);
    return year + '年' + kan(+m[2]) + '月' + kan(+m[3]) + '日';
  }

  // 賞状を出す人の一覧（チェックを外した人は印刷しない）
  var people = [];
  D.teams.forEach(function (t) {
    t.members.forEach(function (m) {
      people.push({ kind: 'team', team: t.name, color: t.color, name: m.name, classroom: m.classroom, note: m.note, dq: m.dq, on: !m.dq });
    });
  });
  D.individuals.forEach(function (p) {
    people.push({ kind: 'indiv', rank: p.rank, name: p.name, classroom: p.classroom, note: p.score + '点', on: true });
  });

  var opt = {
    event: D.mode === 'total' ? '一般常識バトル（全' + kan(D.rounds) + '回の合計）' : '一般常識バトル',
    date: D.date,
    hon: '殿'
  };

  // ---- 設定欄 ----
  $('tb-label').textContent = D.label;
  $('o-event').value = opt.event;
  $('o-date').value = opt.date;
  $('o-event').addEventListener('input', function () { opt.event = this.value; renderSheets(); });
  $('o-date').addEventListener('change', function () { opt.date = this.value; renderSheets(); });
  $('o-hon').addEventListener('change', function () { opt.hon = this.value; renderSheets(); });
  function setFrame(v) {
    ['deco', 'gold', 'none'].forEach(function (k) { document.body.classList.toggle('frame-' + k, k === v); });
  }
  $('o-frame').addEventListener('change', function () { setFrame(this.value); });
  $('o-dots').addEventListener('change', function () { document.body.classList.toggle('no-dots', !this.checked); });
  setFrame($('o-frame').value);
  if (D.practice) $('warn').innerHTML = '<div class="warn">この部屋は「練習」の回です。</div>';

  function renderWho() {
    var html = '';
    D.teams.forEach(function (t) {
      html += '<div class="grp"><b style="color:' + esc(t.color) + '">優勝 ' + esc(t.name) + 'チーム</b>'
        + people.map(function (p, i) {
          if (p.kind !== 'team' || p.team !== t.name) return '';
          return '<label><input type="checkbox" data-i="' + i + '"' + (p.on ? ' checked' : '') + '>' + esc(p.name)
            + (p.note ? ' <small class="' + (p.dq ? 'dq' : '') + '">（' + esc(p.note) + '）</small>' : '') + '</label>';
        }).join('') + '</div>';
    });
    var ind = people.map(function (p, i) {
      if (p.kind !== 'indiv') return '';
      return '<label><input type="checkbox" data-i="' + i + '"' + (p.on ? ' checked' : '') + '>' + p.rank + '位 ' + esc(p.name)
        + ' <small>（' + esc(p.note) + '）</small></label>';
    }).join('');
    if (ind) html += '<div class="grp"><b>個人の部</b>' + ind + '</div>';
    $('who').innerHTML = html;
    Array.prototype.forEach.call($('who').querySelectorAll('[data-i]'), function (c) {
      c.addEventListener('change', function () { people[+c.dataset.i].on = c.checked; renderSheets(); });
    });
  }

  // ---- 飾り枠（SVG。単位は mm、紙＝210×291 の器いっぱい） ----
  // 細い金の二重線＋四隅の飾り（唐草つき）。市販の賞状テンプレートの枠に寄せた形。
  // （ピンクのリボンを周りに回す案も作ったが、ユーザーの希望で外した）
  // 色・線は属性で持つ（CSS クラスにすると印刷で落ちることがある）。線だけなので
  // 印刷の設定の「背景のグラフィック」がオフでも刷れる。全部の紙で同じなので1回だけ作る
  var DECO_SVG = (function () {
    var corner = function (tr) {
      // 左上の形を作って、ほかの角は裏返して置く: 小さな四角2つ＋ひし形＋点＋ふちに沿った唐草
      return '<g transform="' + tr + '" fill="none" stroke="#C4A35A" stroke-linecap="round">'
        + '<rect x="-5" y="-5" width="10" height="10" stroke-width=".45" fill="#fff"/>'
        + '<rect x="-3.3" y="-3.3" width="6.6" height="6.6" stroke-width=".25"/>'
        + '<path d="M -3.3 0 L 0 -3.3 L 3.3 0 L 0 3.3 Z" stroke-width=".22"/>'
        + '<circle r="1" fill="#C4A35A" stroke="none"/>'
        + '<path d="M 5 0 C 8 -3, 11 3, 14.5 0 C 16.5 -1.6, 18 .6, 16.4 1.6 M 9.5 -.6 C 10.5 -2.6, 12.5 -2.2, 12.6 -1" stroke-width=".3"/>'
        + '<path d="M 0 5 C -3 8, 3 11, 0 14.5 C -1.6 16.5, .6 18, 1.6 16.4 M -.6 9.5 C -2.6 10.5, -2.2 12.5, -1 12.6" stroke-width=".3"/>'
        + '</g>';
    };
    return '<svg class="deco" viewBox="0 0 210 291" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
      + '<rect x="13" y="13" width="184" height="265" fill="none" stroke="#C4A35A" stroke-width=".5"/>'
      + '<rect x="14.8" y="14.8" width="180.4" height="261.4" fill="none" stroke="#C4A35A" stroke-width=".22"/>'
      + corner('translate(13 13)') + corner('translate(197 13) scale(-1 1)')
      + corner('translate(13 278) scale(1 -1)') + corner('translate(197 278) scale(-1 -1)')
      + '</svg>';
  })();

  // ---- 水玉もよう（淡い色・大きさの差を極端に） ----
  // 大きな玉は文字の少ない四隅に置き、枠の内側の線（14.8〜195.2 × 14.8〜276.2mm）で切る
  // ＝枠の外へはみ出しているように見せる（紙のふちで切れると印刷機の刷れない白いふちが出て不格好）。
  // 小さな玉は種つきの乱数で散らす＝印刷のたびに模様が変わらない。玉どうしは重ねない。
  // 色は金の枠に合わせたシャンパン・うすいピンク・うすい水色。文字の下に来ても読めるよう淡くしてある。
  // 線と塗りだけなので、印刷の設定の「背景のグラフィック」がオフでも刷れる
  var DOT_COLORS = ['#EBDFBF', '#F6D5DC', '#D6E5F0'];
  var DOTS_BIG = [
    // [中心x, 中心y, 半径(mm), 色, 濃さ]
    [20, 38, 34, 1, 0.6],      // 左上の大玉（枠で切れる）
    [196, 252, 44, 0, 0.62],   // 右下の特大玉（枠で切れる）
    [184, 26, 17, 2, 0.6],     // 右上
    [26, 266, 21, 2, 0.55],    // 左下
    [198, 122, 11, 1, 0.55],   // 右のふち（半分切れる）
    [12, 168, 9, 0, 0.6],      // 左のふち（半分切れる）
    [104, 270, 8, 1, 0.55],    // 下
    [66, 24, 5.5, 0, 0.6]      // 上
  ];
  var DOTS_RING = [[138, 37, 6, 0.45], [52, 216, 4.5, 0.4], [170, 180, 2.6, 0.45]];   // 細い金の輪 [x, y, 半径, 濃さ]
  // 小さな玉を置かない場所＝文字の列（mm。.content の縦書きの並びから測った値に、ゆとりを足してある）。
  // 文字の下に小さな玉が入ると細かくうるさく見えるため。大きな玉は四隅だけなので、もともと文字に当たらない
  var DOTS_KEEP = [
    [154, 33, 186, 104],   // 賞状
    [138, 55, 154, 152],   // 賞の名前（個人の部 第一位 など）
    [115, 99, 139, 242],   // 名前（長い名前でも）
    [55, 31, 115, 199],    // 本文
    [42, 121, 55, 177],    // 日付
    [24, 153, 42, 245]     // 塾名
  ];
  var DOTS_SVG = (function () {
    var seed = 20261007;
    function rnd() {   // mulberry32（種が同じなら毎回同じ並び）
      seed = (seed + 0x6D2B79F5) | 0;
      var t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    }
    var placed = DOTS_BIG.map(function (d) { return [d[0], d[1], d[2]]; })
      .concat(DOTS_RING.map(function (d) { return [d[0], d[1], d[2]]; }));
    var small = [];
    // 半径 0.5〜4mm を対数で一様に（小さい玉ほど多い）。重なる候補は捨てる
    for (var i = 0; i < 900 && small.length < 46; i++) {
      var r = Math.exp(Math.log(0.5) + rnd() * (Math.log(4) - Math.log(0.5)));
      var x = 17 + rnd() * 176, y = 17 + rnd() * 257;
      var ok = placed.every(function (q) {
        var dx = q[0] - x, dy = q[1] - y;
        return Math.sqrt(dx * dx + dy * dy) > q[2] + r + 2.2;
      }) && DOTS_KEEP.every(function (b) {
        return x + r < b[0] || x - r > b[2] || y + r < b[1] || y - r > b[3];
      });
      if (!ok) continue;
      placed.push([x, y, r]);
      small.push([x, y, r, Math.floor(rnd() * 3)]);
    }
    var f = function (v) { return (+v).toFixed(2); };
    return '<svg class="dots" viewBox="0 0 210 291" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
      + '<defs><clipPath id="dotclip"><rect x="14.8" y="14.8" width="180.4" height="261.4"/></clipPath></defs>'
      + '<g clip-path="url(#dotclip)">'
      + DOTS_BIG.map(function (d) {
        return '<circle cx="' + d[0] + '" cy="' + d[1] + '" r="' + d[2] + '" fill="' + DOT_COLORS[d[3]] + '" opacity="' + d[4] + '"/>';
      }).join('')
      + small.map(function (d) {
        return '<circle cx="' + f(d[0]) + '" cy="' + f(d[1]) + '" r="' + f(d[2]) + '" fill="' + DOT_COLORS[d[3]] + '" opacity=".8"/>';
      }).join('')
      + DOTS_RING.map(function (d) {
        return '<circle cx="' + d[0] + '" cy="' + d[1] + '" r="' + d[2] + '" fill="none" stroke="#CDB37A" stroke-width=".3" opacity="' + d[3] + '"/>';
      }).join('')
      + '</g></svg>';
  })();

  // ---- 賞状 ----
  function nameSize(name) {
    var n = Array.from ? Array.from(name.replace(/\s/g, '')).length : name.length;
    return n <= 5 ? 36 : n <= 7 ? 32 : n <= 9 ? 26 : 22;
  }
  function sheetHTML(p) {
    var ev = opt.event || '一般常識バトル';
    var award, lines;
    if (p.kind === 'team') {
      award = 'チーム対抗の部　優勝';
      lines = ['あなたは' + ev + 'で', p.team + 'チームの一員として', '優勝というすばらしい結果をおさめました', 'よってここに賞します'];
    } else {
      var r = '第' + kan(p.rank) + '位';
      award = '個人の部　' + r;
      lines = ['あなたは' + ev + 'で', r + 'というすばらしい結果をおさめました', 'よってここに賞します'];
    }
    return '<section class="sheet">' + DOTS_SVG + DECO_SVG + '<div class="frame"></div><div class="content">'
      + '<div class="c-title">賞状</div>'
      + '<div class="c-award">' + esc(award) + '</div>'
      + '<div class="c-name"><span style="font-size:' + nameSize(p.name) + 'pt">' + esc(p.name) + '</span><span class="hon">' + esc(opt.hon) + '</span></div>'
      + '<div class="c-body">' + lines.map(function (l) { return '<p>' + esc(l) + '</p>'; }).join('') + '</div>'
      + '<div class="c-date">' + esc(wareki(opt.date)) + '</div>'
      + '<div class="c-from">中京個別指導学院</div>'
      + '</div></section>';
  }
  function renderSheets() {
    var on = people.filter(function (p) { return p.on; });
    $('sheets').innerHTML = on.length ? on.map(sheetHTML).join('')
      : '<p class="empty">' + (people.length ? '印刷する人を選んでください' : '賞状を出す人がいません（参加者がいない回です）') + '</p>';
    // 最後の1枚だけ改ページしない（しないと最後に白紙が1枚出る）
    var sh = $('sheets').querySelectorAll('.sheet');
    if (sh.length) sh[sh.length - 1].classList.add('lastp');
    $('print').disabled = !on.length;
    $('print').textContent = on.length ? '印刷する（' + on.length + '枚）' : '印刷する';
  }

  // フォント（明朝）が届いてから印刷する（届く前だと別の書体で刷られる）
  $('print').addEventListener('click', function () {
    var go = function () { window.print(); };
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(go); else go();
  });

  renderWho();
  renderSheets();
})();
</script>
</body>
</html>
