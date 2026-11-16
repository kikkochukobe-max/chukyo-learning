-- ============================================================
-- 私立高校 過去問の記録（生徒が入力 → 講師が進捗を見る）
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
--
-- 画面: 生徒 /kakomon.php（マイページの「私立高校 過去問の記録」から）
--       講師 /kakomon_teacher.php（生徒全体の一覧／?student_id= で個人）
--
-- 設計方針:
--  ・kakomon_schools = 生徒が「買った（持っている）過去問」の高校。1生徒×1高校=1行。
--    高校は志望校マスター target_schools（私立）から選ぶ＝講師画面で同じ学校が同じ名前で並ぶ。
--    マスターに無い学校だけ school_name に手入力の名前を入れる（target_school_id は NULL）。
--    マスターから選んだ行も school_name にその時点の名前を入れておく
--    （表示はマスターの名前が優先＝改名に追従。マスターから消えても名前が残る）。
--  ・kakomon_logs = 過去問を1回解いた記録。1行 = 高校×年度×科目×周目（1〜4周目）。
--    同じ回を2回入れないよう UNIQUE を張る（なおすときは UPDATE）。
--    得点率 rate は必須（0〜100の整数）。点数で入れたときは score / max_score も残し、
--    rate はサーバーが計算する。
--  ・kakomon_pace = 生徒が自分で選ぶ「全体の進み具合」（早い・普通・遅い）。1生徒1行。
--    同じものを押し直しても updated_at は更新する（「この日の時点で普通」の確認になる）。
--  ・学習ツールの実績(answer_logs / study_sessions)とは別枠。XPも付けない（自己申告のため）。
-- ※MySQL は ALTER の "IF NOT EXISTS" を受け付けないが、CREATE TABLE IF NOT EXISTS は可。
-- ※target_schools は migrate_target_schools.sql で作成済みであること（志望校機能）。
-- ============================================================

CREATE TABLE IF NOT EXISTS kakomon_schools (
  kschool_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  target_school_id INT UNSIGNED DEFAULT NULL,            -- 志望校マスター（私立）から選んだとき
  school_name      VARCHAR(80)  NOT NULL,                -- 手入力の名前／マスターから選んだ時点の名前
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (kschool_id),
  KEY idx_ks_student (student_id, kschool_id),
  CONSTRAINT fk_ks_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ks_target FOREIGN KEY (target_school_id) REFERENCES target_schools (target_school_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kakomon_logs (
  log_id      BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  student_id  INT UNSIGNED      NOT NULL,
  kschool_id  INT UNSIGNED      NOT NULL,
  exam_year   SMALLINT UNSIGNED NOT NULL,                -- 年度（西暦）
  subject     VARCHAR(16)       NOT NULL,                -- japanese/math/english/science/social
  lap         TINYINT UNSIGNED  NOT NULL,                -- 何周目か 1〜4
  score       SMALLINT UNSIGNED DEFAULT NULL,            -- 点数（わかるときだけ）
  max_score   SMALLINT UNSIGNED DEFAULT NULL,            -- 満点（score と組で入る）
  rate        TINYINT UNSIGNED  NOT NULL,                -- 得点率(%) 0〜100
  good_text   VARCHAR(500)      DEFAULT NULL,            -- できたところ
  bad_text    VARCHAR(500)      DEFAULT NULL,            -- できなかったところ
  device_id   CHAR(36)          DEFAULT NULL,
  created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  UNIQUE KEY uq_kl_attempt (kschool_id, exam_year, subject, lap),
  KEY idx_kl_student (student_id, created_at),
  CONSTRAINT fk_kl_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_kl_school FOREIGN KEY (kschool_id) REFERENCES kakomon_schools (kschool_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kakomon_pace (
  student_id INT UNSIGNED NOT NULL,
  pace       VARCHAR(8)   NOT NULL,                      -- fast=早い / normal=普通 / slow=遅い
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  CONSTRAINT fk_kp_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
