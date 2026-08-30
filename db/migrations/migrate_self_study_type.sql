-- ============================================================
-- 自習記録に「覚える勉強 / 忘れない勉強」の区別を足す
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
--
--  study_type  = 'memorize' 覚える勉強   … 最近習ったこと・思い出したばかりの復習
--                'retain'   忘れない勉強 … 前に正解した問題の再確認
--  retain_span = 'short' 短期 … 1週間以内に正解した問題の再確認
--                'long'  長期 … 1か月以内に正解した問題の再確認
--                （'retain' のときだけ入る。'memorize' の行は常に NULL）
--
-- 既存の行は両方 NULL のまま＝この機能より前に書かれた「区別なし」の記録。
-- 画面ではバッジを出さないだけで、記録そのものはこれまでどおり残る。
-- ※MySQL は ALTER の "IF NOT EXISTS" を受け付けないので通常構文で書く。
--   2回流すと #1060(Duplicate column name) になる＝すでに適用済みのサイン。
-- ============================================================

ALTER TABLE self_study_logs
  ADD COLUMN study_type  VARCHAR(16) DEFAULT NULL AFTER subject,
  ADD COLUMN retain_span VARCHAR(8)  DEFAULT NULL AFTER study_type;
