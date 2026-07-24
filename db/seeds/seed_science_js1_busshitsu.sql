-- ------------------------------------------------------------
-- 身のまわりの物質マスター（理科・中1 / unit_key = science_js1_busshitsu）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この5行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は science_js1_busshitsu.html の「モード」と一致（CAT_MODE で分野→モードに集約）：
--   busshitsu … 物質（有機物・金属・実験器具・白い粉末・プラスチック）
--   kitai     … 気体（9種類の性質・集め方・発生方法・実験）
--   suiyoueki … 水溶液（溶質溶媒・濃度・溶解度・再結晶・ろ過）
--   joutai    … 状態変化（三態・体積質量・融点沸点・蒸留）
--   keisan    … 計算特集（密度・質量パーセント濃度）
--
-- ※ツール内の細かい分野別（色とにおい・密度の計算 等）の正答率は
--   結果画面でその場表示する。DBにはモード単位で集計する。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('science_js1_busshitsu', 'busshitsu', '物質',       1),
  ('science_js1_busshitsu', 'kitai',     '気体',       1),
  ('science_js1_busshitsu', 'suiyoueki', '水溶液',     1),
  ('science_js1_busshitsu', 'joutai',    '状態変化',   1),
  ('science_js1_busshitsu', 'keisan',    '計算特集',   1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
