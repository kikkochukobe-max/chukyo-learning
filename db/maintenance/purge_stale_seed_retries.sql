-- ============================================================
-- 解き直し方式を「種(seed)方式」に変えた単元の、**古い形式の pending** を片づける
--   実行: phpMyAdmin の「SQL」タブに ①→②→③ の順で貼って実行
--         （本番へは配信しない。db/ はソース管理のみ）
--
-- なぜ必要か
--   下の4単元は question_params の形を変えた（CLAUDE.md 2d の種方式へ）。
--     math_js2_renritsu_riyou     {ans:[…]}          → {m:STEPキー, s:種}
--     social_js_jisa              {a:{…},b:{…},t:…}  → {p:出題面, m:モード, s:種}
--     math_js1_seihunohugohantei  {text:…,answer:…}  → {n:項の数, s:種}
--     math_es6_mojishiki          {cat:…, type:…}    → {c:しゅるい, s:種}
--   形が変わると params_hash も変わるので、**変更前にたまった pending 行は
--   もう二度と一致しない**＝2連続正解による mastered が永久に成立しない。
--   ツール側の initRetry() もこれらの行を出題対象から外している（出せないため）。
--   放っておくと、生徒のマイページの「解き直し」の件数が
--   **何回解き直しても減らない数字**として残り続ける。これを畳む。
--
--   ⚠ **ツール4本を本番へアップロードしたあとに実行すること**。
--     先に流すと、古いツールがまた同じ形式の pending を作る。
--   ⚠ 学習記録(answer_logs)は一切さわらない。消えるのは「解き直しの宿題」だけで、
--     解答数・正答率・XP・学習時間はそのまま残る。
--   ⚠ mojishiki の旧 {cat,type} は「種類ごとに1行」だったので件数は少ないはず。
--     renritsu / jisa / hugohantei は1問=1行なので、それなりの件数になる。
-- ============================================================


-- ------------------------------------------------------------
-- ① まず何件あるか見る（消す前の確認。ここで0なら何もしなくてよい）
--    「旧形式」= 種方式のキー "s" を持っていない pending 行。
--    JSON_EXTRACT が無い環境でも動くよう、生のJSON文字列で判定している。
--    ⚠ コロンまで含めて '%"s":%' と書かない。MariaDB は JSON を書いたまま
--      保存するが、MySQL のネイティブ JSON 型は {"s": 123} と**コロンのあとに
--      空白を入れて**返すため、どちらでも当たるようキー名だけで見ている。
--      旧形式の params にキー "s" は無いので、これで取り違えない。
-- ------------------------------------------------------------
SELECT rq.unit_key,
       COUNT(*)                        AS 旧形式のpending,
       COUNT(DISTINCT rq.student_id)   AS 生徒数,
       MIN(rq.updated_at)              AS いちばん古い,
       MAX(rq.updated_at)              AS いちばん新しい
FROM retry_queue rq
WHERE rq.status = 'pending'
  AND (
       (rq.unit_key = 'math_js2_renritsu_riyou'    AND rq.question_params NOT LIKE '%"s"%')
    OR (rq.unit_key = 'social_js_jisa'             AND rq.question_params NOT LIKE '%"s"%')
    OR (rq.unit_key = 'math_js1_seihunohugohantei' AND rq.question_params NOT LIKE '%"s"%')
    OR (rq.unit_key = 'math_es6_mojishiki'         AND rq.question_params NOT LIKE '%"s"%')
    OR (rq.unit_key IN ('math_js2_renritsu_riyou','social_js_jisa',
                        'math_js1_seihunohugohantei','math_es6_mojishiki')
        AND rq.question_params IS NULL)
  )
GROUP BY rq.unit_key;


-- ------------------------------------------------------------
-- ② 誰の分がどれだけ減るか（生徒ごと）
--    ①が0でなければ、渡す前にこの一覧を見て「解き直しが減らない」と
--    言っていた生徒が入っているかを確かめる。
-- ------------------------------------------------------------
SELECT s.login_id                        AS 生徒コード,
       s.student_name                    AS 生徒名,
       c.classroom_name                  AS 教室,
       rq.unit_key,
       COUNT(*)                          AS 畳まれる件数
FROM retry_queue rq
JOIN students   s ON s.student_id   = rq.student_id
LEFT JOIN classrooms c ON c.classroom_id = s.classroom_id
WHERE rq.status = 'pending'
  AND rq.unit_key IN ('math_js2_renritsu_riyou','social_js_jisa',
                      'math_js1_seihunohugohantei','math_es6_mojishiki')
  AND (rq.question_params IS NULL OR rq.question_params NOT LIKE '%"s"%')
GROUP BY s.login_id, s.student_name, c.classroom_name, rq.unit_key
ORDER BY COUNT(*) DESC;


-- ------------------------------------------------------------
-- ③ 実行（旧形式の pending を mastered に畳む）
--    DELETE ではなく status を倒すのは、「この問題を過去にまちがえた」という
--    履歴（wrong_count）を残したいため。マイページの件数は pending だけを
--    数えるので、これで数字が正しく減る。
--    ⚠ ツール4本のアップロードが済んでから実行すること。
-- ------------------------------------------------------------
UPDATE retry_queue
SET status = 'mastered'
WHERE status = 'pending'
  AND unit_key IN ('math_js2_renritsu_riyou','social_js_jisa',
                   'math_js1_seihunohugohantei','math_es6_mojishiki')
  AND (question_params IS NULL OR question_params NOT LIKE '%"s"%');


-- ------------------------------------------------------------
-- ④ 実行後の確認（①をもう一度流して 0件 になっていればよい）
-- ------------------------------------------------------------
SELECT unit_key, status, COUNT(*) AS 件数
FROM retry_queue
WHERE unit_key IN ('math_js2_renritsu_riyou','social_js_jisa',
                   'math_js1_seihunohugohantei','math_es6_mojishiki')
GROUP BY unit_key, status
ORDER BY unit_key, status;
