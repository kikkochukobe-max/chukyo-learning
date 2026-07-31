-- ------------------------------------------------------------
-- 生物の体のつくりとはたらきマスター（理科・中2 / unit_key = science_js2_seibutsu）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この6行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は science_js2_seibutsu.html の「モード」と一致（CAT_MODE で分野→モードに集約）：
--   saibou     … 細胞（顕微鏡・細胞のつくり・植物と動物の細胞・単細胞多細胞・組織器官個体・細胞呼吸）
--   shokubutsu … 植物（光合成・光合成の実験・呼吸・根・茎と維管束・葉と気孔・蒸散）
--   shouka     … 消化と吸収（五大栄養素・消化管・消化液と消化酵素・分解・柔毛・肝臓）
--   junkan     … 呼吸と循環（肺と呼吸運動・吸う息はく息・心臓・動脈静脈・体循環肺循環・血液の成分・排出）
--   kankaku    … 感覚と運動（感覚器官・目・耳・神経系・刺激と反応の経路・反射・骨格と筋肉）
--   keisan     … 計算特集（顕微鏡の倍率・蒸散量・反応時間・心臓と血液量）
--
-- ※ツール内の細かい分野別（顕微鏡の使い方・蒸散 等）の正答率は
--   結果画面でその場表示する。DBにはモード単位で集計する。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('science_js2_seibutsu', 'saibou',     '細胞',       1),
  ('science_js2_seibutsu', 'shokubutsu', '植物',       1),
  ('science_js2_seibutsu', 'shouka',     '消化と吸収', 1),
  ('science_js2_seibutsu', 'junkan',     '呼吸と循環', 1),
  ('science_js2_seibutsu', 'kankaku',    '感覚と運動', 1),
  ('science_js2_seibutsu', 'keisan',     '計算特集',   1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
