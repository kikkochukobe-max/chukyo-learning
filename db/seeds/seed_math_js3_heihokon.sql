-- ------------------------------------------------------------
-- 平方根マスター（数学・中3 / unit_key = math_js3_heihokon）
-- question_catalog への「あとから足したモード」だけの増分スクリプト。
--
-- DBは既にHetemlに構築済みなので schema_full.sql を流し直す必要はない。
-- この2行を phpMyAdmin で実行する。**API側の変更は不要**。
--
-- 未登録のままでも記録・解き直しは普通に動く（save_answer.php が
-- DEFAULT_BASE_XP=1 で付与する）が、次の3つが起きる:
--   1. 講師・保護者画面のカルテのラベルが question_key の生値（riyou / exam）になる
--   2. **難易度XPが効かない**。update_xp.php は question_catalog を
--      UPDATE ... JOIN で更新する＝カタログに無い行は current_xp も
--      stat_total/stat_correct も一切入らず、XPは1に固定されたまま
--   3. 正解1問ごとに save_answer.php が
--      「question_catalog未登録(既定XPで付与)」を error_log に書く（ログが膨らむ）
--
-- riyou … 「1辺 s cm の正方形の紙を、対角線の交点と頂点が重なるように
--          つないでかざりをつくる」型の文章題（平方根の利用）。
--          対角線・関係の式・k枚の長さ・n枚をnで表す・近似値・
--          上限未満で最大の枚数・何本ぶんの総枚数 を1つの question_key に集約する
--          （出し分けは question_params.t 側に持つ＝カルテは1行で読める）。
-- exam  … 入試問題モード。schema_full.sql の初期シードに入っていなかった分。
-- ------------------------------------------------------------
INSERT INTO question_catalog (unit_key, question_key, label, base_xp) VALUES
  ('math_js3_heihokon', 'riyou', '平方根の利用', 1),
  ('math_js3_heihokon', 'exam',  '入試問題',     1)
ON DUPLICATE KEY UPDATE label = VALUES(label), base_xp = VALUES(base_xp);
