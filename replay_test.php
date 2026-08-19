<?php
declare(strict_types=1);
/* replay 保存の切り分け用の使い捨てテストページ。
   愛知大問1の a1_seifu と同じ形の question_replay を、実際の save_answer.php へ
   POSTして「サーバー側で保存されるか」だけを確かめる。
   生徒でログインした状態で開き、ボタンを押してから retry_queue を見る。
   ⚠ 確認が終わったらサーバーから削除すること。 */
header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>replay保存テスト</title>
<style>
 body{font-family:system-ui,sans-serif;max-width:760px;margin:24px auto;padding:0 16px;line-height:1.7}
 button{font-size:16px;padding:12px 20px;border-radius:10px;border:0;background:#C73E2E;color:#fff;font-weight:700}
 pre{background:#f4f2ec;border:1px solid #ddd;border-radius:8px;padding:12px;white-space:pre-wrap;word-break:break-all}
 code{background:#f4f2ec;padding:2px 5px;border-radius:4px}
</style></head><body>
<h1>replay保存テスト</h1>
<p><strong>生徒でログインした状態</strong>で下のボタンを押してください。
愛知大問1の計算問題と同じ形のデータを <code>/api/save_answer.php</code> へ送ります
（<code>question_key</code> は <code>zz_replay_test</code> なので、ふだんの記録は汚しません）。</p>
<p><button id="go">テスト送信する</button></p>
<pre id="out">（未実行）</pre>
<h2>送信後に実行するSQL</h2>
<pre>SELECT retry_id, question_key, updated_at,
       replay_json IS NULL AS no_replay,
       CHAR_LENGTH(replay_json) AS replay_len
FROM retry_queue
WHERE question_key = 'zz_replay_test';</pre>
<p><code>no_replay = 0</code> → サーバー側は正常。原因はブラウザが古い divp-core.js を使っていたこと。<br>
<code>no_replay = 1</code> → サーバー側の検証で弾かれている（こちらで修正します）。</p>
<script>
/* 実際のツールが送る形と同じ payload（a1_seifu = 正負の数の計算） */
var PAYLOAD = {
  unit_key: 'math_js3_aichi_daimon1',
  question_key: 'zz_replay_test',
  question_params: { t: 't1', n: [7, 3, -2, 6] },
  question_text: 'F(7/3)+(-2)×F(6/3) を計算した結果として正しいものを、次のアからエまでの中から一つ選びなさい。',
  question_figure: null,
  question_choices: null,
  question_replay: {
    key: 'zz_replay_test',
    typeId: 't1',
    multi: 1,
    correct: [2],
    parts: [
      { t: 'tex', v: '\dfrac{7}{3}+(-2)\times\dfrac{6}{3}' },
      { t: 'txt', v: ' を計算した結果として正しいものを、次のアからエまでの中から一つ選びなさい。' }
    ],
    choices: ['\dfrac{5}{3}', '-\dfrac{5}{3}', '-\dfrac{5}{3}', '5'],
    expl: ['=\dfrac{7}{3}+\dfrac{-12}{3}', '=-\dfrac{5}{3}'],
    tableHtml: ''
  },
  correct_answer: 'ウ',
  student_answer: 'ア',
  is_correct: false,
  time_taken_sec: null
};
document.getElementById('go').addEventListener('click', function () {
  var out = document.getElementById('out');
  out.textContent = '送信中…';
  fetch('/api/save_answer.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(PAYLOAD)
  }).then(function (res) {
    return res.text().then(function (t) {
      out.textContent = 'HTTP ' + res.status + '\n\n' + t
        + '\n\n--- 送った question_replay ---\n'
        + JSON.stringify(PAYLOAD.question_replay, null, 1);
    });
  }).catch(function (e) {
    out.textContent = '通信エラー: ' + e;
  });
});
</script>
</body></html>
