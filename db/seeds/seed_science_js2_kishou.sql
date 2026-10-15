-- ------------------------------------------------------------
-- 気象マスター（理科・中2 / unit_key = science_js2_kishou）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この6行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は science_js2_kishou.html の「モード」と一致
-- （CAT_MODE で分野→モードに集約）：
--   taiki    … 大気圧と圧力（圧力の公式・単位・大気圧の大きさと向き）
--   kansoku  … 気象観測（気象要素・雲量・天気記号・風向風力・乾湿計・天気と気温湿度気圧）
--   suijouki … 雲と水蒸気（霧・雲のでき方・実験・降水・水の循環・飽和水蒸気量・露点・湿度）
--   zensen   … 気圧と前線（高気圧低気圧・等圧線・気団・前線・前線の通過・天気の移り変わり）
--   shiki    … 日本の四季（大気の動き・海風陸風・季節風・気団・四季の天気・台風・恵みと災害）
--   keisan   … 計算特集（圧力・湿度・水蒸気量・露点・湿度表・雲のできる高さ・グラフ）
--
-- ※ツール内の細かい分野別の正答率は結果画面でその場表示する。DBにはモード単位で集計する。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('science_js2_kishou', 'taiki',    '大気圧と圧力', 1),
  ('science_js2_kishou', 'kansoku',  '気象観測',     1),
  ('science_js2_kishou', 'suijouki', '雲と水蒸気',   1),
  ('science_js2_kishou', 'zensen',   '気圧と前線',   1),
  ('science_js2_kishou', 'shiki',    '日本の四季',   1),
  ('science_js2_kishou', 'keisan',   '計算特集',     1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
