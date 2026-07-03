-- ============================================================
-- 中京個別指導学院 学習管理システム 完全版スキーマ
-- 2026-07-02 / MySQL (Heteml) / phpMyAdmin でそのまま実行可
-- 16テーブル: 基盤5 + 追跡4 + 保護者2 + 確認テスト2 + XP2 + カタログ1
-- FK依存順に並べてあるので上から一括実行でOK
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. classrooms（教室マスタ：物理8教室）
--    ※ 植田・志段味 はSEO用地名であり物理教室ではないため入れない
--    ※ 表記は「神丘」（「神岡」は誤り）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classrooms (
  classroom_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  classroom_code VARCHAR(20)  NOT NULL,
  classroom_name VARCHAR(50)  NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (classroom_id),
  UNIQUE KEY uq_classroom_code (classroom_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO classrooms (classroom_code, classroom_name) VALUES
  ('yakeyama',  '焼山'),
  ('kikko',     '吉根'),
  ('nagakute',  '長久手'),
  ('kamiokka',  '神丘'),
  ('takabaridai','高針台'),
  ('issha',     '一社'),
  ('kifune',    '貴船'),
  ('arimatsu',  '有松')
ON DUPLICATE KEY UPDATE classroom_name = VALUES(classroom_name);

-- ------------------------------------------------------------
-- 2. teachers（講師・管理者）
--    role: super_admin=統括(全教室) / classroom_admin=教室管理者(生徒登録可)
--          / teacher=講師(閲覧のみ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teachers (
  teacher_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  teacher_name         VARCHAR(50)  NOT NULL,
  role                 ENUM('super_admin','classroom_admin','teacher') NOT NULL DEFAULT 'teacher',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (teacher_id),
  UNIQUE KEY uq_teacher_login (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. teacher_classrooms（講師×教室 多対多：兼任対応）
--    super_admin はここに行が無くても全教室アクセス可（アプリ側で判定）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teacher_classrooms (
  teacher_id   INT UNSIGNED NOT NULL,
  classroom_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (teacher_id, classroom_id),
  CONSTRAINT fk_tc_teacher   FOREIGN KEY (teacher_id)   REFERENCES teachers (teacher_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tc_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms (classroom_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. students（生徒）
--    login_id = 生徒コード / password_hash = 4桁PINのハッシュ
--    created_by = 登録した管理者（監査用）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
  student_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  classroom_id  INT UNSIGNED NOT NULL,
  login_id      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  student_name  VARCHAR(50)  NOT NULL,
  grade         VARCHAR(10)  DEFAULT NULL,          -- 'es4', 'js1' など
  created_by    INT UNSIGNED DEFAULT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  UNIQUE KEY uq_student_login (login_id),
  KEY idx_st_classroom (classroom_id),
  CONSTRAINT fk_st_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms (classroom_id)
    ON UPDATE CASCADE,
  CONSTRAINT fk_st_created_by FOREIGN KEY (created_by) REFERENCES teachers (teacher_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. devices（端末：UUIDクッキー divp_device で識別）
--    label は管理側で後付けする任意の端末名（'焼山iPad-3' など）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devices (
  device_id    CHAR(36)     NOT NULL,               -- UUID
  label        VARCHAR(50)  DEFAULT NULL,
  first_seen_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5b. auth_tokens（自動ログイン用トークン: divp_remember クッキー）
--     クッキーには selector:validator を保存し、DBには validator のsha256のみ置く
--     （DB流出時にもトークンを直接使えないようにするため）。生徒のみ発行、180日有効
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_tokens (
  token_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type     ENUM('student','teacher','guardian') NOT NULL DEFAULT 'student',
  actor_id       INT UNSIGNED NOT NULL,
  selector       CHAR(24)     NOT NULL,
  validator_hash CHAR(64)     NOT NULL,
  device_id      CHAR(36)     DEFAULT NULL,
  expires_at     DATETIME     NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at   DATETIME     DEFAULT NULL,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_at_selector (selector),
  KEY idx_at_actor (actor_type, actor_id),
  CONSTRAINT fk_at_device FOREIGN KEY (device_id) REFERENCES devices (device_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. login_logs（ログイン履歴：生徒/講師/保護者を actor_type で区別）
--    success=0 の行はPIN試行制限（5回失敗で10分ロック）の判定に使う
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_logs (
  login_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type   ENUM('student','teacher','guardian') NOT NULL DEFAULT 'student',
  actor_id     INT UNSIGNED NOT NULL,
  device_id    CHAR(36)     DEFAULT NULL,
  ip           VARCHAR(45)  DEFAULT NULL,
  user_agent   VARCHAR(255) DEFAULT NULL,
  success      TINYINT(1)   NOT NULL DEFAULT 1,
  logged_in_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (login_log_id),
  KEY idx_ll_actor (actor_type, actor_id, logged_in_at),
  KEY idx_ll_device (device_id),
  CONSTRAINT fk_ll_device FOREIGN KEY (device_id) REFERENCES devices (device_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. study_sessions（学習セッション：学習時間の計測単位）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS study_sessions (
  session_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id      INT UNSIGNED NOT NULL,
  unit_key        VARCHAR(64)  NOT NULL,
  started_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at        DATETIME     DEFAULT NULL,
  duration_sec    INT          DEFAULT NULL,        -- ended_at 確定時に算出
  total_questions INT          NOT NULL DEFAULT 0,
  correct_count   INT          NOT NULL DEFAULT 0,
  device_id       CHAR(36)     DEFAULT NULL,
  ip              VARCHAR(45)  DEFAULT NULL,
  user_agent      VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (session_id),
  KEY idx_ss_student_unit (student_id, unit_key),
  KEY idx_ss_started (started_at),
  CONSTRAINT fk_ss_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ss_device FOREIGN KEY (device_id) REFERENCES devices (device_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. answer_logs（解答ログ：1問=1行）
--    question_key = 問題タイプ（種類別集計の軸）
--    student_answer = 生徒が選んだ/入力した答え（誤解答の閲覧用）
--    params_hash = 出題パラメータのハッシュ（同一問題の識別）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS answer_logs (
  answer_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id      BIGINT UNSIGNED DEFAULT NULL,
  student_id      INT UNSIGNED NOT NULL,
  unit_key        VARCHAR(64)  NOT NULL,
  question_key    VARCHAR(128) NOT NULL,
  question_params JSON         DEFAULT NULL,
  params_hash     CHAR(64)     DEFAULT NULL,
  question_text   VARCHAR(255) DEFAULT NULL,
  correct_answer  VARCHAR(100) DEFAULT NULL,
  student_answer  VARCHAR(100) DEFAULT NULL,
  is_correct      TINYINT(1)   NOT NULL,
  retry_of        BIGINT UNSIGNED DEFAULT NULL,     -- 解き直し元の answer_id
  time_taken_sec  INT          DEFAULT NULL,
  answered_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (answer_id),
  KEY idx_al_unit_qkey (unit_key, question_key),
  KEY idx_al_student_unit (student_id, unit_key),
  KEY idx_al_student_params (student_id, params_hash),
  KEY idx_al_session (session_id),
  CONSTRAINT fk_al_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_al_session FOREIGN KEY (session_id) REFERENCES study_sessions (session_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. retry_queue（解き直しキュー：2連続正解でマスター）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retry_queue (
  retry_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  unit_key         VARCHAR(64)  NOT NULL,
  question_key     VARCHAR(128) NOT NULL,
  question_params  JSON         DEFAULT NULL,
  params_hash      CHAR(64)     NOT NULL DEFAULT '',
  wrong_count      INT          NOT NULL DEFAULT 1,
  correct_streak   INT          NOT NULL DEFAULT 0,
  status           ENUM('pending','mastered') NOT NULL DEFAULT 'pending',
  last_answered_at DATETIME     DEFAULT NULL,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (retry_id),
  UNIQUE KEY uq_retry (student_id, unit_key, question_key, params_hash),
  KEY idx_rq_pending (student_id, unit_key, status),
  CONSTRAINT fk_rq_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ここから新規7テーブル（保護者 / 確認テスト / XP / カタログ）
-- ============================================================

-- ------------------------------------------------------------
-- 10. guardians（保護者）※専用ログインのリリースは後日。器だけ先行作成
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS guardians (
  guardian_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  guardian_name VARCHAR(50)  NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (guardian_id),
  UNIQUE KEY uq_guardian_login (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. guardian_students（保護者×生徒 多対多：兄弟対応）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS guardian_students (
  guardian_id INT UNSIGNED NOT NULL,
  student_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (guardian_id, student_id),
  CONSTRAINT fk_gs_guardian FOREIGN KEY (guardian_id) REFERENCES guardians (guardian_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_gs_student  FOREIGN KEY (student_id)  REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. paper_tests（アナログ確認テストのマスタ）
--    unit_key を持たせると「落ちた単元のツール推薦」がJOINで可能
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS paper_tests (
  test_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title      VARCHAR(100) NOT NULL,
  unit_key   VARCHAR(64)  DEFAULT NULL,
  grade      VARCHAR(10)  DEFAULT NULL,
  pass_score INT          DEFAULT NULL,             -- 点数管理しない運用ならNULL
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (test_id),
  KEY idx_pt_unit (unit_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. paper_test_results（受験結果：attempt_no=1が本試、2以降が追試）
--    合格率 = passed の集計 / 追試数 = attempt_no >= 2 の件数
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS paper_test_results (
  result_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  test_id     INT UNSIGNED NOT NULL,
  student_id  INT UNSIGNED NOT NULL,
  attempt_no  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  taken_on    DATE         NOT NULL,
  score       INT          DEFAULT NULL,
  passed      TINYINT(1)   NOT NULL,
  recorded_by INT UNSIGNED DEFAULT NULL,            -- 登録した講師
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (result_id),
  UNIQUE KEY uq_attempt (test_id, student_id, attempt_no),
  KEY idx_ptr_student (student_id, taken_on),
  CONSTRAINT fk_ptr_test    FOREIGN KEY (test_id)    REFERENCES paper_tests (test_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ptr_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ptr_teacher FOREIGN KEY (recorded_by) REFERENCES teachers (teacher_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14. xp_events（XPイベント：指定期間の倍率）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS xp_events (
  event_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title           VARCHAR(100) NOT NULL,
  starts_at       DATETIME     NOT NULL,
  ends_at         DATETIME     NOT NULL,
  multiplier      DECIMAL(3,1) NOT NULL DEFAULT 2.0,
  unit_key_prefix VARCHAR(64)  DEFAULT NULL,        -- 'math_' 等で対象を絞る(NULL=全部)
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  KEY idx_xe_period (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15. xp_logs（XP付与履歴：付与時点の確定値。再計算しない）
--    レベルはカラムに持たず累計XPから式で算出する
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS xp_logs (
  xp_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  amount     INT          NOT NULL,
  reason     VARCHAR(30)  NOT NULL,                 -- 'correct','session','event_bonus' 等
  event_id   INT UNSIGNED DEFAULT NULL,
  answer_id  BIGINT UNSIGNED DEFAULT NULL,          -- 由来の解答（監査用）
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (xp_id),
  KEY idx_xl_student (student_id, created_at),
  CONSTRAINT fk_xl_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_xl_event   FOREIGN KEY (event_id)   REFERENCES xp_events (event_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_xl_answer  FOREIGN KEY (answer_id)  REFERENCES answer_logs (answer_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 16. question_catalog（問題タイプ台帳：ラベル＋難易度XPの一元管理）
--    save_answer.php はここを1回JOINして base_xp × 倍率でXP確定
--    カタログに無い question_key が来たら警告ログを出す運用
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS question_catalog (
  unit_key     VARCHAR(64)  NOT NULL,
  question_key VARCHAR(128) NOT NULL,
  label        VARCHAR(50)  NOT NULL,               -- 保護者・講師画面の日本語名
  base_xp      TINYINT UNSIGNED NOT NULL DEFAULT 10,
  PRIMARY KEY (unit_key, question_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 平方根マスターの初期シード（日曜運用分。当面は難易度を分けず一律XP=1）
-- question_key は math_js3_heihokonmaster.html 内のモードボタンID(switchMode引数)と一致させる
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js3_heihokon', 'truefalse', '正誤問題', 1),
  ('math_js3_heihokon', 'simplify',  '簡略化',   1),
  ('math_js3_heihokon', 'addsub',    '加減',     1),
  ('math_js3_heihokon', 'muldiv',    '乗除',     1),
  ('math_js3_heihokon', 'mixed',     '四則混合', 1),
  ('math_js3_heihokon', 'approx',    '近似値',   1),
  ('math_js3_heihokon', 'intval',    '整数値',   1),
  ('math_js3_heihokon', 'subst',     '代入',     1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);

-- ============================================================
-- 完了。導入順:
--   1) この schema_full.sql を phpMyAdmin で実行
--   2) setup_first_admin.php で統括管理者を作成（実行後に削除）
--   3) register_teacher.php / register_student.php でアカウント発行
-- ============================================================
