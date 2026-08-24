-- ============================================================
-- vocab_words.yomi の照合順序を utf8mb4_bin に直す（作り直さずに直す用）
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。
--
-- migrate_vocab_crossword.sql を「直す前の版」で流してしまった環境向け。
-- CREATE TABLE IF NOT EXISTS は表がもうあると黙って何もしないので、
-- ファイルを直して流し直しても列の照合順序は古いまま残る。それをこれで上書きする。
--
-- なぜ必要か:
--   既定の utf8mb4_unicode_ci は主レベルの重みだけで比較する＝濁点・半濁点・小書きの
--   差を無視するため、MySQLからは「ニシ＝ニジ」「キケン＝キゲン」
--   「規制(キセイ)＝犠牲(ギセイ)」が同じ文字列に見える。
--   UNIQUE KEY uniq_level_yomi (level, yomi) がそれを重複とみなし、
--   seed の投入が #1062 で止まる（実データで44組が衝突した）。
--
-- 実行後に seed（db/seeds/seed_japanese_goi_crossword.sql）を流し直すこと。
-- ============================================================

-- 1) 今どうなっているかの確認（Collation 欄が utf8mb4_bin なら対応済み）
SHOW FULL COLUMNS FROM vocab_words LIKE 'yomi';

-- 2) 照合順序を入れ替える。UNIQUE キーもこの時に張り直される。
--    ※厳しくする方向の変更なので、既存行が原因で失敗することはない
--    ※MySQL は ALTER に IF NOT EXISTS を書けないので通常構文（1回だけ流す）
ALTER TABLE vocab_words
  MODIFY yomi VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
  COMMENT 'カタカナの読み＝マスに入る文字。濁点を区別するため utf8mb4_bin';

-- 3) 入りかけの語を消してから seed をやり直す（ヒントはFKのCASCADEで一緒に消える）。
--    失敗した INSERT は文ごと rollback されるので通常は0行だが、
--    途中まで通っている場合に seed が二重に入るのを防ぐ。
DELETE FROM vocab_words;

-- 4) 確認（utf8mb4_bin になっていること）
SHOW FULL COLUMNS FROM vocab_words LIKE 'yomi';
