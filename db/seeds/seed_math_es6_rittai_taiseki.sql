-- ------------------------------------------------------------
-- 立体の体積マスター（算数・小6 / unit_key = math_es6_rittai_taiseki）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この12行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（menseki_sankaku 等）のままになる）。
--
-- question_key は math_es6_rittai_taiseki.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は
-- 存在しない（カルテが種類別に割れるようにするため）。
-- 「なぜ 高さを かけたら 体積に なるの？」は解説の画面なので出題しない＝行も無い。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es6_rittai_taiseki', 'menseki_sankaku', '面積の復習（三角形）',           1),
  ('math_es6_rittai_taiseki', 'menseki_chouhou', '面積の復習（長方形）',           1),
  ('math_es6_rittai_taiseki', 'menseki_seihou',  '面積の復習（正方形）',           1),
  ('math_es6_rittai_taiseki', 'menseki_heikou',  '面積の復習（平行四辺形）',       1),
  ('math_es6_rittai_taiseki', 'menseki_hishi',   '面積の復習（ひし形）',           1),
  ('math_es6_rittai_taiseki', 'menseki_daikei',  '面積の復習（台形）',             1),
  ('math_es6_rittai_taiseki', 'menseki_en',      '面積の復習（円）',               1),
  ('math_es6_rittai_taiseki', 'kakuchu',         '角柱の体積',                     1),
  ('math_es6_rittai_taiseki', 'kakuchu_gyaku',   '角柱（体積から高さ・底面積）',   1),
  ('math_es6_rittai_taiseki', 'enchu',           '円柱の体積',                     1),
  ('math_es6_rittai_taiseki', 'enchu_gyaku',     '円柱（体積から高さ・底面積）',   1),
  ('math_es6_rittai_taiseki', 'kufuu',           '工夫して求める体積',             1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
