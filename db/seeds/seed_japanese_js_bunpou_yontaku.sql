-- ------------------------------------------------------------
-- 現代文 文法四択問題（国語・中学 / unit_key = japanese_js_bunpou_yontaku）
-- question_catalog への追加シードだけを行う増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- 講師・保護者画面のラベル表示のために、この14行だけ phpMyAdmin で実行する。
-- （未登録でも save_answer.php が既定XP=1を付与するのでXP自体は動くが、
-- 　ラベルが question_key の生値のままになるため必ず登録しておく）
--
-- question_key は japanese_js_bunpou_yontaku.html の UNITS の id（文法の単元）と一致。
-- 単元別トレーニングの問題・単元ごとの文章問題・総合問題編の文章問題（関連単元 u）・
-- 全単元ミックステストのどこで解いても、同じ単元のキーで記録される。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('japanese_js_bunpou_yontaku', 'u1',  '文節と単語',                     1),
  ('japanese_js_bunpou_yontaku', 'u2',  '文の成分・主語と述語',           1),
  ('japanese_js_bunpou_yontaku', 'u3',  '修飾語・接続語・独立語',         1),
  ('japanese_js_bunpou_yontaku', 'u4',  '連文節・並立の関係・補助の関係', 1),
  ('japanese_js_bunpou_yontaku', 'u5',  '文をつなぐ言葉',                 1),
  ('japanese_js_bunpou_yontaku', 'u6',  '自立語と付属語・活用・品詞',     1),
  ('japanese_js_bunpou_yontaku', 'u7',  '名詞',                           1),
  ('japanese_js_bunpou_yontaku', 'u8',  '副詞・連体詞',                   1),
  ('japanese_js_bunpou_yontaku', 'u9',  '接続詞・感動詞',                 1),
  ('japanese_js_bunpou_yontaku', 'u10', '動詞',                           1),
  ('japanese_js_bunpou_yontaku', 'u11', '形容詞・形容動詞',               1),
  ('japanese_js_bunpou_yontaku', 'u12', '助詞',                           1),
  ('japanese_js_bunpou_yontaku', 'u13', '助動詞',                         1),
  ('japanese_js_bunpou_yontaku', 'u14', '敬語',                           1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
