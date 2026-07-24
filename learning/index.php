<?php
declare(strict_types=1);

// 学習ツールの目次ページ。learning/ 配下の *.html をリクエストごとに自動スキャンするため、
// ファイルをFTPアップロードするだけで一覧に反映される（台帳メンテ不要）。
// タイトルは各HTMLの <title> から取得し、塾名・SEO地名リストのセグメントは自動で除去する。

// ---- 教科フォルダの表示設定（並び順・ラベル・教科色） ----
// ここに無いフォルダが増えた場合もフォルダ名のまま末尾に表示される
$subjects = [
    'sansu'    => ['label' => '算数',       'color' => '#D89A45'],
    'math'     => ['label' => '数学',       'color' => '#C73E2E'],
    'english'  => ['label' => '英語',       'color' => '#2C5F8A'],
    'science'  => ['label' => '理科',       'color' => '#4A7C59'],
    'japanese' => ['label' => '国語',       'color' => '#8A5EA6'],
    'social'   => ['label' => '社会',       'color' => '#C9862B'],
    'allgrade' => ['label' => '全学年',     'color' => '#C9A227'],
    'game'     => ['label' => 'ゲーム',     'color' => '#E07A3F'],
];

// ---- 手動で足すリンク（自動スキャン対象外・別サイトの対策アプリ等）----
// 自動生成カードと区別できるよう external=true で藍の破線スタイル＋別タブ表示。
// 配列の並び順がそのまま各教科セクションの先頭での表示順になる（表の順を維持）。
// 新しく足す時はこの配列に1行追加するだけ。
$manual = [
    ['folder' => 'math', 'grade' => '中1', 'title' => '文字の式対策',     'href' => 'https://mojinoshiki.chukyo.workers.dev/Mojinoshiki'],
    ['folder' => 'math', 'grade' => '中1', 'title' => '方程式対策',       'href' => 'https://houteishiki.chukyo.workers.dev/Houteishiki'],
    ['folder' => 'math', 'grade' => '中2', 'title' => '式の計算対策',     'href' => 'https://shikinokeisan.chukyo.workers.dev/ShikinoKeisan'],
    ['folder' => 'math', 'grade' => '中2', 'title' => '連立方程式対策',   'href' => 'https://renritsuhoteishiki.chukyo.workers.dev/Renritsuhoteishiki'],
    ['folder' => 'math', 'grade' => '中2', 'title' => '一次関数対策',     'href' => 'https://ichijikansu.chukyo.workers.dev/Ichijikansu'],
    ['folder' => 'math', 'grade' => '中3', 'title' => '展開因数分解対策', 'href' => 'https://tenkaiinsu.chukyo.workers.dev/TenkaiInsu'],
    ['folder' => 'math', 'grade' => '中3', 'title' => '平方根対策',       'href' => 'https://heihoukon.chukyo.workers.dev/Heihoukon'],
    ['folder' => 'math', 'grade' => '中3', 'title' => '二次方程式対策',   'href' => 'https://nijihoteishiki.chukyo.workers.dev/Nijihoteishiki'],
    ['folder' => 'social', 'grade' => '中学', 'title' => '歴史2章対策',   'href' => 'https://rekishi2.chukyo.workers.dev/Rekishi2'],
    ['folder' => 'social', 'grade' => '中学', 'title' => '歴史3章対策',   'href' => 'https://rekishi3.chukyo.workers.dev/Rekishi3'],
    ['folder' => 'social', 'grade' => '中学', 'title' => '歴史6章対策',   'href' => 'https://rekishi6.chukyo.workers.dev/Rekishi6'],
    ['folder' => 'social', 'grade' => '中学', 'title' => '年号ミニゲーム', 'href' => 'https://nenngouminige-mu.chukyo.workers.dev/Nenngouminige-mu'],
];

// <title> を「メインタイトル」「サブタイトル」に分解する。
// ｜ | や前後スペース付きダッシュで区切り、塾名・地名の羅列（・が3個以上）は捨てる
function parse_title(string $raw): array
{
    $parts = preg_split('/\s*[｜|]\s*|\s+[—–-]\s+/u', $raw) ?: [$raw];
    $kept = [];
    foreach ($parts as $p) {
        $p = trim(preg_replace('/【[^】]*】/u', '', $p)); // 【無料】等のSEO装飾は表示から除去
        $p = trim(preg_replace('/\s*無料\S*/u', '', $p)); // 「無料ドリル」「無料クイズ・ドリル」等のSEO文言も除去
        if ($p === '') continue;
        if (mb_strpos($p, '中京個別指導学院') !== false) continue;
        if (mb_substr_count($p, '・') >= 3) continue; // SEO用の地名リスト
        $kept[] = $p;
    }
    if (!$kept) $kept = [trim($raw)];
    return [$kept[0], $kept[1] ?? ''];
}

// 学年バッジ（小3 / 中1 / 高2 / 小学 / 全学年 / 空）を並べ替え用の数値に変換する。
// 小(100)→中(200)→高(300)→全学年(900)→不明(800) を基準に学年番号を加算。
// 番号なし（小学・中学）は同校種の番号付きより前に来る。
function grade_rank(string $grade): int
{
    $school = mb_substr($grade, 0, 1);
    $base = ['小' => 100, '中' => 200, '高' => 300][$school]
        ?? ($grade === '全学年' ? 900 : 800);
    if (preg_match('/(\d)/u', $grade, $m)) return $base + (int)$m[1];
    return $base;
}

// ファイル名の2番目の要素（es3/js/hs2 等）から学年バッジ文字列を作る
function grade_badge(string $filename): string
{
    $parts = explode('_', pathinfo($filename, PATHINFO_FILENAME));
    foreach ($parts as $p) {
        if (preg_match('/^(es|js|hs)(\d?)$/', $p, $m)) {
            // 番号あり: 小3 / 中1 / 高2。番号なし: 小学 / 中学 / 高校（校種ごとに正しい語尾）
            if ($m[2] !== '') return ['es' => '小', 'js' => '中', 'hs' => '高'][$m[1]] . $m[2];
            return ['es' => '小学', 'js' => '中学', 'hs' => '高校'][$m[1]];
        }
    }
    return '';
}

// ---- learning/ 配下をスキャン ----
$tools = [];
$dirs = array_filter(glob(__DIR__ . '/*', GLOB_ONLYDIR) ?: [], 'is_dir');
foreach ($dirs as $dir) {
    $folder = basename($dir);
    foreach (glob($dir . '/*.html') ?: [] as $file) {
        $head = (string)file_get_contents($file, false, null, 0, 16384);
        // 16KB境界が多バイト文字の途中に当たると preg の u フラグが「文字列全体が
        // 不正UTF-8」と見なして失敗し、<title>が取れずファイル名表示に落ちる。
        // 末尾の壊れたバイト列を除去して妥当なUTF-8に整えてから処理する。
        $head = mb_scrub($head, 'UTF-8');
        $rawTitle = '';
        if (preg_match('/<title>(.*?)<\/title>/isu', $head, $m)) {
            $rawTitle = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }
        // 推奨環境バッジ（ツール側に <meta name="divp-device" content="PC・タブレット推奨"> があれば表示）
        $device = '';
        if (preg_match('/<meta\s+name=["\']divp-device["\']\s+content=["\'](.*?)["\']/iu', $head, $m)) {
            $device = trim($m[1]);
        }
        $name = basename($file);
        [$title, $sub] = $rawTitle !== '' ? parse_title($rawTitle) : [pathinfo($name, PATHINFO_FILENAME), ''];
        $grade = grade_badge($name);
        if ($grade === '' && $folder === 'allgrade') $grade = '全学年';
        // math フォルダは校種で「算数（小学生）」と「数学（中高生）」に分けて表示する
        $group = $folder;
        if ($folder === 'math') {
            $group = (mb_substr($grade, 0, 1) === '小') ? 'sansu' : 'math';
        }
        $tools[] = [
            'folder' => $group,
            'href'   => $folder . '/' . $name,
            'title'  => $title,
            'sub'    => $sub,
            'grade'  => $grade,
            'device' => $device,
            'isNew'  => (time() - (int)filemtime($file)) < 60 * 60 * 24 * 30, // 30日以内は NEW
        ];
    }
}

// 手動リンクを $tools に合流（seq=配列内の順番。教科内での表示順に使う）
foreach ($manual as $i => $m) {
    $tools[] = [
        'folder'   => $m['folder'],
        'href'     => $m['href'],
        'title'    => $m['title'],
        'sub'      => $m['sub'] ?? '',
        'grade'    => $m['grade'] ?? '',
        'device'   => '',
        'isNew'    => false,
        'external' => true,
        'seq'      => $i,
    ];
}

// 教科の定義順 → 学年順（小1→…→高3）→ 同学年内は手動リンク優先 → ファイル名順に並べる
$order = array_flip(array_keys($subjects));
usort($tools, function (array $a, array $b) use ($order): int {
    $oa = $order[$a['folder']] ?? 999;
    $ob = $order[$b['folder']] ?? 999;
    if ($oa !== $ob) return $oa <=> $ob;
    // 学年順（数学は 中1→中2→中3→高… の順。href文字列順だと高が中より先に来てしまうため）
    $ga = grade_rank($a['grade']);
    $gb = grade_rank($b['grade']);
    if ($ga !== $gb) return $ga <=> $gb;
    // 同学年内では手動リンク（seq付き）を先に、$manual の並び順で固定
    $sa = $a['seq'] ?? PHP_INT_MAX;
    $sb = $b['seq'] ?? PHP_INT_MAX;
    if ($sa !== $sb) return $sa <=> $sb;
    return strcmp($a['href'], $b['href']);
});

// タブは「ツールが存在する教科」だけ出す（未定義フォルダはフォルダ名のまま）
$tabs = [];
foreach ($tools as $t) {
    $f = $t['folder'];
    if (!isset($tabs[$f])) {
        $tabs[$f] = $subjects[$f]['label'] ?? $f;
    }
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function subject_color(array $subjects, string $folder): string
{
    return $subjects[$folder]['color'] ?? '#8B877C';
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>学習ツールいちらん | 中京個別指導学院</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@500;700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<style>
  :root{
    --paper:#FBFAF6;
    --grid:#ECE9E0;
    --ink:#33312B;
    --ink-soft:#8B877C;
    --shu:#C73E2E;
    --shu-soft:#F6E3DF;
    --ai:#2C5F8A;
    --kin:#C9A227;
    --white:#FFFFFF;
    --radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08), 0 6px 16px rgba(51,49,43,.06);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;
    color:var(--ink);
    background-color:var(--paper);
    background-image:
      linear-gradient(var(--grid) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size:24px 24px;
    line-height:1.6;
    -webkit-font-smoothing:antialiased;
    -webkit-text-size-adjust:100%;text-size-adjust:100%;
  }

  /* ---------- 固定ヘッダー ---------- */
  .site-head{
    position:sticky;top:0;z-index:100;
    background:rgba(251,250,246,.92);
    backdrop-filter:blur(8px);
    -webkit-backdrop-filter:blur(8px);
    border-bottom:1px solid var(--grid);
    box-shadow:0 1px 6px rgba(51,49,43,.05);
  }
  .head-inner{
    max-width:960px;margin:0 auto;padding:12px 16px 10px;
    display:flex;align-items:center;gap:14px;flex-wrap:wrap;
  }
  .head-inner img.logo{height:32px;width:auto;display:block}
  .head-title{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;
    font-size:17px;letter-spacing:.04em;white-space:nowrap;
  }
  .head-title small{
    display:block;font-size:10px;font-weight:700;
    letter-spacing:.18em;color:var(--shu);line-height:1.2;
  }
  .searchbox{
    flex:1;min-width:200px;position:relative;margin-left:auto;
  }
  .searchbox svg{
    position:absolute;left:12px;top:50%;transform:translateY(-50%);
    width:15px;height:15px;stroke:var(--ink-soft);fill:none;
    stroke-width:2;stroke-linecap:round;
  }
  .searchbox input{
    width:100%;padding:9px 14px 9px 36px;
    font:inherit;font-size:14px;color:var(--ink);
    background:var(--white);border:1.5px solid var(--grid);
    border-radius:999px;outline:none;
    transition:border-color .15s, box-shadow .15s;
  }
  .searchbox input:focus{
    border-color:var(--shu);box-shadow:0 0 0 3px var(--shu-soft);
  }
  .searchbox input::placeholder{color:#B9B5A9}
  /* 教科タブ */
  .tabs{
    max-width:960px;margin:0 auto;padding:0 16px 10px;
    display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;
  }
  .tabs::-webkit-scrollbar{display:none}
  .tab{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;
    font-size:13px;white-space:nowrap;cursor:pointer;
    padding:5px 14px;border-radius:999px;border:1.5px solid var(--grid);
    background:var(--white);color:var(--ink-soft);
    transition:all .15s;
  }
  .tab:hover{border-color:var(--ink-soft)}
  .tab.active{background:var(--ink);border-color:var(--ink);color:var(--white)}

  /* ---------- 本体 ---------- */
  .wrap{max-width:960px;margin:0 auto;padding:22px 16px 80px}
  .count{font-size:12px;color:var(--ink-soft);margin-bottom:14px}
  .count b{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;
    font-size:15px;color:var(--ink)}

  .subject-group{margin-bottom:30px}
  .subject-head{
    display:flex;align-items:center;gap:10px;margin-bottom:12px;
  }
  .subject-head .mark{
    width:10px;height:10px;border-radius:3px;flex:none;
  }
  .subject-head h2{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;
    font-size:18px;letter-spacing:.04em;
  }
  .subject-head .n{
    font-size:12px;color:var(--ink-soft);font-weight:400;
  }

  .grid-cards{
    display:grid;gap:14px;
    grid-template-columns:repeat(auto-fill, minmax(250px, 1fr));
  }
  .card{
    display:block;text-decoration:none;color:inherit;
    background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);padding:16px 16px 14px;
    border-top:4px solid var(--grid);
    transition:transform .15s, box-shadow .15s;
    position:relative;overflow:hidden;
  }
  .card:hover{
    transform:translateY(-3px);
    box-shadow:0 3px 8px rgba(51,49,43,.10), 0 12px 26px rgba(51,49,43,.10);
  }
  .card .badges{display:flex;gap:6px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
  .badge{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;
    font-size:11px;line-height:1;padding:4px 9px;border-radius:999px;
  }
  .badge.grade{background:var(--paper);border:1px solid var(--grid);color:var(--ink-soft)}
  .badge.new{background:var(--kin);color:var(--white);letter-spacing:.08em}
  .badge.device{background:#EAF1F7;border:1px solid #C5D6E4;color:var(--ai)}
  .card h3{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;
    font-size:16px;line-height:1.45;letter-spacing:.01em;
  }
  .card .sub{
    font-size:12px;color:var(--ink-soft);margin-top:4px;line-height:1.5;
  }
  .card .go{
    display:flex;align-items:center;gap:4px;justify-content:flex-end;
    margin-top:10px;font-family:'Zen Maru Gothic',sans-serif;
    font-weight:700;font-size:12px;
  }

  /* ---------- 手動追加リンク（別サイトの対策アプリ）---------- */
  /* 自動生成カード（白地＋教科色の上バー）と区別: 藍の破線枠＋淡い藍地＋別タブ */
  .card.external{
    border:2px dashed var(--ai);
    background:linear-gradient(180deg,#F3F8FC,var(--white));
  }
  .card.external:hover{
    border-color:#22496B;
    box-shadow:0 3px 8px rgba(44,95,138,.12), 0 12px 26px rgba(44,95,138,.14);
  }
  .badge.ext{
    background:var(--ai);color:var(--white);letter-spacing:.04em;
  }
  .card.external .go{color:var(--ai)}

  .empty{
    text-align:center;padding:70px 16px;color:var(--ink-soft);
    display:none;
  }
  .empty .face{font-size:38px;line-height:1;margin-bottom:12px}
  .empty p{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:15px}
  .empty small{font-size:12px}

  footer{
    text-align:center;padding:0 16px 40px;
    font-size:11px;color:var(--ink-soft);letter-spacing:.06em;
  }

  @media (max-width:600px){
    .head-inner{gap:10px;padding:10px 14px 8px}
    .head-inner img.logo{height:26px}
    .head-title{font-size:14px}
    .searchbox{flex-basis:100%;order:10;margin-left:0}
    .wrap{padding-top:16px}
  }
</style>
</head>
<body>

<header class="site-head">
  <div class="head-inner">
    <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png" alt="中京個別指導学院">
    <div class="head-title">
      <small>CHUKYO LEARNING</small>
      学習ツールいちらん
    </div>
    <div class="searchbox">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/></svg>
      <input type="search" id="q" placeholder="ツール名・単元・学年でさがす" autocomplete="off">
    </div>
  </div>
  <nav class="tabs" id="tabs">
    <button class="tab active" data-subject="">すべて</button>
    <?php foreach ($tabs as $folder => $label): ?>
    <button class="tab" data-subject="<?= h($folder) ?>"><?= h($label) ?></button>
    <?php endforeach; ?>
  </nav>
</header>

<main class="wrap">
  <p class="count"><b id="shown"><?= count($tools) ?></b> 件のツール</p>

  <?php foreach ($tabs as $folder => $label): ?>
  <section class="subject-group" data-subject="<?= h($folder) ?>">
    <div class="subject-head">
      <span class="mark" style="background:<?= h(subject_color($subjects, $folder)) ?>"></span>
      <h2><?= h($label) ?></h2>
      <span class="n"></span>
    </div>
    <div class="grid-cards">
      <?php foreach ($tools as $t): if ($t['folder'] !== $folder) continue;
        $color = subject_color($subjects, $folder);
        $search = mb_strtolower($t['title'] . ' ' . $t['sub'] . ' ' . $t['grade'] . ' ' . $label . ' ' . $t['href']);
        $isExt = !empty($t['external']); // 手動追加リンク（別サイトの対策アプリ）は別スタイル＋別タブ
      ?>
      <a class="card<?= $isExt ? ' external' : '' ?>" href="<?= h($t['href']) ?>"
         <?php if ($isExt): ?>target="_blank" rel="noopener"<?php else: ?>style="border-top-color:<?= h($color) ?>"<?php endif; ?>
         data-search="<?= h($search) ?>">
        <div class="badges">
          <?php if ($t['grade'] !== ''): ?><span class="badge grade"><?= h($t['grade']) ?></span><?php endif; ?>
          <?php if ($isExt): ?><span class="badge ext">別サイト ↗</span><?php endif; ?>
          <?php if ($t['device'] !== ''): ?><span class="badge device">💻 <?= h($t['device']) ?></span><?php endif; ?>
          <?php if ($t['isNew']): ?><span class="badge new">NEW</span><?php endif; ?>
        </div>
        <h3><?= h($t['title']) ?></h3>
        <?php if ($t['sub'] !== ''): ?><p class="sub"><?= h($t['sub']) ?></p><?php endif; ?>
        <?php if ($isExt): ?>
        <div class="go">ひらく ↗</div>
        <?php else: ?>
        <div class="go" style="color:<?= h($color) ?>">はじめる →</div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <div class="empty" id="empty">
    <div class="face">･ᴗ･</div>
    <p>見つかりませんでした</p>
    <small>ことばを かえて さがしてみてね</small>
  </div>
</main>

<footer>© 中京個別指導学院</footer>

<script>
(function () {
  'use strict';
  var input = document.getElementById('q');
  var tabs = document.querySelectorAll('.tab');
  var groups = document.querySelectorAll('.subject-group');
  var shown = document.getElementById('shown');
  var empty = document.getElementById('empty');
  var currentSubject = '';

  // カタカナ→ひらがな + 小文字化（ざっくり表記ゆれ吸収）
  function norm(s) {
    return s.toLowerCase().replace(/[ァ-ヶ]/g, function (ch) {
      return String.fromCharCode(ch.charCodeAt(0) - 0x60);
    });
  }

  function apply() {
    var q = norm(input.value.trim());
    var total = 0;
    groups.forEach(function (g) {
      var subjectOk = !currentSubject || g.dataset.subject === currentSubject;
      var visible = 0;
      g.querySelectorAll('.card').forEach(function (card) {
        var hit = subjectOk && (!q || norm(card.dataset.search).indexOf(q) !== -1);
        card.style.display = hit ? '' : 'none';
        if (hit) visible++;
      });
      g.style.display = visible ? '' : 'none';
      g.querySelector('.subject-head .n').textContent = visible + '本';
      total += visible;
    });
    shown.textContent = total;
    empty.style.display = total ? 'none' : 'block';
  }

  input.addEventListener('input', apply);
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      currentSubject = tab.dataset.subject;
      apply();
    });
  });
  apply();
})();
</script>
</body>
</html>
