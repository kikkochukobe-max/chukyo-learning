-- ------------------------------------------------------------
-- 大地の変化マスター（理科・中1 / unit_key = science_js1_daichi）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この5行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は science_js1_daichi.html の「モード」と一致（CAT_MODE で分野→モードに集約）：
--   kazan    … 火山（マグマと噴火・火山噴出物・火山の形・災害と恵み）
--   kaseigan … 火成岩（火山岩と深成岩・組織・造岩鉱物・火成岩の色）
--   jishin   … 地震（震源震央と地震計・P波S波・震度とM・プレート・大地の変化）
--   chisou   … 地層（風化侵食運搬堆積・堆積岩・化石と地質年代・柱状図・断層しゅう曲）
--   keisan   … 計算特集（地震の計算・柱状図の計算）
--
-- ※ツール内の細かい分野別（造岩鉱物・P波とS波 等）の正答率は
--   結果画面でその場表示する。DBにはモード単位で集計する。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('science_js1_daichi', 'kazan',    '火山',     1),
  ('science_js1_daichi', 'kaseigan', '火成岩',   1),
  ('science_js1_daichi', 'jishin',   '地震',     1),
  ('science_js1_daichi', 'chisou',   '地層',     1),
  ('science_js1_daichi', 'keisan',   '計算特集', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
