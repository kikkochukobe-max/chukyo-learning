-- ------------------------------------------------------------
-- 小4算数まるごとパック（算数・小4 / unit_key = math_es4_all）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この15行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は math_es4_all.html の UNITS[].k と一致（＝16単元）。
-- どの出題パターンかは p.key、再生成の種は question_params.s に残るので、
-- カルテは単元単位の粒度にしている（小2・小3パックと同じ方針）。
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es4_all', 'ookii',    '大きい数',             1),
  ('math_es4_all', 'wari1',    'わり算の筆算①',       1),
  ('math_es4_all', 'wari2',    'わり算の筆算②',       1),
  ('math_es4_all', 'kaku',     '角の大きさ',           1),
  ('math_es4_all', 'shikaku',  '垂直・平行と四角形',   1),
  ('math_es4_all', 'shou',       '小数のしくみ',       1),
  ('math_es4_all', 'shouhissan', '小数のひっ算',       1),
  ('math_es4_all', 'shoukake', '小数のかけ算わり算',   1),
  ('math_es4_all', 'gaisuu',   '概数',                 1),
  ('math_es4_all', 'kimari',   '計算のきまり',         1),
  ('math_es4_all', 'menseki',  '面積',                 1),
  ('math_es4_all', 'bunsuu',   '分数',                 1),
  ('math_es4_all', 'hako',     '直方体と立方体',       1),
  ('math_es4_all', 'graph',    '折れ線グラフと表',     1),
  ('math_es4_all', 'kawari',   '変わり方',             1),
  ('math_es4_all', 'bai',      '倍の見方',             1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
