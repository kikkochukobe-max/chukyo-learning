-- ------------------------------------------------------------
-- とけいマスター（算数・小3 / unit_key = math_es3_tokei）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この6行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので、XP自体は動くが
-- 　ラベルが question_key 生値のままになるため、必ず登録しておく）
--
-- question_key は math_es3_tokei.html 内のモード変数(mode)と一致。
-- toy（おもちゃ）モードは記録対象外なので登録しない。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es3_tokei', 'qa',     '何分たった？',           1),
  ('math_es3_tokei', 'qb',     '何時何分？（○分後）',    1),
  ('math_es3_tokei', 'bunkai', 'ちょうどで分けて計算',   1),
  ('math_es3_tokei', 'gyaku',  '出発の時こく（逆算）',   1),
  ('math_es3_tokei', 'master', 'とけいなしマスター',     1),
  ('math_es3_tokei', 'free',   'ミッション（ゴールまで動かす）', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
