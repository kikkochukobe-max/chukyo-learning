-- ------------------------------------------------------------
-- 円の面積マスター（算数・小6 / unit_key = math_es6_en_menseki）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この4行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は math_es6_en_menseki.html の「モード」と一致：
--   hankei  … 半径から求める（円ぜんたい／半円／4分の1の円）
--   chokkei … 直径から求める（直径÷2で半径にしてから）
--   enshu   … 円周から求める（円周÷3.14で直径に。ひもの文章題もここ）
--   kufu    … 工夫して求める（くり抜く・入れかえる・かさねる）
--             サブタイプ: 正方形−円 / ドーナツ / 葉っぱ形 / 弓形 / 花びら4枚 /
--                         半円の入れかえ（question_params の s に入る）
--
-- ※ミックスモードは出題の入口だけで、記録は上の4種のいずれかに入る。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_es6_en_menseki', 'hankei',  '半径から求める', 1),
  ('math_es6_en_menseki', 'chokkei', '直径から求める', 1),
  ('math_es6_en_menseki', 'enshu',   '円周から求める', 1),
  ('math_es6_en_menseki', 'kufu',    '工夫して求める', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
