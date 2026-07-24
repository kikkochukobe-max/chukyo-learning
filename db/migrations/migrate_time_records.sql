-- ============================================================
-- タイムアタック記録テーブル（100マス計算 など「速さを競う」ツール用）
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
--
-- 設計方針: 1問ごとの解答(answer_logs)は残さず、1プレイ=1行の「クリアタイム」だけを
--   ここに記録する。種類別集計(answer_logs)やXPを汚さず、
--   自己ベスト更新の判定と「じぶんの記録トップ10」だけに使う。
-- ※MySQL は ALTER の "IF NOT EXISTS" を受け付けないが、CREATE TABLE IF NOT EXISTS は可。
-- ============================================================

CREATE TABLE IF NOT EXISTS time_records (
  record_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id   INT UNSIGNED NOT NULL,
  unit_key     VARCHAR(64)  NOT NULL,
  question_key VARCHAR(128) NOT NULL DEFAULT 'default',   -- 種目・モード違いを分けたい時の軸
  time_ms      INT UNSIGNED NOT NULL,                      -- クリアタイム(ミリ秒)。小さいほど速い
  miss_count   INT UNSIGNED NOT NULL DEFAULT 0,
  meta         JSON         DEFAULT NULL,                  -- 表示タイプ等の付随情報
  session_id   BIGINT UNSIGNED DEFAULT NULL,
  device_id    CHAR(36)     DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (record_id),
  KEY idx_tr_best (student_id, unit_key, question_key, time_ms),
  KEY idx_tr_unit (unit_key, question_key, time_ms),
  CONSTRAINT fk_tr_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tr_session FOREIGN KEY (session_id) REFERENCES study_sessions (session_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
