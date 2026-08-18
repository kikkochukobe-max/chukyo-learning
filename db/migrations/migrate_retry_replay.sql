-- ============================================================
-- retry_queue に「その問題をそのまま再表示するためのデータ」列を追加する
-- （?retry=1 の同一問題再出題を、生成関数の復元なしで成立させるため）
--
-- 背景: 平方根マスターのような単元は question_params（例 {n:72}）から
--   生成関数が同じ問題を作り直せるが、愛知県公立入試 大問1 マーク演習は
--   生成関数が20種類以上あり、どれも params を受け取って復元する作りではない。
--   同一問題に当たらないと params_hash が一致せず「2連続正解でmastered」が
--   永久に成立しない＝解き直しリストが減らない、という詰みになっていた。
--   → 誤答のときだけ「画面に出した問題そのもの(JSON)」を保存して、
--     再出題は復元ではなく再表示で済ませる。
--
-- 置き場所を retry_queue にする理由: answer_logs は1解答=1行なので、
--   図(SVG)入りのJSONを毎回入れると容量が膨らむ。retry_queue は
--   「まちがえた問題1つ=1行」なので、同じ問題を何回まちがえても1行で済む。
--   （question_figure/question_choices が answer_logs 側にあるのは
--     講師の解き直しプリント用。用途が別なのでそのまま残す）
--
-- 注意: 本番Hetemlの MySQL は「ADD COLUMN IF NOT EXISTS」に対応しない。
--   このSQLは冪等ではない（再実行すると Duplicate column name になる）。
-- ============================================================

ALTER TABLE retry_queue
  ADD COLUMN replay_json JSON NULL AFTER question_params;

-- 既存の pending 行は replay_json が NULL のまま = 再出題できない。
-- その問題をもう一度まちがえた時点で save_answer.php が埋める
-- （NULL のときだけ入れるので、後追いで自然に埋まっていく）。
