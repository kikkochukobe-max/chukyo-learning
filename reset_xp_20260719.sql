-- ============================================================
-- XPリセット（xp_dynamic_spec.md §5）— 1回だけ実行する初期化作業
-- 実施予定日: 2026-07-19（新ルール稼働と同日に実施）
-- 過去の付与ミスがあるため、新ルール稼働前に全生徒のXPをゼロに戻す。
--
-- 実行前提:
--   ・migrate_xp_dynamic.sql（スキーマ変更）を先に適用しておくこと
--   ・phpMyAdmin で1回だけ実行する
--   ・answer_logs / study_sessions は触らない（学習記録・統計は無傷で残す）
--   ・レベルは累計XPから都度計算する設計なので、自動的に全員レベル1に戻る
-- ============================================================

-- 調査用のDB内コピー（Heteml自動バックアップとは別。落ち着いたら DROP してよい）
CREATE TABLE xp_logs_backup_20260719   AS SELECT * FROM xp_logs;
CREATE TABLE xp_events_backup_20260719 AS SELECT * FROM xp_events;

-- リセット本体。
-- xp_events は xp_logs から FK 参照されているため、そのままだと
-- TRUNCATE が 1701（Cannot truncate a table referenced in a foreign key）で失敗する。
-- 一時的に FK チェックを外して両テーブルを空にする。
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE xp_logs;
TRUNCATE TABLE xp_events;
SET FOREIGN_KEY_CHECKS = 1;

-- students に累計XPキャッシュ列は無い（レベルは累計から計算する設計）ため
-- UPDATE students SET total_xp = 0; は不要。
