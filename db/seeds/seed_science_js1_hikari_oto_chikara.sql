-- ------------------------------------------------------------
-- 光・音・力マスター（理科・中1 / unit_key = science_js1_hikari_oto_chikara）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この6行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は science_js1_hikari_oto_chikara.html の「モード」と一致
-- （CAT_MODE で分野→モードに集約）：
--   hikari    … 光（直進・反射の法則・鏡にうつる像・屈折・全反射・光の色）
--   lens      … 凸レンズ（性質・光の道筋・実像と虚像・物体の位置と像・利用）
--   oto       … 音（伝わり方・速さ・振幅と振動数・モノコード・波形・いろいろな音源）
--   chikara   … 力（力のはたらき・いろいろな力・単位N・フックの法則・質量と重さ・つり合い）
--   atsuryoku … 圧力（公式と単位・面積と圧力・大気圧・水圧）
--   keisan    … 計算特集（音の速さ・振動数・ばねののび・重さと質量・圧力・凸レンズの像）
--
-- ※ツール内の細かい分野別（反射の法則・波形 等）の正答率は結果画面でその場表示する。
--   DBにはモード単位で集計する。
--
-- ※出典の問題集・教科書は「光による現象／音による現象／力による現象」の3章立てで、
--   圧力の章は含まれていない（現行の啓林館版では中2扱い）。ただし教室では
--   「光・音・圧力」の単元として扱っているため、圧力モードを標準的な中1内容で追加している。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('science_js1_hikari_oto_chikara', 'hikari',    '光',         1),
  ('science_js1_hikari_oto_chikara', 'lens',      '凸レンズ',   1),
  ('science_js1_hikari_oto_chikara', 'oto',       '音',         1),
  ('science_js1_hikari_oto_chikara', 'chikara',   '力',         1),
  ('science_js1_hikari_oto_chikara', 'atsuryoku', '圧力',       1),
  ('science_js1_hikari_oto_chikara', 'keisan',    '計算特集',   1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
