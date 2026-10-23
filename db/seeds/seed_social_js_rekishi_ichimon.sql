-- ------------------------------------------------------------
-- 歴史一問一答マスター（社会・中学／歴史 古代〜日露戦争 / unit_key = social_js_rekishi_ichimon）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この6行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値（ローマ字）のままになるため必ず登録する）
--
-- question_key は social_js_rekishi_ichimon.html の MODES のキー（時代）と一致。
--   genshi  = 文明と日本のあけぼの（第1〜3章）
--   kodai   = 律令国家と貴族（第4〜6章）
--   chusei  = 武士の世（第7〜11章）
--   kinsei  = 天下統一と江戸幕府（第12〜17章）
--   kindai1 = 市民革命と開国（第18〜20章）
--   kindai2 = 明治の日本（第21〜25章）
-- 章・節は question_key を増やさず、問題IDの接頭辞（r1_〜r25_）で持つ
-- ＝カルテは時代単位で読める。ミックス出題もその問題の時代に割り振られて飛ぶので
-- "mix" という question_key は飛んでこない＝カタログにも登録しない。
--
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('social_js_rekishi_ichimon', 'genshi',  '文明と日本のあけぼの', 1),
  ('social_js_rekishi_ichimon', 'kodai',   '律令国家と貴族',       1),
  ('social_js_rekishi_ichimon', 'chusei',  '武士の世',             1),
  ('social_js_rekishi_ichimon', 'kinsei',  '天下統一と江戸幕府',   1),
  ('social_js_rekishi_ichimon', 'kindai1', '市民革命と開国',       1),
  ('social_js_rekishi_ichimon', 'kindai2', '明治の日本',           1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
