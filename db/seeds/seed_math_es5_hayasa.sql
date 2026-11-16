-- ------------------------------------------------------------
-- 速さマスター（算数・小5 / unit_key = math_es5_hayasa）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この9行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（hikaku_tanni 等）のままになる）。
--
-- question_key は math_es5_hayasa.html の GENS のキーと一致。
-- 「ランダム」は実際に出た種類を記録するので mix という question_key は
-- 存在しない（カルテが種類別に割れるようにするため）。
-- 単位量あたりマスター(math_es5_tanniryou)の hayasa_* とは別の単元として数える。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es5_hayasa', 'motomeru',     '速さを求める',                 1),
  ('math_es5_hayasa', 'michinori',    '道のりを求める',               1),
  ('math_es5_hayasa', 'jikan',        '時間を求める',                 1),
  ('math_es5_hayasa', 'hikaku',       '速さをくらべる',               1),
  ('math_es5_hayasa', 'hikaku_tanni', '単位がちがう速さをくらべる',   1),
  ('math_es5_hayasa', 'kansan',       '時速・分速・秒速',             1),
  ('math_es5_hayasa', 'soroe',        '単位をそろえて計算する',       1),
  ('math_es5_hayasa', 'jikoku',       '時こくと速さ',                 1),
  ('math_es5_hayasa', 'deai',         '出会い・追いつき',             1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
