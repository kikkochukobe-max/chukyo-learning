-- ============================================================
-- 一般常識バトル: 制限時間を問題ごとにする（計算が要る問題だけ長くする）
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
-- （MySQL は ALTER の IF NOT EXISTS を受け付けないので、2回流すと「列が重複」のエラーになる＝それで正常）
-- 流したあと、db/seeds/seed_joshiki_battle_lv1.sql 〜 lv4.sql を流し直す（needs_calc が入る）。
--
-- 難しい・超難は1問20秒、計算が要る問題（needs_calc=1）だけ60秒。
-- 1問ごとに時間が変わるので、部屋を作るときに各問題の制限時間を battle_room_questions.limit_sec に書く。
-- 各問題の出題時刻は「スタート＋それより前の問題の（制限時間＋正解発表）の合計」で計算する（保存しない）。
-- limit_sec が0の部屋（このSQLより前に作った部屋）は、従来どおり部屋の time_limit_sec で進む。
-- ============================================================

ALTER TABLE battle_questions
  ADD COLUMN needs_calc TINYINT(1) NOT NULL DEFAULT 0 AFTER explanation;

ALTER TABLE battle_room_questions
  ADD COLUMN limit_sec TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER choice_order;
