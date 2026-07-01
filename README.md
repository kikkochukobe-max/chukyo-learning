# chukyo-learning

中京個別指導学院（chukyokobetsu.com）の学習ツール群リポジトリ。

## 構成（Hetemlの公開フォルダと1:1で対応）

```
assets/                共通モジュール（全ツールがscriptタグで読み込む）
  divp-header.js        共通ヘッダー（ロゴ・校名・Zen Maru Gothic）
  divp-correct-jh.js     中学・高校用 正解スタンプエフェクト（es用Divp.correctとは別物）
  print-watermark.js     印刷シート用透かしモジュール
  .htaccess              ETagベースのキャッシュ制御（URL固定で更新を届ける）
learning/
  math/                  教科ごとのフォルダ（学校種・学年はファイル名側で表現）
    math_js3_heihokonmaster.html
```

ファイル名規則: `教科_校種学年_単元(_製作者コード).html`
例: `math_js3_heihokonmaster.html` = 数学・中学3年・平方根

## デプロイ

Gitはソース管理のみ。本番反映は変更ファイルをHetemlへFTPアップロード（別工程）。
`assets/` 配下はURLを固定したまま上書きするだけで、読み込んでいる全ツールに反映される。

## 持ち込み厳禁

- `api/config.php`（DB接続情報）は `.gitignore` 済み。絶対にコミットしない。
