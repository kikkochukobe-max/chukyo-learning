-- ============================================================
-- 志望校（私立・公立）機能 スキーマ変更
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
-- ※MySQL は ALTER の "IF NOT EXISTS / DROP FOREIGN KEY IF EXISTS" を受け付けないので通常構文で書く。
-- ============================================================

-- 1) 志望校マスター（全教室共通の台帳。既にあれば作成をスキップ）
CREATE TABLE IF NOT EXISTS target_schools (
  target_school_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(80)  NOT NULL,
  kind             ENUM('private','public') NOT NULL,   -- private=私立 / public=公立
  sort_order       INT          NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (target_school_id),
  UNIQUE KEY uq_ts_name_kind (name, kind),
  KEY idx_ts_kind (kind, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) students に私立/公立の志望校を1校ずつ持つ列とFKを、1つのALTERでまとめて追加
ALTER TABLE students
  ADD COLUMN target_private_id INT UNSIGNED NULL AFTER grade,
  ADD COLUMN target_public_id  INT UNSIGNED NULL AFTER target_private_id,
  ADD CONSTRAINT fk_st_target_private FOREIGN KEY (target_private_id)
    REFERENCES target_schools (target_school_id) ON UPDATE CASCADE ON DELETE SET NULL,
  ADD CONSTRAINT fk_st_target_public FOREIGN KEY (target_public_id)
    REFERENCES target_schools (target_school_id) ON UPDATE CASCADE ON DELETE SET NULL;
