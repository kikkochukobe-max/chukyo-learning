-- ------------------------------------------------------------
-- 計算どぅする？（算数・小6 / unit_key = math_es6_keisan_dousuru）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を丸ごと流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この15行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので、XP自体は動くが
-- 　ラベルが question_key 生値のままになるため、必ず登録しておく）
--
-- question_key は math_es6_keisan_dousuru.html 内の TYPES[].id と一致。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es6_keisan_dousuru', 'misalign',   '小数の＋−（位ずれ）',   1),
  ('math_es6_keisan_dousuru', 'complement', '補数でくふう（＋−）',   1),
  ('math_es6_keisan_dousuru', 'decmult',    '小数のかけ算',         1),
  ('math_es6_keisan_dousuru', 'decdiv',     '小数のわり算',         1),
  ('math_es6_keisan_dousuru', 'approxdiv',  'がい数のわり算',       1),
  ('math_es6_keisan_dousuru', 'mul25',      '25・125のくふう',      1),
  ('math_es6_keisan_dousuru', 'kufu2',      '小数・分数のくふう',   1),
  ('math_es6_keisan_dousuru', 'fracas',     '分数の＋−（通分）',     1),
  ('math_es6_keisan_dousuru', 'fracmd',     '分数の×÷',            1),
  ('math_es6_keisan_dousuru', 'fracmix',    '分数の四則混合',       1),
  ('math_es6_keisan_dousuru', 'ratio',      '割合・単位量',         1),
  ('math_es6_keisan_dousuru', 'unit',       '単位の変換',           1),
  ('math_es6_keisan_dousuru', 'speed',      '速さの文章題',         1),
  ('math_es6_keisan_dousuru', 'geometry',   '図形',                 1),
  ('math_es6_keisan_dousuru', 'word',       '文章題',               1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
