-- ------------------------------------------------------------
-- 2次不等式マスター（数学・高校／数学I 2次不等式 / unit_key = math_hs_niji_futoushiki）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この11行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値（ローマ字）のままになるため必ず登録する）
--
-- question_key は math_hs_niji_futoushiki.html のタイプ名（MODES の k）と一致。
-- ミックス出題（chip の data-mode="mix"）はその場でどれかのタイプに割り振られるので、
-- "mix" という question_key は飛んでこない＝カタログにも登録しない。
--
-- ⚠ レベル（1基本 / 2標準 / 3発展）は question_key を増やさず question_params の lv に
--   持たせているので、この台帳はタイプ単位の11行だけ。
--   ⚠ タイプによってレベルの数が違う（⑨文章題・⑩絶対値は2つまで）。
--
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_hs_niji_futoushiki', 'inshi',    '① 因数分解で解く',         1),
  ('math_hs_niji_futoushiki', 'koushiki', '② 解の公式で解く',         1),
  ('math_hs_niji_futoushiki', 'tokushu',  '③ D≦0 の不等式',           1),
  ('math_hs_niji_futoushiki', 'renritsu', '④ 連立不等式',             1),
  ('math_hs_niji_futoushiki', 'kettei',   '⑤ 解から係数決定',         1),
  ('math_hs_niji_futoushiki', 'hanbetsu', '⑥ 判別式と実数解',         1),
  ('math_hs_niji_futoushiki', 'tsuneni',  '⑦ つねに成り立つ不等式',   1),
  ('math_hs_niji_futoushiki', 'haichi',   '⑧ 解の配置',               1),
  ('math_hs_niji_futoushiki', 'bunsho',   '⑨ 2次不等式の文章題',      1),
  ('math_hs_niji_futoushiki', 'zettai',   '⑩ 絶対値を含む不等式',     1),
  ('math_hs_niji_futoushiki', 'moji',     '⑪ 文字係数・整数解',       1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
