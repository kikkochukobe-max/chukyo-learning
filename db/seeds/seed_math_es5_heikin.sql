-- ------------------------------------------------------------
-- 平均マスター（算数・小5 / unit_key = math_es5_heikin）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この7行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（motomeru_shou 等）のままになる）。
--
-- question_key は math_es5_heikin.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は
-- 存在しない（カルテが種類別に割れるようにするため）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es5_heikin', 'motomeru_sei',  '平均を求める（わりきれる）',     1),
  ('math_es5_heikin', 'motomeru_shou', '平均を求める（小数になる）',     1),
  ('math_es5_heikin', 'zero',          '0をふくむ平均',                  1),
  ('math_es5_heikin', 'goukei',        '平均から合計・個数を求める',     1),
  ('math_es5_heikin', 'nokori',        'わからない1つを求める',          1),
  ('math_es5_heikin', 'hohaba',        '歩はば',                         1),
  ('math_es5_heikin', 'zentai',        '全体の平均（人数がちがう）',     1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
