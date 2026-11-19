-- ------------------------------------------------------------
-- 線対称・点対称マスター（算数・小6 / unit_key = math_es6_taisho_zukei）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この9行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（sen_hantei 等）のままになる）。
--
-- question_key は math_es6_taisho_zukei.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は
-- 存在しない（カルテが種類別に割れるようにするため）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es6_taisho_zukei', 'sen_hantei',    '線対称な図形・対称の軸を見つける',     1),
  ('math_es6_taisho_zukei', 'sen_taiou',     '線対称（対応する点・辺・角）',         1),
  ('math_es6_taisho_zukei', 'sen_seishitsu', '線対称（辺の長さ・角・軸までの長さ）', 1),
  ('math_es6_taisho_zukei', 'sen_kaku',      '線対称な図形をかく（方眼）',           1),
  ('math_es6_taisho_zukei', 'ten_hantei',    '点対称な図形・対称の中心を見つける',   1),
  ('math_es6_taisho_zukei', 'ten_taiou',     '点対称（対応する点・辺・角）',         1),
  ('math_es6_taisho_zukei', 'ten_seishitsu', '点対称（辺の長さ・角・中心までの長さ）', 1),
  ('math_es6_taisho_zukei', 'ten_kaku',      '点対称な図形をかく（方眼）',           1),
  ('math_es6_taisho_zukei', 'takakkei',      '多角形と対称（軸の本数など）',         1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
