-- ------------------------------------------------------------
-- 倍数・約数マスター（算数・小5 / unit_key = math_es5_baisu_yakusu）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この7行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（guusuu 等）のままになる）。
--
-- question_key は math_es5_baisu_yakusu.html の GENS のキーと一致。
-- ⑧ミックスは「実際に出たサブモード」を記録するので mix という
-- question_key は存在しない（カルテが種類別に割れるようにするため）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es5_baisu_yakusu', 'guusuu',      '偶数と奇数',           1),
  ('math_es5_baisu_yakusu', 'koubai2',     '公倍数（2つの数）',     1),
  ('math_es5_baisu_yakusu', 'koubai3',     '公倍数（3つの数）',     1),
  ('math_es5_baisu_yakusu', 'koubai_bun',  '公倍数の文章題',       1),
  ('math_es5_baisu_yakusu', 'yakusu',      '約数の見つけ方',       1),
  ('math_es5_baisu_yakusu', 'kouyaku',     '公約数',               1),
  ('math_es5_baisu_yakusu', 'kouyaku_bun', '公約数の文章題',       1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
