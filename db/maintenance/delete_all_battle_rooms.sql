-- ============================================================
-- 一般常識バトル: これまでの部屋（対戦の記録）を全部消す
--   用途: 試しに作った部屋の掃除。講師画面 /battle_host.php の「これまでの部屋」が空になる。
--   実行: phpMyAdmin の「SQL」タブに貼って実行（本番へは配信しない。db/ はソース管理のみ）
--
--   消えるもの: battle_rooms の全行と、それにぶら下がる
--     battle_room_questions（部屋の出題）/ battle_players（参加者）/ battle_answers（解答）。
--     どれも外部キーが ON DELETE CASCADE なので、battle_rooms を消せば一緒に消える。
--   残るもの: battle_questions（問題バンク）。seed を流し直す必要は無い。
--   学習記録（answer_logs / XP など）にはバトルは一切書いていないので、生徒の記録には影響しない。
--
--   ⚠ 待合室・対戦中の部屋も消える。生徒が対戦している最中には流さないこと
--     （生徒の画面は「部屋が見つからない」になる）。
--   ⚠ 元に戻せない。
-- ============================================================

-- ① 消す前の確認（何部屋あるか・いま開いている部屋が無いか）
SELECT status, COUNT(*) AS rooms FROM battle_rooms GROUP BY status;

-- ② 全部消す
DELETE FROM battle_rooms;

-- ③ 部屋の通し番号（room_id）を1から振り直す（任意。消したあとなら安全）
ALTER TABLE battle_rooms AUTO_INCREMENT = 1;
