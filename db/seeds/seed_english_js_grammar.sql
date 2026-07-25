-- ------------------------------------------------------------
-- 英文法練習（中1〜中3 / unit_key = english_js_grammar）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を丸ごと流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この2行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので、XP自体は動くが
-- 　ラベルが question_key 生値のままになるため、必ず登録しておく）
--
-- question_key は english_js_grammar_app.html 内のモード名と一致。
--   fill … 空所補充（answer入力）
--   sort … 並べ替え（タイル）
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('english_js_grammar', 'fill', '空所補充', 1),
  ('english_js_grammar', 'sort', '並べ替え', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
