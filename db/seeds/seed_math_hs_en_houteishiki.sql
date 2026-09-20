-- ------------------------------------------------------------
-- 円と方程式マスター（数学・高校／数学II 図形と方程式 / unit_key = math_hs_en_houteishiki）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この10行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値（ローマ字）のままになるため必ず登録する）
--
-- question_key は math_hs_en_houteishiki.html のタイプ名（MODES の k）と一致。
-- ミックス出題（chip の data-mode="mix"）はその場でどれかのタイプに割り振られるので、
-- "mix" という question_key は飛んでこない＝カタログにも登録しない。
--
-- ⚠ レベル（1基本 / 2標準）は question_key を増やさず question_params の lv に
--   持たせているので、この台帳はタイプ単位の10行だけ。
--
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_hs_en_houteishiki', 'houteishiki', '① 円の方程式',               1),
  ('math_hs_en_houteishiki', 'ippankei',    '② 円の一般形',               1),
  ('math_hs_en_houteishiki', 'tsukuru',     '③ 条件から円を求める',       1),
  ('math_hs_en_houteishiki', 'chokusen',    '④ 直線と円の位置関係',       1),
  ('math_hs_en_houteishiki', 'kyorigen',    '⑤ 中心との距離・弦の長さ',   1),
  ('math_hs_en_houteishiki', 'sessen_jou',  '⑥ 円上の点における接線',     1),
  ('math_hs_en_houteishiki', 'sessen_gai',  '⑦ 傾き・円外の点からの接線', 1),
  ('math_hs_en_houteishiki', 'nien',        '⑧ 2つの円の位置関係',        1),
  ('math_hs_en_houteishiki', 'nien_kouten', '⑨ 2円の交点・共通弦',        1),
  ('math_hs_en_houteishiki', 'houbutsu',    '⑩ 放物線と円の共有点',       1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
