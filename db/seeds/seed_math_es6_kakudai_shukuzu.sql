-- ------------------------------------------------------------
-- 拡大図と縮図マスター（算数・小6 / unit_key = math_es6_kakudai_shukuzu）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この9行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（shuku_jissai 等）のままになる）。
--
-- question_key は math_es6_kakudai_shukuzu.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は
-- 存在しない（カルテが種類別に割れるようにするため）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es6_kakudai_shukuzu', 'mitsukeru',      '拡大図・縮図を見つける',             1),
  ('math_es6_kakudai_shukuzu', 'nanbai',         '何倍の拡大図・何分の1の縮図か',      1),
  ('math_es6_kakudai_shukuzu', 'hen',            '対応する辺の長さ',                   1),
  ('math_es6_kakudai_shukuzu', 'kaku',           '対応する角の大きさ',                 1),
  ('math_es6_kakudai_shukuzu', 'chushin',        '1つの点を中心にした拡大図・縮図',    1),
  ('math_es6_kakudai_shukuzu', 'shuku_jissai',   '縮尺（実際の長さを求める）',         1),
  ('math_es6_kakudai_shukuzu', 'shuku_chizu',    '縮尺（地図上の長さを求める）',       1),
  ('math_es6_kakudai_shukuzu', 'shuku_motomeru', '縮尺を求める',                       1),
  ('math_es6_kakudai_shukuzu', 'riyou',          '縮図の利用（高さ・きょり）',         1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
