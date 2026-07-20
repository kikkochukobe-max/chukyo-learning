-- ------------------------------------------------------------
-- 立体マスター（表面積・体積）（数学・中1 / unit_key = math_js1_hyomenseki_taiseki）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を丸ごと流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この49行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので XP自体は動くが、
-- 　ラベルが question_key の生値のままになるため、必ず登録しておく）
--
-- question_key は math_js1_hyomenseki_taiseki.html 内の各ジェネレータが返す
-- q.key と一致（vol_/surf_/kai_/kumi_/kis_ の5系統・全49種）。
-- base_xp は当面すべて1で統一（難易度は分けない運用）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  -- 体積（学ぶモードの基本立体）
  ('math_js1_hyomenseki_taiseki', 'vol_cube',       '体積:立方体',                 1),
  ('math_js1_hyomenseki_taiseki', 'vol_cuboid',     '体積:直方体',                 1),
  ('math_js1_hyomenseki_taiseki', 'vol_triprism',   '体積:三角柱',                 1),
  ('math_js1_hyomenseki_taiseki', 'vol_cyl',        '体積:円柱',                   1),
  ('math_js1_hyomenseki_taiseki', 'vol_pyr',        '体積:正四角錐',               1),
  ('math_js1_hyomenseki_taiseki', 'vol_cone',       '体積:円錐',                   1),
  ('math_js1_hyomenseki_taiseki', 'vol_sphere',     '体積:球',                     1),
  -- 表面積
  ('math_js1_hyomenseki_taiseki', 'surf_cube',      '表面積:立方体',               1),
  ('math_js1_hyomenseki_taiseki', 'surf_cuboid',    '表面積:直方体',               1),
  ('math_js1_hyomenseki_taiseki', 'surf_triprism',  '表面積:三角柱',               1),
  ('math_js1_hyomenseki_taiseki', 'surf_cyl',       '表面積:円柱',                 1),
  ('math_js1_hyomenseki_taiseki', 'surf_pyr',       '表面積:正四角錐',             1),
  ('math_js1_hyomenseki_taiseki', 'surf_cone',      '表面積:円錐',                 1),
  ('math_js1_hyomenseki_taiseki', 'surf_sphere',    '表面積:球',                   1),
  -- 回転体
  ('math_js1_hyomenseki_taiseki', 'kai_cyl_v',      '回転体:円柱・体積',           1),
  ('math_js1_hyomenseki_taiseki', 'kai_cyl_s',      '回転体:円柱・表面積',         1),
  ('math_js1_hyomenseki_taiseki', 'kai_cone_v',     '回転体:円錐・体積',           1),
  ('math_js1_hyomenseki_taiseki', 'kai_cone_s',     '回転体:円錐・表面積',         1),
  ('math_js1_hyomenseki_taiseki', 'kai_sphere_v',   '回転体:球・体積',             1),
  ('math_js1_hyomenseki_taiseki', 'kai_sphere_s',   '回転体:球・表面積',           1),
  ('math_js1_hyomenseki_taiseki', 'kai_pipe_v',     '回転体:パイプ形・体積',       1),
  ('math_js1_hyomenseki_taiseki', 'kai_pipe_s',     '回転体:パイプ形・表面積',     1),
  ('math_js1_hyomenseki_taiseki', 'kai_step_v',     '回転体:2段円柱・体積',        1),
  ('math_js1_hyomenseki_taiseki', 'kai_step_s',     '回転体:2段円柱・表面積(工夫)', 1),
  ('math_js1_hyomenseki_taiseki', 'kai_conecyl_v',  '回転体:円柱+円錐・体積',      1),
  ('math_js1_hyomenseki_taiseki', 'kai_bicone_v',   '回転体:円錐2つ・体積',        1),
  ('math_js1_hyomenseki_taiseki', 'kai_bicone_s',   '回転体:円錐2つ・表面積',      1),
  ('math_js1_hyomenseki_taiseki', 'kai_hemicyl_v',  '回転体:円柱+半球・体積',      1),
  ('math_js1_hyomenseki_taiseki', 'kai_hemicyl_s',  '回転体:円柱+半球・表面積',    1),
  ('math_js1_hyomenseki_taiseki', 'kai_hemicone_v', '回転体:円錐+半球・体積',      1),
  ('math_js1_hyomenseki_taiseki', 'kai_hemicone_s', '回転体:円錐+半球・表面積',    1),
  -- 切り抜き・組み合わせ（練習モード「切り抜き」）
  ('math_js1_hyomenseki_taiseki', 'kumi_lprism',    '切り抜き:L字柱・体積',        1),
  ('math_js1_hyomenseki_taiseki', 'kumi_tower_s',   '切り抜き:直方体2段・表面積(工夫)', 1),
  ('math_js1_hyomenseki_taiseki', 'kumi_cyl2_v',    '切り抜き:円柱2段・体積',      1),
  ('math_js1_hyomenseki_taiseki', 'kumi_cyl2_s',    '切り抜き:円柱2段・表面積(工夫)', 1),
  ('math_js1_hyomenseki_taiseki', 'kumi_hemicyl_v', '切り抜き:円柱+半球・体積',    1),
  ('math_js1_hyomenseki_taiseki', 'kumi_conecyl_v', '切り抜き:円柱+円錐・体積',    1),
  ('math_js1_hyomenseki_taiseki', 'kumi_cubecut_p', '切り抜き:立方体の角・三角錐', 1),
  ('math_js1_hyomenseki_taiseki', 'kumi_cubecut_r', '切り抜き:立方体の角切り・残り', 1),
  ('math_js1_hyomenseki_taiseki', 'kumi_cube4cut_r','切り抜き:立方体4隅切り・正四面体', 1),
  ('math_js1_hyomenseki_taiseki', 'kumi_octa_v',    '切り抜き:面の中心・正八面体', 1),
  ('math_js1_hyomenseki_taiseki', 'kumi_hole_v',    '切り抜き:貫通穴・体積',       1),
  ('math_js1_hyomenseki_taiseki', 'kumi_hollow_v',  '切り抜き:内部空洞・体積',     1),
  -- 発展・軌跡（転がし）
  ('math_js1_hyomenseki_taiseki', 'kis_tri_len',    '軌跡:正三角形の転がし・長さ', 1),
  ('math_js1_hyomenseki_taiseki', 'kis_sq_area',    '軌跡:正方形の転がし・面積',   1),
  ('math_js1_hyomenseki_taiseki', 'kis_cline_len',  '軌跡:円の転がし・中心の軌跡', 1),
  ('math_js1_hyomenseki_taiseki', 'kis_cout_len',   '軌跡:長方形の外側1周・長さ',  1),
  ('math_js1_hyomenseki_taiseki', 'kis_cout_area',  '軌跡:長方形の外側1周・通過面積', 1),
  ('math_js1_hyomenseki_taiseki', 'kis_cin_len',    '軌跡:長方形の内側1周・長さ',  1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
