# 動的XPシステム 実装指示書(Claude Code用)

## 背景

学習記録システム(chukyokobetsu.com / Heteml / PHP + MariaDB 10.11)のXP機能を再設計する。
現状XPは機能していない。問題ごとの難易度(base_xp)を手動設定するのが困難なため、**全生徒の正答率から自動的にXP単価が変動する仕組み**に切り替える。

既存の設計原則(維持すること):
- XPは**付与時点で値を確定**し、xp_logs に記録。過去の付与は絶対に再計算しない
- XPは正解時のみ付与
- 1日の合計獲得上限(dailyCap)あり
- レベル = `floor(sqrt(totalXp / 100)) + 1`(累計から都度計算)

---

## 1. スキーマ変更

### question_catalog に列追加

```sql
ALTER TABLE question_catalog
  ADD current_xp INT NULL,
  ADD stat_total INT DEFAULT 0,
  ADD stat_correct INT DEFAULT 0,
  ADD stat_updated_at DATETIME NULL;
```

- `current_xp` … 動的に変動する現在のXP単価。NULLなら base_xp を使う
- `stat_total` / `stat_correct` … 全期間の回答数・正解数(日次バッチで更新)

---

## 2. 日次バッチ(update_xp.php)

Hetemlのcronで深夜(例: 3:00)に1日1回実行。

### 集計・単価更新SQL

```sql
UPDATE question_catalog qc
JOIN (
  SELECT unit_key, question_key,
         COUNT(*) AS total,
         SUM(is_correct) AS correct
  FROM answer_logs
  GROUP BY unit_key, question_key
) t ON qc.unit_key = t.unit_key AND qc.question_key = t.question_key
SET qc.stat_total = t.total,
    qc.stat_correct = t.correct,
    qc.current_xp = CASE
      WHEN t.total < 20 THEN qc.base_xp
      ELSE LEAST(
             qc.base_xp * 3,
             GREATEST(
               qc.base_xp,
               ROUND(qc.base_xp * (2 - (t.correct + 5) / (t.total + 10)))
             )
           )
    END,
    qc.stat_updated_at = NOW();
```

### 変換式の仕様

- スムージング正答率 `p = (correct + 5) / (total + 10)`(データ僅少時の極端値防止)
- `xp = round(base_xp × (2 − p))` … 正答率100%で等倍、50%で1.5倍
- 下限 `base_xp`、上限 `base_xp × 3`
- 回答数20件未満の問題は base_xp をそのまま使用
- **統計は全回答をカウント**する(同一生徒の繰り返しも含む。減衰はXP付与側だけの話で、統計からは除外しない)

### セキュリティ

直接URLアクセスで叩かれないよう保護すること。CLI実行チェック(`php_sapi_name() === 'cli'`)または秘密トークンのGETパラメータ照合のいずれか。

---

## 3. 付与ロジック(save_answer.php 内、正解時のみ)

### 日次減衰(同一問題の繰り返し対策)

- カウント単位: **同一生徒 × 同一 (unit_key, question_key) × 同一日(JST)**
- セッションではなく**日単位**でリセット(生徒は1日に何度も開閉するため、セッション単位はザル)
- 減衰式: `xp = max(1, round(単価 × 0.7^n))`
  - n = 今日すでにその問題で正解してXPを得た回数
  - **下限は1**(0にしない。解けば必ず何かもらえる)

### 擬似コード

```php
// 単価: 日次バッチで変動。NULLなら base_xp
$unit = $row['current_xp'] ?? $row['base_xp'];

// 今日この生徒がこの問題で正解した回数(xp_logs から)
// ※ DATE(created_at)=CURDATE() ではなく範囲比較でインデックスを効かせる
$n = "SELECT COUNT(*) FROM xp_logs
      WHERE student_id = ? AND unit_key = ? AND question_key = ?
      AND created_at >= CURDATE()";

$xp = max(1, round($unit * pow(0.7, $n)));

// 1日の合計上限チェック
$todayTotal = "SELECT COALESCE(SUM(xp),0) FROM xp_logs
               WHERE student_id = ? AND created_at >= CURDATE()";
$xp = min($xp, max(0, $dailyCap - $todayTotal));

// $xp > 0 なら xp_logs に確定値で INSERT(unit_key, question_key も記録)
```

- xp_logs に unit_key / question_key を記録していること(減衰COUNTに必要)
- 減衰用の追加テーブルは作らない(xp_logs のCOUNTで完結)

---

## 4. 表示ポリシー(重要)

**生徒には単価・変動の仕組みを一切見せない(シークレット運用)。**

- 生徒に見える: 累計XP、レベル(mypage)
- 生徒に見えない: 問題ごとのXP単価、正答率、変動ルール
- 正解演出は従来通り。1問ごとのXP数値は表示しない
- 将来的に教師側管理画面では question_catalog の正答率一覧を表示予定(つまずき単元の可視化)。今回は実装不要

---

## 5. XPリセット(1回だけ実行する初期化作業)

過去の付与ミスがあるため、新ルール稼働前に全生徒のXPをリセットする。

```sql
-- バックアップ(Hetemlの自動バックアップとは別の、DB内コピーテーブル)
CREATE TABLE xp_logs_backup_20260712 AS SELECT * FROM xp_logs;
CREATE TABLE xp_events_backup_20260712 AS SELECT * FROM xp_events;

-- リセット
TRUNCATE TABLE xp_logs;
TRUNCATE TABLE xp_events;

-- students に累計XPキャッシュ列がある場合のみ
-- UPDATE students SET total_xp = 0;
```

- answer_logs / study_sessions は触らない(学習記録・統計は無傷で残す)
- レベルは累計から計算する設計なので自動的に全員レベル1に戻る
- バックアップテーブルは調査用。落ち着いたら DROP してよい

---

## 実装順序の推奨

1. スキーマ変更(ALTER TABLE)
2. XPリセット(バックアップ → TRUNCATE)
3. save_answer.php に付与ロジック(減衰 + 上限 + current_xp参照)
4. update_xp.php 作成 + Heteml cron 登録
5. 動作確認: 同一問題を連続正解して 10 → 7 → 5 … と減衰すること、日付が変われば満額に戻ること
