-- ============================================================
-- 退会の予約（生徒の自動無効化）
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
--
-- deactivate_on = 「この日までは使える」最終利用日。**翌日から** is_active=0 になる。
--   例) 8/31 を入れる → 8/31 は通常どおり使え、9/1 になったら自動で無効。
--   退会が決まった時点で月末を入れておけば、月末に無効化操作を忘れても止まる。
--
-- 反映のしかた（二重にしてある）:
--   ・api/run_deactivation.php を Heteml の cron で1日1回（update_xp.php と同じトークン方式）
--   ・cron が動かなくても、ログイン時(api/auth.php)と admin.php / teacher.php 表示時に
--     同じ掃除が走る＝その生徒がログインしようとした瞬間に締まる
--
-- 「有効に戻す」(api/set_active.php) を押すと deactivate_on も NULL に戻す
--   （残したままだと、戻した翌日にまた無効化されてしまうため）。
--
-- ※MySQL は ALTER の "IF NOT EXISTS" を受け付けない。実行は1回だけにすること
--   （2回流すと #1060 Duplicate column name で止まるだけで、実害は無い）。
-- ============================================================

ALTER TABLE students
  ADD COLUMN deactivate_on DATE DEFAULT NULL AFTER is_active;   -- 退会予定日（この日までは使える）

ALTER TABLE students
  ADD KEY idx_st_deactivate_on (deactivate_on);
