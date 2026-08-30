-- ============================================================
-- 自習記録テーブル（生徒が自分で「何を自習したか」を書き残す）
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
--
-- 設計方針:
--  ・ツールの実績(answer_logs / study_sessions / time_records)とは完全に別枠。
--    自己申告なので XP は付けず、集計にも混ぜない（水増しできるため）。
--  ・1件 = 1教科ぶんの自習（日付・教科・教材・範囲・時間・手ごたえ・メモ）。
--    生徒は複数教科をまとめて入力するが、保存は教科ごとに1行に分ける
--    （講師の確認印とコメントを教科ごとに押せる粒度を保つため）。
--  ・study_type で「覚える勉強／忘れない勉強」を区別する
--    （このテーブルを後から拡張した列。db/migrations/migrate_self_study_type.sql）。
--  ・講師の「確認印」とコメントを同じ行に持つ（checked_at が入ったら確認済み）。
--    確認済みの記録は生徒側から編集・削除できない（api 側で弾く）。
-- ※MySQL は ALTER の "IF NOT EXISTS" を受け付けないが、CREATE TABLE IF NOT EXISTS は可。
-- ============================================================

CREATE TABLE IF NOT EXISTS self_study_logs (
  log_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id      INT UNSIGNED NOT NULL,
  study_date      DATE         NOT NULL,                 -- 自習した日（生徒が選ぶ。未来日は不可）
  subject         VARCHAR(16)  NOT NULL,                 -- math/english/science/japanese/social/other
  study_type      VARCHAR(16)  DEFAULT NULL,             -- memorize=覚える勉強 / retain=忘れない勉強
  retain_span     VARCHAR(8)   DEFAULT NULL,             -- retain のとき short=短期(1週間以内) / long=長期(1か月以内)
  material        VARCHAR(100) NOT NULL,                 -- 教材名（自由記述。過去の入力を候補に出す）
  range_text      VARCHAR(100) DEFAULT NULL,             -- 範囲（例: p.24-27 / 大問1〜3）
  minutes         SMALLINT UNSIGNED DEFAULT NULL,        -- 学習時間(分)。自己申告。0〜600
  feeling         TINYINT UNSIGNED DEFAULT NULL,         -- 手ごたえ 1(むずかしい)〜5(かんぺき)
  memo            VARCHAR(500) DEFAULT NULL,             -- ひとことメモ・質問
  teacher_id      INT UNSIGNED DEFAULT NULL,             -- 確認した講師
  checked_at      DATETIME     DEFAULT NULL,             -- NULL = 未確認
  teacher_comment VARCHAR(500) DEFAULT NULL,             -- 講師からのひとこと（生徒のマイページに出る）
  device_id       CHAR(36)     DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_ssl_student (student_id, study_date, log_id),
  KEY idx_ssl_unchecked (student_id, checked_at),
  KEY idx_ssl_date (study_date),
  CONSTRAINT fk_ssl_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ssl_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (teacher_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
