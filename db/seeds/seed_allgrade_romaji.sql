-- ------------------------------------------------------------
-- ローマ字マスター（全学年 / unit_key = allgrade_romaji）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を丸ごと流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この10行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので、XP自体は動くが
-- 　ラベルが question_key 生値のままになるため、必ず登録しておく）
--
-- question_key は romaji_master.html 内の各語の cat と一致。
-- ローマ字モード(WORDS)は cat を持たず "hebon" にフォールバックする。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('allgrade_romaji', 'hebon',      'ローマ字（ヘボン式）', 1),
  ('allgrade_romaji', 'gojuon',     '五十音（ローマ字）',   1),
  ('allgrade_romaji', 'eng_num',    '英単語・数字',         1),
  ('allgrade_romaji', 'eng_day',    '英単語・曜日',         1),
  ('allgrade_romaji', 'eng_month',  '英単語・月',           1),
  ('allgrade_romaji', 'eng_family', '英単語・家族',         1),
  ('allgrade_romaji', 'eng_verb',   '英単語・動作',         1),
  ('allgrade_romaji', 'eng_thing',  '英単語・身近なもの',   1),
  ('allgrade_romaji', 'eng_color',  '英単語・色',           1),
  ('allgrade_romaji', 'eng_adj',    '英単語・気持ち',       1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
