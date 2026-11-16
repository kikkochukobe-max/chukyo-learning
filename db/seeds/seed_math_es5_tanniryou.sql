-- ------------------------------------------------------------
-- 単位量あたりマスター（算数・小5 / unit_key = math_es5_tanniryou）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この13行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（iroiro_tanni 等）のままになる）。
--
-- question_key は math_es5_tanniryou.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は
-- 存在しない（カルテが種類別に割れるようにするため）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es5_tanniryou', 'komi',             'こみぐあい',                     1),
  ('math_es5_tanniryou', 'komi_hikaku',      'こみぐあいをくらべる',           1),
  ('math_es5_tanniryou', 'jinko',            '人口密度',                       1),
  ('math_es5_tanniryou', 'jinko_hikaku',     '人口密度でくらべる',             1),
  ('math_es5_tanniryou', 'iroiro_tanni',     '単位量あたり（1あたりを求める）', 1),
  ('math_es5_tanniryou', 'iroiro_zentai',    '単位量あたり（全体を求める）',   1),
  ('math_es5_tanniryou', 'iroiro_ikutsu',    '単位量あたり（いくつ分を求める）', 1),
  ('math_es5_tanniryou', 'iroiro_hikaku',    '1あたりでくらべる',              1),
  ('math_es5_tanniryou', 'hayasa_motomeru',  '速さを求める',                   1),
  ('math_es5_tanniryou', 'hayasa_michinori', '道のりを求める',                 1),
  ('math_es5_tanniryou', 'hayasa_jikan',     '時間を求める',                   1),
  ('math_es5_tanniryou', 'hayasa_hikaku',    '速さをくらべる',                 1),
  ('math_es5_tanniryou', 'hayasa_kansan',    '時速・分速・秒速',               1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
