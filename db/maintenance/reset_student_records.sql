-- ============================================================
-- 生徒1人の学習記録だけを全消去する（アカウントは残す）
--   用途: テストデータの掃除。「解答が多すぎて講師ページが読めない」時。
--   実行: phpMyAdmin の「SQL」タブに**全文まとめて**貼って実行
--         （本番へは配信しない。db/ はソース管理のみ）
--
--   ★他の生徒に使うときは、エディタで '260031' を全置換してから貼る。
--     どの文も生徒コードを直書きしているので、1文ずつバラバラに実行しても
--     正しく動く（@変数を使うと、別タブ・別接続で実行したときに
--     NULL になって「1行も消えないのにエラーも出ない」事故になるため）。
--
--   ※アカウントごと消したい場合は admin.php の「完全削除」
--     （api/delete_student.php）を使う。こちらは生徒・保護者・
--     PIN・志望校をすべて残して、記録だけを空にする。
--   ※通常の退塾は set_active.php の無効化（記録は残す）。
--   ※ admin.php の「記録リセット」ボタン（api/reset_student_records.php）が
--     同じ処理をする。ボタンをアップロード済みならそちらが早い。
--   ※ time_records は migrate_time_records.sql を当てた環境にしか無い。
--     「Table ... doesn't exist」で止まったらその1行を飛ばして続行する
--     （phpMyAdmin はエラーが出るとその先の文を実行しない）。
-- ============================================================

-- ------------------------------------------------------------
-- ① 消す前の確認（対象の生徒と、消える件数）
--    生徒名が出なければ生徒コードの打ち間違い。ここで止める。
-- ------------------------------------------------------------
SELECT s.student_id,
       s.student_name                                                            AS 生徒名,
       (SELECT COUNT(*) FROM answer_logs        WHERE student_id = s.student_id) AS 解答ログ,
       (SELECT COUNT(*) FROM answer_logs        WHERE student_id = s.student_id
                                                  AND is_correct = 0)            AS うち誤答,
       (SELECT COUNT(*) FROM study_sessions     WHERE student_id = s.student_id) AS 学習セッション,
       (SELECT COUNT(*) FROM retry_queue        WHERE student_id = s.student_id) AS 解き直しキュー,
       (SELECT COUNT(*) FROM xp_logs            WHERE student_id = s.student_id) AS XPログ,
       (SELECT COUNT(*) FROM time_records       WHERE student_id = s.student_id) AS タイム記録,
       (SELECT COUNT(*) FROM paper_test_results WHERE student_id = s.student_id) AS 確認テスト結果
FROM students s
WHERE s.login_id = '260031';

-- ------------------------------------------------------------
-- ② 削除本体
--    「過去のまちがい」も retry_queue と answer_logs(is_correct=0) なので
--    ここで一緒に消える（誤答だけの専用テーブルは無い）。
--    answer_logs を先に消す（study_sessions が先だと fk_al_session の
--    ON DELETE SET NULL が無駄に走るだけ）。answer_logs.retry_of には
--    外部キーが無いので自己参照で詰まることはない。
--    XPログも消す（レベルは累計XPから算出するので、ここを残すと
--    解答0なのにレベルだけ高い状態になる）。
-- ------------------------------------------------------------
DELETE FROM answer_logs        WHERE student_id = (SELECT student_id FROM students WHERE login_id = '260031');
DELETE FROM study_sessions     WHERE student_id = (SELECT student_id FROM students WHERE login_id = '260031');
DELETE FROM retry_queue        WHERE student_id = (SELECT student_id FROM students WHERE login_id = '260031');
DELETE FROM xp_logs            WHERE student_id = (SELECT student_id FROM students WHERE login_id = '260031');
DELETE FROM time_records       WHERE student_id = (SELECT student_id FROM students WHERE login_id = '260031');
DELETE FROM paper_test_results WHERE student_id = (SELECT student_id FROM students WHERE login_id = '260031');

-- ------------------------------------------------------------
-- ③ ログイン履歴も消す場合だけ、この2行の行頭 -- を外す
--    （login_logs / auth_tokens は外部キーが無く actor_type+actor_id 方式。
--      auth_tokens を消すとその生徒の自動ログインが切れて、
--      次回は生徒コード+PINの入力が1回必要になる）
-- ------------------------------------------------------------
-- DELETE FROM login_logs  WHERE actor_type = 'student' AND actor_id = (SELECT student_id FROM students WHERE login_id = '260031');
-- DELETE FROM auth_tokens WHERE actor_type = 'student' AND actor_id = (SELECT student_id FROM students WHERE login_id = '260031');

-- ------------------------------------------------------------
-- ④ 消えたことの確認（①をもう一度実行して、すべて 0 になっていればOK）
--    マイページ側の見え方:
--      「過去のまちがいを解き直す ○問」   → 消える（retry_queue の pending）
--      「今日の1問」カード                 → 出なくなる（同じ retry_queue から選ぶ）
--      解いた問題/正解/正答率/学習時間     → 0
--      Lv./XP                              → Lv.1・0XP に戻る
--    ブラウザで開いたままだと古い画面が残るので、再読み込みして確認する。
-- ------------------------------------------------------------

-- ------------------------------------------------------------
-- 補足: question_catalog の stat_total / stat_correct（動的XPの母数）は
--   api/update_xp.php が answer_logs から毎回まるごと再集計するため、
--   ここでの削除分は次回の日次バッチで自動的に反映される（手当て不要）。
-- ------------------------------------------------------------
