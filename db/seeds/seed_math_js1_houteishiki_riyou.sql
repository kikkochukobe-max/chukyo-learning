-- ------------------------------------------------------------
-- 方程式マスター 文章題編（数学・中1 / unit_key = math_js1_houteishiki_riyou）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この20行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
-- 　カルテのラベルが question_key の生値＝ローマ字のままになるため必ず登録する）
--
-- question_key は math_js1_houteishiki_riyou.html 内の STEP キー(stepObj().key)と一致。
-- 総合ミックスで解いた問題だけ question_key が 'mix' になる（問題そのものの種類は
-- question_params._type に入る）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js1_houteishiki_riyou', 'suu1',      '数：ある数',                   1),
  ('math_js1_houteishiki_riyou', 'suu2',      '数：連続する整数',             1),
  ('math_js1_houteishiki_riyou', 'suu3',      '数：2けたの整数',              1),
  ('math_js1_houteishiki_riyou', 'kaimono1',  '個数と代金：合わせて◯個',      1),
  ('math_js1_houteishiki_riyou', 'kaimono2',  '個数と代金：おつり・所持金',    1),
  ('math_js1_houteishiki_riyou', 'kaimono3',  '個数と代金：ひねり',            1),
  ('math_js1_houteishiki_riyou', 'kafusoku1', '過不足：余りと不足',            1),
  ('math_js1_houteishiki_riyou', 'kafusoku2', '過不足：余り／不足どうし',      1),
  ('math_js1_houteishiki_riyou', 'kafusoku3', '過不足：長いす・人数の増減',    1),
  ('math_js1_houteishiki_riyou', 'hayasa1',   '速さ：追いつく',                1),
  ('math_js1_houteishiki_riyou', 'hayasa2',   '速さ：出会う',                  1),
  ('math_js1_houteishiki_riyou', 'hayasa3',   '速さ：往復（分数の式）',        1),
  ('math_js1_houteishiki_riyou', 'nenrei1',   '年齢と平均：年齢',              1),
  ('math_js1_houteishiki_riyou', 'nenrei2',   '年齢と平均：平均',              1),
  ('math_js1_houteishiki_riyou', 'wariai1',   '割合：定価と割引',              1),
  ('math_js1_houteishiki_riyou', 'wariai2',   '割合：◯%増・◯%減',            1),
  ('math_js1_houteishiki_riyou', 'wariai3',   '割合：食塩水',                  1),
  ('math_js1_houteishiki_riyou', 'hirei1',    '比例式：比例式で解く',          1),
  ('math_js1_houteishiki_riyou', 'hirei2',    '比例式：比で分ける',            1),
  ('math_js1_houteishiki_riyou', 'mix',       '総合ミックス',                  1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
