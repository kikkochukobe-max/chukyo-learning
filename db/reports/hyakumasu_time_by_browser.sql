-- ============================================================
-- 100マス計算 タイム × ブラウザ別（iPhone Safari / iPhone LINE /
--                                  Android Chrome / Android LINE）
-- 2026-09-01 / phpMyAdmin にそのまま貼って実行（SELECT のみ・更新なし）
--
-- 目的: 「Android の LINE ビューだけタイムが短く出る」という感触を、
--       すでに溜まっている time_records で確かめる。
--
-- しくみ: time_records.session_id → study_sessions.user_agent で
--       「そのプレイをどのブラウザでやったか」が分かる（UAは
--       セッション開始時＝ページを開いた時の1本）。
--
-- ⚠ 読み方の注意（これを外すと数字を誤読する）
--   ・モード(meta.mode)で難易度が違う。h=よこしき / grid=100マス。
--     必ずモード別に見る（クエリ1・2はモードで分けてある）。
--   ・端末は生徒ごとに固定されがち＝「速い子がAndroidだった」だけの
--     可能性が常にある。**クエリ4・5（同一生徒の中での比較）が本番**で、
--     クエリ1は目安にすぎない。
--   ・miss_count も並べてある。タイムが短くて誤答も同じくらいなら
--     「本当に速い（＝取りこぼしが無くて打ち直しが要らない）」、
--     タイムが短いのに誤答だけ多いなら「1接地が2入力になっている」等の
--     入力側の異常を疑う。
--     （iOS は素早い2打目が握りつぶされる既知の症状があり、
--       打ち直しの分だけタイムが伸びる＝Android が短く見える側に働く）
--   ・session_id が NULL のプレイ（未ログイン退避からの保存など）は
--     ブラウザが分からないので全クエリの対象外。まず クエリ0 で
--     判定できた件数を確認する。
--   ・テスト生（氏名に「テスト」を含む）は除外している。
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- クエリ0: そもそも何件がブラウザ判定できるか（母数の確認）
-- ------------------------------------------------------------
SELECT
  COUNT(*)                                                     AS 全プレイ,
  SUM(tr.session_id IS NOT NULL AND ss.user_agent IS NOT NULL)  AS UAあり,
  SUM(tr.session_id IS NULL)                                   AS セッション無し,
  MIN(tr.created_at)                                           AS 最初,
  MAX(tr.created_at)                                           AS 最新
FROM time_records tr
LEFT JOIN study_sessions ss ON ss.session_id = tr.session_id
JOIN students s ON s.student_id = tr.student_id
WHERE tr.unit_key = 'math_es_hyakumasu'
  AND s.student_name NOT LIKE '%テスト%';

-- ------------------------------------------------------------
-- クエリ1: ブラウザ × モード の平均・最速・誤答（ざっくり全体像）
--   プレイ数が10未満の行は偶然で動くので数字を信じないこと
-- ------------------------------------------------------------
SELECT
  CASE
    WHEN ss.user_agent LIKE '%Line/%' AND ss.user_agent REGEXP 'iPhone|iPad|iPod' THEN '2. iPhone LINE'
    WHEN ss.user_agent REGEXP 'iPhone|iPad|iPod' AND ss.user_agent LIKE '%CriOS%' THEN '1b. iPhone Chrome'
    WHEN ss.user_agent REGEXP 'iPhone|iPad|iPod'                                  THEN '1. iPhone Safari'
    WHEN ss.user_agent LIKE '%Line/%' AND ss.user_agent LIKE '%Android%'          THEN '4. Android LINE'
    WHEN ss.user_agent LIKE '%Android%'                                           THEN '3. Android Chrome'
    ELSE '9. その他/PC'
  END                                                              AS ブラウザ,
  COALESCE(JSON_UNQUOTE(JSON_EXTRACT(tr.meta, '$.mode')), '(不明)') AS モード,
  COUNT(*)                                                         AS プレイ数,
  COUNT(DISTINCT tr.student_id)                                    AS 生徒数,
  ROUND(AVG(tr.time_ms) / 1000, 1)                                 AS 平均秒,
  ROUND(MIN(tr.time_ms) / 1000, 1)                                 AS 最速秒,
  ROUND(MAX(tr.time_ms) / 1000, 1)                                 AS 最遅秒,
  ROUND(STDDEV_SAMP(tr.time_ms) / 1000, 1)                         AS ばらつき秒,
  ROUND(AVG(tr.miss_count), 2)                                     AS 平均誤答
FROM time_records tr
JOIN study_sessions ss ON ss.session_id = tr.session_id
JOIN students s        ON s.student_id  = tr.student_id
WHERE tr.unit_key = 'math_es_hyakumasu'
  AND ss.user_agent IS NOT NULL
  AND s.student_name NOT LIKE '%テスト%'
GROUP BY ブラウザ, モード
ORDER BY モード, ブラウザ;

-- ------------------------------------------------------------
-- クエリ2: 同じものを「中央値」で（平均は1本の外れ値で動くため）
--   MariaDB 10.2 / MySQL 8 以降で動く（ウィンドウ関数）
-- ------------------------------------------------------------
WITH base AS (
  SELECT
    CASE
      WHEN ss.user_agent LIKE '%Line/%' AND ss.user_agent REGEXP 'iPhone|iPad|iPod' THEN '2. iPhone LINE'
      WHEN ss.user_agent REGEXP 'iPhone|iPad|iPod' AND ss.user_agent LIKE '%CriOS%' THEN '1b. iPhone Chrome'
      WHEN ss.user_agent REGEXP 'iPhone|iPad|iPod'                                  THEN '1. iPhone Safari'
      WHEN ss.user_agent LIKE '%Line/%' AND ss.user_agent LIKE '%Android%'          THEN '4. Android LINE'
      WHEN ss.user_agent LIKE '%Android%'                                           THEN '3. Android Chrome'
      ELSE '9. その他/PC'
    END AS fam,
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(tr.meta, '$.mode')), '(不明)') AS md,
    tr.time_ms
  FROM time_records tr
  JOIN study_sessions ss ON ss.session_id = tr.session_id
  JOIN students s        ON s.student_id  = tr.student_id
  WHERE tr.unit_key = 'math_es_hyakumasu'
    AND ss.user_agent IS NOT NULL
    AND s.student_name NOT LIKE '%テスト%'
),
ranked AS (
  SELECT fam, md, time_ms,
         ROW_NUMBER() OVER (PARTITION BY fam, md ORDER BY time_ms) AS rn,
         COUNT(*)     OVER (PARTITION BY fam, md)                  AS cnt
  FROM base
)
SELECT fam AS ブラウザ, md AS モード, cnt AS プレイ数,
       ROUND(AVG(time_ms) / 1000, 1) AS 中央値秒
FROM ranked
WHERE rn IN (FLOOR((cnt + 1) / 2), CEILING((cnt + 1) / 2))
GROUP BY fam, md, cnt
ORDER BY md, fam;

-- ------------------------------------------------------------
-- クエリ3: UAの生文字列を並べる（分類が正しいかの目視確認）
--   見慣れないUAが混ざっていたら、上のCASEに枝を足す
-- ------------------------------------------------------------
SELECT ss.user_agent AS UA, COUNT(*) AS プレイ数,
       COUNT(DISTINCT tr.student_id) AS 生徒数
FROM time_records tr
JOIN study_sessions ss ON ss.session_id = tr.session_id
JOIN students s        ON s.student_id  = tr.student_id
WHERE tr.unit_key = 'math_es_hyakumasu'
  AND ss.user_agent IS NOT NULL
  AND s.student_name NOT LIKE '%テスト%'
GROUP BY ss.user_agent
ORDER BY プレイ数 DESC;

-- ------------------------------------------------------------
-- クエリ4【本番】: 同じ生徒の中でブラウザを比べる
--   2種類以上のブラウザで遊んだ生徒だけを出す＝「速い子が
--   Androidだった」という交絡を消せる。同じ生徒の行を縦に読んで、
--   Android LINE の秒だけ短いかを見る。
-- ------------------------------------------------------------
WITH base AS (
  SELECT
    tr.student_id, s.student_name,
    CASE
      WHEN ss.user_agent LIKE '%Line/%' AND ss.user_agent REGEXP 'iPhone|iPad|iPod' THEN 'iPhone LINE'
      WHEN ss.user_agent REGEXP 'iPhone|iPad|iPod' AND ss.user_agent LIKE '%CriOS%' THEN 'iPhone Chrome'
      WHEN ss.user_agent REGEXP 'iPhone|iPad|iPod'                                  THEN 'iPhone Safari'
      WHEN ss.user_agent LIKE '%Line/%' AND ss.user_agent LIKE '%Android%'          THEN 'Android LINE'
      WHEN ss.user_agent LIKE '%Android%'                                           THEN 'Android Chrome'
      ELSE 'その他/PC'
    END AS fam,
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(tr.meta, '$.mode')), '(不明)') AS md,
    tr.time_ms, tr.miss_count
  FROM time_records tr
  JOIN study_sessions ss ON ss.session_id = tr.session_id
  JOIN students s        ON s.student_id  = tr.student_id
  WHERE tr.unit_key = 'math_es_hyakumasu'
    AND ss.user_agent IS NOT NULL
    AND s.student_name NOT LIKE '%テスト%'
),
per AS (
  SELECT student_id, student_name, md, fam,
         COUNT(*) AS n,
         AVG(time_ms)    AS avg_ms,
         MIN(time_ms)    AS min_ms,
         AVG(miss_count) AS avg_miss
  FROM base
  GROUP BY student_id, student_name, md, fam
),
multi AS (
  SELECT student_id, md
  FROM per
  GROUP BY student_id, md
  HAVING COUNT(DISTINCT fam) >= 2
)
SELECT p.student_name AS 生徒, p.md AS モード, p.fam AS ブラウザ,
       p.n AS プレイ数,
       ROUND(p.avg_ms / 1000, 1) AS 平均秒,
       ROUND(p.min_ms / 1000, 1) AS 最速秒,
       ROUND(p.avg_miss, 2)      AS 平均誤答
FROM per p
JOIN multi m ON m.student_id = p.student_id AND m.md = p.md
ORDER BY p.student_name, p.md, p.fam;

-- ------------------------------------------------------------
-- クエリ5: クエリ4を1行にまとめた「差」
--   同一生徒・同一モードで Android LINE と他ブラウザの両方を
--   持っている組だけを対象に、生徒ごとの平均の差を平均する。
--   マイナス＝Android LINE の方が速い。生徒数が小さいと当てにならない。
-- ------------------------------------------------------------
WITH base AS (
  SELECT
    tr.student_id,
    CASE
      WHEN ss.user_agent LIKE '%Line/%' AND ss.user_agent REGEXP 'iPhone|iPad|iPod' THEN 'iPhone LINE'
      WHEN ss.user_agent REGEXP 'iPhone|iPad|iPod' AND ss.user_agent LIKE '%CriOS%' THEN 'iPhone Chrome'
      WHEN ss.user_agent REGEXP 'iPhone|iPad|iPod'                                  THEN 'iPhone Safari'
      WHEN ss.user_agent LIKE '%Line/%' AND ss.user_agent LIKE '%Android%'          THEN 'Android LINE'
      WHEN ss.user_agent LIKE '%Android%'                                           THEN 'Android Chrome'
      ELSE 'その他/PC'
    END AS fam,
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(tr.meta, '$.mode')), '(不明)') AS md,
    tr.time_ms, tr.miss_count
  FROM time_records tr
  JOIN study_sessions ss ON ss.session_id = tr.session_id
  JOIN students s        ON s.student_id  = tr.student_id
  WHERE tr.unit_key = 'math_es_hyakumasu'
    AND ss.user_agent IS NOT NULL
    AND s.student_name NOT LIKE '%テスト%'
),
per AS (
  SELECT student_id, md, fam, COUNT(*) AS n,
         AVG(time_ms) AS avg_ms, AVG(miss_count) AS avg_miss
  FROM base GROUP BY student_id, md, fam
)
SELECT a.md AS モード, b.fam AS 比較相手,
       COUNT(*)                                  AS 生徒数,
       ROUND(AVG(a.avg_ms - b.avg_ms) / 1000, 1) AS 差の平均秒,
       ROUND(AVG(a.avg_miss - b.avg_miss), 2)    AS 誤答の差
FROM per a
JOIN per b ON b.student_id = a.student_id AND b.md = a.md AND b.fam <> a.fam
WHERE a.fam = 'Android LINE'
GROUP BY a.md, b.fam
ORDER BY a.md, b.fam;
