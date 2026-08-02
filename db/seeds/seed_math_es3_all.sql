-- ------------------------------------------------------------
-- 小3算数まるごとパック（算数・小3 / unit_key = math_es3_all）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この15行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は math_es3_all.html の UNITS[].k と一致（＝15単元）。
-- どの出題パターンかは p.key、再生成の種は question_params.s に残るので、
-- カルテは単元単位の粒度にしている（小2パックと同じ方針）。
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es3_all', 'kake',   'かけ算のきまり',       1),
  ('math_es3_all', 'tokei',  '時こくと時間',         1),
  ('math_es3_all', 'wari',   'わり算',               1),
  ('math_es3_all', 'hissan', 'たし算とひき算の筆算', 1),
  ('math_es3_all', 'nagasa', '長さ',                 1),
  ('math_es3_all', 'amari',  'あまりのあるわり算',   1),
  ('math_es3_all', 'ookii',  '大きい数',             1),
  ('math_es3_all', 'kakeh',  'かけ算の筆算',         1),
  ('math_es3_all', 'en',     '円と球',               1),
  ('math_es3_all', 'shou',   '小数',                 1),
  ('math_es3_all', 'omosa',  '重さ',                 1),
  ('math_es3_all', 'bun',    '分数',                 1),
  ('math_es3_all', 'shi',    '□を使った式',          1),
  ('math_es3_all', 'san',    '三角形と角',           1),
  ('math_es3_all', 'graph',  '棒グラフと表',         1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
