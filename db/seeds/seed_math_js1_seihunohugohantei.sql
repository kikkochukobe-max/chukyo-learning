-- ------------------------------------------------------------
-- 符号判定クイズ（数学・中1 / unit_key = math_js1_seihunohugohantei）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者・マイページのラベル表示のために、この3行だけ phpMyAdmin で実行する。
-- （未登録だと単元カルテに question_key の生値 condition / terms2 / terms3 が出る）
--
-- question_key は math_js1_seihunohugohantei.html の出題種別と一致：
--   condition = 大小条件問題（genCond。a>0>b 等の条件付き。約25%）
--   terms2    = 通常問題で項が2個（'terms' + termCount, termCount=2）
--   terms3    = 通常問題で項が3個（termCount=3）
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js1_seihunohugohantei', 'condition', '大小条件つき',   1),
  ('math_js1_seihunohugohantei', 'terms2',    '2項の符号',      1),
  ('math_js1_seihunohugohantei', 'terms3',    '3項の符号',      1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
