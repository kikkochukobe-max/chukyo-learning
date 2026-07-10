-- ------------------------------------------------------------
-- 文字式の計算マスター（数学・中1 / unit_key = math_js1_mojishiki_keisan）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を丸ごと流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この8行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので、XP自体は動くが
-- 　ラベルが question_key 生値のままになるため、必ず登録しておく）
--
-- question_key は math_js1_mojishiki_keisan.html 内のカテゴリ(state.cat)と一致。
-- 難易度 easy/normal/hard はモードではなく question_params 側に入る。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js1_mojishiki_keisan', 'term',       '項えらび',           1),
  ('math_js1_mojishiki_keisan', 'coef',       '係数',               1),
  ('math_js1_mojishiki_keisan', 'linear',     '1次式えらび',        1),
  ('math_js1_mojishiki_keisan', 'douruikou',  '同類項をまとめる',   1),
  ('math_js1_mojishiki_keisan', 'kagen',      '加減（かっこ外し）', 1),
  ('math_js1_mojishiki_keisan', 'kakejowari', '×÷（数）',           1),
  ('math_js1_mojishiki_keisan', 'bunpai',     '分配法則・かっこ',   1),
  ('math_js1_mojishiki_keisan', 'mix',        '四則ミックス',       1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
