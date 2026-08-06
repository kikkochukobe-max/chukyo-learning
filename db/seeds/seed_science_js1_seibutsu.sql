-- ------------------------------------------------------------
-- 生物の観察と分類マスター（理科・中1 / unit_key = science_js1_seibutsu）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この6行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は science_js1_seibutsu.html の「モード」と一致（CAT_MODE で分野→モードに集約）：
--   kansatsu   … 観察（ルーペ・双眼実体顕微鏡・顕微鏡のつくりと使い方・倍率・スケッチ）
--   hana       … 花のつくり（花の各部・受粉と果実と種子・被子植物と裸子植物・マツの花）
--   shokubutsu … 植物の分類（子葉葉脈根・単子葉双子葉・離弁花合弁花・シダコケ・分類まとめ）
--   sekitsui   … セキツイ動物（背骨と骨格・5つのなかま・呼吸・体表と体温・子の生まれ方）
--   musekitsui … 無セキツイ動物（外骨格・節足動物・軟体動物・その他・なかま分けの判定）
--   seikatsu   … 動物の生活（肉食と草食・歯と目のつくり・体のつくりと食べ物）
--
-- ※ツール内の細かい分野別（顕微鏡の使い方・シダ植物とコケ植物 等）の正答率は
--   結果画面でその場表示する。DBにはモード単位で集計する。
-- ※中2の science_js2_seibutsu（生物の体のつくりとはたらき）とは別単元。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('science_js1_seibutsu', 'kansatsu',   '観察',           1),
  ('science_js1_seibutsu', 'hana',       '花のつくり',     1),
  ('science_js1_seibutsu', 'shokubutsu', '植物の分類',     1),
  ('science_js1_seibutsu', 'sekitsui',   'セキツイ動物',   1),
  ('science_js1_seibutsu', 'musekitsui', '無セキツイ動物', 1),
  ('science_js1_seibutsu', 'seikatsu',   '動物の生活',     1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
