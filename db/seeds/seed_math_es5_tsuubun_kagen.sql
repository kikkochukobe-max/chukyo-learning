-- ------------------------------------------------------------
-- 通分マスター（算数・小5 / unit_key = math_es5_tsuubun_kagen）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のカルテを日本語ラベルで出すために、この10行を
-- phpMyAdmin で実行する（未登録でもXPは既定1で付くが、ラベルが
-- question_key の生値（add_tai 等）のままになる）。
--
-- question_key は math_es5_tsuubun_kagen.html の GENS のキーと一致。
--
-- ⚠ ①「通分のしくみ」(tsuubun) の行は **わざと入れていない**。①は練習問題では
--    なく参考書あつかいで、入力する前に「続きを見る」で答えを見られる作りに
--    してあるため、ツール側が Divp.answer を呼ばない（＝記録が飛ばない）。
--    カタログに行だけあると「記録されるはずなのに来ない」と誤読するので置かない。
-- ⑩⑪のミックスは「別モードの問題を混ぜて出す」形式ではなく、
-- ＋と－がまざった独自の問題を作るので、mix_bun / mix_all という
-- question_key を自分で持つ（＝カルテにもそのまま出る）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es5_tsuubun_kagen', 'add2',      'たし算（2つ）',                    1),
  ('math_es5_tsuubun_kagen', 'add3',      'たし算（3つ）',                    1),
  ('math_es5_tsuubun_kagen', 'add_tai',   '帯分数を含むたし算',               1),
  ('math_es5_tsuubun_kagen', 'add_shou',  '小数を含むたし算',                 1),
  ('math_es5_tsuubun_kagen', 'sub2',      'ひき算（2つ）',                    1),
  ('math_es5_tsuubun_kagen', 'sub3',      'ひき算（3つ）',                    1),
  ('math_es5_tsuubun_kagen', 'sub_tai',   '帯分数を含むひき算',               1),
  ('math_es5_tsuubun_kagen', 'sub_shou',  '小数を含むひき算',                 1),
  ('math_es5_tsuubun_kagen', 'mix_bun',   '加減ミックス（分数のみ）',         1),
  ('math_es5_tsuubun_kagen', 'mix_all',   '加減ミックス（小数・分数・帯分数）', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
