# CLAUDE.md

中京個別指導学院（chukyokobetsu.com / Heteml共有サーバー）の学習ツール群リポジトリ。

## 構成（Hetemlの公開フォルダと1:1で対応）

```
assets/                共通モジュール（全ツールがscriptタグで読み込む）
  divp-header.js        共通ヘッダー（ロゴ・校名・Zen Maru Gothic）
                        **@media print で自分を display:none にする**（紙に共通ヘッダーは出さない）。
                        ツール側の印刷CSSが `.wrap`/`.app` だけを消していると、
                        兄弟として注入されたヘッダーが紙の1枚目上部に残るため、
                        モジュール側で一律に消している。ツール側にも同じ1行を入れてあるので
                        （HTMLだけ先に上げても紙に出ない）、印刷シートを新設するときは
                        `.divp-header{display:none!important}` を印刷CSSに書くこと。
                        ⚠ **script タグは必ず `<body>` 開始直後に置く**。ヘッダーは
                        `document.currentScript` の位置に挿すので、`<head>` に書くと
                        （defer 付きでも）`<head>` の中に入って**画面に一切出ない**。
                        エラーが出ないので「ヘッダーだけ付かない」という気づきにくい
                        壊れ方をする（4ツールで発生: romaji_master /
                        japanese_esjs_goi_crossword / math_js1_mojishiki_keisan /
                        social_es4_todofuken）。2026-08にモジュール側で受け止める
                        よう修正済み（body の中から読まれた時だけその場に挿し、
                        それ以外は body 先頭へ）＝assets を上げれば置き場所を
                        間違えているツールも直る。とはいえ新規ツールは body 直後に置くこと。
  divp-correct.js        小学生用 正解エフェクト（星＋「正解！」演出）
  divp-correct-firework.js  小学生用 正解エフェクト「花火」（絵文字が360度に弾けて落下＋
                         「せいかい！」が1文字ずつ散って戻る＋落ちた記号が画面下に積もる）。
                         divp-correct.js とは排他で、<body data-effect="firework"> の
                         ときだけ Divp.correct を差し替える。単体でも
                         window.DivpFirework() で呼べる（既定値は読み込み前に
                         window.DIVP_FIREWORK_OPTS で上書き）。divp-core.js より後ろに置くこと。
                         ⚠️積もる山(pile)は z-index:5 の固定レイヤーで、pileRaiseUI が
                         :where(button,h1,…) を z-index:10 に持ち上げて埋没を防ぐ。
                         この :where() は詳細度0なので**セレクタから漏れた要素は
                         ツール側CSSで前面に出すこと**（div/span だけで組んだ
                         ふきだし・ヘッダーが埋もれる。DivpFirework.clearPile() で消せる）
  divp-correct-jh.js     中学・高校用 正解スタンプエフェクト（es用Divp.correctとは別物）
  divp-choice-mark.js    選択肢の「答え合わせ表示」。正解=緑 / 選んだ誤答=朱 /
                         選ばなかった正解=緑＋「正解」バッジ / 残り=薄く。
                         ⚠ divp-correct系(正解した瞬間のお祝い演出)とは別の関心事で、
                         誤答時こそ必要なもの。Divp.correct を差し替えないので併用可。
                         Divp.markChoices(list,{correct,selected,dimOthers,label})
                         または Divp.markChoice(el,"correct|answer|wrong|dim")。
                         クラス名ではなく data-divp-mark 属性で状態を持つので、
                         ツール側の既存クラス(.hit/.sel-ok/.judge-ok/.is-correct と
                         5流派に分裂している)を消さずに1本ずつ移行できる。
                         **正解の緑は #3E8E5A に統一**（既定値）。以前はツールごとに
                         #3E8E5A/#3E7A4E/#2e7d4f/#5E7B4E と4種類に散っていて
                         「大問1以外が暗い」状態になったので、原則そろえる。
                         例外は暗背景の science_js3_ionlab のみ(#7ee0a0)。
                         色は var() のフォールバックで既定値を持つ＝ツールが :root で
                         --divp-mark-ok / -ok-bg / -ok-bg-soft / -ng / -ng-bg /
                         -dim-opacity を差せば読み込み順に関係なく勝つ。
                         --divp-mark-ok-text は「正解」の文字色だけを分けたいとき用
                         (既定は枠と同色。イオンのしくみラボのような暗い背景の
                         ツールで、枠は明るい緑・文字は読める薄い色にするために使う)。
                         組み込み済み7本: 一次関数・愛知県大問1・方程式の利用・
                         連立方程式の利用・計算特集(中2)・二次方程式・イオンのしくみラボ。
                         回帰テスト tests/choice-mark.spec.js(仕様) と
                         tests/choice-mark-tools.spec.js(各ツールの配線)
                         ⚠ HTMLだけ先に上げるとツール側の採点CSSはもう無いので
                         色が一切つかない(エラーも出ない)。assets を先に上げること
  divp-result.js         「10問ごとの評価」＝RESULT画面（ランクSSS〜D＋正解数＋次の行き先）。
                         ひな型は平方根マスターの renderResult() で、そこの実装
                         （ランクの段階・文言・「10問未満はランクを付けない」判断）を
                         そのまま共通化した。平方根は同じ画面を8モード分コピーして
                         持っているので、移行すると文言修正が1箇所で済む。
                         Divp.resultInit({total,questionKey,masterLabel,onNext,
                         onRetryWrong,onModes,home,mount}) → 判定直後に
                         Divp.resultPush(ok, item) を1行。1セット終わると結果画面が出る。
                         解き直しラウンドは Divp.resultStart({total:n,retry:true})。
                         ボタンは「つぎの10問へ」「間違えた N 問を解き直す」
                         「モード選択にもどる」「ホームへ」の4つ（渡した分だけ出る）。
                         mount を省略すると画面中央のオーバーレイで出るので、
                         既存ツールにレイアウトを変えずに後付けできる。
                         連続全問正解カウントは questionKey(モード)ごとに持つ
                         （平方根はツール全体で1変数を共有しており、モードを
                         渡り歩くと連続が繋がっていた。そこだけ直してある）。
                         色は var() のフォールバックで既定値を持つ＝ツールが :root で
                         --divp-result-bg / -ink / -sub / -accent / -accent-ink /
                         -ok / -ok-bg / -ng / -ng-bg を差せば読み込み順に関係なく勝つ。
                         ランクの色(--divp-result-grade-sss 〜 -d)は既定で平方根の配色。
                         ⚠ 小学生(es)ツールは対象外。ひらがな主体の文言にするか、
                         そもそもランクを出すかが未決なので、別モジュールにする。
                         ⚠ 4点セット(記録・正解エフェクト・解き直し・図)とは別の
                         任意機能。セット制で出題するツールだけが読めばよい。
                         組み込み済み1本: math_js3_aichi_daimon1。
                         ⚠ **終わりのない練習モードにも入れること**。大問1は最初
                         「本番セット10問」だけに入れたが、生徒が触るのは
                         タイプ別トレーニング（無限に出続ける方）で、そこでは
                         10問解いても何も出なかった。「10問ごとの評価」は
                         元々この無限モードのための機能なので、セット制の画面が
                         あるツールでも練習モード側を先に確認する。
                         大問1は今どちらも10問で1ラウンド（練習モードは
                         問題番号を (3/10) と出し、10問目のボタンが「採点結果へ」）。
                         cap:'─ 採点結果 ─' と extra でタイム・○✕一覧を残し、
                         「まちがえた N 問をもう一度」は同じ問題オブジェクトを
                         使い回す(params_hash が変わらない＝2連続正解でmasteredになる)。
                         ⚠ そのミニセットは saveTime を飛ばす。本番10問のタイムと
                         同列に time_records へ入れると速さの比較が壊れるため。
                         ⚠ ツール側は「共通モジュールが無ければ従来の静的
                         #scr-result が出る」形にしてある。assets を先に上げること。
  print-watermark.js     印刷シート用透かしモジュール
  menseki-fig.js         円の面積(math_es6_en_menseki)の問題図SVG。単元専用だが
                         ツール本体と teacher.php の解き直しプリントの両方が読むため
                         assets に置く（図の実装を1箇所に保ち、画面と印刷を一致させる）
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
  maintenance/          運用SQL（生徒1人の記録だけ消す等。テストデータ掃除用）
  reports/              集計SQL（教室別稼働率など。phpMyAdminで実行して読む参照専用）
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
- **ツールを組み込むときの「共通処理」は4点セット**。①学習記録が飛ぶ
  ②正解エフェクトが出る（**共通モジュールを読む**。ツール自前の演出で代替しない）
  ③**解き直しができる**（`?retry=1` で同一問題が再出題され、2連続正解でリストから消える。
  `api/units.php` に `url` を載せる）④**図が表示される**（図が無いと解けない問題は
  誤答時に図を保存し、解き直し画面と解き直しプリントの両方に出る）。
  ③④も共通処理であり、欠けていたら組み込みは未完了とみなす
  （「記録は入っているが解き直せない」状態だと、生徒のリストが永久に減らずに溜まり続ける）
- 物理教室は8つ: 焼山・吉根・長久手・神丘・高針台・一社・貴船・有松
  （植田・志段味はSEO用地名。表記は「神丘」が正、「神岡」は誤り）
- 正解エフェクトは es（小学生）ツールのみ `divp-correct.js`、jh は
  `divp-correct-jh.js`（スタンプ）。js/jh に星エフェクトは付けない
- **正解エフェクトのファイルは「エフェクト名」で分ける**（`divp-correct-〇〇.js`）。
  学年や連番で分けない（`divp-correct-es2.js` は「es向け2番目」の意味だったが
  ファイル名規則の `es2`＝小2 と読めたため `divp-correct-firework.js` に改名した）。
  新エフェクトは自分を `window.DivpEffects.〇〇` に登録し、`<body data-effect="〇〇">`
  のときだけ `Divp.correct` を差し替える。**1ファイルに詰め込まない**
  （divp-correct.js は9ツール・divp-correct-jh.js は22ツールが読んでいるので、
  統合すると1回の上書きミスの巻き添えがそのままツール数になる。
  「窓口が1つ」は DivpEffects レジストリ側で担保する）

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
| コンテンツ | vocab_words / vocab_hints | 語彙クロスワード(japanese_goi_crossword)の語3350件と段階ヒント。**schema_full.sql には無い**（db/migrations/migrate_vocab_crossword.sql + db/seeds/seed_japanese_goi_crossword.sql を phpMyAdmin で実行）。重複判定は `(level, yomi, hyoki)` の UNIQUE で、**漢字表記が同音異義語（ホショウ＝保証／保障／補償）の唯一の区別材料**（旧 `(level,yomi)` だと同じレベルに1語しか置けなかった。既存DBは db/migrations/migrate_vocab_homonym.sql を実行）。**問題データをDBに置く最初のツール**で、先生が /vocab_admin.php から語とヒントを増やせる（API=api/vocab_admin.php）。正誤は既存 answer_logs に集約＝専用ログ表は作らない |
| 自習 | self_study_logs | 生徒が自分で書く「家でやった自習」の記録（日付・教科・教材・範囲・時間・手ごたえ・メモ）＋講師の確認印(checked_at)とコメント。**schema_full.sql には無い**（db/migrations/migrate_self_study.sql を実行）。自己申告なので**XPも学習時間集計も付けない**（ロードマップ 5g） |

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
2a. **question_text の数式マーカーと、それを描く3つのレンダラー**。
   question_text はプレーンテキストなので、縦組みが要るものは決まったマーカーで書き出し、
   閲覧側(PHP)が KaTeX/HTML に変換する。現在の規約:
   - `F(分子/分母)` = 分数（分子・分母に文字式も可。例 `F(2x+3y/12)`、`F(x²/3)`）
   - `SYS(式1|式2)` = 連立方程式（中かっこでまとめて縦2段に表示）
   マーカーを使わずスラッシュ直書きすると「6xy/2x」のような除算表記と区別できないので、
   **分数のつもりの `/` は必ず `F()` で包む**（question_params 側は params_hash に効くので書式を変えない）。
   ⚠️ **この変換を行う `renderMathToHTML` は mypage.php / retry.php / teacher.php の
   3ファイルに同じものがコピーされている**（teacher.php のみ純粋数式をKaTeXへ寄せる分岐が追加）。
   さらに teacher.php は解き直しプリント用に別ウィンドウのCSSを組み立てるため、
   `.sysbrace`/`.sysrows` 等のCSSは**ページ用と印刷シート用の2箇所**にある。
   ツール側で question_text の表記を変えたら、**3ファイルすべて**を更新すること
   （1つ漏らすとその画面だけマーカーが生文字で出る。実際に3回やらかしている）
2b. **図が無いと解けない問題は question_figure で図そのものを保存する**。
   `Divp.answer(ok,{… question_figure:<画面に出したSVG/表のHTML>})` と渡すと、
   **誤答のときだけ** answer_logs.question_figure に入り、teacher.php の解き直し
   プリントに出る（正解分は保存しない＝容量の無駄）。question_text と同じ「人間用の
   表現」で、**question_params から図を復元する実装をPHPに持たせない**という
   2. の原則をそのまま守る形。組み込み済み: math_js3_aichi_daimon1（`q.tableHtml`）/
   理科4本（`q.tbl`）。列の追加は db/migrations/migrate_question_figure.sql。
   ⚠ 講師画面に生HTMLとして描くので、save_answer.php の `figure_is_safe()` が
   タグ・属性のホワイトリストで検証し、**想定外なら図を丸ごと捨てる**（掃除はしない）。
   新しい図でタグ・属性を増やしたら FIG_TAGS / FIG_ATTRS にも足すこと。
   足し忘れても記録自体は普通に残り、図だけが落ちる（＝気づきにくい）。
   `fill="url(#…)"` は同じ図の中の defs 参照だけ許可。id は印刷シート側の
   `scopeFigIds()` が問題番号で名前空間化する（同じ図が2問並ぶと id が衝突するため）
2c. **params から問題を復元できないツールは question_replay で「問題そのもの」を保存する**。
   `Divp.answer(ok,{… question_replay:{key,typeId,multi,correct,parts,choices,expl,tableHtml}})`
   と渡すと、**誤答のときだけ** retry_queue.replay_json に入り、`?retry=1` で
   `Divp.getRetries()` の `item.replay` として返る。ツールはそれを描画関数へ
   そのまま渡す＝**復元ではなく再表示**で同一問題を出す（列の追加は
   db/migrations/migrate_retry_replay.sql）。
   平方根マスターのように params（例 `{n:72}`）から生成関数が作り直せる単元は
   従来どおりでよく、こちらは**生成関数が多すぎて復元経路を持てないツール用**
   （math_js3_aichi_daimon1 = 生成関数20種類以上。これが無いと params_hash が
   一致せず「2連続正解でmastered」が永久に成立せず、解き直しリストが減らない）。
   ⚠ 置き場所は answer_logs ではなく retry_queue（誤答1問=1行なので、
   同じ問題を何回まちがえても1行。answer_logs だと1解答ごとに図が複製される）。
   ⚠ **params_hash は変わらない**。再表示した問題で `Divp.answer` を呼ぶとき
   同じハッシュになるよう、ツール側で question_params 由来の値（typeId・_meta）を
   問題オブジェクトに戻すこと（ここがずれると永久に mastered にならない）。
   ⚠ tableHtml と svg 選択肢はツール側で innerHTML に入るので
   `figure_is_safe()`、tex/text は長さとタグらしさだけ見る `replay_text_ok()` で検証し、
   **想定外なら replay を丸ごと捨てる**（記録自体は残るので気づきにくい。
   数式の生の `<`「3a+2b<1200」「x<y」は通す作りにしてある）
2d. **乱数を種(seed)から作るツールは、種を保存すれば復元も再表示も要らない**。
   出題のたびに種を1つ引き、`question_params:{m:モード, s:種}` だけを保存する。
   `?retry=1` では同じ種で生成関数を回すだけで**まったく同じ問題**が出るので、
   2c の replay_json（画面に出した問題そのもの）も、生成関数ごとの復元経路も不要。
   条件は**ツール内の乱数が1か所に集まっていること**（`ri()` だけが乱数を使う形。
   `Math.random()` を各所で直接呼んでいると種で再現できない）。
   組み込み済み: math_js2_ichijikansu（生成関数50個以上・モード13種）。
   ⚠ 生成関数の中身を変えると、既存 pending の種から出る問題が変わる
   （params_hash は同じままなので解き直し自体は成立するが、出る問題は別物になる）。
   ⚠ 種は question_params に入る＝params_hash に効くので、
   「同じ問題」の単位は種そのもの。同じ問題を2回出したいなら種を使い回す。
2e. **問題データそのものがDBにあるツールは、そのIDだけを question_params に入れる**。
   語彙クロスワード(japanese_goi_crossword)は語を vocab_words に持つので
   `question_params:{word_id:123}` の1項目だけ。読み・語釈・レベル・分野は**入れない**
   （先生が /vocab_admin.php で語を直したとたん params_hash が変わって、
   その語は永久に mastered にならなくなる）。復元も再表示も要らず、
   `?retry=1` では word_id を api/vocab_words.php の `ids=` に渡して
   「pending の語を必ず盤面に載せる」だけで解き直しが成立する。
   ⚠ 出題プールはAPIが決めるので、**プールを広げると1盤面に載る pending 語が減る**
   （盤面は語を混ぜて作るため）。解き直し時だけ limit を絞ってある。
   ⚠ 1語=1レコード。ヒントでマスを開けた語は正解でも**誤答で記録する**
   （そうしないとヒント連打で全問正解になり、解き直しリストが減らない）
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
   復元経路を持てないツール（愛知県公立入試 大問1）は question_replay の再表示方式（2c参照）。
   retry.php は units.php に `url` の無い単元では「同じ問題をもう一度出す機能に未対応」と
   その場に書く（ボタンが無いだけだと壊れて見えるため）
5a. **学習時間は活動ベースの積算方式**: 壁時計(開始〜終了)ではなく、
   解答(save_answer)・1分ごとのハートビート(api/heartbeat.php、タブ表示中のみ)・
   終了(end_session)のたびに「前回活動からの経過(上限5分)」をduration_secに加算。
   study_sessions.ended_at は「最後に活動した時刻」の意味（NULLチェック廃止）。
   放置時間は最大5分しか数えず、ページ強制終了でも誤差は1分強。
   講師ページのセッション一覧は解答0件かつ1分未満の空セッションを非表示
5a2. **「1回のプレイにかかった時間」は time_records に1プレイ=1行**（学習時間 duration_sec とは別物）。
   ツールが `Divp.saveTime({question_key, time_ms, miss_count, meta})` を呼ぶだけ。
   **見せる単元の台帳は api/time_ranking.php の `time_units()`**（ここに無い unit_key は
   time_records に溜まっていてもどの画面にも出ない＝記録されていないと誤読しやすい）。
   台帳の項目: `ranking`(true のときだけ教室内の速さランキングに載せる。**速さを競わせると
   急いでミスする方向に働くので、本番形式の演習は false にしてタイムだけ見せる**) /
   `order`('time'=速い順 / 'recent'=新しい順) / `total`(1プレイの問題数。miss_count から
   得点を出す) / `miss_label` / `precision`('sec'なら「8分12秒」表記) / `question_key`。
   表示は mypage.php（カード）と teacher.php の生徒詳細（表）の2箇所。
   組み込み済み: math_es_hyakumasu(100マス・ランキング有) /
   math_js3_aichi_daimon1(本番セット10問・ランキング無・得点10点満点・出題範囲をmetaに)。
   1問ごとの所要時間は別で、`Divp.answer` に `time_taken_sec` を渡すと answer_logs に入る
   （大問1は組み込み済み。離席ぶんを混ぜないよう10分超は記録しない）
5b. **講師確認ページ → 完了（/teacher.php）**: 講師ログインフォーム内蔵。
   生徒一覧（期間タブ+教室タブ+教科タブ、学習時間/解答数/正答率/解き直し数/最終学習）→
   生徒名クリックで詳細（教科別にグループ化した単元カルテ・直近の誤答30件・学習セッション20件+端末）。
   教科は unit_key の先頭要素（math/english/…）で判定。
   **自習報告ビュー（?view=selfstudy）**: 担当教室の生徒が書いた自習記録を
   **生徒名だけの一覧**にして、押した生徒の記録だけを開く（details/summary、既定は全部閉じる）。
   記録を全部フラットに並べると読めないため。生徒を1人ずつ詳細ページで開かなくても
   確認印とひとことを返せる（期間/教室/教科タブ＋「未確認だけ」トグル。新しい順に200件まで）。
   未確認がある生徒は見出しに金の帯と「未確認 N」が出る＝開かなくても押すべき相手が分かる。
   並び順はSQLの「未確認→日付の新しい順」をそのままグループ化するので、
   未確認を持つ生徒が自然に上に来る。
   生徒一覧の「自習報告」タブ（緑・未確認があれば件数つき）から入る。
   ⚠ **教科タブはここだけ自習用の一覧**（SELF_STUDY_SUBJECTS＝社会・その他を含む）で、
   生徒一覧の教科タブ（unit_key の先頭）とは別物。行き来するリンクでは subject を落とす。
   ⚠ 1行のHTMLは **ssl_row_html()** が生徒詳細の「自習の記録」カードと共用する
   （確認印のJS paint() が .ssl-state / .ssl-body を書き替えるので構造をそろえる必要がある）。
   生徒名は行に出さない＝畳んだ見出し(summary)側にあるため。
   ランキングビュー（?view=ranking）: 解答数/正答率/XPの3表、教室チェックボックスで
   教室別・複数教室混合のどちらも可。権限: super_admin=全教室 / それ以外=teacher_classroomsの担当教室のみ。
   基調色は藍(#2C5F8A)。誤答詳細・端末情報はこのページのみ（マイページには出さない）。
   テスト生（名前に「テスト」を含む生徒）は**生徒一覧・ランキングとも既定で非表示**、
   「テスト生を表示」タブ（?showtest=1）で表示する（api/ranking.php / api/time_ranking.php の
   $includeTest も既定 false）。テスト生の解答自体は普通に記録されているので、
   「一覧に出ない＝記録されていない」と誤読しないこと。
   誤答一覧のフィルタは「単元でしぼる」＋「種類でしぼる」の2段で、
   種類キーは `unit_key|ラベル` と単元で名前空間を分ける
   （理科の「計算特集」のように単元をまたぐ同名モードがあるため）。解き直しプリントも両方の絞り込みに従う
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
   **生徒一覧の「保護者・兄弟」列で兄弟登録済みの家庭が分かる**（保護者ID＋「兄弟N人」バッジ＋
   兄弟の生徒コード/氏名。保護者が未発行なら朱色で「保護者未発行」＝ご家庭向け案内文を
   渡す前に気づける）。値は api/list_students.php が相関サブクエリで付ける
   guardian_login_id / siblings で、並び替え用の sibling_state（兄弟あり/兄弟なし/保護者未発行）は
   admin.php 側で組む＝列見出しクリックで兄弟ありをまとめて先頭に出せる。
   削除は is_active 切替（api/set_active.php、講師は統括のみ・自分自身は不可）。
   生徒の完全物理削除は統括のみ（api/delete_student.php、生徒コード打ち直し確認つき。
   CASCADE+login_logs/auth_tokens明示削除。子がいなくなった保護者のみ道連れ、兄弟がいれば保護者は残る）。
   保護者の完全物理削除も統括のみ（api/delete_guardian.php。生徒・学習記録は残る。
   無効化と違いIDが空くので同じ代表の子で登録し直せる＝テスト掃除用）
   生徒の「記録リセット」も統括のみ（api/reset_student_records.php、生徒コード打ち直し確認つき）。
   アカウント（生徒コード・PIN・保護者ひもづけ・志望校）は残し、
   answer_logs / study_sessions / retry_queue / xp_logs / time_records / paper_test_results の
   その生徒の行だけを消す＝テストデータの掃除用。**xp_logs も一緒に消す**
   （レベルは累計XPから算出するので残すと「解答0なのにレベルだけ高い」状態になる）。
   login_logs / auth_tokens は残す（生徒がログインし直さずに済むように）。
   question_catalog の stat_total/stat_correct は update_xp.php が全件再集計するので手当て不要。
   phpMyAdmin から直接やる同内容のSQLが db/maintenance/reset_student_records.sql にある。
   **退会の予約（自動無効化）→ 完了**: 退会が月の途中で決まったとき、その場で
   「最終利用日」を入れておくと**その翌日から自動で is_active=0** になる
   （月末に無効化操作をする必要が無い＝操作忘れの防止）。
   列は students.deactivate_on（db/migrations/migrate_student_deactivate_schedule.sql を
   phpMyAdmin で1回実行。schema_full.sql にも反映済み）。
   予約・取り消しは api/schedule_deactivate.php（権限は set_active.php と同じ）、
   admin.php の生徒一覧に「退会予約」ボタン（日付＋「今月末」「翌月末」のワンタップ）と
   「9/30で退会予定」バッジ。**予約日は無効化後も残す**（一覧で退会日が分かる）。
   実際に落とすのは api/run_deactivation.php（Hetemlのcronで1日1回。update_xp.php と
   同じトークン方式で、config の `batch_token`、無ければ `xp_batch_token` を見る）。
   ⚠ **cron が止まっていても締まるよう二重にしてある**: helpers.php の
   `sweep_due_deactivations()` が api/auth.php（PIN入力・自動ログインの両方）と
   admin.php / teacher.php の表示時にも走る。cron は「一覧やレポートの人数も当日中に
   正しくする」ためのもの。
   ⚠ **「有効に戻す」は予約も一緒に取り消す**（set_active.php が deactivate_on を NULL に。
   残したままだと戻した翌日にまた無効になる）。
   ⚠ 学習記録は一切消えない（is_active を落とすだけ。解答数・正答率もそのまま残り、
   完全削除 delete_student.php とは別物）。
   ⚠ ALTER 未実行のまま PHP だけ上げても壊れない作りにしてある
   （sweep は try/catch で黙って skip、一覧は table_has_column() で列を出し分け、
   予約APIは schema_not_ready を返す）。とはいえ**先にSQLを流すこと**。
5e. **講師パスワード → 完了**: password.php + api/change_password.php（現PW照合必須・**講師専用**）。
   must_change_password=1 の講師は teacher.php/admin.php から password.php へ強制リダイレクト。
   統括は登録一覧の「PW初期化」で仮PWを自動生成発行（api/reset_teacher_password.php、自分自身は不可）。
   **保護者はお子さまのPINでログインする方式のためパスワード変更・初期化は無い**
   （change_password は講師専用に変更、reset_guardian_password.php は廃止=410、
   admin.php の保護者行「PW初期化」ボタンも撤去済み。保護者がログインできない＝
   お子さまのPINが不明な場合は生徒PIN側で対応する）
5f. **TZ診断**: api/timecheck.php（講師ログイン必須）で php_now/db_now/session_tz を確認できる。
   2026-07-03 に旧db.phpで入ったUTC行(+9時間ずれ)は修正済み
5g. **自習の記録 → 完了（self_study_logs）**: 生徒が「家で何を自習したか」を自分で書き残し、
   講師が確認印とひとことを返す。テーブルは db/migrations/migrate_self_study.sql
   （schema_full.sql には無い。phpMyAdmin で1回実行する）。
   ラベル・権限判定の共通定義は **api/self_study_common.php**（SELF_STUDY_SUBJECTS /
   SELF_STUDY_TYPES / SELF_STUDY_TYPE_DESCS / SELF_STUDY_RETAIN_SPANS /
   SELF_STUDY_RETAIN_SPAN_DESCS / SELF_STUDY_FEELINGS / SELF_STUDY_FEELING_FACES /
   SELF_STUDY_BACKDATE_DAYS / SELF_STUDY_MAX_ITEMS）で、
   生徒側API・講師側API・mypage.php・teacher.php の4か所が読む＝文言を1箇所で直せる。
   **SELECT する列も self_study_select_columns() にまとめてある**
   （list_self_study / check_self_study / teacher.php が同じ形で読むため。
   列を足したときの入れ忘れを防ぐ。表の別名は sslog / t 固定）。
   API: save_self_study.php（生徒・新規/修正）/ list_self_study.php（生徒=自分・
   講師=担当教室の生徒・保護者=ひもづく子）/ delete_self_study.php /
   check_self_study.php（講師の確認印・コメント）。
   入力項目は 日付・**勉強の種類**・教科・「なにを・どこを」・時間(分)・手ごたえ(1〜5)・メモ。
   **教材名と範囲は1つの欄**（カードが教科ぶん並ぶので手数を減らす）＝新しい入力は
   material 1列に入る。range_text 列は残っているが**新規入力では使わない**
   （欄が分かれていた頃の記録が入っており、一覧は material に続けて薄く表示する。
   なおすときは1欄につないで出すので、保存し直すと material 側に寄る）。
   **勉強の種類は塾で使っている言葉をそのまま出す**（言い換えない）:
   `study_type` = memorize「覚える勉強」(最近習ったこと・思い出したばかりの復習) /
   retain「忘れない勉強」(前に正解した問題の再確認)。retain のときだけ
   `retain_span` = short「短期」(1週間以内に正解した問題) / long「長期」(1か月以内)。
   列は db/migrations/migrate_self_study_type.sql を phpMyAdmin で1回実行
   （**既存行は両方 NULL = 区別を付ける前の記録**。画面はバッジを出さないだけで記録は残る）。
   **入力画面は「覚える勉強」「忘れない勉強」の2欄に分かれ、各欄の教科ボタンを押すと
   その教科の入力カードが1枚増える**（生徒はほとんどの日に複数教科を書くため）。
   何教科ぶんでも1回の送信でまとめて保存するが、**保存は1教科=1行のまま**
   （講師の確認印とコメントを教科ごとに押せる粒度を保つ。1行に複数教科を詰めると
   「英語だけ確認」ができなくなる）。save_self_study.php は
   `{study_date, items:[…]}` を1トランザクションで入れ、1件でも弾かれたら全件保存しない
   （「英語だけ入った」状態にすると生徒が二重に書き直す）。エラーは `index` で
   何件目かを返し、画面はそのカードだけを赤くする。
   ⚠ **「なおす」は1件ずつ**（画面は .editing でカード1枚だけの編集モードに切り替わる）。
   API も log_id 付きの単件パスのままなので、まとめ書きのパスと混ぜないこと。
   ⚠ **XPは付けない。answer_logs / study_sessions / time_records にも一切書かない**
   （自己申告なので水増しできる。がんばりカードの学習時間・正答率とは別枠のまま保つ）。
   ⚠ **確認印が押された記録は生徒側から編集・削除できない**（api が already_checked=409 で弾く）。
   講師のコメントと食い違うため。押しまちがいは講師側の「確認を取り消す」で戻せる
   （そのときコメントも一緒に消す＝「確認していないのにコメントだけ残る」を防ぐ）。
   ⚠ 生徒が書ける日付は今日〜SELF_STUDY_BACKDATE_DAYS(31)日前まで。未来日は不可。
   ⚠ **講師画面の一覧は未確認の記録を期間タブに関係なく必ず出す**
   （確認印は「見たかどうか」のTo-Doなので、期間を切り替えて隠れると押し忘れる）。
   生徒一覧にも「自習 未確認」列があり、こちらも期間フィルタの対象外。
   ⚠ **SQLのテーブル別名に `ssl` を使わない**（`SSL` はMySQLの予約語で、
   `FROM self_study_logs ssl` が構文エラー1064になる。本番で踏んだ）。`sslog` を使う。
   ⚠ 保護者ページ(guardian.php)の画面は未実装。list_self_study.php は
   guardian でも読めるようにしてあるので、出すならUIを足すだけでよい
5h. **利用状況レポート → 完了（/report.php）**: 会議・共有用の1枚資料。
   ①教室別利用状況（**1問以上解いた生徒 ÷ 在籍生徒 × 100** を「小」「中」に分けて出す。
   高校生・テスト生・退塾は分母分子とも除外し、除外人数を注記に出す）
   ②人気のサイトランキング（利用生徒数の多い順＋解答数・正答率。小/中のベスト5も）
   ③教室別 保護者ログイン率（**ログインした保護者 ÷ 保護者アカウントを発行した家庭 × 100**。
   期間内ログイン／一度でもログイン／ログイン回数／保護者未発行の生徒数）。
   期間タブ（今週/今月/先月/全期間）＋任意期間、印刷ボタンつき。teacher.php のヘッダーから遷移。
   権限は teacher.php と同じ（super_admin=全教室 / それ以外=担当教室のみ）。
   ⚠ **学校種は students.grade の文字列から判定する**（es4/js1 形式が正だが 小4/中２ も拾う）。
   空欄の生徒は小中どちらの分母にも入らない＝**学年欄が空だと稼働率が実際より高く出る**。
   画面下に「学年未設定 N人」を出してあるので、そこが0でないうちは数字を鵜呑みにしない。
   ⚠ 同じ定義の phpMyAdmin 用SQLが db/reports/kadouritsu_by_classroom.sql にある
   （こちらは高校生・未設定も行として出る）。**定義を変えたら両方直す**。
   ⚠ ツール名は api/units.php の台帳から引くので、台帳に無い unit_key は
   キーのまま出る（＝1行足せば名前が出る。記録が無いわけではない）。
   ⚠ **③だけは学年を問わない**（高校生の保護者も1家庭として数える。①②とは分母が違う）。
   家庭の教室は**代表のお子さま（ひもづく在籍生徒のうち student_id が最小）の教室**に寄せる＝
   兄弟が別教室でも二重計上しない。保護者は自動ログイン(divp_remember)を発行しないので
   login_logs に訪問のたび1行残る＝「ログイン回数」がそのまま「見に来た回数」になる
   （生徒は自動ログインで記録が残らない回があるため、同じ数え方は生徒に使えない）。
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
- **日本語の「読み」にUNIQUEを張る列は `COLLATE utf8mb4_bin` にする**。既定の
  `utf8mb4_unicode_ci` は主レベルの重みだけで比較する＝**濁点・半濁点・小書きの差を無視**し、
  「ニシ＝ニジ」「キケン＝キゲン」「規制(キセイ)＝犠牲(ギセイ)」が同じ文字列になる。
  vocab_words.yomi でこれを踏み、seed投入が #1062（44組が衝突）で止まった。
  検索窓のLIKEだけは `列 COLLATE utf8mb4_unicode_ci LIKE ?` と寄せると
  かな・濁点の揺れを吸収できる（UNIQUE制約は列の照合順序のままなので厳密）
- HetemlのMySQL/PHPはUTC → `api/db.php` で `date_default_timezone_set('Asia/Tokyo')` と
  接続時 `SET time_zone = '+09:00'` を必ず通す（NOW()/CURDATE()が9時間ずれるため）
- **`SHOW COLUMNS ... LIKE ?` のようにSHOW文へプレースホルダを渡さない**。`api/db.php` は
  `PDO::ATTR_EMULATE_PREPARES => false`（ネイティブのプリペアド）なので環境によっては例外になる。
  列の有無は `SELECT 列 FROM 表 LIMIT 0`（実際に読んでみる）で判定する＝helpers.php の
  `table_has_column()`。本番で踏んだ（列はあるのに「無い」と誤判定した）
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
