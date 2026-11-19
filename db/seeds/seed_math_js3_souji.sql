-- ------------------------------------------------------------
-- 相似マスター（数学・中3 / unit_key = math_js3_souji）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この7行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（jouken 等）のままになる）。
--
-- question_key は math_js3_souji.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は存在しない。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js3_souji', 'hi',      '相似な図形と相似比',         1),
  ('math_js3_souji', 'jouken',  '三角形の相似条件',           1),
  ('math_js3_souji', 'nagasa',  '相似を使って長さを求める',   1),
  ('math_js3_souji', 'heikou',  '平行線と線分の比',           1),
  ('math_js3_souji', 'chuuten', '中点連結定理',               1),
  ('math_js3_souji', 'menseki', '面積比と体積比',             1),
  ('math_js3_souji', 'oyou',    '相似の応用（チャレンジ）',   1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
