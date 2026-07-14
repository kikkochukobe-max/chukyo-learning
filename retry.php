<?php
declare(strict_types=1);

// 解き直しページ: retry_queue の pending 問題を一覧表示し、該当ツールへ誘導する。
// 正解・誤答の詳細は出さない（マイページ設計ルール: 誤解答詳細は講師画面専用）。
// マスター判定は「同じ問題(params_hash)に2連続正解」なので、ツール側で
// 同一問題を再出題する仕組みが入るまでは、ここは復習リスト+練習への導線。
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

$actor = current_actor();

if (!$actor || $actor['type'] !== 'student') {
    header('Location: /mypage.php');
    exit;
}

$studentId = $actor['id'];
$pdo = db();

// pending の解き直し一覧（問題文は最後に間違えた時の answer_logs から取る）
$stmt = $pdo->prepare(
    "SELECT rq.unit_key, rq.question_key, rq.wrong_count, rq.correct_streak, rq.last_answered_at,
            COALESCE(qc.label, rq.question_key) AS label,
            al.question_text
     FROM retry_queue rq
     LEFT JOIN question_catalog qc
       ON qc.unit_key = rq.unit_key AND qc.question_key = rq.question_key
     LEFT JOIN answer_logs al ON al.answer_id = (
        SELECT MAX(al2.answer_id) FROM answer_logs al2
        WHERE al2.student_id = rq.student_id AND al2.unit_key = rq.unit_key
          AND al2.question_key = rq.question_key AND al2.params_hash = rq.params_hash
          AND al2.is_correct = 0
     )
     WHERE rq.student_id = :id AND rq.status = 'pending'
     ORDER BY rq.unit_key, rq.updated_at DESC"
);
$stmt->execute(['id' => $studentId]);
$rows = $stmt->fetchAll();

$unitMeta = require __DIR__ . '/api/units.php';
$units = [];
foreach ($rows as $row) {
    $units[$row['unit_key']][] = $row;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>過去のまちがいを解き直す | 中京個別指導学院</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@500;700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
<style>
  :root{
    --paper:#FBFAF6; --grid:#ECE9E0; --ink:#33312B; --ink-soft:#8B877C;
    --shu:#C73E2E; --shu-soft:#F6E3DF; --ai:#2C5F8A; --kin:#C9A227;
    --white:#FFFFFF; --radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08), 0 6px 16px rgba(51,49,43,.06);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);
    background-color:var(--paper);
    background-image:
      linear-gradient(var(--grid) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size:24px 24px;
    line-height:1.6;-webkit-font-smoothing:antialiased;
    /* Androidが端末フォント設定で本文を縮小するのを防ぎ、指定サイズで表示する */
    -webkit-text-size-adjust:100%;text-size-adjust:100%;
  }
  .wrap{max-width:560px;margin:0 auto;padding:0 16px 64px}
  header{display:flex;align-items:center;justify-content:space-between;padding:14px 2px 10px}
  header img.logo{height:34px;width:auto;display:block}
  .back{font-size:13px;color:var(--ai);text-decoration:none;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}

  .hero{
    background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:20px;margin-top:6px;border-top:4px solid var(--shu);
  }
  .hero h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:20px}
  .hero p{font-size:13px;color:var(--ink-soft);margin-top:4px}

  .section-title{
    display:flex;align-items:center;gap:10px;margin:24px 2px 10px;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;
  }
  .section-title::after{content:"";flex:1;border-top:2px dotted var(--ink-soft);opacity:.4}

  .karte{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:18px;margin-bottom:14px}
  .karte h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:16px;
    display:flex;align-items:baseline;gap:8px}
  .karte h2 small{font-size:11px;font-weight:500;color:var(--ink-soft)}
  .item{padding:12px 0;border-bottom:1px solid #F3F0E8}
  .item:last-of-type{border-bottom:none}
  .chip{
    display:inline-block;font-size:11px;font-weight:700;color:var(--shu);
    background:var(--shu-soft);border-radius:999px;padding:1px 10px;
    font-family:'Zen Maru Gothic',sans-serif;
  }
  .qtext{margin-top:6px;font-size:15px;overflow-x:auto}
  .meta{font-size:11px;color:var(--ink-soft);margin-top:4px}
  .golink{
    display:block;text-align:center;margin-top:14px;background:var(--shu);color:#fff;
    border-radius:10px;padding:12px;text-decoration:none;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:14px;
  }
  .empty{
    background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:32px 20px;text-align:center;margin-top:16px;
  }
  .empty .big{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:20px;color:var(--shu)}
  .empty p{font-size:13px;color:var(--ink-soft);margin-top:6px}
  footer{margin-top:28px;text-align:center;font-size:11px;color:var(--ink-soft)}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png"
         alt="中京個別指導学院">
    <a class="back" href="/mypage.php">← 学習の記録へ</a>
  </header>

  <section class="hero">
    <h1>過去のまちがいを解き直す</h1>
    <p>これまでにまちがえた問題のリストです。「再チャレンジ」から全く同じ問題がもう一度出ます。<br><strong>同じ問題に2回連続で正解すると、リストから消えます。</strong></p>
  </section>

<?php if (count($units) === 0): ?>
  <div class="empty">
    <div class="big">解き直しはありません！</div>
    <p>まちがえた問題がここに たまっていきます</p>
  </div>
<?php else: ?>
<?php foreach ($units as $unitKey => $items):
    $meta = $unitMeta[$unitKey] ?? ['title' => $unitKey, 'sub' => '', 'url' => null];
?>
  <div class="section-title"><?= h($meta['title']) ?></div>
  <section class="karte">
<?php foreach ($items as $item): ?>
    <div class="item">
      <span class="chip"><?= h($item['label']) ?></span>
      <?php if ($item['question_text']): ?>
      <div class="qtext" data-math="<?= h($item['question_text']) ?>"><?= h($item['question_text']) ?></div>
      <?php endif; ?>
      <div class="meta">まちがえた回数: <?= (int)$item['wrong_count'] ?>回 ・ <?= (int)$item['correct_streak'] === 1 ? 'あと1回連続で正解すると消えるよ' : '2回連続で正解すると消えるよ' ?></div>
    </div>
<?php endforeach; ?>
<?php if (!empty($meta['url'])): ?>
    <a class="golink" href="<?= h($meta['url']) ?>?retry=1">同じ問題に再チャレンジする →</a>
<?php endif; ?>
  </section>
<?php endforeach; ?>
<?php endif; ?>

  <footer>中京個別指導学院 学習の記録</footer>
</div>
<script>
// question_text を KaTeX で整形。全体がLaTeXのものと、Unicodeの√/²・分数F(a/b)が
// 混じった日本語文（正誤問題など）の両方に対応する。混在文はクイズ本体
// (math_js3_heihokonmaster.html) と同じ規則で数式トークンを LaTeX 化して描画する。
function _mescape(t){ return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function _texWhole(src){ try { return katex.renderToString(src, { throwOnError: true, displayMode: false }); } catch (e) { return _mescape(src); } }
// KaTeXでLaTeX片を描画。未読込・失敗時は fallback（なければ生LaTeX）にする。
function _K(latex, fallback){
  try { if (typeof katex === 'undefined') throw 0;
    return katex.renderToString(latex, { throwOnError: false, displayMode: false }); }
  catch (e) { return _mescape(fallback != null ? fallback : latex); }
}
// 数式トークン → LaTeX（クイズ本体 toLatex と同じ変換規則）
function _toLatex(token){
  var m = token.match(/^\([-－]√([\d.]+)\)²$/);   if (m) return '(-\\sqrt{' + m[1] + '})^2';
  m = token.match(/^\(√([\d.]+)\)²$/);            if (m) return '(\\sqrt{' + m[1] + '})^2';
  m = token.match(/^±√([\d.]+)$/);                 if (m) return '\\pm\\sqrt{' + m[1] + '}';
  m = token.match(/^(\d+)√([\d.]+)$/);             if (m) return m[1] + '\\sqrt{' + m[2] + '}';
  m = token.match(/^[-－]√([\d.]+)$/);             if (m) return '-\\sqrt{' + m[1] + '}';
  m = token.match(/^√\((\d+)\/(\d+)\)$/);          if (m) return '\\sqrt{\\dfrac{' + m[1] + '}{' + m[2] + '}}';
  m = token.match(/^F\((\d+)\/(\d+)\)$/);          if (m) return '\\dfrac{' + m[1] + '}{' + m[2] + '}';
  m = token.match(/^[-－]√\(\(-(\d+)\)²\)$/);      if (m) return '-\\sqrt{(-' + m[1] + ')^2}';
  m = token.match(/^√\(\(-(\d+)\)²\)$/);           if (m) return '\\sqrt{(-' + m[1] + ')^2}';
  m = token.match(/^√([\d.]+)$/);                  if (m) return '\\sqrt{' + m[1] + '}';
  return token;
}
// 地の文（数式トークン以外）だけをエスケープ＋整形。改行→<br> はここだけで行い、
// KaTeX出力（SVGパスに改行を含む）は絶対に触らない。
function _plain(t){ return _mescape(t).replace(/(?<!\d)-([\d])/g, '－$1').replace(/\n/g, '<br>'); }
// 日本語文中の数式トークンだけをKaTeX描画し、地の文はエスケープして返す
function _renderMath(str){
  var re = /[-－]√\(\(-\d+\)²\)|√\(\(-\d+\)²\)|\([-－]√[\d.]+\)²|\(√[\d.]+\)²|√\(\d+\/\d+\)|±√[\d.]+|\d+√[\d.]+|[-－]√[\d.]+|√[\d.]+|F\(\d+\/\d+\)/g;
  var out = '', last = 0, mt;
  while ((mt = re.exec(str)) !== null) {
    out += _plain(str.slice(last, mt.index));
    out += _K(_toLatex(mt[0]), mt[0]);
    last = mt.index + mt[0].length;
  }
  out += _plain(str.slice(last));
  return out;
}
function renderMathToHTML(src){
  src = String(src == null ? '' : src);
  if (/[\\^_{}]/.test(src)) return _texWhole(src);
  if (/[√²³]/.test(src) || /F\(\d+\/\d+\)/.test(src)) return _renderMath(src);
  return _mescape(src).replace(/\n/g, '<br>');
}
document.querySelectorAll('.qtext').forEach(function (el) {
  el.innerHTML = renderMathToHTML(el.getAttribute('data-math') || '');
});
</script>
</body>
</html>
