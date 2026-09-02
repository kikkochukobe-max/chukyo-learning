-- ------------------------------------------------------------
-- 数列完全マスター（数学・高校／数学B 数列 / unit_key = math_hs_suuretsu）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この11行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値（ローマ字）のままになるため必ず登録する）
--
-- question_key は math_hs_suuretsu.html のタイプ名（MODES の k）と一致。
-- ミックス出題（chip の data-mode="mix"）はその場でどれかのタイプに割り振られるので、
-- "mix" という question_key は飛んでこない＝カタログにも登録しない。
--
-- ⚠ レベル（1基本 / 2標準 / 3発展）は question_key を増やさず
--   question_params の lv に持たせているので、この台帳はタイプ単位の11行だけ。
--   カルテを「等差数列(基本)」「等差数列(発展)」まで割りたくなったら、
--   ツール側の question_key を変える必要がある（既存 pending の params_hash は
--   そのままなので、解き直しリストは壊れない）。
--
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_hs_suuretsu', 'touhi',   '等差数列（一般項）',         1),
  ('math_hs_suuretsu', 'touhiwa', '等差数列の和',               1),
  ('math_hs_suuretsu', 'hi',      '等比数列（一般項）',         1),
  ('math_hs_suuretsu', 'hiwa',    '等比数列の和',               1),
  ('math_hs_suuretsu', 'sigma',   'Σと和の公式',                1),
  ('math_hs_suuretsu', 'kaisa',   '階差数列',                   1),
  ('math_hs_suuretsu', 'sntoan',  '和Snと一般項',               1),
  ('math_hs_suuretsu', 'bubun',   '部分分数分解の和',           1),
  ('math_hs_suuretsu', 'gun',     '群数列',                     1),
  ('math_hs_suuretsu', 'zenka',   '漸化式',                     1),
  ('math_hs_suuretsu', 'kinou',   '数学的帰納法',               1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
