-- ------------------------------------------------------------
-- 連立方程式マスター 文章題編（数学・中2 / unit_key = math_js2_renritsu_riyou）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を丸ごと流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この21行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので、XP自体は動くが
-- 　ラベルが question_key 生値のままになるため、必ず登録しておく）
--
-- question_key は math_js2_renritsu_riyou.html 内の STEP キー(stepObj().key)と一致。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js2_renritsu_riyou', 'suu1',      '数：和と差',                 1),
  ('math_js2_renritsu_riyou', 'suu2',      '数：◯倍と和・差',           1),
  ('math_js2_renritsu_riyou', 'suu3',      '数：2けたの整数',           1),
  ('math_js2_renritsu_riyou', 'kaimono1',  '個数と代金：個数を求める',   1),
  ('math_js2_renritsu_riyou', 'kaimono2',  '個数と代金：値段を求める',   1),
  ('math_js2_renritsu_riyou', 'kaimono3',  '個数と代金：ひねり',         1),
  ('math_js2_renritsu_riyou', 'kafusoku1', '過不足：余りと不足',         1),
  ('math_js2_renritsu_riyou', 'kafusoku2', '過不足：余り／不足どうし',   1),
  ('math_js2_renritsu_riyou', 'kafusoku3', '過不足：ひねり',             1),
  ('math_js2_renritsu_riyou', 'hayasa1',   '速さ：歩く＋走る',           1),
  ('math_js2_renritsu_riyou', 'hayasa2',   '速さ：出会い・追いつき',     1),
  ('math_js2_renritsu_riyou', 'hayasa3',   '速さ：単位変換',             1),
  ('math_js2_renritsu_riyou', 'tsuka1',    '通過算：鉄橋とトンネル',     1),
  ('math_js2_renritsu_riyou', 'tsuka2',    '通過算：電柱・かくれる時間', 1),
  ('math_js2_renritsu_riyou', 'tsuka3',    '通過算：2台の列車',         1),
  ('math_js2_renritsu_riyou', 'wariai1',   '割合：定価と割引',           1),
  ('math_js2_renritsu_riyou', 'wariai2',   '割合：◯%増・◯%減',        1),
  ('math_js2_renritsu_riyou', 'wariai3',   '割合：値引きして売り切る',   1),
  ('math_js2_renritsu_riyou', 'shokuen1',  '食塩水：混ぜて◯%にする',    1),
  ('math_js2_renritsu_riyou', 'shokuen2',  '食塩水：濃度を求める',       1),
  ('math_js2_renritsu_riyou', 'mix',       '総合ミックス',               1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
