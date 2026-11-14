-- ------------------------------------------------------------
-- 座標・比例・反比例マスター（数学・中1 / unit_key = math_js1_hirei_hanpirei）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この17行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（hirei_shiki 等）のままになる）。
--
-- question_key は math_js1_hirei_hanpirei.html の GENS のキーと一致。
-- 「ランダム」「座標のランダム」「比例のランダム」「反比例のランダム」は実際に出た種類を記録するので
-- mix・zahyou・hirei・hanpi という question_key は存在しない（カルテが種類別に割れるように）。
-- 小6の比例と反比例マスター（math_es6_hirei_hanpirei）とは unit_key が別なので、
-- 同じ question_key（hirei_hyou など）があっても混ざらない。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js1_hirei_hanpirei', 'zahyou_yomu',    '座標を読む・点をとる',           1),
  ('math_js1_hirei_hanpirei', 'zahyou_idou',    '点の移動・対称な点',             1),
  ('math_js1_hirei_hanpirei', 'zahyou_menseki', '座標と三角形の面積',             1),
  ('math_js1_hirei_hanpirei', 'hirei_shiki',    '比例の式と比例定数',             1),
  ('math_js1_hirei_hanpirei', 'hirei_hyou',     '比例の表',                       1),
  ('math_js1_hirei_hanpirei', 'hirei_atai',     '比例（xやyの値を求める）',       1),
  ('math_js1_hirei_hanpirei', 'hirei_graph',    '比例のグラフ',                   1),
  ('math_js1_hirei_hanpirei', 'hirei_heniki',   '比例の変域',                     1),
  ('math_js1_hirei_hanpirei', 'riyou_hirei',    '比例の利用',                     1),
  ('math_js1_hirei_hanpirei', 'hirei_heniki_hatten', '比例の変域の応用（発展）',  1),
  ('math_js1_hirei_hanpirei', 'hirei_zukei',    '比例のグラフと図形（発展）',   1),
  ('math_js1_hirei_hanpirei', 'hanpi_shiki',    '反比例の式と比例定数',           1),
  ('math_js1_hirei_hanpirei', 'hanpi_hyou',     '反比例の表',                     1),
  ('math_js1_hirei_hanpirei', 'hanpi_atai',     '反比例（xやyの値を求める）',     1),
  ('math_js1_hirei_hanpirei', 'hanpi_graph',    '反比例のグラフ',                 1),
  ('math_js1_hirei_hanpirei', 'riyou_hanpi',    '反比例の利用',                   1),
  ('math_js1_hirei_hanpirei', 'miwake',         '比例・反比例を見分ける',         1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
