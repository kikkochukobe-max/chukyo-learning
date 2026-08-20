-- ------------------------------------------------------------
-- 一次関数マスター（数学・中2 / unit_key = math_js2_ichijikansu）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この13行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　カルテのラベルが question_key の生値（ローマ字）のままになるため必ず登録する）
--
-- question_key は math_js2_ichijikansu.html のモード名（chip の data-mode）と一致。
-- ミックス出題（data-mode="mix"）はその場でどれかのモードに割り振られるので、
-- "mix" という question_key は飛んでこない＝カタログにも登録しない。
-- 当面は難易度を分けず base_xp=1 で統一（CLAUDE.md 方針）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js2_ichijikansu', 'hantei',       '式をつくる（一次関数かどうか）', 1),
  ('math_js2_ichijikansu', 'zoukaryo',     '増加量・変化の割合',             1),
  ('math_js2_ichijikansu', 'graph',        'グラフをかく',                   1),
  ('math_js2_ichijikansu', 'graphadv',     'グラフをかく（応用）',           1),
  ('math_js2_ichijikansu', 'yomitori',     'グラフを読む',                   1),
  ('math_js2_ichijikansu', 'tooru',        '通る座標',                       1),
  ('math_js2_ichijikansu', 'hendomain',    '変域',                           1),
  ('math_js2_ichijikansu', 'hendomainadv', '変域（応用）',                   1),
  ('math_js2_ichijikansu', 'tokucho',      '式の特徴',                       1),
  ('math_js2_ichijikansu', 'shiki',        '式を求める',                     1),
  ('math_js2_ichijikansu', 'shikiadv',     '式を求める（応用）',             1),
  ('math_js2_ichijikansu', 'koten',        '交点を求める',                   1),
  ('math_js2_ichijikansu', 'menseki',      '三角形の面積',                   1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
