-- ------------------------------------------------------------
-- 都道府県・県庁所在地マスター（社会・小4 / unit_key = social_es4_todofuken）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この3行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は social_es4_todofuken.html 内の step.key と一致：
--   pref_from_map     … 地図で光った都道府県名を6択（地図あり）
--   capital_from_pref … 都道府県名 → 県庁所在地を6択
--   pref_from_capital … 県庁所在地 → 都道府県名を6択（実力チェック）
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('social_es4_todofuken', 'pref_from_map',     '地図から都道府県名',       1),
  ('social_es4_todofuken', 'capital_from_pref', '都道府県名→県庁所在地',    1),
  ('social_es4_todofuken', 'pref_from_capital', '県庁所在地→都道府県名',    1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
