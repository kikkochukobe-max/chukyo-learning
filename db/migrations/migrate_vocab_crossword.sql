-- ============================================================
-- 語彙クロスワード（unit_key = japanese_goi_crossword）用テーブル追加
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
--
-- 語そのもの(vocab_words)と段階ヒント(vocab_hints)の2表だけを足す。
-- 正誤の記録は既存 answer_logs に集約する（1語=1解答）。専用のログ表は作らない＝
-- マイページ・講師カルテ・XP・retry_queue が既存のまま効く。
-- 語のデータ本体（3350語）は db/seeds/seed_japanese_goi_crossword.sql。
--
-- ※MySQL は ALTER の "IF NOT EXISTS" を受け付けないが、CREATE TABLE IF NOT EXISTS は可。
--
-- ⚠ 一度この2表を作った後にこのファイルを直しても、CREATE TABLE IF NOT EXISTS は
--   **黙って何もしない**（前の定義のまま残る）。作り直すときは先に
--     DROP TABLE IF EXISTS vocab_hints; DROP TABLE IF EXISTS vocab_words;
--   を実行すること（FKがあるので hints → words の順。語データはseedで入れ直せる）。
-- ============================================================

-- 1) 語の本体 -------------------------------------------------
CREATE TABLE IF NOT EXISTS vocab_words (
  word_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- ⚠ yomi だけ照合順序を utf8mb4_bin にする（表の既定 utf8mb4_unicode_ci にしない）。
  --   utf8mb4_unicode_ci は主レベルの重みだけで比べるので、**濁点・半濁点・小書きの差を
  --   無視する**＝「ニシ＝ニジ」「キケン＝キゲン」「規制＝犠牲」が同じ文字列とみなされ、
  --   下の UNIQUE KEY uniq_level_yomi が #1062 で弾いてしまう（実データで44組が衝突）。
  --   読みは1文字違えば別の語なので、ここは符号位置どおりに比べる必要がある。
  yomi        VARCHAR(12)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                           COMMENT 'カタカナの読み＝マスに入る文字。濁点を区別するため utf8mb4_bin',
  hyoki       VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '漢字表記。ひらがな語は空',
  gloss       VARCHAR(255) NOT NULL COMMENT '語釈（カギ本文）',
  level       TINYINT UNSIGNED NOT NULL COMMENT '1=小低 2=小中 3=小高 4=中易 5=中普 6=中難',
  category    VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'daily/science/society/math/kokugo/hyoron/idiom/yojijukugo',
  length      TINYINT UNSIGNED NOT NULL COMMENT '読みの文字数（配置用）',
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED NULL COMMENT 'teachers.teacher_id',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (word_id),
  KEY idx_level_active (level, is_active),
  KEY idx_level_len (level, length),
  KEY idx_category (category),
  -- ⚠ 漢字表記まで含めた3列の UNIQUE。(level, yomi) の2列だけにすると
  --   「ホショウ＝保証／保障／補償」のような**同音異義語が同じレベルに登録できない**
  --   （実際に /vocab_admin.php で弾かれた）。表記が違えば別の語として持てる。
  --   同じレベルに同じ読み・同じ表記を2回入れるのは今までどおり弾く＝打ち間違い防止。
  --   既存DBへの適用は db/migrations/migrate_vocab_homonym.sql。
  UNIQUE KEY uniq_level_yomi_hyoki (level, yomi, hyoki)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) 段階ヒント（語に対して0〜複数） ---------------------------
CREATE TABLE IF NOT EXISTS vocab_hints (
  hint_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  word_id    INT UNSIGNED NOT NULL,
  step       TINYINT UNSIGNED NOT NULL COMMENT '1,2,3… 出す順',
  hint_type  VARCHAR(12)  NOT NULL DEFAULT 'free' COMMENT 'example/synonym/antonym/firstchar/free',
  body       VARCHAR(255) NOT NULL,
  PRIMARY KEY (hint_id),
  KEY idx_word_step (word_id, step),
  CONSTRAINT fk_hint_word FOREIGN KEY (word_id) REFERENCES vocab_words(word_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
