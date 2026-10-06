-- ============================================================
-- 一般常識バトル: チーム戦（複数チームの合計点で競う）
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。1回だけ流す。
-- （MySQL は ALTER の IF NOT EXISTS を受け付けないので、2回流すと「列が重複」のエラーになる＝それで正常）
--
-- 講師が部屋を作るときにチーム数（2〜6。0=個人戦）を決める → 生徒は待合室で自分のチームを選ぶ
-- （スタートまで選び直せる）→ スタートの時にまだ選んでいない生徒は、人数の少ないチームへ自動で入る。
-- チームの順位は合計点。1人あたりの平均点も並べて出す（人数がそろわない時に講師が判断できるように）。
-- 失格した生徒の点は、失格するまでに取ったぶんをチームの合計に数える。
-- このSQLを流す前に PHP だけ上げても、個人戦はそのまま動く（チーム戦のボタンが押せないだけ）。
-- ============================================================

ALTER TABLE battle_rooms
  ADD COLUMN team_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reveal_sec;   -- 0=個人戦 / 2〜6=チーム戦

ALTER TABLE battle_players
  ADD COLUMN team TINYINT UNSIGNED DEFAULT NULL AFTER status;                  -- 1〜team_count。個人戦・未選択は NULL
