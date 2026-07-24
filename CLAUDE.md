# CLAUDE.md

中京個別指導学院（chukyokobetsu.com / Heteml共有サーバー）の学習ツール群リポジトリ。

## 構成（Hetemlの公開フォルダと1:1で対応）

```
assets/                共通モジュール（全ツールがscriptタグで読み込む）
  divp-header.js        共通ヘッダー（ロゴ・校名・Zen Maru Gothic）
  divp-correct.js        小学生用 正解エフェクト（星＋「正解！」演出）
  divp-correct-jh.js     中学・高校用 正解スタンプエフェクト（es用Divp.correctとは別物）
  print-watermark.js     印刷シート用透かしモジュール
  .htaccess              ETagベースのキャッシュ制御（URL固定で更新を届ける）
learning/
  index.php              学習ツール目次ページ（配下の*.htmlを毎リクエスト自動スキャン。
                         titleタグから表示名取得。ファイルをアップするだけで一覧に反映）
  math/                  教科ごとのフォルダ（学校種・学年はファイル名側で表現）
  english/
  science/
  japanese/
  allgrade/
  game/
db/                    DB用SQL（phpMyAdminで手動実行。本番へは配信しない）
  schema_full.sql       マスタ定義（16テーブル・検証済み）
  migrations/           適用済みスキーマ変更の履歴
  seeds/                question_catalog等のシード（ミラー環境の再構築で再利用）
```

ファイル名規則: `教科_校種学年_単元(_製作者コード).html`
例: `math_js3_heihokonmaster.html` = 数学・中学3年・平方根

## デプロイ

Gitはソース管理のみ。本番反映は変更ファイルをHetemlへFTPアップロード（別工程）。
`assets/` 配下はURLを固定したまま上書きするだけで、読み込んでいる全ツールに反映される。

## 持ち込み厳禁

- `api/config.php`（DB接続情報）は `.gitignore` 済み。絶対にコミットしない。

---

# 学習記録システム DB設計〜運用（進行中プロジェクト）

生徒の学習記録機能を追加するプロジェクト。設計詳細は `db/schema_full.sql`（16テーブル・検証済み）を参照。
マイページのデザインは実装済みの `mypage.php` が正（元モックの mypage_mock.html は実装完了に伴い削除済み）。
詳細な引き継ぎ経緯は `docs/HANDOFF.md` にも記載。

## 前提知識

- 学習ツールは単一HTMLファイル群（85本以上）。共通基盤は divp-core
  （`assets/divp-core.js` / `divp-header.js` / `divp-correct.js`）
- `unit_key` = コンテンツの論理ID（例 `math_js3_heihokon`）。ファイルパスや
  製作者コードから自動生成してはならない（進捗が分断されるため）
- `question_key` = ツール内の問題タイプ（モード名をそのまま使う）
- 物理教室は8つ: 焼山・吉根・長久手・神丘・高針台・一社・貴船・有松
  （植田・志段味はSEO用地名。表記は「神丘」が正、「神岡」は誤り）
- 正解エフェクトは es（小学生）ツールのみ `divp-correct.js`、jh は
  `divp-correct-jh.js`（スタンプ）。js/jh に星エフェクトは付けない

## スキーマ（db/schema_full.sql / 16テーブル）

| 区分 | テーブル | 要点 |
|---|---|---|
| 基盤 | classrooms | 8教室シード済み |
| 基盤 | teachers | role: super_admin(統括・全教室) / classroom_admin(自教室の生徒登録可) / teacher(閲覧のみ) |
| 基盤 | teacher_classrooms | 兼任講師の多対多 |
| 基盤 | students | login_id=生徒コード, password_hash=4桁PINのhash, created_by=登録者監査 |
| 基盤 | devices | UUIDクッキー divp_device(httponly,1年)で端末識別。labelは管理側で後付け |
| 追跡 | login_logs | actor_type(student/teacher/guardian)で3ロール共用 |
| 追跡 | study_sessions | 学習時間。ended_at確定時にduration_sec算出。device_id/ip/ua記録 |
| 追跡 | answer_logs | 1問=1行。question_key(種類別集計の軸), question_params(JSON), params_hash, student_answer(誤解答閲覧用), retry_of |
| 追跡 | retry_queue | 誤答をキュー化。correct_streak 2連続正解で mastered |
| 保護者 | guardians / guardian_students | 兄弟対応の多対多。**専用ログイン・保護者ページ(/guardian.php)実装済み**。**保護者は自前のパスワードを持たず、ひもづくお子さまの生徒PIN(4桁)でログインする**(auth.php が guardian の入力PINを生徒側 password_hash と照合。兄弟のうち誰かのPINが合えば成立)。guardians.password_hash / must_change_password 列は残置だが未使用(登録時は password_hash に未使用ダミーhashを入れるだけ。must_change_password 列は後付けマイグレーションで未適用環境がありうるため INSERT で触れない)。change_password / reset_guardian_password は保護者対象外(前者は講師専用、後者は廃止=410) |
| 確認テスト | paper_tests / paper_test_results | アナログ確認テスト。attempt_no=1が本試、2以降が追試。合格率・追試数は集計で算出 |
| XP | xp_events / xp_logs | イベント期間の倍率。**XPは付与時点で確定値を記録**(再計算禁止)。レベルはカラムに持たず累計XPから式で算出: floor(sqrt(totalXp/100))+1 |
| カタログ | question_catalog | (unit_key,question_key)→日本語ラベル+base_xp。平方根8モードシード済み。当面は難易度を分けずbase_xp=1で統一 |

## 主要な設計判断（確定事項）

1. **正誤判定はツール側の責務、判定後の処理を共通化**。divp-core に
   `Divp.answer(ok, {question_key, question_params, question_text, correct_answer, wrong_answer})` を新設し、
   中で log送信 → 正解エフェクト(校種判定) → retry_queue 連携まで一括。
   ツールへの組み込みは「判定関数の直後に1行挿入」のみ。
   未ログイン・ローカル起動時は黙ってスキップする guard を内蔵
   （safeDivpCorrect() と同じ思想。未組み込みツールと混在しても壊れない）。
   ただし**サーバー応答あり＋未ログイン(401)の時だけ**、そのタブで初回1問目に一度だけ
   「ログインすると記録が残る」案内バナーを出す（sessionStorage `divp_login_nudge_shown`
   で抑制、12秒で自動消灯、API未到達=catch時は出さない。しつこくしない思想）。
2. **問題は「機械用」と「人間用」の二重表現で保存する**。
   question_params(JSON) = 再生成用（ランダム問題は生成パラメータ 例 {n:72}、
   固定問題は問題ID 例 {qid:12}）。question_text / correct_answer(文字列) =
   講師・保護者画面の表示用。4値とも出題時点でツールの手元に揃っている変数を
   そのまま渡すだけ。**PHP側でparamsから問題文を復元する実装は禁止**
   （サーバーにツールと同じ数学ロジックを持ち込まない）。
   params_hash が同一問題の判定キーで、retry_queue の再出題と
   2連続正解マスター判定はこのハッシュの同一性で成立する
3. **question_key はツールのモード変数をそのまま使う**。命名ブレ防止のため
   question_catalog が台帳を兼ね、カタログに無い question_key が飛んできたら
   save_answer.php が警告ログを出す
4. **教室は常に students 経由でJOIN**（ログ側に classroom_id を非正規化しない）
5. **保護者は当面、生徒アカウントのマイページを親子共用**。マイページは
   保護者向け仕様（学習時間・種類別の解答数/正解数/正答率）で作り、
   誤解答詳細・端末情報は出さない（それらは講師画面専用）。
   guardian 専用ログイン＋保護者ページ(/guardian.php)はリリース済み
   （子ども全員のサマリー表示。誤解答詳細・端末情報は出さない）
6. **ランキングは全てスキーマ変更なしの集計**。権限フィルタ
   （super_admin=全教室 / それ以外=teacher_classrooms でJOIN）を共通関数化
7. **正答率ランキングには最低問題数の足切りを入れる**（3問全問正解が1位になる事故防止）
8. XPの不正対策方針: 正解のみ付与、同一question_keyの大量連打は減衰、1日上限
9. **ログインは divp-header のログイン窓に一本化する**（実装済み）。各ツールに
   ログイン画面は作らない。ヘッダーJSが読み込み時に whoami.php を fetch して
   ログイン状態を取得し、未ログインなら生徒コード+PIN入力窓、ログイン済みなら
   「◯◯さん／ログアウト」を描画する。ログインは fetch で auth.php にPOST、
   維持はPHPセッション(クッキー、同一ドメイン)。入力欄は inputmode="numeric"、
   PINは type="password"。**auth.php にPIN試行制限実装済み**
   (同一アカウントで直近10分に5回失敗→10分ロック=HTTP 429。
   失敗も login_logs に success=0 で記録)。
   **自動ログイン実装済み**: 生徒ログイン時に divp_remember クッキー
   (selector:validator方式、180日) を発行し auth_tokens テーブルに保存。
   セッション切れ時は whoami/require_login が自動復元。ログアウトで失効。
   LINE内ブラウザとSafari等はクッキーが別＝ブラウザごとに初回1回ログイン（仕様）

## ロードマップ

1. ~~**DB構築**: db/schema_full.sql を phpMyAdmin で実行~~ **→ 完了（Hetemlに適用済み）**
   → 次は setup_first_admin.php で統括管理者作成(実行後削除) →
   register_teacher.php / register_student.php でアカウント発行
2. **API疎通（最優先・詰まりやすい）**: auth.php / start_session.php /
   save_answer.php / end_session.php を Heteml に置き、手動POSTで
   answer_logs に1行入るまで確認。save_answer.php は
   question_catalog をJOINして base_xp×イベント倍率で xp_logs にも書く
3. ~~**Divp.answer() 実装**（divp-core.js に追加）~~ **→ 完了**
4. ~~**平方根マスター組み込み**: math_js3_heihokon の8モード
   （truefalse/simplify/addsub/muldiv/mixed/approx/intval/subst）の判定直後に Divp.answer() 挿入~~ **→ 完了**
   （intvalは2種類の出題形式(gradeIntMulti/selectIntAnswer)があるが、question_keyは両方とも`intval`に統一）
5. ~~**マイページ1枚**: 生徒ログイン→学習時間・種類別集計の表示。
   ラベルは question_catalog から取得。親子で見る前提のデザイン~~ **→ 完了（/mypage.php）**
   未ログイン時はdivp-headerのログイン窓を出す。期間タブ（今週/先週/今月/全期間）で
   がんばりカード・単元カルテの集計範囲を切替（足あとは週表示時のみ）。
   レベル/XPは常に全期間累計。花丸SVGは削除済み。
   解き直しボタン（pending>0の時のみ表示）→ /retry.php: pending問題の復習リスト
   （問題文はKaTeXで整形、正解は見せない）+「?retry=1」付きツールリンク。
   **単元名・ツールURLの台帳は api/units.php**（新単元を組み込んだら1行追加）。
   **同一問題の再出題は実装済み**: question_params に問題の完全な生成情報を保存し、
   ツールを ?retry=1 で開くと api/list_retries.php から pending を取得して
   モード別の既存リトライキュー(_xxRetryQueue)に流し込み全く同じ問題を再出題。
   同じparams_hashに2連続正解でmastered。
   ※question_params のJSON形式を変えると既存pending行のハッシュと合わなくなる点に注意
5a. **学習時間は活動ベースの積算方式**: 壁時計(開始〜終了)ではなく、
   解答(save_answer)・1分ごとのハートビート(api/heartbeat.php、タブ表示中のみ)・
   終了(end_session)のたびに「前回活動からの経過(上限5分)」をduration_secに加算。
   study_sessions.ended_at は「最後に活動した時刻」の意味（NULLチェック廃止）。
   放置時間は最大5分しか数えず、ページ強制終了でも誤差は1分強。
   講師ページのセッション一覧は解答0件かつ1分未満の空セッションを非表示
5b. **講師確認ページ → 完了（/teacher.php）**: 講師ログインフォーム内蔵。
   生徒一覧（期間タブ+教室タブ+教科タブ、学習時間/解答数/正答率/解き直し数/最終学習）→
   生徒名クリックで詳細（教科別にグループ化した単元カルテ・直近の誤答30件・学習セッション20件+端末）。
   教科は unit_key の先頭要素（math/english/…）で判定。
   ランキングビュー（?view=ranking）: 解答数/正答率/XPの3表、教室チェックボックスで
   教室別・複数教室混合のどちらも可。権限: super_admin=全教室 / それ以外=teacher_classroomsの担当教室のみ。
   基調色は藍(#2C5F8A)。誤答詳細・端末情報はこのページのみ（マイページには出さない）
5c. **ランキング → 完了**: 共通集計は api/ranking.php（正答率はRANK_MIN_SOLVED=10問の足切り、
   同値同順位、実績0は非掲載）。マイページには教室内の自分の順位のみ表示。
   教室混合の順位は api/ranking_events.php の期間台帳（例: 夏休み）に載っている間だけ
   マイページに出る（集計もイベント期間の実績、from/toは両端を含む）。
   イベント期間中は teacher.php のランキングビューにもイベント名のタブ（金色）が出て、
   台帳で決めた教室混合を**権限に関係なく全講師が見られる**（生徒に見せている順位と同じ集計。
   担当外教室の生徒は名前のみ表示で詳細リンクなし）。台帳の行に classroom_ids => [1,3] を
   付けるとその教室だけの混合（省略時=全教室混合。絞る場合はマイページの
   「ぜんきょうしつでの じゅんい」文言に注意）
5d. **アカウント管理ページ → 完了（/admin.php）**: 生徒登録/生徒一括登録(Excel貼り付け・CSV)/
   保護者登録/講師登録(統括のみ)/登録一覧 をタブで切替。
   **生徒登録（単独・一括とも）で保護者アカウントを同一トランザクションで自動発行**
   （ID=g+生徒コード、表示名=生徒名+保護者様。**保護者はお子さまと同じPINでログイン**するため
   パスワードは発行しない。register_student.php が guardian_login_id を返す）。
   登録後にご家庭向け案内文（生徒コード/PIN・保護者ID+URL入り。保護者のパスワード欄は
   「お子さまのPIN」と表記）を生成・コピーできる（単独=自動表示、一括=「案内文をまとめて生成」ボタン）。
   保護者は氏名もパスワードも入力しない（表示名は自動。
   代表の子を api/update_student.php で改名すると
   guardians.guardian_name も連動更新＝リアルタイム追従）。
   保護者登録タブは保護者未発行の既存生徒への後追い発行用
   （単独=register_guardian.php / 一括=bulk_register_guardians.php、1行=1家庭・
   6桁の数字トークン=生徒コード、それ以外の文字は無視。PIN自動生成で結果一覧にのみ表示
   →Excelコピー配布・案内文生成も可。
   兄弟の誰かが既に保護者に紐づく行は already_has_guardian で弾く）。
   兄弟の後付けは api/add_guardian_student.php（保護者ID g〜 または既存の子の生徒コードで指定、
   登録一覧の保護者行「兄弟追加」ボタンからフォームに流し込み可。
   別々に登録済みの兄弟の統合にも対応: 別保護者に紐づく子は needs_move(409)→画面で確認→
   move=true 再送で付け替え、空になった元の保護者は自動 is_active=0）。登録一覧は開くたび再取得、
   同一ページ内で登録・変更した行は金色+NEWで先頭表示、教室フィルタあり(生徒)。
   削除は is_active 切替（api/set_active.php、講師は統括のみ・自分自身は不可）。
   生徒の完全物理削除は統括のみ（api/delete_student.php、生徒コード打ち直し確認つき。
   CASCADE+login_logs/auth_tokens明示削除。子がいなくなった保護者のみ道連れ、兄弟がいれば保護者は残る）。
   保護者の完全物理削除も統括のみ（api/delete_guardian.php。生徒・学習記録は残る。
   無効化と違いIDが空くので同じ代表の子で登録し直せる＝テスト掃除用）
5e. **講師パスワード → 完了**: password.php + api/change_password.php（現PW照合必須・**講師専用**）。
   must_change_password=1 の講師は teacher.php/admin.php から password.php へ強制リダイレクト。
   統括は登録一覧の「PW初期化」で仮PWを自動生成発行（api/reset_teacher_password.php、自分自身は不可）。
   **保護者はお子さまのPINでログインする方式のためパスワード変更・初期化は無い**
   （change_password は講師専用に変更、reset_guardian_password.php は廃止=410、
   admin.php の保護者行「PW初期化」ボタンも撤去済み。保護者がログインできない＝
   お子さまのPINが不明な場合は生徒PIN側で対応する）
5f. **TZ診断**: api/timecheck.php（講師ログイン必須）で php_now/db_now/session_tz を確認できる。
   2026-07-03 に旧db.phpで入ったUTC行(+9時間ずれ)は修正済み
6. **実機で1周**: ログイン→解く→answer_logs→マイページ反映まで確認
7. 平方根が1周通っていれば「計算どぅする？」
   (math_es_keisan_dousuru、13カテゴリのIDをquestion_keyに)も横展開

## 検証済み事項

db/schema_full.sql は MariaDB 10.11 で実行検証済み: 16テーブル作成、
FK整合、7教室+カタログ5行のシード、実データINSERT一式、
種類別正答率の GROUP BY 集計、確認テストの一発合格率・追試数集計まで動作確認済み。

## 種類別集計の基本クエリ（マイページ用）

```sql
SELECT COALESCE(qc.label, al.question_key) AS label,
       COUNT(*) AS solved, SUM(al.is_correct) AS correct,
       ROUND(100*SUM(al.is_correct)/COUNT(*),1) AS rate
FROM answer_logs al
LEFT JOIN question_catalog qc
  ON qc.unit_key = al.unit_key AND qc.question_key = al.question_key
WHERE al.student_id = ? AND al.unit_key = ?
GROUP BY al.question_key;
```

## デザイン仕様（マイページ・管理画面 共通）

実装済みの `mypage.php` が生徒マイページの確定デザイン
（元になった mypage_mock.html は削除済み。見た目を変える時は mypage.php を直接編集する）。

コンセプト: 塾の「丸つけ」文化。方眼ノートの紙面 + 朱色の採点ペン + 花丸。
管理画面然とさせない「がんばりの記録帳」。

デザイントークン（CSS変数として定義済み）:
- 紙 #FBFAF6 / 方眼 #ECE9E0 / 墨 #33312B / 薄墨 #8B877C
- 朱色 #C73E2E（丸つけ・アクセント。ロゴの実色と合わせて微調整可）
- 藍 #2C5F8A（リンク・講師画面の基調色）/ 金 #C9A227（XP・レベル）
- フォント: 見出し = Zen Maru Gothic（丸ゴ）/ 本文 = Zen Kaku Gothic New
  **本番はHetemlに自前ホスティング+サブセット必須**
  （mypage.php / teacher.php / admin.php / password.php は現状CDN参照のままなので本番運用までに差し替える）
- ロゴ: `https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png`
  （本サイト共通ロゴ。同一ドメインなので相対パス化してもよい）

デザインルール:
- 正答率90%以上のバーには「◎」の丸つけマークを付ける
- 60%未満は橙 #D89A45（「がんばりどころ」。赤で責めない）
- 保護者と一緒に見る前提: 誤解答の詳細・端末情報はマイページに出さない
- 講師画面も同じトークンで作るが、基調を朱→藍に反転し、
  情報密度を上げてよい（テーブル可）。世界観は共通、役割で色が変わる

## 注意（Heteml固有）

- Heteml はリモートMySQL接続不可 → ローカル開発はMySQLミラーで
- HetemlのMySQL/PHPはUTC → `api/db.php` で `date_default_timezone_set('Asia/Tokyo')` と
  接続時 `SET time_zone = '+09:00'` を必ず通す（NOW()/CURDATE()が9時間ずれるため）
- .html はPHPを実行しない → ヘッダーはJS注入方式（divp-header.js）
- キャッシュは ?v= ではなく .htaccess の ETag 固定URL方式
- フォントはCDN不可、自前ホスティング必須
- `config.php`（DB接続情報）は第一候補として公開ルート直下（`learning/`と同階層）
  に配置する。`api/db.php` の `config_path()` が自動でそちらを優先的に見に行く。
  置けない場合のみ `api/config.php` に置く（`api/.htaccess` で直アクセス拒否済み）
- 公開ルート直下の [.htaccess](.htaccess) は保守会社管理の本番ファイル（WordPress /
  Wordfence WAF / SiteGuard 設定込み）を丸ごと反映済み。末尾の
  `BEGIN/END chukyo-learning config.php protection` が今回追加した分。
  本番へは既存ファイルを日付付きでバックアップ（保守会社の慣習: `.htaccess_20220707`
  のような命名）してから、このファイルで上書きアップロードする。
  **WordPressの`BEGIN/END WordPress`ブロックはWP側で自動再生成されるため、
  以後もし本番側でその区間が変わっていたら、この git 側のファイルも同期し直すこと**
  （`api/.htaccess` はapi/が新規フォルダで衝突しないため通常通りアップロード可）

## 生徒コード採番ルール（確定）

6桁の数字のみ: **[入塾年度下2桁][全教室通し連番4桁]**（例: 2026年度38人目 → 260038）
- register_student.php が自動採番する（同年度の最大連番+1。同時登録の重複はDBのUNIQUE制約とリトライで担保）
- 教室番号・学年はコードに入れない（転籍・進級で変わる情報のため）。教室はDBの所属欄が正
- コードは卒塾まで不変。数字のみなのでテンキー入力で完結（PIN 4桁も数字）

**保護者ログインID（確定）**: `g` + 代表のお子さま（登録時に最初に指定した生徒）の生徒コード（例 260038 → `g260038`）。
register_guardian.php が student_codes[0] から自動生成。兄弟は guardian_students で複数ひもづけ（IDは1つ）。
**パスワードは持たず、ひもづくお子さまの生徒PIN(4桁)でログインする**（兄弟がいれば誰のPINでも可。
生徒ログインと同じPINで、専用パスワードは無い）。同じ代表の子で二重登録すると login_id 衝突で409（＝その家庭は登録済み）。
保護者テーブルは別なので生徒コードと文字列衝突しない（`g`接頭辞で数字の生徒コードとも明確に区別）。
