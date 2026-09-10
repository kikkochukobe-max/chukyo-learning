-- ------------------------------------------------------------
-- 一次関数マスター 文章題編（数学・中2 / unit_key = math_js2_ichijikansu_riyou）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を丸ごと流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この23行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するので、XP自体は動くが
-- 　ラベルが question_key 生値のままになるため、必ず登録しておく）
--
-- question_key は math_js2_ichijikansu_riyou.html 内の STEP キー(stepObj().key)と一致。
-- 計算・グラフ側の「一次関数マスター」(math_js2_ichijikansu) とは別の unit_key。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js2_ichijikansu_riyou', 'mizu1',    '水そう：式をつくる',           1),
  ('math_js2_ichijikansu_riyou', 'mizu2',    '水そう：量と時間',             1),
  ('math_js2_ichijikansu_riyou', 'mizu3',    '水そう：ひねり',               1),
  ('math_js2_ichijikansu_riyou', 'hyo1',     '表から式：式をつくる',         1),
  ('math_js2_ichijikansu_riyou', 'hyo2',     '表から式：予測する',           1),
  ('math_js2_ichijikansu_riyou', 'ryoukin1', '料金プラン：式をつくる',       1),
  ('math_js2_ichijikansu_riyou', 'ryoukin2', '料金プラン：同じ金額になるのは', 1),
  ('math_js2_ichijikansu_riyou', 'ryoukin3', '料金プラン：ひねり',           1),
  ('math_js2_ichijikansu_riyou', 'hayasa1',  '速さ：道のりの式',             1),
  ('math_js2_ichijikansu_riyou', 'hayasa2',  '速さ：出会う',                 1),
  ('math_js2_ichijikansu_riyou', 'hayasa3',  '速さ：追いつく',               1),
  ('math_js2_ichijikansu_riyou', 'ugoku1',   '動く点と面積：面積の式',       1),
  ('math_js2_ichijikansu_riyou', 'ugoku2',   '動く点と面積：面積と時間',     1),
  ('math_js2_ichijikansu_riyou', 'kouten1',  'グラフの交点：交点の座標',     1),
  ('math_js2_ichijikansu_riyou', 'kouten2',  'グラフの交点：交点と面積',     1),
  ('math_js2_ichijikansu_riyou', 'kouten3',  'グラフの交点：グラフから読む', 1),
  ('math_js2_ichijikansu_riyou', 'yomu1',    'グラフを読む：グラフから式',   1),
  ('math_js2_ichijikansu_riyou', 'yomu2',    'グラフを読む：グラフの外を予測', 1),
  ('math_js2_ichijikansu_riyou', 'yomu3',    'グラフを読む：グラフと面積',   1),
  ('math_js2_ichijikansu_riyou', 'diagram1', 'ダイヤグラム：速さ・式',       1),
  ('math_js2_ichijikansu_riyou', 'diagram2', 'ダイヤグラム：出会う・追いつく', 1),
  ('math_js2_ichijikansu_riyou', 'diagram3', 'ダイヤグラム：途中で休む',     1),
  ('math_js2_ichijikansu_riyou', 'mix',      '総合ミックス',                 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
