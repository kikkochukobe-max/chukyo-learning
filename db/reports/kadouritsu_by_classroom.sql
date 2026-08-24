-- ============================================================
-- 学習サイト 稼働率レポート（教室別 × 小/中/高）
-- 2026-08-24 / phpMyAdmin にそのまま貼って実行（SELECT のみ・更新なし）
--
-- 稼働率 = 使用者 ÷ 在籍生徒数
--   在籍生徒数 : students.is_active = 1 かつ 氏名に「テスト」を含まない生徒
--   使用者     : answer_logs に記録がある生徒（全期間・累計）
--                「1問以上」＝一度でも触った生徒
--                「50問以上」＝実際に使い込んでいる生徒
--
-- 学校種は students.grade から判定する。保存形式のブレ（es4 / 小4 / ES4）を
-- 吸収するため LIKE で先頭を見る（teacher.php の grade_sort_key と同じ方針）。
-- 数字のみ（例 "4"）や空欄は学校種が決まらないので「未設定」に入る。
-- → クエリ4で中身を確認し、admin.php の学年欄を直すと分母が正しくなる。
--
-- ⚠ 退塾（is_active=0）の生徒は分母・分子とも除外している。
--   「配ったアカウント全体」で見たいときは各クエリの is_active 条件を外す。
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- クエリ1: 教室別 × 小/中/高 の稼働率（メイン）
--   在籍0の組み合わせも 0 行として出るので、教室ごとの構成が一目でわかる
-- ------------------------------------------------------------
SELECT
  c.classroom_name                                        AS 教室,
  g.stage                                                 AS 区分,
  COUNT(t.student_id)                                     AS 在籍,
  COALESCE(SUM(t.n >= 1), 0)                              AS 使用者_1問以上,
  ROUND(100 * COALESCE(SUM(t.n >= 1), 0)
        / NULLIF(COUNT(t.student_id), 0), 1)              AS `稼働率%_1問以上`,
  COALESCE(SUM(t.n >= 50), 0)                             AS 使用者_50問以上,
  ROUND(100 * COALESCE(SUM(t.n >= 50), 0)
        / NULLIF(COUNT(t.student_id), 0), 1)              AS `稼働率%_50問以上`
FROM classrooms c
CROSS JOIN (
  SELECT '小' AS stage UNION ALL SELECT '中' UNION ALL SELECT '高' UNION ALL SELECT '未設定'
) g
LEFT JOIN (
  SELECT
    s.student_id,
    s.classroom_id,
    CASE
      WHEN TRIM(s.grade) LIKE 'es%' OR TRIM(s.grade) LIKE '小%' THEN '小'
      WHEN TRIM(s.grade) LIKE 'js%' OR TRIM(s.grade) LIKE '中%' THEN '中'
      WHEN TRIM(s.grade) LIKE 'hs%' OR TRIM(s.grade) LIKE '高%' THEN '高'
      ELSE '未設定'
    END AS stage,
    COALESCE(a.n, 0) AS n
  FROM students s
  LEFT JOIN (
    SELECT student_id, COUNT(*) AS n FROM answer_logs GROUP BY student_id
  ) a ON a.student_id = s.student_id
  WHERE s.is_active = 1
    AND s.student_name NOT LIKE '%テスト%'
) t ON t.classroom_id = c.classroom_id AND t.stage = g.stage
GROUP BY c.classroom_id, c.classroom_name, g.stage
ORDER BY c.classroom_id, FIELD(g.stage, '小', '中', '高', '未設定');

-- ------------------------------------------------------------
-- クエリ2: 小/中/高 別の全教室合計（全体像）
-- ------------------------------------------------------------
SELECT
  t.stage                                                 AS 区分,
  COUNT(*)                                                AS 在籍,
  SUM(t.n >= 1)                                           AS 使用者_1問以上,
  ROUND(100 * SUM(t.n >= 1) / COUNT(*), 1)                AS `稼働率%_1問以上`,
  SUM(t.n >= 50)                                          AS 使用者_50問以上,
  ROUND(100 * SUM(t.n >= 50) / COUNT(*), 1)               AS `稼働率%_50問以上`
FROM (
  SELECT
    s.student_id,
    CASE
      WHEN TRIM(s.grade) LIKE 'es%' OR TRIM(s.grade) LIKE '小%' THEN '小'
      WHEN TRIM(s.grade) LIKE 'js%' OR TRIM(s.grade) LIKE '中%' THEN '中'
      WHEN TRIM(s.grade) LIKE 'hs%' OR TRIM(s.grade) LIKE '高%' THEN '高'
      ELSE '未設定'
    END AS stage,
    COALESCE(a.n, 0) AS n
  FROM students s
  LEFT JOIN (
    SELECT student_id, COUNT(*) AS n FROM answer_logs GROUP BY student_id
  ) a ON a.student_id = s.student_id
  WHERE s.is_active = 1
    AND s.student_name NOT LIKE '%テスト%'
) t
GROUP BY t.stage
ORDER BY FIELD(t.stage, '小', '中', '高', '未設定');

-- ------------------------------------------------------------
-- クエリ3: 教室別の全学年合計（教室ランキング用）
-- ------------------------------------------------------------
SELECT
  c.classroom_name                                        AS 教室,
  COUNT(t.student_id)                                     AS 在籍,
  COALESCE(SUM(t.n >= 1), 0)                              AS 使用者_1問以上,
  ROUND(100 * COALESCE(SUM(t.n >= 1), 0)
        / NULLIF(COUNT(t.student_id), 0), 1)              AS `稼働率%_1問以上`,
  COALESCE(SUM(t.n >= 50), 0)                             AS 使用者_50問以上,
  ROUND(100 * COALESCE(SUM(t.n >= 50), 0)
        / NULLIF(COUNT(t.student_id), 0), 1)              AS `稼働率%_50問以上`,
  COALESCE(SUM(t.n), 0)                                   AS 総解答数
FROM classrooms c
LEFT JOIN (
  SELECT s.student_id, s.classroom_id, COALESCE(a.n, 0) AS n
  FROM students s
  LEFT JOIN (
    SELECT student_id, COUNT(*) AS n FROM answer_logs GROUP BY student_id
  ) a ON a.student_id = s.student_id
  WHERE s.is_active = 1
    AND s.student_name NOT LIKE '%テスト%'
) t ON t.classroom_id = c.classroom_id
GROUP BY c.classroom_id, c.classroom_name
ORDER BY `稼働率%_1問以上` DESC, 在籍 DESC;

-- ------------------------------------------------------------
-- クエリ4: 学年表記から学校種が判別できない生徒（クエリ1の「未設定」の中身）
--   ここが多いと小中高の分母がずれる。admin.php の学年欄を es4 / js1 / hs2 形式に直す
-- ------------------------------------------------------------
SELECT
  c.classroom_name AS 教室,
  s.login_id       AS 生徒コード,
  s.student_name   AS 氏名,
  COALESCE(s.grade, '(空欄)') AS 学年の保存値
FROM students s
JOIN classrooms c ON c.classroom_id = s.classroom_id
WHERE s.is_active = 1
  AND s.student_name NOT LIKE '%テスト%'
  AND NOT (
        TRIM(s.grade) LIKE 'es%' OR TRIM(s.grade) LIKE '小%'
     OR TRIM(s.grade) LIKE 'js%' OR TRIM(s.grade) LIKE '中%'
     OR TRIM(s.grade) LIKE 'hs%' OR TRIM(s.grade) LIKE '高%'
  )
ORDER BY c.classroom_id, s.login_id;

-- ------------------------------------------------------------
-- クエリ5: 一度も解いていない生徒の一覧（声かけリスト）
-- ------------------------------------------------------------
SELECT
  c.classroom_name AS 教室,
  COALESCE(s.grade, '') AS 学年,
  s.login_id       AS 生徒コード,
  s.student_name   AS 氏名,
  s.created_at     AS 登録日
FROM students s
JOIN classrooms c ON c.classroom_id = s.classroom_id
LEFT JOIN answer_logs a ON a.student_id = s.student_id
WHERE s.is_active = 1
  AND s.student_name NOT LIKE '%テスト%'
  AND a.answer_id IS NULL
ORDER BY c.classroom_id, s.created_at;

-- ------------------------------------------------------------
-- クエリ6: テスト生だけの稼働率（氏名に「テスト」を含む生徒）
--   クエリ1〜5は全部このテスト生を除外しているので、あちらの数字には一切入っていない。
--   講師が動作確認で作ったアカウントなので、稼働率としての意味は無い
--   （＝どの端末・どの教室で検証したかの記録として読む）。
--   1人ずつ出すので、掃除したい行を選ぶのにも使える
--   （記録だけ消す: api/reset_student_records.php / db/maintenance/reset_student_records.sql）。
-- ------------------------------------------------------------
SELECT
  c.classroom_name          AS 教室,
  COALESCE(s.grade, '')      AS 学年,
  s.login_id                AS 生徒コード,
  s.student_name            AS 氏名,
  CASE WHEN s.is_active = 1 THEN '有効' ELSE '停止中' END AS 状態,
  COALESCE(a.n, 0)          AS 解答数,
  a.last_answered_at        AS 最終解答
FROM students s
JOIN classrooms c ON c.classroom_id = s.classroom_id
LEFT JOIN (
  SELECT student_id, COUNT(*) AS n, MAX(answered_at) AS last_answered_at
  FROM answer_logs GROUP BY student_id
) a ON a.student_id = s.student_id
WHERE s.student_name LIKE '%テスト%'
ORDER BY c.classroom_id, s.login_id;

-- ------------------------------------------------------------
-- クエリ7: テスト生の合計（クエリ1と同じ形。本物の生徒と混ぜずに1行で見る）
-- ------------------------------------------------------------
SELECT
  COUNT(*)                                                AS テスト生の数,
  SUM(t.n >= 1)                                           AS 使用者_1問以上,
  ROUND(100 * SUM(t.n >= 1) / COUNT(*), 1)                AS `稼働率%_1問以上`,
  SUM(t.n >= 50)                                          AS 使用者_50問以上,
  ROUND(100 * SUM(t.n >= 50) / COUNT(*), 1)               AS `稼働率%_50問以上`,
  SUM(t.n)                                                AS 総解答数
FROM (
  SELECT s.student_id, COALESCE(a.n, 0) AS n
  FROM students s
  LEFT JOIN (
    SELECT student_id, COUNT(*) AS n FROM answer_logs GROUP BY student_id
  ) a ON a.student_id = s.student_id
  WHERE s.student_name LIKE '%テスト%'
) t;
