-- ------------------------------------------------------------
-- 漸化式マスター（数学・高校／数学B 漸化式 / unit_key = math_hs_zenkashiki）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この10行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値（ローマ字）のままになるため必ず登録する）
--
-- question_key は math_hs_zenkashiki.html のタイプ名（MODES の k）と一致。
-- ミックス出題（chip の data-mode="mix"）はその場でどれかのタイプに割り振られるので、
-- "mix" という question_key は飛んでこない＝カタログにも登録しない。
--
-- ⚠ レベル（1基本 / 2標準）は question_key を増やさず question_params の lv に
--   持たせているので、この台帳はタイプ単位の10行だけ。
--
-- ⚠ 数列完全マスター(math_hs_suuretsu)にも "zenka"（漸化式）という question_key が
--   あるが別単元の別ラベル。混ぜないこと（カルテは unit_key ごとに読む）。
--
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_hs_zenkashiki', 'tousa',    '二項間① 等差型',         1),
  ('math_hs_zenkashiki', 'touhi',    '二項間② 等比型',         1),
  ('math_hs_zenkashiki', 'kaisa',    '二項間③ 階差型',         1),
  ('math_hs_zenkashiki', 'tokusei',  '二項間④ 特性方程式',     1),
  ('math_hs_zenkashiki', 'shisuu',   '二項間⑤ 指数',           1),
  ('math_hs_zenkashiki', 'gyakusuu', '二項間⑥ 逆数',           1),
  ('math_hs_zenkashiki', 'slide',    '二項間⑦ 関数スライド',   1),
  ('math_hs_zenkashiki', 'kaihi',    '二項間⑧ 階比',           1),
  ('math_hs_zenkashiki', 'taisuu',   '二項間⑨ 対数',           1),
  ('math_hs_zenkashiki', 'sankou',   '三項間① 基本（=0の形）', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
