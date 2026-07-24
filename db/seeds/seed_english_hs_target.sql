-- ------------------------------------------------------------
-- TARGET 1900+（英語・高校 / unit_key = english_hs_target）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この2行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XPを付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は english_hs_target.html の safeDivpAnswer() が渡す値と一致：
--   challenge … 1分間チャレンジ（英単語→意味の4択・スピード）
--   quiz      … 確認テスト（英単語→意味の4択・No.範囲指定）
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('english_hs_target', 'challenge', '1分間チャレンジ',   1),
  ('english_hs_target', 'quiz',      '確認テスト（4択）', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
