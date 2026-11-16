<?php
declare(strict_types=1);

// 過去問の記録（生徒が入力する画面）。「私立高校」「公立高校」のタブで切りかえる。
// 私立:
//   ・全体の進み具合（早い／普通／遅い。購入した過去問を1校以上登録すると出る）
//   ・購入した過去問（高校）の登録（3校まで）
//   ・1回ぶんの記録: 高校・年度・科目・何周目(1〜4)・得点率（わかれば点数）・できたところ・できなかったところ
//   ・高校ごとの進捗表（年度×科目に周目と得点率）と記録の一覧（なおす・けす）
// 公立（愛知県公立高校入試。学力検査は県内共通なので高校は選ばない）:
//   ・全体の進み具合
//   ・1回ぶんの記録: 年度（2022年度まではA/Bグループも）・科目・何周目・点数（各教科22点満点）・できた／できなかった
//   ・進捗表（年度×科目に周目と点数、5科の合計）と記録の一覧
// 年度は西暦だけで出す（「2026年度」）。
// 講師は /kakomon_teacher.php で読む。定義は api/kakomon_common.php、保存は api/save_kakomon.php。
//
// 画面はすべてJSで組み立てる。PHPからは #kk-boot のJSON 1つだけを渡す
// （tests/kakomon.spec.js がこのファイルの後半のHTMLをそのまま読み、JSONだけ差し替えて動かす。
//   後半のHTMLに PHP の式を増やすとテストが読めなくなるので、出したい値は $boot に足すこと）。
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/kakomon_common.php';

$actor = current_actor();

// ---- 未ログイン時: 共通ヘッダーのログイン窓で入ってもらう（ログインすると読み直される） ----
if (!$actor || $actor['type'] !== 'student') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>過去問の記録 | 中京個別指導学院</title>
</head>
<body style="font-family:sans-serif;background:#FBFAF6;">
<script src="/assets/divp-header.js"></script>
<div style="max-width:560px;margin:60px auto;padding:0 16px;text-align:center;color:#33312B;">
  <p style="font-size:18px;font-weight:700;">過去問の記録をつけるにはログインが必要です</p>
  <p style="font-size:14px;color:#8B877C;">右上の「ログイン」ボタンから<br>生徒コードとPINを入力してください</p>
</div>
</body>
</html><?php
    exit;
}

$studentId = (int)$actor['id'];
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT s.student_name, c.classroom_name
     FROM students s JOIN classrooms c ON c.classroom_id = s.classroom_id
     WHERE s.student_id = :id'
);
$stmt->execute(['id' => $studentId]);
$student = $stmt->fetch() ?: ['student_name' => '', 'classroom_name' => ''];

$ready = kakomon_ready($pdo);
$boot = [
    'ready'   => $ready,
    'student' => ['name' => (string)$student['student_name'], 'classroom' => (string)$student['classroom_name']],
    'state'   => $ready ? kakomon_state($pdo, $studentId) : null,
    'meta'    => $ready ? kakomon_meta($pdo) : null,
];
// JSON_HEX_TAG: 生徒が書いた文に "</script>" があってもタグを閉じさせない
$bootJson = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>過去問の記録 | 中京個別指導学院</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@500;700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<style>
  :root{
    --paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;
    --shu:#C73E2E;--shu-soft:#F6E3DF;--ai:#2C5F8A;--ai-soft:#EDF3F8;--kin:#C9A227;--dai:#D89A45;
    --midori:#3E7A5E;--midori-soft:#E9F2EC;--white:#FFFFFF;--radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08), 0 6px 16px rgba(51,49,43,.06);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px, transparent 1px),linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size:24px 24px;line-height:1.6;-webkit-font-smoothing:antialiased;
    -webkit-text-size-adjust:100%;text-size-adjust:100%;
  }
  .wrap{max-width:560px;margin:0 auto;padding:0 16px 64px}
  header{display:flex;align-items:center;justify-content:space-between;padding:14px 2px 10px}
  header img.logo{height:34px;width:auto;display:block}
  .back{font-size:13px;color:var(--ai);text-decoration:none;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}

  .card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:16px 18px;margin-top:14px;border-top:4px solid var(--ai)}
  .hero{border-top-color:var(--shu)}
  .hero h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:20px}
  .hero p{font-size:12px;color:var(--ink-soft);margin-top:4px}
  .hero .who{font-size:12px;color:var(--ink-soft);margin-top:2px}
  .c-title{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:15px;color:var(--ink)}
  .c-sub{font-size:11px;color:var(--ink-soft);margin-top:1px}
  .msg{font-size:12px;margin-top:8px;font-weight:700;min-height:0}
  .msg:empty{display:none}
  .msg.err{color:var(--shu)}
  .msg.ok{color:var(--midori)}
  .hint{font-size:12px;color:var(--ink-soft);padding:6px 0}

  /* ---------- 全体の進み具合 ---------- */
  .paces{display:flex;gap:8px;margin-top:10px}
  .pace{flex:1;padding:10px 4px 8px;border-radius:12px;cursor:pointer;text-align:center;
    background:var(--paper);border:2px solid var(--grid);color:var(--ink-soft);
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:17px;line-height:1.3}
  .pace small{display:block;font-size:10px;font-weight:700;margin-top:2px}
  .pace[data-pace="fast"].on{background:var(--midori-soft);border-color:var(--midori);color:var(--midori)}
  .pace[data-pace="normal"].on{background:var(--ai-soft);border-color:var(--ai);color:var(--ai)}
  .pace[data-pace="slow"].on{background:#FBEBD9;border-color:var(--dai);color:#A9661B}
  .pace-note{font-size:12px;color:var(--ink-soft);margin-top:8px;text-align:center}
  .pace-note b{color:var(--ink)}

  /* ---------- 購入した過去問 ---------- */
  .schools{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
  .sch{display:inline-flex;align-items:center;gap:6px;padding:6px 6px 6px 12px;border-radius:999px;
    background:var(--paper);border:1.5px solid var(--grid);font-size:13px;font-weight:700}
  .sch .n{font-size:11px;color:var(--ink-soft);font-weight:500}
  .sch .x{border:none;background:none;cursor:pointer;color:var(--ink-soft);font-size:16px;line-height:1;
    width:22px;height:22px;border-radius:50%}
  .sch .x:hover{background:var(--grid)}
  .sch .x.lock{color:#D6D2C7}
  .btn-line{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:13px;
    margin-top:10px;padding:8px 14px;border-radius:999px;cursor:pointer;
    background:var(--white);color:var(--ai);border:1.5px solid var(--ai)}
  .addform{margin-top:10px;padding:10px;border-radius:10px;background:var(--paper);border:1.5px dashed var(--grid)}
  .addform[hidden]{display:none}
  .addrow{display:flex;gap:8px;margin-top:8px}
  .addrow>*{min-width:0}

  /* 入力欄（iOS の拡大を避けるため 16px） */
  select,input[type=text],input[type=number],textarea{
    width:100%;font-family:'Zen Kaku Gothic New',sans-serif;font-size:16px;color:var(--ink);
    padding:8px 10px;border:1.5px solid var(--grid);border-radius:8px;background:var(--white);
  }
  textarea{resize:vertical;min-height:64px;line-height:1.5}
  select:focus,input:focus,textarea:focus{outline:none;border-color:var(--ai)}

  /* ---------- 記録する ---------- */
  .card.rec{border-top-color:var(--shu)}
  .card.rec.editing{border-top-color:var(--kin);box-shadow:0 0 0 3px #F3E7B5, var(--shadow)}
  .f-row{margin-top:12px}
  .f-lbl{display:block;font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    color:var(--ink-soft);margin-bottom:4px}
  .f-lbl small{font-weight:500;margin-left:4px}
  .chips{display:flex;flex-wrap:wrap;gap:6px}
  .chip{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:14px;
    padding:7px 13px;border-radius:999px;cursor:pointer;
    background:var(--white);color:var(--ink);border:1.5px solid var(--grid)}
  .chip.on{background:var(--shu);border-color:var(--shu);color:#fff}
  .chip:disabled{cursor:default;color:#C7C2B6;background:var(--paper);border-style:dashed}
  .chip .done{font-size:10px;margin-left:3px}
  .laps .chip{flex:1;text-align:center;padding:7px 4px}
  .modes{display:flex;gap:6px;margin-bottom:8px}
  .mode{flex:1;padding:6px 4px;border-radius:8px;cursor:pointer;text-align:center;font-size:12px;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;
    background:var(--paper);border:1.5px solid var(--grid);color:var(--ink-soft)}
  .mode.on{background:var(--ai-soft);border-color:var(--ai);color:var(--ai)}
  .score-row{display:flex;align-items:center;gap:6px;font-size:14px}
  .score-row input{width:84px;text-align:right}
  .score-row .calc{margin-left:auto;font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:15px}
  .score-row[hidden]{display:none}
  .prev{margin-top:12px;padding:8px 10px;border-radius:8px;background:#FDF6F5;border-left:3px solid var(--shu);
    font-size:12px;word-break:break-word}
  .prev[hidden]{display:none}
  .prev b{font-family:'Zen Maru Gothic',sans-serif;color:var(--shu)}
  .prev .t{white-space:pre-wrap;margin-top:2px}
  .lbl-good{color:var(--midori)}
  .lbl-bad{color:#A9661B}
  .save{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:15px;
    width:100%;margin-top:16px;padding:12px 14px;border-radius:999px;cursor:pointer;
    background:var(--shu);color:#fff;border:none}
  .card.rec.editing .save{background:var(--kin)}
  .save:disabled{opacity:.5;cursor:default}
  .cancel{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:13px;
    width:100%;margin-top:8px;padding:9px 14px;border-radius:999px;cursor:pointer;
    background:transparent;color:var(--ink-soft);border:1.5px solid var(--grid)}
  .cancel[hidden]{display:none}

  /* ---------- 高校ごとの進捗表 ---------- */
  .card.board{border-top-color:var(--kin)}
  .b-head{display:flex;align-items:baseline;justify-content:space-between;gap:8px;flex-wrap:wrap}
  .b-name{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px}
  .b-sum{font-size:12px;color:var(--ink-soft);font-feature-settings:'tnum'}
  .b-sum b{color:var(--ink)}
  .gridwrap{overflow-x:auto;margin-top:10px}
  table.grid{border-collapse:collapse;width:100%;font-size:12px}
  table.grid th,table.grid td{border:1px solid var(--grid);padding:4px 4px;text-align:center;vertical-align:top}
  table.grid thead th{background:var(--paper);font-family:'Zen Maru Gothic',sans-serif;font-weight:700;color:var(--ink-soft)}
  table.grid tbody th{background:var(--paper);white-space:nowrap;font-family:'Zen Maru Gothic',sans-serif;
    font-weight:700;font-size:12px;line-height:1.2}
  table.grid tbody th small{display:block;font-size:9px;color:var(--ink-soft);font-weight:500}
  .lp{display:block;font-weight:700;font-feature-settings:'tnum';white-space:nowrap;border-radius:4px;
    padding:0 3px;margin:1px 0}
  .r-good{color:var(--ai);background:var(--ai-soft)}
  .r-mid{color:#8A6D12;background:#FFF8E1}
  .r-low{color:#A9661B;background:#FBEBD9}
  .r-na{color:var(--ink-soft)}
  details.logs{margin-top:10px}
  details.logs summary{cursor:pointer;font-size:12px;color:var(--ai);font-weight:700;
    font-family:'Zen Maru Gothic',sans-serif}
  .lg{border-top:1px dashed var(--grid);padding:9px 0}
  .lg:first-of-type{border-top:none}
  .lg1{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:13px}
  .lg1 b{font-family:'Zen Maru Gothic',sans-serif}
  .lapb{font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;background:var(--paper);
    border:1px solid var(--grid);color:var(--ink-soft);font-family:'Zen Maru Gothic',sans-serif}
  .rt{font-weight:900;font-family:'Zen Maru Gothic',sans-serif;padding:0 6px;border-radius:6px}
  .lg1 small{color:var(--ink-soft)}
  .lgd{margin-left:auto;font-size:10px;color:#C0BBB0;font-feature-settings:'tnum'}
  .lgt{font-size:12px;margin-top:3px;white-space:pre-wrap;word-break:break-word}
  .lgt i{font-style:normal;font-weight:700;font-size:10px;font-family:'Zen Maru Gothic',sans-serif;margin-right:4px}
  .acts{margin-top:4px;display:flex;gap:12px}
  .act{font-size:11px;color:var(--ai);background:none;border:none;cursor:pointer;padding:0;
    text-decoration:underline;font-family:'Zen Kaku Gothic New',sans-serif}
  .act.del{color:var(--ink-soft)}
  .empty{font-size:13px;color:var(--ink-soft);padding:8px 0}

  /* ---------- 私立／公立のタブ ---------- */
  .ktabs{display:flex;gap:6px;margin-top:14px;padding:4px;background:var(--white);border-radius:999px;
    box-shadow:var(--shadow)}
  .ktabs[hidden]{display:none}
  .ktab{flex:1;padding:9px 6px;border-radius:999px;cursor:pointer;border:none;background:transparent;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:15px;color:var(--ink-soft)}
  .ktab.on{background:var(--ai);color:#fff}

  /* ---------- 公立: 合計の列 ---------- */
  table.grid th.total,table.grid td.total{background:#FFFCF0;border-left:2px solid #E8DDB5}
  .lp small{font-size:9px;font-weight:500;margin-left:1px}
  /* スマホ幅に7列を収めるため、「／110」「（2科）」は2行目に回す */
  table.grid thead th.total small,table.grid td.total .lp small{display:block;font-size:9px;font-weight:500;
    line-height:1.1;margin:0}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png"
         alt="中京個別指導学院">
    <a class="back" href="/mypage.php">← 学習の記録へ</a>
  </header>

  <section class="card hero">
    <h1>過去問の記録</h1>
    <div class="who" id="kkWho"></div>
    <p>過去問を1回解いたら、点数と「できたところ」「できなかったところ」を書いておこう。先生も見ているよ。</p>
  </section>

  <div id="kkNotReady" class="card" hidden>
    <p class="hint">この画面はまだ準備中です。先生に知らせてください。</p>
  </div>

  <!-- 私立／公立の切りかえ -->
  <nav class="ktabs" id="kkTabs" hidden>
    <button type="button" class="ktab" data-tab="private">私立高校</button>
    <button type="button" class="ktab" data-tab="public">公立高校</button>
  </nav>

  <!-- ============ 公立（愛知県公立高校入試。高校は選ばない） ============ -->
  <div id="kkPub" hidden>
    <div class="card" id="kkPubNotReady" hidden>
      <p class="hint">公立の記録はまだ準備中です。先生に知らせてください。</p>
    </div>
    <div id="kkPubBody" hidden>
      <!-- 受ける公立高校（2校まで。記録とはひもづけない＝問題は県内共通） -->
      <section class="card">
        <div class="c-title">受ける公立高校</div>
        <div class="c-sub">受ける予定の公立高校を登録しよう</div>
        <div class="schools" id="kkPubSchools"></div>
        <p class="hint" id="kkPubSchoolFull" hidden></p>
        <button type="button" class="btn-line" id="kkPubAddOpen">＋ 高校を追加</button>
        <div class="addform" id="kkPubAddForm" hidden>
          <select id="kkPubAddSel" aria-label="高校をえらぶ"></select>
          <input type="text" id="kkPubAddName" maxlength="80" placeholder="高校名を入力" hidden style="margin-top:8px">
          <div class="addrow">
            <button type="button" class="save" id="kkPubAddBtn" style="margin-top:0">追加する</button>
            <button type="button" class="cancel" id="kkPubAddCancel" style="margin-top:0">やめる</button>
          </div>
        </div>
        <div class="msg" id="kkPubSchoolMsg"></div>
      </section>

      <section class="card" id="kkPubPaceCard">
        <div class="c-title">全体の進み具合</div>
        <div class="c-sub">公立の過去問は予定どおりに進んでいる？</div>
        <div class="paces" id="kkPubPaces"></div>
        <div class="pace-note" id="kkPubPaceNote"></div>
        <div class="msg" id="kkPubPaceMsg"></div>
      </section>

      <section class="card rec" id="kkPubRec">
        <div class="c-title" id="kkPubFormTitle">結果を記録する</div>
        <div class="c-sub" id="kkPubSub"></div>
        <form id="kkPubForm" autocomplete="off" novalidate>
          <div class="f-row">
            <label class="f-lbl" for="kkPFYear">年度</label>
            <select id="kkPFYear"></select>
          </div>
          <div class="f-row" id="kkPFGroupRow" hidden>
            <span class="f-lbl">問題<small id="kkPFGroupNote"></small></span>
            <div class="chips" id="kkPFGroup"></div>
          </div>
          <div class="f-row">
            <span class="f-lbl">科目</span>
            <div class="chips" id="kkPFSubj"></div>
          </div>
          <div class="f-row">
            <span class="f-lbl">何周目？</span>
            <div class="chips laps" id="kkPFLap"></div>
            <p class="hint" id="kkPLapNote" hidden></p>
          </div>
          <div class="prev" id="kkPPrev" hidden></div>
          <div class="f-row">
            <span class="f-lbl">得点</span>
            <div class="score-row">
              <input type="number" id="kkPScore" inputmode="numeric" min="0" placeholder="得点" aria-label="得点">
              <span id="kkPMaxLbl"></span>
              <span class="calc" id="kkPCalc"></span>
            </div>
          </div>
          <div class="f-row">
            <label class="f-lbl lbl-good" for="kkPGood">できたところ</label>
            <textarea id="kkPGood" placeholder="例: 大問1の計算は全部正解できた"></textarea>
          </div>
          <div class="f-row">
            <label class="f-lbl lbl-bad" for="kkPBad">できなかったところ</label>
            <textarea id="kkPBad" placeholder="例: 大問3の図形(2)。補助線が思いつかなかった"></textarea>
          </div>
          <button type="submit" class="save" id="kkPSave">この内容で記録する</button>
          <button type="button" class="cancel" id="kkPCancel" hidden>なおすのをやめる</button>
          <div class="msg" id="kkPFormMsg"></div>
        </form>
      </section>

      <div id="kkPubBoard"></div>
    </div>
  </div>

  <!-- ============ 私立 ============ -->
  <div id="kkMain" hidden>
    <!-- 全体の進み具合 -->
    <section class="card" id="kkPaceCard">
      <div class="c-title">全体の進み具合</div>
      <div class="c-sub">過去問は予定どおりに進んでいる？</div>
      <div class="paces" id="kkPaces"></div>
      <div class="pace-note" id="kkPaceNote"></div>
      <div class="msg" id="kkPaceMsg"></div>
    </section>

    <!-- 購入した過去問（高校） -->
    <section class="card" id="kkSchoolCard">
      <div class="c-title">購入した過去問</div>
      <div class="c-sub">買った（持っている）過去問の高校を登録しよう</div>
      <div class="schools" id="kkSchools"></div>
      <p class="hint" id="kkSchoolFull" hidden></p>
      <button type="button" class="btn-line" id="kkAddOpen">＋ 高校を追加</button>
      <div class="addform" id="kkAddForm" hidden>
        <select id="kkAddSel" aria-label="高校をえらぶ"></select>
        <input type="text" id="kkAddName" maxlength="80" placeholder="高校名を入力" hidden style="margin-top:8px">
        <div class="addrow">
          <button type="button" class="save" id="kkAddBtn" style="margin-top:0">追加する</button>
          <button type="button" class="cancel" id="kkAddCancel" style="margin-top:0">やめる</button>
        </div>
      </div>
      <div class="msg" id="kkSchoolMsg"></div>
    </section>

    <!-- 記録する（なおすときも同じフォーム） -->
    <section class="card rec" id="kkRec">
      <div class="c-title" id="kkFormTitle">結果を記録する</div>
      <p class="hint" id="kkNoSchool" hidden>先に「購入した過去問」に高校を追加してね。</p>
      <form id="kkForm" autocomplete="off" novalidate>
        <div class="f-row">
          <span class="f-lbl">高校</span>
          <div class="chips" id="kkFSchool"></div>
        </div>
        <div class="f-row">
          <label class="f-lbl" for="kkFYear">年度</label>
          <select id="kkFYear"></select>
        </div>
        <div class="f-row">
          <span class="f-lbl">科目</span>
          <div class="chips" id="kkFSubj"></div>
        </div>
        <div class="f-row">
          <span class="f-lbl">何周目？</span>
          <div class="chips laps" id="kkFLap"></div>
          <p class="hint" id="kkLapNote" hidden></p>
        </div>
        <div class="prev" id="kkPrev" hidden></div>
        <div class="f-row">
          <span class="f-lbl">得点</span>
          <div class="modes" id="kkModes">
            <button type="button" class="mode" data-mode="point">点数で入れる</button>
            <button type="button" class="mode" data-mode="rate">得点率(%)で入れる</button>
          </div>
          <div class="score-row" id="kkPointRow">
            <input type="number" id="kkScore" inputmode="numeric" min="0" placeholder="得点" aria-label="得点">
            <span>点 ／</span>
            <input type="number" id="kkMax" inputmode="numeric" min="1" value="100" aria-label="満点">
            <span>点</span>
            <span class="calc" id="kkCalc"></span>
          </div>
          <div class="score-row" id="kkRateRow" hidden>
            <input type="number" id="kkRate" inputmode="numeric" min="0" max="100" placeholder="得点率" aria-label="得点率">
            <span>%</span>
          </div>
        </div>
        <div class="f-row">
          <label class="f-lbl lbl-good" for="kkGood">できたところ</label>
          <textarea id="kkGood" placeholder="例: 大問1の計算は全部正解できた"></textarea>
        </div>
        <div class="f-row">
          <label class="f-lbl lbl-bad" for="kkBad">できなかったところ</label>
          <textarea id="kkBad" placeholder="例: 大問4の関数(2)。グラフの式の立て方がわからなかった"></textarea>
        </div>
        <button type="submit" class="save" id="kkSave">この内容で記録する</button>
        <button type="button" class="cancel" id="kkCancel" hidden>なおすのをやめる</button>
        <div class="msg" id="kkFormMsg"></div>
      </form>
    </section>

    <!-- 高校ごとの進捗表 -->
    <div id="kkBoards"></div>
  </div>
</div>

<script type="application/json" id="kk-boot"><?= $bootJson ?></script>
<script>
(function () {
  'use strict';
  var boot = null;
  try { boot = JSON.parse(document.getElementById('kk-boot').textContent || 'null'); } catch (e) { boot = null; }
  if (!boot || !boot.ready) {
    document.getElementById('kkNotReady').hidden = false;
    return;
  }
  var meta = boot.meta;
  var state = boot.state;
  var $ = function (id) { return document.getElementById(id); };

  var who = boot.student || {};
  $('kkWho').textContent = (who.name ? who.name + ' さん' : '') + (who.classroom ? '・' + who.classroom + '教室' : '');

  // ---- 私立／公立のタブ（最後に開いた方を覚えておく。保存できない環境でも動く） ----
  var tab = 'private';
  try { if (localStorage.getItem('kk_tab') === 'public') tab = 'public'; } catch (e) { /* 使えなくてもよい */ }
  function showTab(t) {
    tab = t;
    [].forEach.call($('kkTabs').querySelectorAll('.ktab'), function (b) {
      b.classList.toggle('on', b.getAttribute('data-tab') === t);
    });
    $('kkMain').hidden = t !== 'private';
    $('kkPub').hidden = t !== 'public';
    try { localStorage.setItem('kk_tab', t); } catch (e) { /* 使えなくてもよい */ }
  }
  $('kkTabs').hidden = false;
  $('kkTabs').addEventListener('click', function (e) {
    var b = e.target.closest('.ktab');
    if (b) showTab(b.getAttribute('data-tab'));
  });

  var SUBJ = {};
  meta.subjects.forEach(function (s) { SUBJ[s.key] = s.label; });
  var SUBJ_ORDER = meta.subjects.map(function (s) { return s.key; });
  var CIRCLED = ['', '①', '②', '③', '④', '⑤', '⑥'];

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  // 年度は西暦だけ（どの画面も「2026年度」にそろえる）
  function yearLabel(y) { return y + '年度'; }
  function rateClass(r) {
    if (r == null) return 'r-na';
    return r >= 80 ? 'r-good' : (r >= 60 ? 'r-mid' : 'r-low');
  }
  function schoolById(id) {
    for (var i = 0; i < state.schools.length; i++) if (state.schools[i].id === id) return state.schools[i];
    return null;
  }
  function logById(id) {
    for (var i = 0; i < state.logs.length; i++) if (state.logs[i].id === id) return state.logs[i];
    return null;
  }
  function attemptName(l) { return l.year + '年度 ' + (SUBJ[l.subject] || l.subject) + ' ' + l.lap + '周目'; }

  // ============================================================
  // サーバーとのやりとり（成功したら state を差し替えて全部描き直す）
  // ============================================================
  var ERR = {
    invalid_pace: '進み具合をえらんでください',
    invalid_school: '高校をえらんでください',
    invalid_school_name: '高校名を入れてください（80文字まで）',
    duplicate_school: 'その高校はもう登録してあります',
    too_many_schools: '高校は' + meta.maxSchools + '校まで登録できます',
    too_many_public_schools: '公立高校は' + ((meta.pub && meta.pub.maxSchools) || 2) + '校まで登録できます',
    school_has_logs: '記録がある高校は外せません',
    invalid_year: '年度をえらんでください',
    invalid_subject: '科目をえらんでください',
    invalid_lap: '何周目かをえらんでください',
    invalid_max_score: '満点を正しく入れてください',
    invalid_score: '点数を正しく入れてください（満点より大きくはできません）',
    invalid_rate: '得点率は0〜100で入れてください',
    invalid_group: 'AグループかBグループかをえらんでください',
    invalid_public_score: '点数は0〜' + (meta.pub ? meta.pub.max : 22) + '点で入れてください',
    text_too_long: '「できたところ」「できなかったところ」は' + meta.textMax + '文字までです',
    duplicate_log: 'その回はもう記録してあります。下の一覧の「なおす」から直してね',
    not_found: '記録が見つかりません。画面を読み直してください',
    unauthenticated: 'ログインが切れました。画面を読み直してログインしてください',
    schema_not_ready: 'この画面はまだ準備中です。先生に知らせてください',
    internal_error: 'サーバーでエラーが起きました。少し待ってからもう一度ためしてください'
  };
  var busy = false;

  function setMsg(el, text, ok) {
    el.textContent = text || '';
    el.className = 'msg' + (text ? (ok ? ' ok' : ' err') : '');
  }

  function call(payload, msgEl) {
    if (busy) return Promise.resolve(null);
    busy = true;
    setMsg(msgEl, '');
    return fetch('/api/save_kakomon.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (data) {
        busy = false;
        if (res.ok && data && data.ok) {
          state = data.state;
          renderAll();
          return data;
        }
        var code = data && data.error;
        setMsg(msgEl, ERR[code] || ('保存できませんでした（' + (code || res.status) + '）'));
        return null;
      });
    }).catch(function () {
      busy = false;
      setMsg(msgEl, '通信できませんでした。電波のよいところでもう一度ためしてください');
      return null;
    });
  }

  // ============================================================
  // 全体の進み具合
  // ============================================================
  // 私立・公立で共用（ps = {pace, pace_label, pace_updated_label}）
  function paceButtons(ps) {
    return meta.paces.map(function (p) {
      return '<button type="button" class="pace' + (ps.pace === p.key ? ' on' : '') + '" data-pace="' + esc(p.key) + '">'
        + esc(p.label) + '<small>' + esc(p.desc) + '</small></button>';
    }).join('');
  }
  function paceNote(ps) {
    return ps.pace
      ? 'いまは <b>「' + esc(ps.pace_label) + '」</b>（' + esc(ps.pace_updated_label) + ' に記録）'
      : 'まだえらんでいません。あてはまるものを押してね';
  }
  function renderPace() {
    // 進み具合は、購入した過去問（高校）を登録してから聞く
    $('kkPaceCard').hidden = !state.schools.length;
    $('kkPaces').innerHTML = paceButtons(state);
    $('kkPaceNote').innerHTML = paceNote(state);
  }
  $('kkPaces').addEventListener('click', function (e) {
    var b = e.target.closest('.pace');
    if (!b) return;
    call({ action: 'pace', pace: b.getAttribute('data-pace') }, $('kkPaceMsg'));
  });

  // ============================================================
  // 高校を追加するプルダウン（私立・公立で共用）。
  // 登録ずみの学校は出さない。地区があれば地区ごとに見出しで区切る
  // ============================================================
  function fillSchoolSelect(sel, master, registered) {
    var have = {};
    registered.forEach(function (s) { if (s.target_school_id) have[s.target_school_id] = true; });
    var avail = (master || []).filter(function (p) { return !have[p.id]; });
    var opt = function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; };
    var regions = meta.regions || [];
    var list;
    if (avail.some(function (p) { return p.region; })) {
      var known = {};
      regions.forEach(function (r) { known[r.key] = true; });
      var groups = regions.map(function (r) {
        return { label: r.label, items: avail.filter(function (p) { return p.region === r.key; }) };
      });
      groups.push({ label: 'その他', items: avail.filter(function (p) { return !known[p.region]; }) });
      list = groups.filter(function (g) { return g.items.length; }).map(function (g) {
        return '<optgroup label="' + esc(g.label) + '">' + g.items.map(opt).join('') + '</optgroup>';
      }).join('');
    } else {
      list = avail.map(opt).join('');
    }
    var keep = sel.value;
    sel.innerHTML = '<option value="">高校をえらぶ</option>' + list
      + '<option value="other">リストにない高校（自分で入力）</option>';
    sel.value = keep;
    if (sel.value !== keep) sel.value = '';
  }

  // ============================================================
  // 購入した過去問（高校）
  // ============================================================
  function renderSchools() {
    var box = $('kkSchools');
    if (!state.schools.length) {
      box.innerHTML = '<span class="hint">まだ登録していません</span>';
    } else {
      box.innerHTML = state.schools.map(function (s) {
        return '<span class="sch">' + esc(s.name) + '<span class="n">' + s.log_count + '回</span>'
          + '<button type="button" class="x' + (s.log_count > 0 ? ' lock' : '') + '" data-id="' + s.id + '"'
          + ' aria-label="' + esc(s.name) + 'を外す">×</button></span>';
      }).join('');
    }
    fillSchoolSelect($('kkAddSel'), meta.privateSchools, state.schools);
    $('kkAddName').hidden = $('kkAddSel').value !== 'other';

    // 上限（受けられる私立は最大3校）まで登録したら、追加の入口を閉じて案内だけ出す
    var full = state.schools.length >= meta.maxSchools;
    if (full) $('kkAddForm').hidden = true;
    $('kkAddOpen').hidden = full || !$('kkAddForm').hidden;
    $('kkSchoolFull').hidden = !full;
    $('kkSchoolFull').textContent = full
      ? '高校は' + meta.maxSchools + '校まで登録できます（入れかえるときは × で外してね）' : '';
  }
  function closeAdd() {
    $('kkAddForm').hidden = true;
    $('kkAddOpen').hidden = state.schools.length >= meta.maxSchools;
    $('kkAddSel').value = '';
    $('kkAddName').value = '';
    $('kkAddName').hidden = true;
  }
  $('kkAddOpen').addEventListener('click', function () {
    $('kkAddForm').hidden = false;
    $('kkAddOpen').hidden = true;
    setMsg($('kkSchoolMsg'), '');
  });
  $('kkAddCancel').addEventListener('click', closeAdd);
  $('kkAddSel').addEventListener('change', function () {
    var other = this.value === 'other';
    $('kkAddName').hidden = !other;
    if (other) $('kkAddName').focus();
  });
  $('kkAddBtn').addEventListener('click', function () {
    var v = $('kkAddSel').value;
    var payload = { action: 'add_school' };
    if (v === 'other') {
      var name = $('kkAddName').value.trim();
      if (!name) { setMsg($('kkSchoolMsg'), '高校名を入れてください'); return; }
      payload.name = name;
    } else if (v) {
      payload.target_school_id = parseInt(v, 10);
    } else {
      setMsg($('kkSchoolMsg'), '高校をえらんでください');
      return;
    }
    call(payload, $('kkSchoolMsg')).then(function (data) {
      if (!data) return;
      closeAdd();
      // 足した高校を記録フォームでそのまま選んでおく
      if (!f.editId) { f.school = data.school_id; renderForm(); }
      setMsg($('kkSchoolMsg'), '登録しました', true);
    });
  });
  $('kkSchools').addEventListener('click', function (e) {
    var b = e.target.closest('.x');
    if (!b) return;
    var s = schoolById(parseInt(b.getAttribute('data-id'), 10));
    if (!s) return;
    if (s.log_count > 0) {
      setMsg($('kkSchoolMsg'), '「' + s.name + '」は記録があるので外せません（記録を全部けすと外せます）');
      return;
    }
    if (!window.confirm('「' + s.name + '」を一覧から外しますか？')) return;
    call({ action: 'remove_school', school_id: s.id }, $('kkSchoolMsg'));
  });

  // ============================================================
  // 記録する（なおす）フォーム
  // ============================================================
  // フォームの状態。年度も select の値ではなくここに持つ
  // （select は描き直すたびに作り直すので、そちらを正にすると「なおす」で年度がずれる）
  var f = { editId: null, school: null, year: null, subject: null, lap: null, mode: 'point' };

  // いま選んでいる 高校×年度×科目 で、もう記録がある周目（なおしている記録は除く）
  function doneLaps() {
    var done = {};
    if (!f.school || !f.year || !f.subject) return done;
    state.logs.forEach(function (l) {
      if (l.school_id === f.school && l.year === f.year && l.subject === f.subject && l.id !== f.editId) done[l.lap] = l;
    });
    return done;
  }
  function firstFreeLap(done) {
    for (var i = 1; i <= meta.laps; i++) if (!done[i]) return i;
    return null;
  }

  function renderForm() {
    var hasSchool = state.schools.length > 0;
    $('kkNoSchool').hidden = hasSchool;
    $('kkForm').hidden = !hasSchool && !f.editId;
    if (f.school && !schoolById(f.school)) f.school = null;
    if (!f.school && state.schools.length === 1) f.school = state.schools[0].id;

    $('kkFSchool').innerHTML = state.schools.map(function (s) {
      return '<button type="button" class="chip' + (f.school === s.id ? ' on' : '') + '" data-id="' + s.id + '">' + esc(s.name) + '</button>';
    }).join('');

    var years = meta.years.map(function (y) { return y.y; });
    // 選択肢より古い年度の記録をなおすときは、その年度も選べるようにする
    if (f.year && years.indexOf(f.year) < 0) years.push(f.year);
    var ysel = $('kkFYear');
    ysel.innerHTML = '<option value="">年度をえらぶ</option>' + years.map(function (y) {
      return '<option value="' + y + '">' + esc(yearLabel(y)) + '</option>';
    }).join('');
    ysel.value = f.year ? String(f.year) : '';

    $('kkFSubj').innerHTML = meta.subjects.map(function (s) {
      return '<button type="button" class="chip' + (f.subject === s.key ? ' on' : '') + '" data-key="' + esc(s.key) + '">' + esc(s.label) + '</button>';
    }).join('');

    renderLaps();
    renderModes();
  }

  function renderLaps() {
    var done = doneLaps();
    if (f.lap && done[f.lap]) f.lap = null;
    if (!f.lap) f.lap = firstFreeLap(done);
    var html = '';
    for (var i = 1; i <= meta.laps; i++) {
      html += '<button type="button" class="chip' + (f.lap === i ? ' on' : '') + '" data-lap="' + i + '"'
        + (done[i] ? ' disabled' : '') + '>' + i + '周目' + (done[i] ? '<span class="done">済</span>' : '') + '</button>';
    }
    $('kkFLap').innerHTML = html;
    var allDone = firstFreeLap(done) === null;
    $('kkLapNote').hidden = !allDone;
    $('kkLapNote').textContent = allDone ? meta.laps + '周目まで記録ずみです。なおすときは下の一覧の「なおす」から' : '';
    renderPrev(done);
  }

  // 前の周の記録（できなかったところ）を見ながら書けるように出す
  function renderPrev(done) {
    var box = $('kkPrev');
    var prev = null;
    if (f.lap) for (var i = f.lap - 1; i >= 1; i--) if (done[i]) { prev = done[i]; break; }
    if (!prev) { box.hidden = true; box.innerHTML = ''; return; }
    box.hidden = false;
    box.innerHTML = '<b>' + prev.lap + '周目のときは ' + prev.rate + '%</b>'
      + (prev.bad ? '<div class="t"><span class="lbl-bad">できなかったところ: </span>' + esc(prev.bad) + '</div>' : '');
  }

  function renderModes() {
    [].forEach.call($('kkModes').querySelectorAll('.mode'), function (b) {
      b.classList.toggle('on', b.getAttribute('data-mode') === f.mode);
    });
    $('kkPointRow').hidden = f.mode !== 'point';
    $('kkRateRow').hidden = f.mode !== 'rate';
    renderCalc();
  }
  function renderCalc() {
    var sc = $('kkScore').value.trim(), mx = $('kkMax').value.trim();
    var s = parseInt(sc, 10), m = parseInt(mx, 10);
    $('kkCalc').textContent = (sc !== '' && mx !== '' && m > 0 && s >= 0 && s <= m)
      ? '→ ' + Math.round(100 * s / m) + '%' : '';
  }

  $('kkFSchool').addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b) return;
    f.school = parseInt(b.getAttribute('data-id'), 10);
    if (!f.editId) f.lap = null;
    renderForm();
  });
  $('kkFYear').addEventListener('change', function () {
    f.year = parseInt(this.value, 10) || null;
    if (!f.editId) f.lap = null;
    renderLaps();
  });
  $('kkFSubj').addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b) return;
    f.subject = b.getAttribute('data-key');
    if (!f.editId) f.lap = null;
    renderForm();
  });
  $('kkFLap').addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b || b.disabled) return;
    f.lap = parseInt(b.getAttribute('data-lap'), 10);
    renderLaps();
  });
  $('kkModes').addEventListener('click', function (e) {
    var b = e.target.closest('.mode');
    if (!b) return;
    f.mode = b.getAttribute('data-mode');
    renderModes();
  });
  $('kkScore').addEventListener('input', renderCalc);
  $('kkMax').addEventListener('input', renderCalc);

  function clearAnswerFields() {
    f.subject = null;
    f.lap = null;
    $('kkScore').value = '';
    $('kkRate').value = '';
    $('kkGood').value = '';
    $('kkBad').value = '';
  }

  function setEditing(on) {
    $('kkRec').classList.toggle('editing', on);
    $('kkCancel').hidden = !on;
    $('kkSave').textContent = on ? 'なおした内容で保存する' : 'この内容で記録する';
  }

  function startEdit(l) {
    f.editId = l.id;
    f.school = l.school_id;
    f.year = l.year;
    f.subject = l.subject;
    f.lap = l.lap;
    f.mode = l.score != null ? 'point' : 'rate';
    renderForm();
    $('kkScore').value = l.score != null ? l.score : '';
    $('kkMax').value = l.max_score != null ? l.max_score : 100;
    $('kkRate').value = l.score != null ? '' : l.rate;
    $('kkGood').value = l.good || '';
    $('kkBad').value = l.bad || '';
    renderCalc();
    $('kkFormTitle').textContent = '記録をなおす（' + attemptName(l) + '）';
    setEditing(true);
    setMsg($('kkFormMsg'), '');
    $('kkRec').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function endEdit() {
    f.editId = null;
    clearAnswerFields();
    $('kkFormTitle').textContent = '結果を記録する';
    setEditing(false);
    renderForm();
  }
  $('kkCancel').addEventListener('click', function () { endEdit(); setMsg($('kkFormMsg'), ''); });

  $('kkForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = $('kkFormMsg');
    var year = f.year;
    if (!f.school) { setMsg(msg, '高校をえらんでください'); return; }
    if (!year) { setMsg(msg, '年度をえらんでください'); return; }
    if (!f.subject) { setMsg(msg, '科目をえらんでください'); return; }
    if (!f.lap) { setMsg(msg, '何周目かをえらんでください'); return; }

    var payload = { action: 'save_log', school_id: f.school, year: year, subject: f.subject, lap: f.lap,
      good: $('kkGood').value, bad: $('kkBad').value };
    if (f.editId) payload.log_id = f.editId;

    if (f.mode === 'point') {
      var sc = $('kkScore').value.trim(), mx = $('kkMax').value.trim();
      if (!/^\d+$/.test(mx) || parseInt(mx, 10) < 1 || parseInt(mx, 10) > meta.scoreMax) { setMsg(msg, ERR.invalid_max_score); return; }
      if (!/^\d+$/.test(sc) || parseInt(sc, 10) > parseInt(mx, 10)) { setMsg(msg, ERR.invalid_score); return; }
      payload.score = parseInt(sc, 10);
      payload.max_score = parseInt(mx, 10);
    } else {
      var rt = $('kkRate').value.trim();
      if (!/^\d+(\.\d+)?$/.test(rt) || parseFloat(rt) > 100) { setMsg(msg, ERR.invalid_rate); return; }
      payload.rate = rt;
    }
    if (payload.good.length > meta.textMax || payload.bad.length > meta.textMax) { setMsg(msg, ERR.text_too_long); return; }

    var wasEdit = !!f.editId;
    var name = attemptName({ year: year, subject: f.subject, lap: f.lap });
    $('kkSave').disabled = true;
    call(payload, msg).then(function (data) {
      $('kkSave').disabled = false;
      if (!data) return;
      // 同じ高校・年度のまま、次の科目をすぐ書けるようにする（満点も残す）
      if (wasEdit) endEdit();
      else { clearAnswerFields(); renderForm(); }
      setMsg(msg, (wasEdit ? 'なおしました: ' : '記録しました: ') + name, true);
    });
  });

  // ============================================================
  // 高校ごとの進捗表＋記録の一覧
  // ============================================================
  function renderBoards() {
    var box = $('kkBoards');
    box.innerHTML = state.schools.map(function (s) {
      var logs = state.logs.filter(function (l) { return l.school_id === s.id; });
      var head = '<div class="b-head"><span class="b-name">' + esc(s.name) + '</span>';
      if (!logs.length) {
        return '<section class="card board">' + head + '</div><p class="empty">まだ記録がありません</p></section>';
      }
      var sum = 0;
      logs.forEach(function (l) { sum += l.rate; });
      head += '<span class="b-sum">記録 <b>' + logs.length + '</b>回・平均 <b>' + Math.round(sum / logs.length) + '</b>%</span></div>';

      // 年度（新しい順）× 科目（記録のある科目だけ）
      var years = [], subs = {};
      logs.forEach(function (l) { if (years.indexOf(l.year) < 0) years.push(l.year); subs[l.subject] = true; });
      years.sort(function (a, b) { return b - a; });
      var cols = SUBJ_ORDER.filter(function (k) { return subs[k]; });
      var grid = '<div class="gridwrap"><table class="grid"><thead><tr><th>年度</th>'
        + cols.map(function (k) { return '<th>' + esc(SUBJ[k]) + '</th>'; }).join('') + '</tr></thead><tbody>'
        + years.map(function (y) {
          return '<tr><th>' + y + '</th>' + cols.map(function (k) {
            var cell = logs.filter(function (l) { return l.year === y && l.subject === k; })
              .sort(function (a, b) { return a.lap - b.lap; });
            return '<td>' + cell.map(function (l) {
              return '<span class="lp ' + rateClass(l.rate) + '">' + (CIRCLED[l.lap] || l.lap) + l.rate + '%</span>';
            }).join('') + '</td>';
          }).join('') + '</tr>';
        }).join('') + '</tbody></table></div>';

      // くわしい記録（年度の新しい順 → 科目 → 周目）
      var sorted = logs.slice().sort(function (a, b) {
        return (b.year - a.year) || (SUBJ_ORDER.indexOf(a.subject) - SUBJ_ORDER.indexOf(b.subject)) || (a.lap - b.lap);
      });
      var list = sorted.map(function (l) {
        return '<div class="lg"><div class="lg1"><b>' + l.year + '年度</b> ' + esc(SUBJ[l.subject] || l.subject)
          + ' <span class="lapb">' + l.lap + '周目</span>'
          + ' <span class="rt ' + rateClass(l.rate) + '">' + l.rate + '%</span>'
          + (l.score != null ? '<small>' + l.score + '/' + l.max_score + '点</small>' : '')
          + '<span class="lgd">' + esc(l.date_label) + ' 記録</span></div>'
          + (l.good ? '<div class="lgt"><i class="lbl-good">できた</i>' + esc(l.good) + '</div>' : '')
          + (l.bad ? '<div class="lgt"><i class="lbl-bad">できなかった</i>' + esc(l.bad) + '</div>' : '')
          + '<div class="acts"><button type="button" class="act" data-edit="' + l.id + '">なおす</button>'
          + '<button type="button" class="act del" data-del="' + l.id + '">けす</button></div></div>';
      }).join('');
      return '<section class="card board">' + head + grid
        + '<details class="logs"><summary>くわしい記録（' + logs.length + '件）</summary>' + list + '</details>'
        + '<div class="msg" data-board-msg="' + s.id + '"></div></section>';
    }).join('');
  }

  $('kkBoards').addEventListener('click', function (e) {
    var ed = e.target.closest('[data-edit]');
    if (ed) {
      var l = logById(parseInt(ed.getAttribute('data-edit'), 10));
      if (l) startEdit(l);
      return;
    }
    var del = e.target.closest('[data-del]');
    if (del) {
      var d = logById(parseInt(del.getAttribute('data-del'), 10));
      if (!d) return;
      if (!window.confirm(attemptName(d) + ' の記録をけしますか？')) return;
      var msgEl = del.closest('.board').querySelector('[data-board-msg]');
      call({ action: 'delete_log', log_id: d.id }, msgEl).then(function (data) {
        if (data && f.editId === d.id) endEdit();
      });
    }
  });

  // ============================================================
  // 公立（愛知県公立高校入試。学力検査は県内共通なので高校は選ばない）
  // ============================================================
  var PUB = meta.pub || { max: 22, groups: [], abLastYear: 0 };
  var GROUP = {};
  PUB.groups.forEach(function (g) { GROUP[g.key] = g.label; });
  var GROUP_ORDER = { '': 0, A: 1, B: 2 };
  // 2022年度まではAグループ・Bグループの2回
  function hasGroups(y) { return !!y && y <= PUB.abLastYear; }
  function pubExamName(y, g) { return y + '年度' + (GROUP[g] ? ' ' + GROUP[g] : ''); }
  function pubAttemptName(l) { return pubExamName(l.year, l.group) + ' ' + (SUBJ[l.subject] || l.subject) + ' ' + l.lap + '周目'; }
  function pubRate(score) { return Math.round(100 * score / PUB.max); }
  function pubLogById(id) {
    var logs = state.pub ? state.pub.logs : [];
    for (var i = 0; i < logs.length; i++) if (logs[i].id === id) return logs[i];
    return null;
  }

  // 公立のフォームの状態（私立の f と同じ考え方。group は 2023年度からは ''）
  var pf = { editId: null, year: null, group: '', subject: null, lap: null };

  function pubDoneLaps() {
    var done = {};
    if (!state.pub || !pf.year || !pf.subject || (hasGroups(pf.year) && !pf.group)) return done;
    state.pub.logs.forEach(function (l) {
      if (l.year === pf.year && l.group === pf.group && l.subject === pf.subject && l.id !== pf.editId) done[l.lap] = l;
    });
    return done;
  }

  function renderPub() {
    var ready = !!state.pub;
    $('kkPubNotReady').hidden = ready;
    $('kkPubBody').hidden = !ready;
    if (!ready) return;
    renderPubSchools();
    // 進み具合は、受ける公立高校を登録してから聞く（私立と同じ）
    $('kkPubPaceCard').hidden = !state.pub.schools.length;
    $('kkPubPaces').innerHTML = paceButtons(state.pub);
    $('kkPubPaceNote').innerHTML = paceNote(state.pub);
    renderPubForm();
    renderPubBoard();
  }

  // ---- 受ける公立高校（2校まで。記録とはひもづけないので、いつでも外せる） ----
  function pubMaxSchools() { return PUB.maxSchools || 2; }
  function renderPubSchools() {
    var list = state.pub.schools;
    $('kkPubSchools').innerHTML = list.length
      ? list.map(function (s) {
        return '<span class="sch">' + esc(s.name) + '<button type="button" class="x" data-id="' + s.id + '"'
          + ' aria-label="' + esc(s.name) + 'を外す">×</button></span>';
      }).join('')
      : '<span class="hint">まだ登録していません</span>';
    fillSchoolSelect($('kkPubAddSel'), meta.publicSchools, list);
    $('kkPubAddName').hidden = $('kkPubAddSel').value !== 'other';
    var full = list.length >= pubMaxSchools();
    if (full) $('kkPubAddForm').hidden = true;
    $('kkPubAddOpen').hidden = full || !$('kkPubAddForm').hidden;
    $('kkPubSchoolFull').hidden = !full;
    $('kkPubSchoolFull').textContent = full
      ? '公立高校は' + pubMaxSchools() + '校まで登録できます（入れかえるときは × で外してね）' : '';
  }
  function pubCloseAdd() {
    $('kkPubAddForm').hidden = true;
    $('kkPubAddOpen').hidden = state.pub.schools.length >= pubMaxSchools();
    $('kkPubAddSel').value = '';
    $('kkPubAddName').value = '';
    $('kkPubAddName').hidden = true;
  }
  $('kkPubAddOpen').addEventListener('click', function () {
    $('kkPubAddForm').hidden = false;
    $('kkPubAddOpen').hidden = true;
    setMsg($('kkPubSchoolMsg'), '');
  });
  $('kkPubAddCancel').addEventListener('click', pubCloseAdd);
  $('kkPubAddSel').addEventListener('change', function () {
    var other = this.value === 'other';
    $('kkPubAddName').hidden = !other;
    if (other) $('kkPubAddName').focus();
  });
  $('kkPubAddBtn').addEventListener('click', function () {
    var v = $('kkPubAddSel').value;
    var payload = { action: 'public_add_school' };
    if (v === 'other') {
      var name = $('kkPubAddName').value.trim();
      if (!name) { setMsg($('kkPubSchoolMsg'), '高校名を入れてください'); return; }
      payload.name = name;
    } else if (v) {
      payload.target_school_id = parseInt(v, 10);
    } else {
      setMsg($('kkPubSchoolMsg'), '高校をえらんでください');
      return;
    }
    call(payload, $('kkPubSchoolMsg')).then(function (data) {
      if (!data) return;
      pubCloseAdd();
      setMsg($('kkPubSchoolMsg'), '登録しました', true);
    });
  });
  $('kkPubSchools').addEventListener('click', function (e) {
    var b = e.target.closest('.x');
    if (!b) return;
    var id = parseInt(b.getAttribute('data-id'), 10);
    var s = state.pub.schools.filter(function (x) { return x.id === id; })[0];
    if (!s || !window.confirm('「' + s.name + '」を一覧から外しますか？')) return;
    call({ action: 'public_remove_school', school_id: id }, $('kkPubSchoolMsg'));
  });

  function renderPubForm() {
    $('kkPubSub').textContent = '愛知県公立高校入試（各教科' + PUB.max + '点満点）';
    $('kkPMaxLbl').textContent = '点 ／ ' + PUB.max + '点';
    var years = meta.years.map(function (y) { return y.y; });
    if (pf.year && years.indexOf(pf.year) < 0) years.push(pf.year);
    var ysel = $('kkPFYear');
    ysel.innerHTML = '<option value="">年度をえらぶ</option>' + years.map(function (y) {
      return '<option value="' + y + '">' + esc(yearLabel(y)) + '</option>';
    }).join('');
    ysel.value = pf.year ? String(pf.year) : '';

    var showG = hasGroups(pf.year);
    $('kkPFGroupRow').hidden = !showG;
    $('kkPFGroupNote').textContent = showG ? '（' + PUB.abLastYear + '年度までは2回）' : '';
    $('kkPFGroup').innerHTML = PUB.groups.map(function (g) {
      return '<button type="button" class="chip' + (pf.group === g.key ? ' on' : '') + '" data-key="' + esc(g.key) + '">' + esc(g.label) + '</button>';
    }).join('');

    $('kkPFSubj').innerHTML = meta.subjects.map(function (s) {
      return '<button type="button" class="chip' + (pf.subject === s.key ? ' on' : '') + '" data-key="' + esc(s.key) + '">' + esc(s.label) + '</button>';
    }).join('');
    renderPubLaps();
    renderPubCalc();
  }

  function renderPubLaps() {
    var done = pubDoneLaps();
    if (pf.lap && done[pf.lap]) pf.lap = null;
    if (!pf.lap) pf.lap = firstFreeLap(done);
    var html = '';
    for (var i = 1; i <= meta.laps; i++) {
      html += '<button type="button" class="chip' + (pf.lap === i ? ' on' : '') + '" data-lap="' + i + '"'
        + (done[i] ? ' disabled' : '') + '>' + i + '周目' + (done[i] ? '<span class="done">済</span>' : '') + '</button>';
    }
    $('kkPFLap').innerHTML = html;
    var allDone = firstFreeLap(done) === null;
    $('kkPLapNote').hidden = !allDone;
    $('kkPLapNote').textContent = allDone ? meta.laps + '周目まで記録ずみです。なおすときは下の一覧の「なおす」から' : '';

    // 前の周の記録（できなかったところ）を見ながら書けるように出す
    var box = $('kkPPrev');
    var prev = null;
    if (pf.lap) for (var j = pf.lap - 1; j >= 1; j--) if (done[j]) { prev = done[j]; break; }
    box.hidden = !prev;
    box.innerHTML = prev
      ? '<b>' + prev.lap + '周目のときは ' + prev.score + '点</b>'
        + (prev.bad ? '<div class="t"><span class="lbl-bad">できなかったところ: </span>' + esc(prev.bad) + '</div>' : '')
      : '';
  }

  function renderPubCalc() {
    var sc = $('kkPScore').value.trim(), s = parseInt(sc, 10);
    $('kkPCalc').textContent = (sc !== '' && s >= 0 && s <= PUB.max) ? '→ ' + pubRate(s) + '%' : '';
  }

  $('kkPubPaces').addEventListener('click', function (e) {
    var b = e.target.closest('.pace');
    if (b) call({ action: 'public_pace', pace: b.getAttribute('data-pace') }, $('kkPubPaceMsg'));
  });
  $('kkPFYear').addEventListener('change', function () {
    pf.year = parseInt(this.value, 10) || null;
    if (!hasGroups(pf.year)) pf.group = '';
    if (!pf.editId) pf.lap = null;
    renderPubForm();
  });
  $('kkPFGroup').addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b) return;
    pf.group = b.getAttribute('data-key');
    if (!pf.editId) pf.lap = null;
    renderPubForm();
  });
  $('kkPFSubj').addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b) return;
    pf.subject = b.getAttribute('data-key');
    if (!pf.editId) pf.lap = null;
    renderPubForm();
  });
  $('kkPFLap').addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b || b.disabled) return;
    pf.lap = parseInt(b.getAttribute('data-lap'), 10);
    renderPubLaps();
  });
  $('kkPScore').addEventListener('input', renderPubCalc);

  function pubClearAnswer() {
    pf.subject = null;
    pf.lap = null;
    $('kkPScore').value = '';
    $('kkPGood').value = '';
    $('kkPBad').value = '';
  }
  function pubSetEditing(on) {
    $('kkPubRec').classList.toggle('editing', on);
    $('kkPCancel').hidden = !on;
    $('kkPSave').textContent = on ? 'なおした内容で保存する' : 'この内容で記録する';
  }
  function pubStartEdit(l) {
    pf.editId = l.id;
    pf.year = l.year;
    pf.group = l.group;
    pf.subject = l.subject;
    pf.lap = l.lap;
    $('kkPScore').value = l.score;
    $('kkPGood').value = l.good || '';
    $('kkPBad').value = l.bad || '';
    renderPubForm();
    $('kkPubFormTitle').textContent = '記録をなおす（' + pubAttemptName(l) + '）';
    pubSetEditing(true);
    setMsg($('kkPFormMsg'), '');
    $('kkPubRec').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  function pubEndEdit() {
    pf.editId = null;
    pubClearAnswer();
    $('kkPubFormTitle').textContent = '結果を記録する';
    pubSetEditing(false);
    renderPubForm();
  }
  $('kkPCancel').addEventListener('click', function () { pubEndEdit(); setMsg($('kkPFormMsg'), ''); });

  $('kkPubForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = $('kkPFormMsg');
    if (!pf.year) { setMsg(msg, '年度をえらんでください'); return; }
    if (hasGroups(pf.year) && !pf.group) { setMsg(msg, ERR.invalid_group); return; }
    if (!pf.subject) { setMsg(msg, '科目をえらんでください'); return; }
    if (!pf.lap) { setMsg(msg, '何周目かをえらんでください'); return; }
    var sc = $('kkPScore').value.trim();
    if (!/^\d+$/.test(sc) || parseInt(sc, 10) > PUB.max) { setMsg(msg, ERR.invalid_public_score); return; }

    var payload = { action: 'public_save_log', year: pf.year, group: hasGroups(pf.year) ? pf.group : '',
      subject: pf.subject, lap: pf.lap, score: parseInt(sc, 10), good: $('kkPGood').value, bad: $('kkPBad').value };
    if (pf.editId) payload.log_id = pf.editId;
    if (payload.good.length > meta.textMax || payload.bad.length > meta.textMax) { setMsg(msg, ERR.text_too_long); return; }

    var wasEdit = !!pf.editId;
    var name = pubAttemptName(payload);
    $('kkPSave').disabled = true;
    call(payload, msg).then(function (data) {
      $('kkPSave').disabled = false;
      if (!data) return;
      // 同じ年度（グループ）のまま、次の科目をすぐ書けるようにする
      if (wasEdit) pubEndEdit();
      else { pubClearAnswer(); renderPubForm(); }
      setMsg(msg, (wasEdit ? 'なおしました: ' : '記録しました: ') + name, true);
    });
  });

  // 進捗表: 年度（グループ）× 5科目 ＋ 周目ごとの合計
  function renderPubBoard() {
    var box = $('kkPubBoard');
    var logs = state.pub.logs;
    var head = '<div class="b-head"><span class="b-name">愛知県公立高校入試</span>';
    if (!logs.length) {
      box.innerHTML = '<section class="card board">' + head + '</div><p class="empty">まだ記録がありません</p></section>';
      return;
    }
    var sum = 0;
    logs.forEach(function (l) { sum += l.score; });
    head += '<span class="b-sum">記録 <b>' + logs.length + '</b>回・平均 <b>' + (Math.round(10 * sum / logs.length) / 10)
      + '</b>点（' + PUB.max + '点満点）</span></div>';

    var rows = [], seen = {};
    logs.forEach(function (l) {
      var k = l.year + '|' + l.group;
      if (!seen[k]) { seen[k] = true; rows.push({ year: l.year, group: l.group }); }
    });
    rows.sort(function (a, b) { return (b.year - a.year) || (GROUP_ORDER[a.group] - GROUP_ORDER[b.group]); });
    var full = PUB.max * SUBJ_ORDER.length;

    var grid = '<div class="gridwrap"><table class="grid"><thead><tr><th>年度</th>'
      + SUBJ_ORDER.map(function (k) { return '<th>' + esc(SUBJ[k]) + '</th>'; }).join('')
      + '<th class="total">合計<small>／' + full + '</small></th></tr></thead><tbody>'
      + rows.map(function (r) {
        var inRow = logs.filter(function (l) { return l.year === r.year && l.group === r.group; });
        var cells = SUBJ_ORDER.map(function (k) {
          var cell = inRow.filter(function (l) { return l.subject === k; }).sort(function (a, b) { return a.lap - b.lap; });
          return '<td>' + cell.map(function (l) {
            return '<span class="lp ' + rateClass(pubRate(l.score)) + '">' + (CIRCLED[l.lap] || l.lap) + l.score + '</span>';
          }).join('') + '</td>';
        }).join('');
        // 周目ごとの合計。5科そろっていない周は何科ぶんの合計かを添える
        var laps = {};
        inRow.forEach(function (l) {
          if (!laps[l.lap]) laps[l.lap] = { sum: 0, n: 0 };
          laps[l.lap].sum += l.score;
          laps[l.lap].n++;
        });
        var total = Object.keys(laps).map(Number).sort(function (a, b) { return a - b; }).map(function (lap) {
          var t = laps[lap];
          return '<span class="lp ' + rateClass(Math.round(100 * t.sum / (PUB.max * t.n))) + '">' + (CIRCLED[lap] || lap) + t.sum
            + (t.n < SUBJ_ORDER.length ? '<small>（' + t.n + '科）</small>' : '') + '</span>';
        }).join('');
        return '<tr><th>' + r.year + (r.group ? '<small>' + esc(GROUP[r.group] || r.group) + '</small>' : '') + '</th>'
          + cells + '<td class="total">' + total + '</td></tr>';
      }).join('') + '</tbody></table></div>';

    var sorted = logs.slice().sort(function (a, b) {
      return (b.year - a.year) || (GROUP_ORDER[a.group] - GROUP_ORDER[b.group])
        || (SUBJ_ORDER.indexOf(a.subject) - SUBJ_ORDER.indexOf(b.subject)) || (a.lap - b.lap);
    });
    var list = sorted.map(function (l) {
      return '<div class="lg"><div class="lg1"><b>' + esc(pubExamName(l.year, l.group)) + '</b> ' + esc(SUBJ[l.subject] || l.subject)
        + ' <span class="lapb">' + l.lap + '周目</span>'
        + ' <span class="rt ' + rateClass(l.rate) + '">' + l.score + '点</span>'
        + '<small>／' + PUB.max + '点（' + l.rate + '%）</small>'
        + '<span class="lgd">' + esc(l.date_label) + ' 記録</span></div>'
        + (l.good ? '<div class="lgt"><i class="lbl-good">できた</i>' + esc(l.good) + '</div>' : '')
        + (l.bad ? '<div class="lgt"><i class="lbl-bad">できなかった</i>' + esc(l.bad) + '</div>' : '')
        + '<div class="acts"><button type="button" class="act" data-pedit="' + l.id + '">なおす</button>'
        + '<button type="button" class="act del" data-pdel="' + l.id + '">けす</button></div></div>';
    }).join('');
    box.innerHTML = '<section class="card board">' + head + grid
      + '<details class="logs" id="kkPubLogs"><summary>くわしい記録（' + logs.length + '件）</summary>' + list + '</details>'
      + '<div class="msg" id="kkPubBoardMsg"></div></section>';
  }

  $('kkPubBoard').addEventListener('click', function (e) {
    var ed = e.target.closest('[data-pedit]');
    if (ed) {
      var l = pubLogById(parseInt(ed.getAttribute('data-pedit'), 10));
      if (l) pubStartEdit(l);
      return;
    }
    var del = e.target.closest('[data-pdel]');
    if (del) {
      var d = pubLogById(parseInt(del.getAttribute('data-pdel'), 10));
      if (!d) return;
      if (!window.confirm(pubAttemptName(d) + ' の記録をけしますか？')) return;
      call({ action: 'public_delete_log', log_id: d.id }, $('kkPubBoardMsg')).then(function (data) {
        if (data && pf.editId === d.id) pubEndEdit();
      });
    }
  });

  // ============================================================
  function renderAll() {
    // 描き直すと「くわしい記録」が閉じてしまうので、開いていたものを覚えておく
    var open = {};
    [].forEach.call(document.querySelectorAll('#kkBoards details.logs'), function (d) {
      var m = d.parentNode.querySelector('[data-board-msg]');
      if (d.open && m) open[m.getAttribute('data-board-msg')] = true;
    });
    var pubOpen = !!($('kkPubLogs') && $('kkPubLogs').open);
    renderPace();
    renderSchools();
    renderForm();
    renderBoards();
    renderPub();
    [].forEach.call(document.querySelectorAll('#kkBoards details.logs'), function (d) {
      var m = d.parentNode.querySelector('[data-board-msg]');
      if (m && open[m.getAttribute('data-board-msg')]) d.open = true;
    });
    if (pubOpen && $('kkPubLogs')) $('kkPubLogs').open = true;
  }
  showTab(tab);
  renderAll();
})();
</script>
</body>
</html>
