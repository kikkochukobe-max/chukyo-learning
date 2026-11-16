-- ============================================================
-- 志望校マスター（target_schools）に「地区」を持たせる
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
--   ※2回流すと「Duplicate column name 'region'」で止まる（＝もう入っている。害は無い）。
--
-- region: nagoya=名古屋地区 / owari=尾張地区 / mikawa=三河地区 / NULL=地区なし
--   ラベルは api/kakomon_common.php の SCHOOL_REGIONS。
--   いま使っているのは過去問の記録（/kakomon.php）の「高校を追加」のプルダウンだけで、
--   地区ごとに見出しを付けて並べる（NULL の学校は最後の「その他」にまとめる）。
-- 学校の中身は db/seeds/seed_target_schools_private_aichi.sql（このあとに流す）。
-- ※MySQL は ALTER の "IF NOT EXISTS" を受け付けないので通常構文で書く。
-- ============================================================

ALTER TABLE target_schools ADD COLUMN region VARCHAR(16) NULL AFTER kind;
