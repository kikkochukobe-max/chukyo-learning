-- ------------------------------------------------------------
-- 図形の面積マスター（算数・小5 / unit_key = math_es5_menseki）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この9行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（sankaku_gyaku 等）のままになる）。
--
-- question_key は math_es5_menseki.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は
-- 存在しない（カルテが種類別に割れるようにするため）。
-- 「公式の ひみつ」は解説の画面なので出題しない＝行も無い。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es5_menseki', 'sankaku',       '三角形の面積',                 1),
  ('math_es5_menseki', 'sankaku_gyaku', '三角形（面積から高さ・底辺）', 1),
  ('math_es5_menseki', 'heikou',        '平行四辺形の面積',             1),
  ('math_es5_menseki', 'heikou_gyaku',  '平行四辺形（面積から高さ・底辺）', 1),
  ('math_es5_menseki', 'daikei',        '台形の面積',                   1),
  ('math_es5_menseki', 'daikei_gyaku',  '台形（面積から高さ・上底）',   1),
  ('math_es5_menseki', 'hishi',         'ひし形の面積',                 1),
  ('math_es5_menseki', 'hishi_gyaku',   'ひし形（面積から対角線）',     1),
  ('math_es5_menseki', 'kufuu',         '工夫して求める面積',           1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
