-- ============================================================
-- 常識バトル（小学校高学年向け 6択クイズの一斉対戦）のテーブル
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
-- そのあと問題を db/seeds/seed_joshiki_battle_lv1.sql 〜 lv4.sql の順に流す。
--
-- 流れ: 講師が部屋を作る（難易度・問題数）→ 生徒が4桁の部屋番号で入る →
--       講師がスタート → 全員が同じ問題を同じ時刻に解く → 合計点で順位。
-- 方針:
--  * 正解は画面に送らずサーバーで採点する（ほかの学習ツールと違い、点数を競うため）。
--  * 時刻はすべてサーバー(PHP)の時計で決める。進行は start_ms から計算で決まるので、
--    講師の画面を閉じても対戦は止まらない。
--  * 学習記録（answer_logs / XP / 解き直し / 学習時間）には一切書かない（バトルだけで完結）。
-- ※ MySQL は ALTER の "IF NOT EXISTS" を受け付けないが、CREATE TABLE IF NOT EXISTS は可。
-- ============================================================

-- 問題バンク。原本は db/seeds/joshiki_battle/*.json（ここを手で直さず、JSONを直して seed を作り直す）
CREATE TABLE IF NOT EXISTS battle_questions (
  question_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  src_key       VARCHAR(32)  NOT NULL,            -- 原本の問題ID 'kotoba_lv1_001'。seed を流し直すと同じ行を上書きする
  level         TINYINT UNSIGNED NOT NULL,        -- 1=易しい 2=普通 3=難しい 4=超難
  category      VARCHAR(16)  NOT NULL,            -- kotoba / kanji / sansu / ikimono / chikyu / chiri / rekishi / kurashi / eigo / seikatsu
  question_text VARCHAR(500) NOT NULL,
  answer        VARCHAR(100) NOT NULL,            -- 正解（画面の並びは部屋ごとに混ぜる）
  wrong1        VARCHAR(100) NOT NULL,
  wrong2        VARCHAR(100) NOT NULL,
  wrong3        VARCHAR(100) NOT NULL,
  wrong4        VARCHAR(100) NOT NULL,
  wrong5        VARCHAR(100) NOT NULL,
  explanation   VARCHAR(500) NOT NULL DEFAULT '',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 にすると出題されない（まちがいが見つかった問題を止める）
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (question_id),
  UNIQUE KEY uq_bq_src (src_key),
  KEY idx_bq_level (level, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 部屋（1回の対戦）
CREATE TABLE IF NOT EXISTS battle_rooms (
  room_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_code       CHAR(4)      NOT NULL,          -- 生徒が入力する部屋番号（待合室・対戦中の部屋の中では重ならない）
  host_teacher_id INT UNSIGNED DEFAULT NULL,      -- 作った講師（講師を削除しても対戦の記録は残す＝SET NULL）
  level           TINYINT UNSIGNED NOT NULL,
  question_count  TINYINT UNSIGNED NOT NULL,      -- 10〜100（5問きざみ）
  time_limit_sec  TINYINT UNSIGNED NOT NULL,      -- 1問の制限時間。作成時に確定させる（定数を変えても進行中の部屋はずれない）
  reveal_sec      TINYINT UNSIGNED NOT NULL,      -- 締め切りから次の問題までの「正解発表」の時間
  status          ENUM('lobby','playing','finished','cancelled') NOT NULL DEFAULT 'lobby',
  start_ms        BIGINT UNSIGNED DEFAULT NULL,   -- 1問目を出す時刻（UNIXミリ秒・PHPの時計）。以後の進行はここから計算で決まる
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at      DATETIME     DEFAULT NULL,
  finished_at     DATETIME     DEFAULT NULL,
  PRIMARY KEY (room_id),
  KEY idx_br_code (room_code, status),
  KEY idx_br_host (host_teacher_id, created_at),
  CONSTRAINT fk_br_host FOREIGN KEY (host_teacher_id) REFERENCES teachers (teacher_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 部屋の出題（全員が同じ問題を同じ並びで解く）
CREATE TABLE IF NOT EXISTS battle_room_questions (
  room_id      INT UNSIGNED NOT NULL,
  seq          TINYINT UNSIGNED NOT NULL,         -- 0始まりの出題順
  question_id  INT UNSIGNED NOT NULL,
  choice_order CHAR(6)      NOT NULL,             -- 画面の並び。'0'=正解 '1'〜'5'=wrong1〜5（'305142' なら正解は左から2番目）
  PRIMARY KEY (room_id, seq),
  KEY idx_brq_question (question_id),
  CONSTRAINT fk_brq_room FOREIGN KEY (room_id) REFERENCES battle_rooms (room_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_brq_question FOREIGN KEY (question_id) REFERENCES battle_questions (question_id)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 参加者
CREATE TABLE IF NOT EXISTS battle_players (
  room_id      INT UNSIGNED NOT NULL,
  student_id   INT UNSIGNED NOT NULL,
  status       ENUM('waiting','playing','dq','left') NOT NULL DEFAULT 'waiting',
               -- waiting=待合室 / playing=対戦中・完走 / dq=失格 / left=スタートの時に画面にいなかった・自分で抜けた
  last_seen_ms BIGINT UNSIGNED NOT NULL,          -- 最後に画面から通信があった時刻（途絶えたら失格の判定に使う）
  dq_reason    VARCHAR(16)  DEFAULT NULL,         -- hidden(別のアプリ・タブ) / blur(別のウィンドウ) / pagehide(閉じた) / reload(開き直した) / lost(通信が途絶えた)
  dq_seq       TINYINT UNSIGNED DEFAULT NULL,     -- 失格した時の問題（0始まり）。スタート前のカウントダウン中なら NULL
  joined_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dq_at        DATETIME     DEFAULT NULL,
  PRIMARY KEY (room_id, student_id),
  KEY idx_bp_student (student_id),
  CONSTRAINT fk_bp_room FOREIGN KEY (room_id) REFERENCES battle_rooms (room_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_bp_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 解答（1人1問1行。締め切りまでは選び直すたびに上書き＝最後の答えが有効。締め切り後は受け付けない）
CREATE TABLE IF NOT EXISTS battle_answers (
  room_id     INT UNSIGNED NOT NULL,
  student_id  INT UNSIGNED NOT NULL,
  seq         TINYINT UNSIGNED NOT NULL,
  choice      TINYINT UNSIGNED NOT NULL,          -- 押した位置（画面の並びで 0〜5）
  is_correct  TINYINT(1)   NOT NULL,
  elapsed_ms  INT UNSIGNED NOT NULL,              -- 出題から解答が届くまで（サーバーの時計）
  answered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (room_id, student_id, seq),
  CONSTRAINT fk_ba_player FOREIGN KEY (room_id, student_id) REFERENCES battle_players (room_id, student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
