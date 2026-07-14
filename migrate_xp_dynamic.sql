-- ============================================================
-- 動的XPシステム スキーマ変更（xp_dynamic_spec.md §1）
-- MariaDB 10.11。phpMyAdmin で1回実行する。
-- 列/INDEX は IF NOT EXISTS 付きなので再実行しても安全。
-- ============================================================

-- ------------------------------------------------------------
-- question_catalog: 動的XP単価と全期間統計を保持する列を追加
--   current_xp   … 日次バッチで変動する現在のXP単価。NULLなら base_xp を使う
--   stat_total   … 全期間の回答数（日次バッチで更新。統計は全回答をカウント）
--   stat_correct … 全期間の正解数
--   stat_updated_at … 最後に統計を更新した時刻
-- ------------------------------------------------------------
ALTER TABLE question_catalog
  ADD COLUMN IF NOT EXISTS current_xp      INT      NULL     AFTER base_xp,
  ADD COLUMN IF NOT EXISTS stat_total      INT      NOT NULL DEFAULT 0 AFTER current_xp,
  ADD COLUMN IF NOT EXISTS stat_correct    INT      NOT NULL DEFAULT 0 AFTER stat_total,
  ADD COLUMN IF NOT EXISTS stat_updated_at DATETIME NULL     AFTER stat_correct;

-- ------------------------------------------------------------
-- xp_logs: 日次減衰カウント（同一生徒×同一問題×当日）に必要な
--   unit_key / question_key を記録する（spec §3）。
--   減衰は「今日その問題で何回XPを得たか」を xp_logs の COUNT で数えるため、
--   この2列と当日範囲のインデックスが必須。
-- ------------------------------------------------------------
ALTER TABLE xp_logs
  ADD COLUMN IF NOT EXISTS unit_key     VARCHAR(64)  NULL AFTER reason,
  ADD COLUMN IF NOT EXISTS question_key VARCHAR(128) NULL AFTER unit_key,
  ADD INDEX IF NOT EXISTS idx_xl_decay (student_id, unit_key, question_key, created_at);
