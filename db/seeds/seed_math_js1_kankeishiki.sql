-- ------------------------------------------------------------
-- 関係を表す式マスター（数学・中1 / unit_key = math_js1_kankeishiki）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この12行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（kin_eq 等）のままになる）。
--
-- question_key は math_js1_kankeishiki.html の「カテゴリ_式の種類」。
-- カテゴリ = CATS のキー（kin/hei/hay/zu/ki/wari）、
-- 式の種類 = 生成関数の rel（eq=等式 / ineq=不等式）。
-- 「ランダム」「すべて」で出題しても実際に出た組み合わせを記録するので、
-- random / all という question_key は存在しない（カルテが種類別に割れるようにするため）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js1_kankeishiki', 'kin_eq',    '個数・金額・重さ（等式）',   1),
  ('math_js1_kankeishiki', 'kin_ineq',  '個数・金額・重さ（不等式）', 1),
  ('math_js1_kankeishiki', 'hei_eq',    '平均（等式）',               1),
  ('math_js1_kankeishiki', 'hei_ineq',  '平均（不等式）',             1),
  ('math_js1_kankeishiki', 'hay_eq',    '速さ（等式）',               1),
  ('math_js1_kankeishiki', 'hay_ineq',  '速さ（不等式）',             1),
  ('math_js1_kankeishiki', 'zu_eq',     '図形（等式）',               1),
  ('math_js1_kankeishiki', 'zu_ineq',   '図形（不等式）',             1),
  ('math_js1_kankeishiki', 'ki_eq',     '規則性（等式）',             1),
  ('math_js1_kankeishiki', 'ki_ineq',   '規則性（不等式）',           1),
  ('math_js1_kankeishiki', 'wari_eq',   '割合（等式）',               1),
  ('math_js1_kankeishiki', 'wari_ineq', '割合（不等式）',             1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
