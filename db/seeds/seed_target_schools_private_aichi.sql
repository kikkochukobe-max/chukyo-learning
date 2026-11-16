-- ============================================================
-- 愛知県の私立高校（名古屋地区・尾張地区・三河地区）を志望校マスターに地区つきで登録する
-- Heteml(MySQL)。phpMyAdmin でDBを選び「SQL」にこの中身を貼って「実行」。
-- ※先に db/migrations/migrate_target_school_region.sql（region 列）を流しておくこと。
-- ※何度流しても増えない（同じ学校があれば地区と並び順を付け直すだけ）。
--
-- 56校（nagoya=28 / owari=16 / mikawa=12）。南山は男子部・女子部の2校に分けた。
-- 並び順 sort_order は 名古屋=101〜 / 尾張=201〜 / 三河=301〜（元の一覧の並び＝ほぼ五十音順）。
-- 「同じ学校」の判定は、名前から「高等学校」「高校」と空白を除いて比べる
--   （例: すでに「東邦高校」があれば、新しく足さずにその行へ地区を付ける）。
--   「愛工大名電」「中京大中京」のような略称は同じ学校と判定できないので、
--   流したあと admin.php の「志望校」タブで二重になった方を「無効」にすること。
-- ============================================================

-- 1) すでに登録されている学校に、地区と並び順を付ける
UPDATE target_schools ts
JOIN (
  SELECT '愛知高等学校' AS name, 'nagoya' AS region, 101 AS sort_order
  UNION ALL SELECT '愛知工業大学名電高等学校', 'nagoya', 102
  UNION ALL SELECT '愛知淑徳高等学校', 'nagoya', 103
  UNION ALL SELECT '愛知みずほ大学瑞穂高等学校', 'nagoya', 104
  UNION ALL SELECT '桜花学園高等学校', 'nagoya', 105
  UNION ALL SELECT '菊華高等学校', 'nagoya', 106
  UNION ALL SELECT '享栄高等学校', 'nagoya', 107
  UNION ALL SELECT '金城学院高等学校', 'nagoya', 108
  UNION ALL SELECT '啓明学館高等学校', 'nagoya', 109
  UNION ALL SELECT '至学館高等学校', 'nagoya', 110
  UNION ALL SELECT '椙山女学園高等学校', 'nagoya', 111
  UNION ALL SELECT '大同大学大同高等学校', 'nagoya', 112
  UNION ALL SELECT '中京大学附属中京高等学校', 'nagoya', 113
  UNION ALL SELECT '東海高等学校', 'nagoya', 114
  UNION ALL SELECT '東海学園高等学校', 'nagoya', 115
  UNION ALL SELECT '東邦高等学校', 'nagoya', 116
  UNION ALL SELECT '同朋高等学校', 'nagoya', 117
  UNION ALL SELECT '名古屋高等学校', 'nagoya', 118
  UNION ALL SELECT '名古屋葵大学高等学校', 'nagoya', 119
  UNION ALL SELECT '名古屋大谷高等学校', 'nagoya', 120
  UNION ALL SELECT '名古屋経済大学市邨高等学校', 'nagoya', 121
  UNION ALL SELECT '名古屋経済大学高蔵高等学校', 'nagoya', 122
  UNION ALL SELECT '名古屋工業高等学校', 'nagoya', 123
  UNION ALL SELECT '名古屋国際高等学校', 'nagoya', 124
  UNION ALL SELECT '名古屋たちばな高等学校', 'nagoya', 125
  UNION ALL SELECT '南山高等学校男子部', 'nagoya', 126
  UNION ALL SELECT '南山高等学校女子部', 'nagoya', 127
  UNION ALL SELECT '名城大学附属高等学校', 'nagoya', 128
  UNION ALL SELECT '愛知啓成高等学校', 'owari', 201
  UNION ALL SELECT '愛知黎明高等学校', 'owari', 202
  UNION ALL SELECT '栄徳高等学校', 'owari', 203
  UNION ALL SELECT '国際高等学校', 'owari', 204
  UNION ALL SELECT '修文学院高等学校', 'owari', 205
  UNION ALL SELECT '聖カピタニオ女子高等学校', 'owari', 206
  UNION ALL SELECT '星城高等学校', 'owari', 207
  UNION ALL SELECT '誠信高等学校', 'owari', 208
  UNION ALL SELECT '清林館高等学校', 'owari', 209
  UNION ALL SELECT '聖霊高等学校', 'owari', 210
  UNION ALL SELECT '大成高等学校', 'owari', 211
  UNION ALL SELECT '滝高等学校', 'owari', 212
  UNION ALL SELECT '中部大学第一高等学校', 'owari', 213
  UNION ALL SELECT '中部大学春日丘高等学校', 'owari', 214
  UNION ALL SELECT '日本福祉大学付属高等学校', 'owari', 215
  UNION ALL SELECT '誉高等学校', 'owari', 216
  UNION ALL SELECT '愛知産業大学三河高等学校', 'mikawa', 301
  UNION ALL SELECT '安城学園高等学校', 'mikawa', 302
  UNION ALL SELECT '岡崎城西高等学校', 'mikawa', 303
  UNION ALL SELECT '杜若高等学校', 'mikawa', 304
  UNION ALL SELECT '豊田大谷高等学校', 'mikawa', 305
  UNION ALL SELECT '人間環境大学附属岡崎高等学校', 'mikawa', 306
  UNION ALL SELECT '光ヶ丘女子高等学校', 'mikawa', 307
  UNION ALL SELECT '桜丘高等学校', 'mikawa', 308
  UNION ALL SELECT '黄柳野高等学校', 'mikawa', 309
  UNION ALL SELECT '豊川高等学校', 'mikawa', 310
  UNION ALL SELECT '豊橋中央高等学校', 'mikawa', 311
  UNION ALL SELECT '藤ノ花女子高等学校', 'mikawa', 312
) t ON REPLACE(REPLACE(REPLACE(REPLACE(ts.name, '高等学校', ''), '高校', ''), ' ', ''), '　', '') = CONVERT(REPLACE(REPLACE(REPLACE(REPLACE(t.name, '高等学校', ''), '高校', ''), ' ', ''), '　', '') USING utf8mb4) COLLATE utf8mb4_unicode_ci
SET ts.region = t.region, ts.sort_order = t.sort_order
WHERE ts.kind = 'private';

-- 2) まだ無い学校だけ追加する
INSERT INTO target_schools (name, kind, region, sort_order)
SELECT t.name, 'private', t.region, t.sort_order
FROM (
  SELECT '愛知高等学校' AS name, 'nagoya' AS region, 101 AS sort_order
  UNION ALL SELECT '愛知工業大学名電高等学校', 'nagoya', 102
  UNION ALL SELECT '愛知淑徳高等学校', 'nagoya', 103
  UNION ALL SELECT '愛知みずほ大学瑞穂高等学校', 'nagoya', 104
  UNION ALL SELECT '桜花学園高等学校', 'nagoya', 105
  UNION ALL SELECT '菊華高等学校', 'nagoya', 106
  UNION ALL SELECT '享栄高等学校', 'nagoya', 107
  UNION ALL SELECT '金城学院高等学校', 'nagoya', 108
  UNION ALL SELECT '啓明学館高等学校', 'nagoya', 109
  UNION ALL SELECT '至学館高等学校', 'nagoya', 110
  UNION ALL SELECT '椙山女学園高等学校', 'nagoya', 111
  UNION ALL SELECT '大同大学大同高等学校', 'nagoya', 112
  UNION ALL SELECT '中京大学附属中京高等学校', 'nagoya', 113
  UNION ALL SELECT '東海高等学校', 'nagoya', 114
  UNION ALL SELECT '東海学園高等学校', 'nagoya', 115
  UNION ALL SELECT '東邦高等学校', 'nagoya', 116
  UNION ALL SELECT '同朋高等学校', 'nagoya', 117
  UNION ALL SELECT '名古屋高等学校', 'nagoya', 118
  UNION ALL SELECT '名古屋葵大学高等学校', 'nagoya', 119
  UNION ALL SELECT '名古屋大谷高等学校', 'nagoya', 120
  UNION ALL SELECT '名古屋経済大学市邨高等学校', 'nagoya', 121
  UNION ALL SELECT '名古屋経済大学高蔵高等学校', 'nagoya', 122
  UNION ALL SELECT '名古屋工業高等学校', 'nagoya', 123
  UNION ALL SELECT '名古屋国際高等学校', 'nagoya', 124
  UNION ALL SELECT '名古屋たちばな高等学校', 'nagoya', 125
  UNION ALL SELECT '南山高等学校男子部', 'nagoya', 126
  UNION ALL SELECT '南山高等学校女子部', 'nagoya', 127
  UNION ALL SELECT '名城大学附属高等学校', 'nagoya', 128
  UNION ALL SELECT '愛知啓成高等学校', 'owari', 201
  UNION ALL SELECT '愛知黎明高等学校', 'owari', 202
  UNION ALL SELECT '栄徳高等学校', 'owari', 203
  UNION ALL SELECT '国際高等学校', 'owari', 204
  UNION ALL SELECT '修文学院高等学校', 'owari', 205
  UNION ALL SELECT '聖カピタニオ女子高等学校', 'owari', 206
  UNION ALL SELECT '星城高等学校', 'owari', 207
  UNION ALL SELECT '誠信高等学校', 'owari', 208
  UNION ALL SELECT '清林館高等学校', 'owari', 209
  UNION ALL SELECT '聖霊高等学校', 'owari', 210
  UNION ALL SELECT '大成高等学校', 'owari', 211
  UNION ALL SELECT '滝高等学校', 'owari', 212
  UNION ALL SELECT '中部大学第一高等学校', 'owari', 213
  UNION ALL SELECT '中部大学春日丘高等学校', 'owari', 214
  UNION ALL SELECT '日本福祉大学付属高等学校', 'owari', 215
  UNION ALL SELECT '誉高等学校', 'owari', 216
  UNION ALL SELECT '愛知産業大学三河高等学校', 'mikawa', 301
  UNION ALL SELECT '安城学園高等学校', 'mikawa', 302
  UNION ALL SELECT '岡崎城西高等学校', 'mikawa', 303
  UNION ALL SELECT '杜若高等学校', 'mikawa', 304
  UNION ALL SELECT '豊田大谷高等学校', 'mikawa', 305
  UNION ALL SELECT '人間環境大学附属岡崎高等学校', 'mikawa', 306
  UNION ALL SELECT '光ヶ丘女子高等学校', 'mikawa', 307
  UNION ALL SELECT '桜丘高等学校', 'mikawa', 308
  UNION ALL SELECT '黄柳野高等学校', 'mikawa', 309
  UNION ALL SELECT '豊川高等学校', 'mikawa', 310
  UNION ALL SELECT '豊橋中央高等学校', 'mikawa', 311
  UNION ALL SELECT '藤ノ花女子高等学校', 'mikawa', 312
) t
WHERE NOT EXISTS (
  SELECT 1 FROM target_schools ts
  WHERE ts.kind = 'private' AND REPLACE(REPLACE(REPLACE(REPLACE(ts.name, '高等学校', ''), '高校', ''), ' ', ''), '　', '') = CONVERT(REPLACE(REPLACE(REPLACE(REPLACE(t.name, '高等学校', ''), '高校', ''), ' ', ''), '　', '') USING utf8mb4) COLLATE utf8mb4_unicode_ci
);
