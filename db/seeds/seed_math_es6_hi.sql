-- ------------------------------------------------------------
-- 比マスター（算数・小6 / unit_key = math_es6_hi）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この9行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（kantan_shou 等）のままになる）。
--
-- question_key は math_es6_hi.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は
-- 存在しない（カルテが種類別に割れるようにするため）。
-- 「比って なに？」はテープ図の解説画面なので出題しない＝行も無い。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es6_hi', 'atai',           '比の値',                       1),
  ('math_es6_hi', 'hitoshii',       '等しい比（□を求める）',        1),
  ('math_es6_hi', 'kantan_sei',     '比を簡単にする（整数）',       1),
  ('math_es6_hi', 'kantan_shou',    '比を簡単にする（小数）',       1),
  ('math_es6_hi', 'kantan_bun',     '比を簡単にする（分数）',       1),
  ('math_es6_hi', 'tsukuru_tani',   '比を作る（単位をそろえる）',   1),
  ('math_es6_hi', 'tsukuru_zentai', '比を作る（全体と部分）',       1),
  ('math_es6_hi', 'riyou_ippou',    '比の利用（一方を求める）',     1),
  ('math_es6_hi', 'riyou_bunkatsu', '比の利用（全体を比で分ける）', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
