-- ------------------------------------------------------------
-- 日本の主要都市マスター（社会・中学／地理 日本の諸地域 / unit_key = social_js_shuyou_toshi）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この6行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
--  　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は social_js_shuyou_toshi.html の QKEY（都市がある地方）と一致。
-- 地方の区分は都道府県・県庁所在地マスターと同じ（三重県は近畿）。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('social_js_shuyou_toshi', 'hokkaido_tohoku', '北海道・東北の都市', 1),
  ('social_js_shuyou_toshi', 'kanto',           '関東の都市',         1),
  ('social_js_shuyou_toshi', 'chubu',           '中部の都市',         1),
  ('social_js_shuyou_toshi', 'kinki',           '近畿の都市',         1),
  ('social_js_shuyou_toshi', 'chugoku_shikoku', '中国・四国の都市',   1),
  ('social_js_shuyou_toshi', 'kyushu_okinawa',  '九州・沖縄の都市',   1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
