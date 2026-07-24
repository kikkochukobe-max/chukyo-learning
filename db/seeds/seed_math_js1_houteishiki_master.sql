-- ------------------------------------------------------------
-- 方程式マスター（数学・中1 / unit_key = math_js1_houteishiki_master）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を丸ごと流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この10行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので、XP自体は動くが
-- 　ラベルが question_key 生値のままになるため、必ず登録しておく）
--
-- question_key は math_js1_houteishiki_master.html 内のモード(state.mode)と一致。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js1_houteishiki_master', 'kai',         '解（あてはめ）',   1),
  ('math_js1_houteishiki_master', 'toushiki',    '等式の性質',       1),
  ('math_js1_houteishiki_master', 'keisu_nashi', '係数なし',         1),
  ('math_js1_houteishiki_master', 'keisu_ari',   '係数あり',         1),
  ('math_js1_houteishiki_master', 'doruikou',    '同類項あり',       1),
  ('math_js1_houteishiki_master', 'kakko',       'かっこあり',       1),
  ('math_js1_houteishiki_master', 'bunsuu',      '分数',             1),
  ('math_js1_houteishiki_master', 'shousuu',     '小数',             1),
  ('math_js1_houteishiki_master', 'hireishiki',  '比例式',           1),
  ('math_js1_houteishiki_master', 'ouyou',       '応用',             1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
