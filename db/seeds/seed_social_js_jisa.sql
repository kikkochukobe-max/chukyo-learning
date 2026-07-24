-- ------------------------------------------------------------
-- 時差計算練習（社会・中学 / unit_key = social_js_jisa）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この6行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は social_js_jisa.html の logJisaAnswer() が組み立てる
-- 「出題面(basic/applied/test) + '_' + 種類(jisa/jikoku)」と一致：
--   basic_jisa      … 基本篇・時差を求める（15°刻み）
--   basic_jikoku    … 基本篇・時刻を求める（15°刻み）
--   applied_jisa    … 応用篇・時差を求める（15°刻み以外も含む）
--   applied_jikoku  … 応用篇・時刻を求める（15°刻み以外も含む）
--   test_jisa       … 確認テスト・時差を求める
--   test_jikoku     … 確認テスト・時刻を求める
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('social_js_jisa', 'basic_jisa',     '基本・時差を求める',   1),
  ('social_js_jisa', 'basic_jikoku',   '基本・時刻を求める',   1),
  ('social_js_jisa', 'applied_jisa',   '応用・時差を求める',   1),
  ('social_js_jisa', 'applied_jikoku', '応用・時刻を求める',   1),
  ('social_js_jisa', 'test_jisa',      '確認テスト・時差',     1),
  ('social_js_jisa', 'test_jikoku',    '確認テスト・時刻',     1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
