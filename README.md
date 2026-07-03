# chukyo-learning

中京個別指導学院（chukyokobetsu.com）の学習ツール群リポジトリ。

## 構成（Hetemlの公開フォルダと1:1で対応）

```
assets/                共通モジュール（全ツールがscriptタグで読み込む）
  divp-header.js        共通ヘッダー（ロゴ・校名・ログイン窓。Zen Maru Gothic）
  divp-core.js          学習記録システム共通クライアント（Divp.init / Divp.answer / Divp.getRetries）
  divp-correct.js        小学生用 正解エフェクト（星＋「正解！」演出）
  divp-correct-jh.js     中学・高校用 正解スタンプエフェクト（es用Divp.correctとは別物）
  print-watermark.js     印刷シート用透かしモジュール
  .htaccess              ETagベースのキャッシュ制御（URL固定で更新を届ける）
api/                   学習記録システムのAPI（auth / save_answer / list_retries など）
mypage.php             生徒マイページ（保護者と共用） / retry.php 解き直しリスト
teacher.php            講師確認ページ / admin.php アカウント管理 / password.php 講師PW変更
schema_full.sql        DBスキーマ＋question_catalogシード（16テーブル・適用済み）
learning/
  math/                  教科ごとのフォルダ（学校種・学年はファイル名側で表現）
    math_es3_tokei.html
    math_es4_warizan_hissan.html
    math_js3_heihokonmaster.html
  english/
    english_js_eibunpou.html
    english_js_eitango.html
  science/
    science_js3_ionlab.html
```

ファイル名規則: `教科_校種学年_単元(_製作者コード).html`
例: `math_js3_heihokonmaster.html` = 数学・中学3年・平方根

## ツール共通UIの慣習

- **印刷ボタンはタイトル行の右端**に置く（42px角の小型アイコンボタン、🖨＋「印刷」の縦組み、
  `class="title-print-btn" id="title-print-btn"`）。押すと現在のモードでA4プリントを生成する。
  平方根マスター・2次方程式マスターがこの形（配色は各ツールのヘッダーに合わせて調整可）

## 学習記録システム（DB連携）

生徒がログインして解くと answer_logs に記録され、マイページ・講師ページ・解き直しに反映される。
設計の詳細は `CLAUDE.md` と `schema_full.sql` を参照。

### DB連携 組み込み済みユニット

| unit_key | ツール | question_key（モード） |
|---|---|---|
| `math_js3_heihokon` | math_js3_heihokonmaster.html（平方根マスター） | truefalse / simplify / addsub / muldiv / mixed / approx / intval / subst |
| `math_js3_nijihoteishiki` | math_js3_nijihoteishiki.html（2次方程式マスター） | heihokon / katamari / heihokansei / insu_zumi / insu_jibun / kai_koshiki / random |

### 新しいツールへの組み込み手順

1. `divp-header.js` / `divp-core.js` / 正解エフェクト（jhは `divp-correct-jh.js`）を `/assets/` から読み込む
2. `Divp.init('unit_key')` をページ読み込み時に1回呼ぶ（deferで読み込む場合は DOMContentLoaded 内で）
3. 正誤判定の直後に `Divp.answer(isCorrect, {question_key, question_params, question_text, correct_answer, wrong_answer})` を1行挿入。
   question_params には再出題に必要な情報一式を入れる（形式を後から変えると既存の解き直しpending行とハッシュが合わなくなる）
4. `?retry=1` 対応: `Divp.getRetries()` で pending を取得し、question_params から全く同じ問題を再出題する
5. `question_catalog` にモードを登録（schema_full.sql に追記 → phpMyAdmin で該当INSERTを実行）
6. `api/units.php` の台帳に unit_key → 表示名・URL を1行追加

未ログイン・ローカル起動（file://）では divp-core が黙ってスキップするので、組み込んでもツール単体は壊れない。

※製作者から届くツールには、スマホ単体で動作確認するための**代用のDivp呼び出しと内蔵フォールバック**
（簡易ハンコなど）が入っていることがある。組み込み時は呼び出し箇所を本物のAPI
（`Divp.answer(isCorrect, {...})` / `Divp.correct(セレクタ, {...})`）に書き換えればよく、
内蔵フォールバック自体は消さなくてよい（本物が読み込めない環境では今までどおり代用が動く）。

## デプロイ

Gitはソース管理のみ。本番反映は変更ファイルをHetemlへFTPアップロード（別工程）。
`assets/` 配下はURLを固定したまま上書きするだけで、読み込んでいる全ツールに反映される。
`schema_full.sql` はアップロードせず、差分INSERTを phpMyAdmin で直接実行する。

## 持ち込み厳禁

- `api/config.php`（DB接続情報）は `.gitignore` 済み。絶対にコミットしない。
