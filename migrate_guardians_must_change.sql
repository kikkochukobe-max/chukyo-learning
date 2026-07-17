-- 保護者パスワードを講師と同じ運用にする（仮パスワード発行 → 初回ログインで本人が8〜15字英数に変更）
-- ためのカラム追加。phpMyAdmin で1回実行する。
-- ※Heteml の MySQL は ADD COLUMN IF NOT EXISTS 非対応なので素の ALTER（2回実行するとエラーになるが実害なし）
-- 既存の保護者（4桁PINで作成済み）も DEFAULT 1 で「次回ログイン時に変更」の対象になる。
ALTER TABLE guardians
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 1 AFTER guardian_name;
