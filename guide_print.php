<?php
declare(strict_types=1);

// ご家庭向け「中京個別AI学習ツールのご案内」の印刷（講師ページから開く）
//   教室を選び、生徒にチェックを入れて印刷するだけ。1人=A4縦1枚。
//   登録時の案内文（admin.php）と違い、いつでも何度でも出せる。
// ⚠ PIN は password_hash でしか持っていないので印字できない → PW欄は手書きの空欄（□□□□）
//   （再発行して印字する案はとらなかった＝保護者のログインPINまで変わってしまうため）
// ⚠ 保護者IDは guardians.login_id をそのまま出す。兄弟の2人目以降は「g＋自分の生徒コード」に
//   ならない（代表のお子さまのコード）ので、本文の説明と食い違う紙にはその旨の注記を足す
// 権限: super_admin=全教室 / それ以外=担当教室のみ（teacher.php / report.php と同じ）
// QRコードは URL が固定なので、qrcode-generator(1.4.4, qr_print_a4.html と同じもの・誤り訂正M)で
// 作ったモジュールを SVG の path として埋め込んである（印刷時にJSやCDNに頼らない）。
// URL を変えたら作り直すこと（qr_print_a4.html を開いて同じ URL で作れば同じ絵になる）
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

const GUIDE_URL_LEARNING = 'https://chukyokobetsu.com/learning/';
const GUIDE_URL_GUARDIAN = 'https://chukyokobetsu.com/guardian.php';
// 37 = 29モジュール（バージョン3）＋ 余白4×2
const QR_SIZE = 37;
const QR_PATH_LEARNING = 'M4 4h7v1h-7zM12 4h2v1h-2zM19 4h2v1h-2zM22 4h3v1h-3zM26 4h7v1h-7zM4 5h1v1h-1zM10 5h1v1h-1zM12 5h5v1h-5zM18 5h2v1h-2zM24 5h1v1h-1zM26 5h1v1h-1zM32 5h1v1h-1zM4 6h1v1h-1zM6 6h3v1h-3zM10 6h1v1h-1zM14 6h2v1h-2zM17 6h3v1h-3zM22 6h1v1h-1zM24 6h1v1h-1zM26 6h1v1h-1zM28 6h3v1h-3zM32 6h1v1h-1zM4 7h1v1h-1zM6 7h3v1h-3zM10 7h1v1h-1zM12 7h1v1h-1zM14 7h3v1h-3zM21 7h1v1h-1zM23 7h1v1h-1zM26 7h1v1h-1zM28 7h3v1h-3zM32 7h1v1h-1zM4 8h1v1h-1zM6 8h3v1h-3zM10 8h1v1h-1zM15 8h1v1h-1zM17 8h1v1h-1zM23 8h2v1h-2zM26 8h1v1h-1zM28 8h3v1h-3zM32 8h1v1h-1zM4 9h1v1h-1zM10 9h1v1h-1zM14 9h1v1h-1zM18 9h1v1h-1zM21 9h4v1h-4zM26 9h1v1h-1zM32 9h1v1h-1zM4 10h7v1h-7zM12 10h1v1h-1zM14 10h1v1h-1zM16 10h1v1h-1zM18 10h1v1h-1zM20 10h1v1h-1zM22 10h1v1h-1zM24 10h1v1h-1zM26 10h7v1h-7zM12 11h1v1h-1zM14 11h9v1h-9zM4 12h1v1h-1zM6 12h2v1h-2zM9 12h3v1h-3zM14 12h11v1h-11zM26 12h1v1h-1zM29 12h1v1h-1zM31 12h2v1h-2zM7 13h2v1h-2zM14 13h3v1h-3zM21 13h4v1h-4zM26 13h3v1h-3zM32 13h1v1h-1zM4 14h2v1h-2zM9 14h2v1h-2zM18 14h1v1h-1zM20 14h3v1h-3zM26 14h1v1h-1zM28 14h1v1h-1zM30 14h2v1h-2zM5 15h3v1h-3zM11 15h1v1h-1zM14 15h1v1h-1zM16 15h4v1h-4zM22 15h1v1h-1zM25 15h2v1h-2zM28 15h1v1h-1zM32 15h1v1h-1zM6 16h1v1h-1zM10 16h2v1h-2zM13 16h1v1h-1zM16 16h1v1h-1zM19 16h1v1h-1zM21 16h2v1h-2zM24 16h1v1h-1zM29 16h2v1h-2zM5 17h2v1h-2zM9 17h1v1h-1zM11 17h1v1h-1zM14 17h4v1h-4zM19 17h2v1h-2zM23 17h1v1h-1zM26 17h1v1h-1zM30 17h3v1h-3zM4 18h1v1h-1zM6 18h2v1h-2zM9 18h5v1h-5zM16 18h1v1h-1zM18 18h1v1h-1zM20 18h4v1h-4zM25 18h1v1h-1zM27 18h1v1h-1zM30 18h3v1h-3zM9 19h1v1h-1zM16 19h2v1h-2zM20 19h1v1h-1zM24 19h1v1h-1zM26 19h3v1h-3zM31 19h1v1h-1zM4 20h1v1h-1zM10 20h1v1h-1zM15 20h2v1h-2zM19 20h1v1h-1zM22 20h1v1h-1zM25 20h1v1h-1zM28 20h2v1h-2zM31 20h1v1h-1zM5 21h1v1h-1zM11 21h1v1h-1zM14 21h4v1h-4zM19 21h1v1h-1zM21 21h1v1h-1zM24 21h1v1h-1zM27 21h1v1h-1zM29 21h3v1h-3zM4 22h1v1h-1zM8 22h4v1h-4zM13 22h2v1h-2zM16 22h1v1h-1zM19 22h2v1h-2zM24 22h3v1h-3zM28 22h1v1h-1zM30 22h1v1h-1zM6 23h3v1h-3zM12 23h3v1h-3zM21 23h1v1h-1zM25 23h4v1h-4zM30 23h1v1h-1zM5 24h1v1h-1zM7 24h1v1h-1zM10 24h2v1h-2zM13 24h1v1h-1zM15 24h3v1h-3zM19 24h12v1h-12zM12 25h1v1h-1zM14 25h1v1h-1zM17 25h2v1h-2zM20 25h1v1h-1zM22 25h1v1h-1zM24 25h1v1h-1zM28 25h5v1h-5zM4 26h7v1h-7zM12 26h4v1h-4zM17 26h1v1h-1zM21 26h4v1h-4zM26 26h1v1h-1zM28 26h2v1h-2zM31 26h1v1h-1zM4 27h1v1h-1zM10 27h1v1h-1zM12 27h2v1h-2zM15 27h1v1h-1zM19 27h1v1h-1zM22 27h1v1h-1zM24 27h1v1h-1zM28 27h2v1h-2zM31 27h1v1h-1zM4 28h1v1h-1zM6 28h3v1h-3zM10 28h1v1h-1zM15 28h2v1h-2zM18 28h1v1h-1zM21 28h1v1h-1zM24 28h5v1h-5zM30 28h2v1h-2zM4 29h1v1h-1zM6 29h3v1h-3zM10 29h1v1h-1zM12 29h3v1h-3zM17 29h5v1h-5zM25 29h1v1h-1zM27 29h3v1h-3zM32 29h1v1h-1zM4 30h1v1h-1zM6 30h3v1h-3zM10 30h1v1h-1zM12 30h2v1h-2zM17 30h1v1h-1zM20 30h1v1h-1zM23 30h1v1h-1zM27 30h1v1h-1zM30 30h1v1h-1zM32 30h1v1h-1zM4 31h1v1h-1zM10 31h1v1h-1zM14 31h2v1h-2zM17 31h1v1h-1zM24 31h1v1h-1zM26 31h2v1h-2zM29 31h1v1h-1zM31 31h1v1h-1zM4 32h7v1h-7zM12 32h2v1h-2zM16 32h2v1h-2zM20 32h1v1h-1zM22 32h6v1h-6zM31 32h1v1h-1z';
const QR_PATH_GUARDIAN = 'M4 4h7v1h-7zM12 4h2v1h-2zM17 4h1v1h-1zM20 4h1v1h-1zM22 4h3v1h-3zM26 4h7v1h-7zM4 5h1v1h-1zM10 5h1v1h-1zM12 5h1v1h-1zM14 5h1v1h-1zM18 5h1v1h-1zM24 5h1v1h-1zM26 5h1v1h-1zM32 5h1v1h-1zM4 6h1v1h-1zM6 6h3v1h-3zM10 6h1v1h-1zM13 6h1v1h-1zM17 6h1v1h-1zM20 6h1v1h-1zM22 6h1v1h-1zM24 6h1v1h-1zM26 6h1v1h-1zM28 6h3v1h-3zM32 6h1v1h-1zM4 7h1v1h-1zM6 7h3v1h-3zM10 7h1v1h-1zM12 7h2v1h-2zM17 7h5v1h-5zM23 7h1v1h-1zM26 7h1v1h-1zM28 7h3v1h-3zM32 7h1v1h-1zM4 8h1v1h-1zM6 8h3v1h-3zM10 8h1v1h-1zM14 8h2v1h-2zM19 8h2v1h-2zM23 8h2v1h-2zM26 8h1v1h-1zM28 8h3v1h-3zM32 8h1v1h-1zM4 9h1v1h-1zM10 9h1v1h-1zM13 9h1v1h-1zM15 9h1v1h-1zM17 9h1v1h-1zM20 9h5v1h-5zM26 9h1v1h-1zM32 9h1v1h-1zM4 10h7v1h-7zM12 10h1v1h-1zM14 10h1v1h-1zM16 10h1v1h-1zM18 10h1v1h-1zM20 10h1v1h-1zM22 10h1v1h-1zM24 10h1v1h-1zM26 10h7v1h-7zM12 11h1v1h-1zM15 11h1v1h-1zM18 11h5v1h-5zM4 12h1v1h-1zM6 12h2v1h-2zM9 12h3v1h-3zM14 12h1v1h-1zM17 12h1v1h-1zM19 12h6v1h-6zM26 12h1v1h-1zM29 12h1v1h-1zM31 12h2v1h-2zM4 13h1v1h-1zM7 13h2v1h-2zM13 13h2v1h-2zM17 13h1v1h-1zM21 13h4v1h-4zM26 13h3v1h-3zM32 13h1v1h-1zM6 14h1v1h-1zM10 14h1v1h-1zM13 14h1v1h-1zM16 14h2v1h-2zM21 14h2v1h-2zM26 14h1v1h-1zM28 14h1v1h-1zM30 14h2v1h-2zM4 15h1v1h-1zM6 15h1v1h-1zM11 15h4v1h-4zM19 15h1v1h-1zM22 15h1v1h-1zM25 15h2v1h-2zM28 15h1v1h-1zM32 15h1v1h-1zM4 16h2v1h-2zM10 16h2v1h-2zM14 16h1v1h-1zM16 16h1v1h-1zM18 16h1v1h-1zM21 16h2v1h-2zM24 16h1v1h-1zM29 16h2v1h-2zM4 17h1v1h-1zM6 17h3v1h-3zM11 17h1v1h-1zM13 17h1v1h-1zM18 17h3v1h-3zM23 17h1v1h-1zM26 17h1v1h-1zM30 17h3v1h-3zM5 18h2v1h-2zM8 18h5v1h-5zM14 18h1v1h-1zM17 18h1v1h-1zM21 18h3v1h-3zM25 18h1v1h-1zM27 18h1v1h-1zM30 18h3v1h-3zM8 19h2v1h-2zM11 19h1v1h-1zM13 19h2v1h-2zM18 19h2v1h-2zM24 19h1v1h-1zM26 19h3v1h-3zM31 19h1v1h-1zM4 20h5v1h-5zM10 20h1v1h-1zM14 20h2v1h-2zM18 20h1v1h-1zM22 20h1v1h-1zM25 20h1v1h-1zM28 20h2v1h-2zM31 20h1v1h-1zM6 21h1v1h-1zM14 21h1v1h-1zM18 21h2v1h-2zM24 21h1v1h-1zM27 21h1v1h-1zM29 21h3v1h-3zM4 22h1v1h-1zM6 22h3v1h-3zM10 22h1v1h-1zM12 22h1v1h-1zM16 22h6v1h-6zM24 22h3v1h-3zM28 22h1v1h-1zM30 22h1v1h-1zM8 23h1v1h-1zM15 23h4v1h-4zM21 23h2v1h-2zM25 23h4v1h-4zM30 23h1v1h-1zM5 24h1v1h-1zM10 24h1v1h-1zM13 24h1v1h-1zM16 24h15v1h-15zM12 25h1v1h-1zM15 25h1v1h-1zM20 25h1v1h-1zM24 25h1v1h-1zM28 25h5v1h-5zM4 26h7v1h-7zM12 26h1v1h-1zM14 26h3v1h-3zM18 26h1v1h-1zM21 26h4v1h-4zM26 26h1v1h-1zM28 26h2v1h-2zM31 26h1v1h-1zM4 27h1v1h-1zM10 27h1v1h-1zM12 27h1v1h-1zM14 27h1v1h-1zM16 27h3v1h-3zM22 27h1v1h-1zM24 27h1v1h-1zM28 27h2v1h-2zM4 28h1v1h-1zM6 28h3v1h-3zM10 28h1v1h-1zM14 28h1v1h-1zM19 28h1v1h-1zM21 28h1v1h-1zM24 28h5v1h-5zM30 28h1v1h-1zM4 29h1v1h-1zM6 29h3v1h-3zM10 29h1v1h-1zM12 29h3v1h-3zM16 29h2v1h-2zM19 29h3v1h-3zM25 29h1v1h-1zM27 29h3v1h-3zM32 29h1v1h-1zM4 30h1v1h-1zM6 30h3v1h-3zM10 30h1v1h-1zM12 30h6v1h-6zM20 30h1v1h-1zM23 30h1v1h-1zM27 30h1v1h-1zM30 30h1v1h-1zM32 30h1v1h-1zM4 31h1v1h-1zM10 31h1v1h-1zM14 31h1v1h-1zM16 31h1v1h-1zM24 31h1v1h-1zM26 31h2v1h-2zM29 31h1v1h-1zM31 31h1v1h-1zM4 32h7v1h-7zM12 32h1v1h-1zM15 32h3v1h-3zM20 32h1v1h-1zM22 32h6v1h-6zM31 32h1v1h-1z';

// 時候の挨拶（発行した月で入れ替える。元の文面は8月の「酷暑の候」）
const SEASON_GREETINGS = [
    1 => '厳寒の候', 2 => '余寒の候', 3 => '早春の候', 4 => '陽春の候',
    5 => '新緑の候', 6 => '梅雨の候', 7 => '盛夏の候', 8 => '酷暑の候',
    9 => '初秋の候', 10 => '秋冷の候', 11 => '晩秋の候', 12 => '師走の候',
];

// teacher.php の grade_sort_key と同じ並び（小→中→高）
function grade_sort_key(?string $grade): int
{
    if ($grade === null || $grade === '') return 999;
    $grade = strtr($grade, ['０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9']);
    if (preg_match('/(es|小)\s*(\d)/ui', $grade, $m)) return 100 + (int)$m[2];
    if (preg_match('/(js|中)\s*(\d)/ui', $grade, $m)) return 200 + (int)$m[2];
    if (preg_match('/(hs|高)\s*(\d)/ui', $grade, $m)) return 300 + (int)$m[2];
    return 900;
}

// es4 → 小4 / js1 → 中1 / hs2 → 高2（それ以外はそのまま）
function grade_label(?string $grade): string
{
    $g = trim((string)$grade);
    if (preg_match('/^(es|js|hs)\s*(\d)$/i', $g, $m)) {
        return ['es' => '小', 'js' => '中', 'hs' => '高'][strtolower($m[1])] . $m[2];
    }
    return $g;
}

$actor = current_actor();
if (!$actor || $actor['type'] !== 'teacher') {
    header('Location: /teacher.php');   // ログインは講師ページで
    exit;
}

$teacherId = $actor['id'];
$pdo = db();

$stmt = $pdo->prepare('SELECT role, must_change_password FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $teacherId]);
$me = $stmt->fetch();
if (!$me) { header('Location: /teacher.php'); exit; }
if ((int)$me['must_change_password'] === 1) { header('Location: /password.php'); exit; }
$role = (string)$me['role'];

if ($role === 'super_admin') {
    $classrooms = $pdo->query('SELECT classroom_id, classroom_name FROM classrooms ORDER BY classroom_id')->fetchAll();
} else {
    $stmt = $pdo->prepare(
        'SELECT c.classroom_id, c.classroom_name FROM classrooms c
         JOIN teacher_classrooms tc ON tc.classroom_id = c.classroom_id
         WHERE tc.teacher_id = :id ORDER BY c.classroom_id'
    );
    $stmt->execute(['id' => $teacherId]);
    $classrooms = $stmt->fetchAll();
}

$classroomNames = [];
foreach ($classrooms as $c) $classroomNames[(int)$c['classroom_id']] = (string)$c['classroom_name'];

$cid = (int)($_GET['c'] ?? 0);
if (!isset($classroomNames[$cid])) $cid = $classrooms ? (int)$classrooms[0]['classroom_id'] : 0;

$students = [];
if ($cid > 0) {
    // 保護者は有効なものだけ（兄弟の統合で外れた元の保護者は is_active=0）
    $stmt = $pdo->prepare(
        'SELECT s.student_id, s.login_id, s.student_name, s.grade,
                (SELECT g.login_id FROM guardian_students gs
                   JOIN guardians g ON g.guardian_id = gs.guardian_id
                  WHERE gs.student_id = s.student_id AND g.is_active = 1
                  ORDER BY g.guardian_id LIMIT 1) AS guardian_login_id
           FROM students s
          WHERE s.classroom_id = :cid AND s.is_active = 1'
    );
    $stmt->execute(['cid' => $cid]);
    $students = $stmt->fetchAll();
    usort($students, function ($a, $b) {
        return [grade_sort_key($a['grade']), $a['login_id']] <=> [grade_sort_key($b['grade']), $b['login_id']];
    });
}

$today = new DateTimeImmutable('today');
$issued = '令和' . ((int)$today->format('Y') - 2018) . '年' . (int)$today->format('n') . '月' . (int)$today->format('j') . '日';
$season = SEASON_GREETINGS[(int)$today->format('n')];
$classroomName = $classroomNames[$cid] ?? '';
$logo = 'https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png';
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ご家庭向け 学習ツールのご案内 | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--shu:#C73E2E;--ai:#2C5F8A;--kin:#C9A227;
    --white:#fff;--line:#E3DECF;--radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6}

  /* ---- 操作パネル（画面だけ） ---- */
  .panel{max-width:860px;margin:20px auto 0;background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);border-top:4px solid var(--ai);padding:18px 20px}
  .panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
  .panel h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai)}
  .back{font-size:13px;color:var(--ai)}
  .note{font-size:12px;color:var(--ink-soft);margin-top:6px}
  .tabs{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px}
  .tabs a{font-size:13px;padding:5px 12px;border-radius:999px;border:1px solid var(--line);color:var(--ink);
    text-decoration:none;background:var(--paper)}
  .tabs a.on{background:var(--ai);border-color:var(--ai);color:#fff;font-weight:700}
  .tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:14px}
  .tools input[type=search]{padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;width:180px}
  .btn{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:13px;padding:6px 14px;border-radius:8px;
    border:1px solid var(--ai);background:var(--white);color:var(--ai);cursor:pointer}
  .btn.go{background:var(--ai);color:#fff}
  .btn:disabled{opacity:.4;cursor:default}
  .count{font-size:13px;font-weight:700}
  .list{margin-top:12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:6px}
  .list label{display:flex;align-items:center;gap:8px;font-size:14px;padding:6px 10px;border:1px solid var(--line);
    border-radius:8px;cursor:pointer;background:var(--paper)}
  .list label:has(input:checked){border-color:var(--ai);background:#EAF1F7}
  .list .gr{font-size:11px;color:var(--ink-soft);min-width:2.2em}
  .list .nog{font-size:10px;color:var(--shu);margin-left:auto}
  .empty{font-size:13px;color:var(--ink-soft);margin-top:12px}

  /* ---- 案内の紙（A4縦） ---- */
  .sheets{padding:24px 16px 80px;display:flex;flex-direction:column;align-items:center;gap:24px}
  .sheet{display:none;width:210mm;height:296mm;background:#fff;box-shadow:0 10px 40px rgba(51,49,43,.22);
    padding:12mm 15mm 10mm;font-size:9.6pt;line-height:1.62;color:#222;overflow:hidden;position:relative}
  .sheet.on{display:flex;flex-direction:column}
  .s-top{display:flex;justify-content:space-between;align-items:flex-start;gap:6mm}
  .s-to{font-size:10.5pt;font-weight:700;margin-top:1mm}
  .s-date{font-size:9.5pt;text-align:right;white-space:nowrap}
  .s-brand{display:flex;align-items:center;gap:2mm;justify-content:flex-end;margin-top:1mm;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:10pt;letter-spacing:.08em}
  .s-brand img{width:7mm;height:7mm}
  .s-title-row{display:flex;align-items:center;justify-content:space-between;gap:6mm;margin-top:3mm}
  .s-title{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:19pt;letter-spacing:.06em;
    color:var(--ai);border-bottom:.6mm solid var(--ai);padding-bottom:1.5mm;flex:1}
  .qr-box{text-align:center;flex:none}
  .qr-box svg{display:block;width:27mm;height:27mm;margin:0 auto}
  .qr-cap{font-size:8pt;font-weight:700;line-height:1.35;margin-top:.5mm}
  .qr-url{font-size:6.5pt;color:#555;line-height:1.3;word-break:break-all}
  .s-letter{margin-top:4mm}
  .s-letter p{text-indent:1em}
  .s-letter .hk{text-indent:0}
  .s-letter .kg{text-align:right;text-indent:0}
  .s-h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:12pt;color:var(--ai);
    margin-top:3.5mm;padding-left:2.5mm;border-left:1.6mm solid var(--ai);line-height:1.4}
  .s-feat{list-style:none;margin:2mm 0 4mm}
  .s-feat li{padding-left:1em;text-indent:-1em;margin-top:.5mm}
  .s-feat li::before{content:'・'}
  .s-feat .sub{display:block;padding-left:1em;text-indent:-1em;font-size:9pt}
  .s-feat .sub2{display:block;padding-left:2em;font-size:9pt;text-indent:0}
  .s-feat strong{color:var(--shu)}

  .s-id{margin-top:auto;border:.5mm solid var(--ink);border-radius:3mm;padding:3.5mm 5mm 3.5mm;
    display:flex;gap:5mm;align-items:stretch}
  .s-id-main{flex:1;min-width:0}
  .s-id-name{font-size:12pt;font-weight:700;border-bottom:.3mm solid #999;padding-bottom:1mm}
  .s-id-name small{font-size:9pt;font-weight:400;margin-right:3mm}
  .s-id table{border-collapse:collapse;width:100%;margin-top:2mm}
  .s-id th{font-size:9pt;text-align:left;font-weight:700;white-space:nowrap;padding:1.2mm 3mm 1.2mm 0;vertical-align:middle}
  .s-id td{padding:1.2mm 2mm 1.2mm 0;vertical-align:middle;font-size:9.5pt}
  .s-id .k{font-size:8.5pt;color:#555;white-space:nowrap}
  .s-id .v{font-family:ui-monospace,'Consolas',monospace;font-size:15pt;font-weight:700;letter-spacing:.08em}
  .pinbox{display:inline-flex;gap:1.5mm;vertical-align:middle}
  .pinbox i{display:inline-block;width:8mm;height:9mm;border:.35mm solid #333;border-radius:1mm;background:#fff}
  .s-id .row-g th,.s-id .row-g td{border-top:.3mm dashed #aaa}
  .s-id .warn{font-size:8pt;color:var(--shu);margin-top:1mm;line-height:1.45}
  .s-id .keep{font-size:8pt;color:#555;margin-top:1.5mm;line-height:1.45}
  .s-id .qr-box{align-self:center}
  .s-foot{margin-top:2.5mm;text-align:right;font-size:9pt;font-weight:700}

  @media print{
    @page{size:A4 portrait;margin:0}
    body{background:#fff}
    .panel{display:none}
    .sheets{padding:0;gap:0;display:block}
    .sheet{box-shadow:none;margin:0 auto;height:290mm}
    .sheet.on{break-after:page;page-break-after:always}
    .sheet.on.lastp{break-after:auto;page-break-after:auto}
    .s-title,.s-h2{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
</style>
</head>
<body>

<div class="panel">
  <div class="panel-head">
    <h1>ご家庭向け 学習ツールのご案内（印刷）</h1>
    <a class="back" href="/teacher.php">← 講師ページへもどる</a>
  </div>
  <p class="note">教室を選び、生徒にチェックを入れて「印刷する」。1人につきA4縦1枚です。生徒コード・保護者IDは自動で入ります。
    <strong>PIN（4桁）は記録していないため印字されません</strong>。PW欄の□に手書きで記入してください。</p>
  <?php if (!$classrooms): ?>
    <p class="empty">担当の教室がありません。</p>
  <?php else: ?>
  <nav class="tabs">
    <?php foreach ($classrooms as $c): ?>
      <a href="?c=<?= (int)$c['classroom_id'] ?>" class="<?= (int)$c['classroom_id'] === $cid ? 'on' : '' ?>"><?= h($c['classroom_name']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!$students): ?>
    <p class="empty"><?= h($classroomName) ?>教室に在籍中の生徒はいません。</p>
  <?php else: ?>
  <div class="tools">
    <input type="search" id="q" placeholder="氏名・生徒コードでしぼる">
    <button class="btn" type="button" id="all">表示中を全員選ぶ</button>
    <button class="btn" type="button" id="none">選択を外す</button>
    <span class="count" id="count">0人を選択中</span>
    <button class="btn go" type="button" id="print" disabled>印刷する</button>
  </div>
  <div class="list" id="list">
    <?php foreach ($students as $s): ?>
      <label data-key="<?= h($s['student_name'] . ' ' . $s['login_id']) ?>">
        <input type="checkbox" value="<?= (int)$s['student_id'] ?>">
        <span class="gr"><?= h(grade_label($s['grade'])) ?></span>
        <span><?= h($s['student_name']) ?></span>
        <?php if (!$s['guardian_login_id']): ?><span class="nog" title="保護者アカウントが未発行です（admin.php の保護者登録で発行できます）">保護者未発行</span><?php endif; ?>
      </label>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="qr-learning" viewBox="0 0 <?= QR_SIZE ?> <?= QR_SIZE ?>" shape-rendering="crispEdges">
    <rect width="<?= QR_SIZE ?>" height="<?= QR_SIZE ?>" fill="#fff"/><path d="<?= QR_PATH_LEARNING ?>" fill="#000"/>
  </symbol>
  <symbol id="qr-guardian" viewBox="0 0 <?= QR_SIZE ?> <?= QR_SIZE ?>" shape-rendering="crispEdges">
    <rect width="<?= QR_SIZE ?>" height="<?= QR_SIZE ?>" fill="#fff"/><path d="<?= QR_PATH_GUARDIAN ?>" fill="#000"/>
  </symbol>
</svg>

<div class="sheets">
<?php foreach ($students as $s):
    $code = (string)$s['login_id'];
    $gid  = $s['guardian_login_id'] !== null ? (string)$s['guardian_login_id'] : '';
    $grade = grade_label($s['grade']);
?>
  <section class="sheet" data-id="<?= (int)$s['student_id'] ?>">
    <div class="s-top">
      <p class="s-to">中京個別指導学院に在籍する生徒様・保護者様</p>
      <div>
        <p class="s-date"><?= h($issued) ?></p>
        <p class="s-brand"><img src="<?= h($logo) ?>" alt="">中京個別指導学院</p>
      </div>
    </div>

    <div class="s-title-row">
      <h2 class="s-title">中京個別AI学習ツールのご案内</h2>
      <div class="qr-box">
        <svg viewBox="0 0 <?= QR_SIZE ?> <?= QR_SIZE ?>" role="img" aria-label="学習ツールのQRコード"><use href="#qr-learning"/></svg>
        <p class="qr-cap">学習ツールはこちら</p>
        <p class="qr-url"><?= h(GUIDE_URL_LEARNING) ?></p>
      </div>
    </div>

    <div class="s-letter">
      <p class="hk">拝啓</p>
      <p><?= h($season) ?>、ますますご健勝のこととお慶び申し上げます。また、平素は格別のご高配を賜り、厚く御礼申し上げます。</p>
      <p>本日は中京個別指導学院AI学習ツールについて、ご案内いたします。昨今のAIの進歩とスマートフォン・タブレットの普及を鑑み、この度、お子様がたが「いつでも」「どこでも」学習できるツールを中京個別指導学院の講師陣が独自で開発いたしました。今後は、学力向上における必須要素のひとつである「繰り返し練習する」という部分を、このAI学習ツールでサポートすることが可能になります。“お子様にとってプラスになることはすぐ実行する”という、中京個別の講師マインドを体現したツールですので、ぜひご活用下さい。今後も、お子様の学力向上・志望校合格に向け、講師一同全力で取り組んでまいります。何卒よろしくお願い申し上げます。</p>
      <p class="kg">敬具</p>
    </div>

    <h3 class="s-h2">中京個別AI学習ツールの特徴</h3>
    <ul class="s-feat">
      <li>中京個別に在籍しているお子様限定で使用できるツールです。
        <span class="sub">※お子様お一人ずつのID/PWを発行しております。他の人に見せず、大切に保管して下さい。</span>
        <span class="sub2">（ご本人様以外の方の使用が発覚した場合、今後のツール利用を制限いたしますのでご注意下さい。）</span>
        <span class="sub">※ID/PWを記載している紙面、もしくは右上のQRコードからログインしてください。</span></li>
      <li>間違えた問題のチェック・解き直しがツール内で可能です。（学習管理を行うことができます。）</li>
      <li>ツール内のお子様の学習状況を講師が確認し、今後の指導に活用することが可能です。</li>
      <li>講師陣が試行錯誤しながら作成したツールですので、不具合等が発生することもございます。何卒ご理解下さい。（その際、不具合の状況を教室内の講師にお教え頂けますと幸いです）</li>
      <li>ツール内のお子様の学習状況を、保護者様が確認できます（一部の学習ページのみが対象です）。
        <span class="sub">※下記の保護者様用QRコードを読み取り、ID/PWを入力・ログインして下さい。IDは、お子様のIDの文字列の最初に、小文字のg（ジー）を加えたものです（例：g○○○○○○）。PWはお子様と同じものです。</span></li>
    </ul>

    <div class="s-id">
      <div class="s-id-main">
        <p class="s-id-name"><small><?= h($classroomName) ?>教室<?= $grade !== '' ? '　' . h($grade) : '' ?></small><?= h($s['student_name']) ?> 様</p>
        <table>
          <tr>
            <th>お子様用</th>
            <td><span class="k">ID（生徒コード）</span><br><span class="v"><?= h($code) ?></span></td>
            <td><span class="k">PW（4桁の数字）</span><br><span class="pinbox"><i></i><i></i><i></i><i></i></span></td>
          </tr>
          <tr class="row-g">
            <th>保護者様用</th>
            <td><span class="k">ID</span><br><span class="v"><?= $gid !== '' ? h($gid) : '―' ?></span></td>
            <td><span class="k">PW</span><br>お子様と同じ4桁の数字</td>
          </tr>
        </table>
        <?php if ($gid === ''): ?>
          <p class="warn">※保護者様用のIDは準備中です。教室の講師までお問い合わせください。</p>
        <?php elseif ($gid !== 'g' . $code): ?>
          <p class="warn">※ご兄弟でご登録いただいているため、保護者様用IDは上記のとおりです（ご兄弟どなたのPWでもログインできます）。</p>
        <?php endif; ?>
        <p class="keep">この用紙はID/PWを記載しています。他の人に見せず、大切に保管して下さい。</p>
      </div>
      <div class="qr-box">
        <svg viewBox="0 0 <?= QR_SIZE ?> <?= QR_SIZE ?>" role="img" aria-label="保護者様用QRコード"><use href="#qr-guardian"/></svg>
        <p class="qr-cap">保護者様用QRコード</p>
        <p class="qr-url"><?= h(GUIDE_URL_GUARDIAN) ?></p>
      </div>
    </div>
    <p class="s-foot">中京個別指導学院　<?= h($classroomName) ?>教室</p>
  </section>
<?php endforeach; ?>
</div>

<script>
(function () {
  var list = document.getElementById('list');
  if (!list) return;
  var boxes = Array.prototype.slice.call(list.querySelectorAll('input[type=checkbox]'));
  var sheets = {};
  document.querySelectorAll('.sheet').forEach(function (el) { sheets[el.getAttribute('data-id')] = el; });
  var count = document.getElementById('count');
  var printBtn = document.getElementById('print');

  // チェックした生徒の紙だけを表示＝印刷する。最後の1枚だけ改ページを切る（白紙の最終ページ防止）
  function sync() {
    var last = null, n = 0;
    boxes.forEach(function (b) {
      var s = sheets[b.value];
      s.classList.toggle('on', b.checked);
      s.classList.remove('lastp');
      if (b.checked) { n++; last = s; }
    });
    if (last) last.classList.add('lastp');
    count.textContent = n + '人を選択中';
    printBtn.disabled = n === 0;
  }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });

  function visible(b) { return b.closest('label').style.display !== 'none'; }
  document.getElementById('all').addEventListener('click', function () {
    boxes.forEach(function (b) { if (visible(b)) b.checked = true; });
    sync();
  });
  document.getElementById('none').addEventListener('click', function () {
    boxes.forEach(function (b) { b.checked = false; });
    sync();
  });
  document.getElementById('q').addEventListener('input', function (e) {
    var q = e.target.value.trim();
    boxes.forEach(function (b) {
      var lab = b.closest('label');
      lab.style.display = (q === '' || lab.getAttribute('data-key').indexOf(q) !== -1) ? '' : 'none';
    });
  });
  // Webフォントが届く前に印刷すると字幅が変わって紙からはみ出すので待つ
  printBtn.addEventListener('click', function () {
    var go = function () { window.print(); };
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(go); else go();
  });
  sync();
})();
</script>
</body>
</html>
