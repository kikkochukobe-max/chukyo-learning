-- ------------------------------------------------------------
-- 円周角マスター（数学・中3 / unit_key = math_js3_enshuukaku）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この6行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（chuushin 等）のままになる）。
--
-- question_key は math_js3_enshuukaku.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は存在しない。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js3_enshuukaku', 'chuushin', '円周角と中心角',             1),
  ('math_js3_enshuukaku', 'onaji',    '同じ弧に対する円周角',       1),
  ('math_js3_enshuukaku', 'chokkei',  '直径と円周角',               1),
  ('math_js3_enshuukaku', 'ko',       '弧と円周角',                 1),
  ('math_js3_enshuukaku', 'gyaku',    '円周角の定理の逆',           1),
  ('math_js3_enshuukaku', 'oyou',     '円周角の応用（チャレンジ）', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
