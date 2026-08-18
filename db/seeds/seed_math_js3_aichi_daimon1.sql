-- ------------------------------------------------------------
-- 愛知県公立入試 大問1 マーク演習（数学・中3 / unit_key = math_js3_aichi_daimon1）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- ★ 2026-08-17 修正: question_key の区切りを「:」から「_」に変更した。
--   save_answer.php / save_time.php / list_times.php の3つが question_key を
--   /^[a-zA-Z0-9_]{1,128}$/ で検証していて、コロン入りキー(a1:seifu)は
--   400 invalid_request で捨てられ1行も記録されなかったため。
--   他ツール(addsub / truefalse / quiz など)も英数字のみで統一されている。
--   → 先に流した「a1:〜」の32行を消してから入れ直すこと（下のDELETEが担当）。
--     answer_logs 側は1行も入っていないので、消しても学習記録は失われない。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を丸ごと流し直す必要はない。
-- phpMyAdmin でこのファイルを上から順に実行する。
--
-- question_key は math_js3_aichi_daimon1.html 内の q_() 第2引数と一致。
-- ツールは1モード（TYPES[].id = t1〜t21）に対して複数の question_key を
-- 出し分けるため、TYPESの数(21)より多い32行になる。
-- 例: t10「関数・数の性質」は henka/shizen/hanpirei/koten/heikou/kansuhan の6種、
-- 　　t13「図形(角度)」は kakudo/enshukaku の2種。
--
-- ラベル先頭の (n) は本番の大問1における設問番号。生徒一覧・単元カルテで
-- 「(4)平方根の計算」のように並ぶので、本番の出題順と対応づけて読める。
-- ------------------------------------------------------------

-- 旧「a1:〜」(コロン版)の掃除。初回実行なら0行削除で問題ない。
DELETE FROM question_catalog
 WHERE unit_key = 'math_js3_aichi_daimon1' AND question_key LIKE 'a1:%';

INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js3_aichi_daimon1', 'a1_seifu',        '(1)正負の数の計算',            1),
  ('math_js3_aichi_daimon1', 'a1_mojibunsuu',   '(2)文字式(分数)',              1),
  ('math_js3_aichi_daimon1', 'a1_shikinoatai',  '(2)式の値(代入)',              1),
  ('math_js3_aichi_daimon1', 'a1_tanko',        '(3)単項式の乗除',              1),
  ('math_js3_aichi_daimon1', 'a1_tenkai',       '(3)式の展開',                  1),
  ('math_js3_aichi_daimon1', 'a1_insu',         '(3)因数分解',                  1),
  ('math_js3_aichi_daimon1', 'a1_heihokon',     '(4)平方根の計算',              1),
  ('math_js3_aichi_daimon1', 'a1_niji',         '(5)二次方程式',                1),
  ('math_js3_aichi_daimon1', 'a1_renritsu',     '(5)連立方程式',                1),
  ('math_js3_aichi_daimon1', 'a1_risshiki',     '(6)不等式の立式',              1),
  ('math_js3_aichi_daimon1', 'a1_wariai',       '(6)割合の文章題',              1),
  ('math_js3_aichi_daimon1', 'a1_seigo',        '(7)正誤・二つ選ぶ',            1),
  ('math_js3_aichi_daimon1', 'a1_hyohon',       '(8)標本調査',                  1),
  ('math_js3_aichi_daimon1', 'a1_histo',        '(8)ヒストグラム読み取り',      1),
  ('math_js3_aichi_daimon1', 'a1_heikin',       '(8)平均値(度数分布表)',        1),
  ('math_js3_aichi_daimon1', 'a1_kakuritsu',    '(9)確率',                      1),
  ('math_js3_aichi_daimon1', 'a1_hakohige',     '(9)箱ひげ図',                  1),
  ('math_js3_aichi_daimon1', 'a1_henka',        '(10)変化の割合',               1),
  ('math_js3_aichi_daimon1', 'a1_shizen',       '(10)平方根と自然数',           1),
  ('math_js3_aichi_daimon1', 'a1_hanpirei',     '(10)反比例の格子点',           1),
  ('math_js3_aichi_daimon1', 'a1_koten',        '(10)2直線の交点の座標',        1),
  ('math_js3_aichi_daimon1', 'a1_heikou',       '(10)交点を通り平行な直線の切片', 1),
  ('math_js3_aichi_daimon1', 'a1_kansuhan',     '(10)一次関数・比例・反比例の判別', 1),
  ('math_js3_aichi_daimon1', 'a1_kakudo',       '(10)図形(角度)',               1),
  ('math_js3_aichi_daimon1', 'a1_enshukaku',    '(10)図形(円周角)',             1),
  ('math_js3_aichi_daimon1', 'a1_heikousen',    '(10)平行線と角',               1),
  ('math_js3_aichi_daimon1', 'a1_chu2kakudo',   '(10)中2図形の角度(二等辺・平行四辺形など)', 1),
  ('math_js3_aichi_daimon1', 'a1_souji',        '(10)相似(平行線と線分比)',     1),
  ('math_js3_aichi_daimon1', 'a1_sanpei',       '(10)三平方(直角三角形・対角線)', 1),
  ('math_js3_aichi_daimon1', 'a1_enpei',        '(10)円と三平方(弦の長さ)',     1),
  ('math_js3_aichi_daimon1', 'a1_hiritsu',      '(10)面積比・体積比',           1),
  ('math_js3_aichi_daimon1', 'a1_nitohen',      '(10)二等辺三角形+垂線(相似と三平方)', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);

-- 本番セットのタイム記録用（save_time.php が question_key='set' で書く）
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js3_aichi_daimon1', 'set', '本番セット10問', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);

-- 確認用（33行返り、コロン入りが0件なら成功）
-- SELECT question_key, label, base_xp FROM question_catalog
--  WHERE unit_key = 'math_js3_aichi_daimon1' ORDER BY question_key;
-- SELECT COUNT(*) AS colon_left FROM question_catalog
--  WHERE unit_key = 'math_js3_aichi_daimon1' AND question_key LIKE 'a1:%';
