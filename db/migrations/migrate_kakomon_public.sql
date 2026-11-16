-- ============================================================
-- 公立高校 過去問の記録（愛知県公立高校入試。生徒が入力 → 講師が進捗を見る）
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。
-- 私立のテーブル（migrate_kakomon.sql）とは別。どちらを先に流してもよい。
--
-- 画面: 生徒 /kakomon.php の「公立高校」タブ、講師 /kakomon_teacher.php の「公立」タブと個人ページ。
--
-- 設計方針:
--  ・学力検査は県内共通なので高校は持たない。1行 = 年度×（A/Bグループ）×科目×周目（1〜4周目）。
--  ・exam_group: 2022年度まではAグループ・Bグループの2回だったので 'A' / 'B'。
--    2023年度からは1回なので ''（空文字。NULL だと UNIQUE が効かないため空文字で持つ）。
--    境目の年度は api/kakomon_common.php の KAKOMON_PUBLIC_AB_LAST_YEAR。
--  ・score は各教科22点満点の点数（0〜22）。得点率は表示のときに計算する（列は持たない）。
--  ・kakomon_public_schools = 受ける公立高校（2校まで）。志望校マスター target_schools（公立）から選び、
--    無い学校だけ手入力（私立の kakomon_schools と同じ形）。記録（kakomon_public_logs）とはひもづけない
--    ＝問題が県内共通なので、同じ問題を高校ごとに二重に書かせない。
--  ・kakomon_public_pace = 公立の過去問の「全体の進み具合」（早い・普通・遅い）。1生徒1行。
-- ※何度流してもよい（CREATE TABLE IF NOT EXISTS。できている表は飛ばす）。
--  ・XPは付けない（自己申告のため。私立と同じ）。
-- ============================================================

CREATE TABLE IF NOT EXISTS kakomon_public_logs (
  log_id      BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  student_id  INT UNSIGNED      NOT NULL,
  exam_year   SMALLINT UNSIGNED NOT NULL,                -- 年度（西暦）
  exam_group  CHAR(1)           NOT NULL DEFAULT '',     -- 'A' / 'B'（2022年度まで）/ ''（2023年度から）
  subject     VARCHAR(16)       NOT NULL,                -- japanese/math/english/science/social
  lap         TINYINT UNSIGNED  NOT NULL,                -- 何周目か 1〜4
  score       TINYINT UNSIGNED  NOT NULL,                -- 点数 0〜22（22点満点）
  good_text   VARCHAR(500)      DEFAULT NULL,            -- できたところ
  bad_text    VARCHAR(500)      DEFAULT NULL,            -- できなかったところ
  device_id   CHAR(36)          DEFAULT NULL,
  created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  UNIQUE KEY uq_kpl_attempt (student_id, exam_year, exam_group, subject, lap),
  KEY idx_kpl_student (student_id, created_at),
  CONSTRAINT fk_kpl_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kakomon_public_schools (
  pschool_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  target_school_id INT UNSIGNED DEFAULT NULL,            -- 志望校マスター（公立）から選んだとき
  school_name      VARCHAR(80)  NOT NULL,                -- 手入力の名前／マスターから選んだ時点の名前
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (pschool_id),
  KEY idx_kps_student (student_id, pschool_id),
  CONSTRAINT fk_kps_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_kps_target FOREIGN KEY (target_school_id) REFERENCES target_schools (target_school_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kakomon_public_pace (
  student_id INT UNSIGNED NOT NULL,
  pace       VARCHAR(8)   NOT NULL,                      -- fast=早い / normal=普通 / slow=遅い
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  CONSTRAINT fk_kpp_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
