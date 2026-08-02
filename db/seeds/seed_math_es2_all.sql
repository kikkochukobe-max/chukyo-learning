-- ------------------------------------------------------------
-- 小2算数まるごとパック（算数・小2 / unit_key = math_es2_all）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この15行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は math_es2_all.html の UNITS[].k と一致（＝15単元）。
-- どの生成関数から出た問題かは question_params.g に残るので、
-- カルテは単元単位の粒度にしている。
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es2_all', 'hyou',   'ひょうとグラフ',       1),
  ('math_es2_all', 'tashi',  'たし算のひっ算',       1),
  ('math_es2_all', 'hiki',   'ひき算のひっ算',       1),
  ('math_es2_all', 'nagasa', '長さ（cm・mm・m）',    1),
  ('math_es2_all', 'kazu3',  '100より大きい数',      1),
  ('math_es2_all', 'kasa',   '水のかさ',             1),
  ('math_es2_all', 'tokei',  '時こくと時間',         1),
  ('math_es2_all', 'kufuu',  '計算のくふう',         1),
  ('math_es2_all', 'zukei',  '三角形と四角形',       1),
  ('math_es2_all', 'kuku',   'かけ算九九',           1),
  ('math_es2_all', 'kimari', 'かけ算のきまり',       1),
  ('math_es2_all', 'kazu4',  '大きい数（10000まで）', 1),
  ('math_es2_all', 'zu',     '図をつかって考えよう', 1),
  ('math_es2_all', 'bunsu',  '分数',                 1),
  ('math_es2_all', 'hako',   'はこの形',             1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
