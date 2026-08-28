-- ------------------------------------------------------------
-- 二次方程式マスター 文章題編（数学・中3 / unit_key = math_js3_nijihoteishiki_riyou）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この15行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので、XP自体は動くが
-- 　ラベルが question_key 生値のままになるため、必ず登録しておく）
--
-- question_key は math_js3_nijihoteishiki_riyou.html 内の STEP キー(GENSのキー)と一致。
-- 計算編の math_js3_nijihoteishiki とは別 unit_key（同じ二次方程式でも
-- 「計算」と「文章題」で進捗を分けて見たいため）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js3_nijihoteishiki_riyou', 'suu1',   '数：連続する2つの整数',       1),
  ('math_js3_nijihoteishiki_riyou', 'suu2',   '数：3つの整数・和と積',       1),
  ('math_js3_nijihoteishiki_riyou', 'suu3',   '数：ある数(ひねり)',          1),
  ('math_js3_nijihoteishiki_riyou', 'men1',   '面積：長方形の縦と横',        1),
  ('math_js3_nijihoteishiki_riyou', 'men2',   '面積：道の幅',                1),
  ('math_js3_nijihoteishiki_riyou', 'men3',   '面積：のばす・切り取る',      1),
  ('math_js3_nijihoteishiki_riyou', 'dou1',   '動点：長方形の上の点',        1),
  ('math_js3_nijihoteishiki_riyou', 'dou2',   '動点：直角三角形の上の点',    1),
  ('math_js3_nijihoteishiki_riyou', 'kansu1', '1次関数：直線上の点と三角形',  1),
  ('math_js3_nijihoteishiki_riyou', 'kansu2', '1次関数：2直線と三角形',       1),
  ('math_js3_nijihoteishiki_riyou', 'kansu3', '1次関数：はさまれた長方形',    1),
  ('math_js3_nijihoteishiki_riyou', 'kakaku', '応用：値上げと売り上げ',      1),
  ('math_js3_nijihoteishiki_riyou', 'takasa', '応用：ボールの高さ',          1),
  ('math_js3_nijihoteishiki_riyou', 'seido',  '応用：制動距離・停止距離',    1),
  ('math_js3_nijihoteishiki_riyou', 'mix',    '総合ミックス',                1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
