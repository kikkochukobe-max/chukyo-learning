-- ------------------------------------------------------------
-- 世界の諸地域マスター（社会・中1／地理 世界の諸地域 / unit_key = social_js1_sekai_chiri）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この6行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値（ローマ字）のままになるため必ず登録する）
--
-- question_key は social_js1_sekai_chiri.html の MODES のキー（州）と一致。
-- 章（第8〜17章）・節は question_key を増やさず、問題IDの接頭辞（c8_〜c17_）で持つ
-- ＝カルテは州単位で読める。ミックス出題もその問題の州に割り振られて飛ぶので
-- "mix" という question_key は飛んでこない＝カタログにも登録しない。
--
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('social_js1_sekai_chiri', 'asia',     'アジア州',     1),
  ('social_js1_sekai_chiri', 'europe',   'ヨーロッパ州', 1),
  ('social_js1_sekai_chiri', 'africa',   'アフリカ州',   1),
  ('social_js1_sekai_chiri', 'namerica', '北アメリカ州', 1),
  ('social_js1_sekai_chiri', 'samerica', '南アメリカ州', 1),
  ('social_js1_sekai_chiri', 'oceania',  'オセアニア州', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
