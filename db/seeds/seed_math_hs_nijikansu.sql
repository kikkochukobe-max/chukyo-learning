-- ------------------------------------------------------------
-- 2次関数マスター（数学・高校／数学I 2次関数 / unit_key = math_hs_nijikansu）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この11行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値（ローマ字）のままになるため必ず登録する）
--
-- question_key は math_hs_nijikansu.html のタイプ名（MODES の k）と一致。
-- ミックス出題（chip の data-mode="mix"）はその場でどれかのタイプに割り振られるので、
-- "mix" という question_key は飛んでこない＝カタログにも登録しない。
--
-- ⚠ レベル（1基本 / 2標準 / 3発展）は question_key を増やさず question_params の lv に
--   持たせているので、この台帳はタイプ単位の11行だけ。
--   ⚠ タイプによってレベルの数が違う（発展を持たないタイプは2つまで）。
--
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_hs_nijikansu', 'kansuu', '① 関数の値と値域',           1),
  ('math_hs_nijikansu', 'heihei', '② 平方完成と頂点',           1),
  ('math_hs_nijikansu', 'heikou', '③ 放物線の平行移動',         1),
  ('math_hs_nijikansu', 'taisho', '④ 放物線の対称移動',         1),
  ('math_hs_nijikansu', 'saidai', '⑤ 最大値・最小値',           1),
  ('math_hs_nijikansu', 'ugoku',  '⑥ 動く最大・最小（場合分け）', 1),
  ('math_hs_nijikansu', 'kettei', '⑦ 2次関数の決定',            1),
  ('math_hs_nijikansu', 'keisu',  '⑧ 最大値から係数決定',       1),
  ('math_hs_nijikansu', 'bunsho', '⑨ 最大・最小の文章題',       1),
  ('math_hs_nijikansu', 'zettai', '⑩ 絶対値を含む1次関数のグラフ', 1),
  ('math_hs_nijikansu', 'joken',  '⑪ 条件式がある場合の最大・最小', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
