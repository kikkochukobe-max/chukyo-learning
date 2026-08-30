<?php
declare(strict_types=1);

/**
 * 語彙クロスワード（unit_key = japanese_goi_crossword）の作問・ヒント編集ページ。
 * teacher.php / admin.php と同じ立ち位置の講師ページ。
 * データ操作はすべて /api/vocab_admin.php（講師ログイン必須）に投げる。
 *
 * 権限: 閲覧は全講師、追加・編集・削除は super_admin / classroom_admin（admin.php と同じ切り方）。
 * 未ログインのときは teacher.php のログインフォームへ送る（ログイン窓を二重に持たない）。
 */

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

$actor = current_actor();
if (!$actor || $actor['type'] !== 'teacher') {
    header('Location: /teacher.php');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT role, teacher_name, must_change_password FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$me = $stmt->fetch();
if (!$me) {
    header('Location: /teacher.php');
    exit;
}
// 初期パスワードのままなら、変更するまで先に進ませない（teacher.php / admin.php と同じ）
if ((int)$me['must_change_password'] === 1) {
    header('Location: /password.php');
    exit;
}
$canEdit = in_array($me['role'], ['super_admin', 'classroom_admin'], true);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>語彙 作問｜ことばのマスで語彙力マス</title>
<style>
:root{
  --paper:#FBF6EA; --rule:#3C7A5E; --rule-soft:#B7D2C3;
  --ink:#23211C; --ink-soft:#6C665A; --vermilion:#C24E3A;
  --vermilion-soft:#F3DCD5; --gold:#FEB342; --line:#E3DECF;
}
*{box-sizing:border-box;}
body{margin:0;background:var(--paper);color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans","Noto Sans JP","Yu Gothic",sans-serif;}
.mincho{font-family:"Hiragino Mincho ProN","Yu Mincho","Noto Serif JP",serif;}
header{padding:12px 18px;border-bottom:2px solid var(--rule);
  display:flex;align-items:baseline;gap:12px;background:var(--paper);
  position:sticky;top:0;z-index:5;}
header .brand{font-size:11px;letter-spacing:.14em;color:var(--ink-soft);}
header h1{font-size:18px;margin:0;letter-spacing:.14em;
  font-family:"Hiragino Mincho ProN","Yu Mincho",serif;font-weight:600;}
header .navs{margin-left:auto;display:flex;align-items:baseline;gap:12px;font-size:12px;}
header .navs a{color:var(--rule);font-weight:700;text-decoration:none;border-bottom:1px solid var(--rule-soft);}
header .navs .who{color:var(--ink-soft);}
.wrap{max-width:1000px;margin:0 auto;padding:16px 18px 60px;}
.readonly{border-left:3px solid var(--gold);background:#fff;padding:8px 12px;
  font-size:12px;color:var(--ink-soft);margin:0;}

/* 絞り込みバー */
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px;}
.filters select,.filters input[type=text]{
  border:1.5px solid var(--rule-soft);background:#fff;border-radius:2px;
  padding:7px 9px;font-size:13px;color:var(--ink);}
.filters input[type=text]{flex:1;min-width:160px;}
.btn{border:1.5px solid var(--ink);background:#fff;border-radius:2px;
  padding:8px 14px;font-size:13px;font-weight:700;color:var(--ink);cursor:pointer;}
.btn.primary{background:var(--vermilion);border-color:var(--vermilion);color:#fff;}
.btn.ghost{border-color:var(--rule-soft);color:var(--ink-soft);}
.btn:disabled{opacity:.4;cursor:default;}

.count{font-size:12px;color:var(--ink-soft);margin:0 0 8px;}

/* 一覧 */
table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;}
th,td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top;}
th{background:var(--rule);color:#fff;font-weight:700;font-size:11px;letter-spacing:.06em;
  position:sticky;top:52px;z-index:3;}
td .yomi{font-family:"Hiragino Mincho ProN","Yu Mincho",serif;font-size:15px;}
td .hyoki{color:var(--rule);font-weight:700;margin-left:6px;}
tr.off td{opacity:.45;}
.tag{display:inline-block;font-size:10px;border:1px solid var(--rule-soft);
  border-radius:2px;padding:1px 6px;color:var(--ink-soft);}
.hintbadge{font-size:11px;color:var(--rule);font-weight:700;}
.rowbtn{border:1px solid var(--rule-soft);background:#fff;border-radius:2px;
  padding:4px 9px;font-size:12px;cursor:pointer;color:var(--ink);}
.rowbtn.del{border-color:var(--vermilion-soft);color:var(--vermilion);}

/* 編集パネル（モーダル） */
.overlay{position:fixed;inset:0;background:rgba(35,33,28,.5);z-index:40;
  display:none;align-items:flex-start;justify-content:center;padding:24px 12px;overflow:auto;}
.overlay.show{display:flex;}
.panel{background:var(--paper);width:100%;max-width:620px;border-top:3px solid var(--vermilion);
  border-radius:3px;padding:20px 20px 24px;}
.panel h2{margin:0 0 14px;font-size:17px;letter-spacing:.1em;
  font-family:"Hiragino Mincho ProN","Yu Mincho",serif;}
.field{margin-bottom:12px;}
.field label{display:block;font-size:11px;color:var(--ink-soft);
  letter-spacing:.06em;margin-bottom:3px;font-weight:700;}
.field input,.field select,.field textarea{width:100%;border:1.5px solid var(--rule-soft);
  background:#fff;border-radius:2px;padding:8px 10px;font-size:14px;color:var(--ink);}
.field textarea{min-height:52px;resize:vertical;}
.field .hint{font-size:11px;color:var(--ink-soft);margin-top:3px;}
.two{display:flex;gap:10px;}
.two>div{flex:1;}

/* ヒント編集 */
.hintrow{display:flex;gap:6px;align-items:center;margin-bottom:6px;}
.hintrow select{flex:0 0 110px;border:1.5px solid var(--rule-soft);border-radius:2px;
  padding:6px;font-size:12px;background:#fff;}
.hintrow input{flex:1;border:1.5px solid var(--rule-soft);border-radius:2px;
  padding:6px 8px;font-size:13px;}
.hintrow .x{flex:0 0 auto;border:1px solid var(--vermilion-soft);color:var(--vermilion);
  background:#fff;border-radius:2px;padding:5px 9px;cursor:pointer;font-size:12px;}
.addhint{border:1px dashed var(--rule-soft);background:#fff;color:var(--rule);
  border-radius:2px;padding:6px 12px;font-size:12px;cursor:pointer;margin-top:4px;}
.autohint{font-size:11px;color:var(--ink-soft);margin-top:6px;
  border-left:3px solid var(--gold);padding:4px 8px;}
.panel .actions{display:flex;gap:8px;justify-content:flex-end;margin-top:18px;}
.err{color:var(--vermilion);font-size:12px;min-height:16px;margin-top:4px;}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);
  background:var(--rule);color:#fff;padding:10px 18px;border-radius:3px;font-size:13px;
  opacity:0;pointer-events:none;transition:opacity .2s;z-index:60;}
.toast.show{opacity:1;}
.empty{padding:40px 12px;text-align:center;color:var(--ink-soft);font-size:13px;}
</style>
</head>
<body>

<header>
  <span class="brand">中京個別指導学院</span>
  <h1>語彙 作問</h1>
  <span class="brand mincho">ことばのマスで語彙力マス</span>
  <span class="navs">
    <a href="/teacher.php">講師ページ</a>
    <a href="/learning/japanese/japanese_esjs_goi_crossword.html" target="_blank" rel="noopener">ツールを開く</a>
    <span class="who"><?= htmlspecialchars((string)$me['teacher_name'], ENT_QUOTES, 'UTF-8') ?> さん</span>
  </span>
</header>

<?php if (!$canEdit): ?>
<div class="wrap" style="padding-bottom:0;">
  <p class="readonly">閲覧のみの権限です。語の追加・編集・削除は 統括管理者／教室管理者 が行います。</p>
</div>
<?php endif; ?>

<div class="wrap">
  <div class="filters">
    <select id="fLevel">
      <option value="">全レベル</option>
      <option value="1">小学低学年</option>
      <option value="2">小学中学年</option>
      <option value="3">小学高学年</option>
      <option value="4">中学 やさしい</option>
      <option value="5">中学 ふつう</option>
      <option value="6">中学 むずかしい</option>
    </select>
    <select id="fCat">
      <option value="">全分野</option>
      <option value="daily">日常語</option>
      <option value="science">理科</option>
      <option value="society">社会</option>
      <option value="math">算数・数学</option>
      <option value="kokugo">国語</option>
      <option value="hyoron">評論</option>
      <option value="idiom">慣用句</option>
      <option value="yojijukugo">四字熟語</option>
    </select>
    <input type="text" id="fQ" placeholder="読み・漢字・語釈で検索">
    <button class="btn ghost" id="btnSearch">検索</button>
<?php if ($canEdit): ?>
    <button class="btn primary" id="btnNew">＋ 語を追加</button>
<?php endif; ?>
  </div>

  <p class="count" id="count"></p>
  <table id="tbl">
    <thead>
      <tr><th style="width:34%">語</th><th style="width:40%">語釈</th>
          <th style="width:10%">レベル</th><th style="width:8%">ヒント</th><th style="width:8%"></th></tr>
    </thead>
    <tbody id="rows"></tbody>
  </table>
  <div class="empty" id="empty" style="display:none;">語が見つかりません。絞り込みを変えるか、上の「＋ 語を追加」から登録できます。</div>
</div>

<!-- 編集パネル -->
<div class="overlay" id="overlay">
  <div class="panel">
    <h2 id="panelTitle">語を追加</h2>
    <input type="hidden" id="wid">
    <div class="two">
      <div class="field">
        <label>読み（カタカナ・マスに入る文字）</label>
        <input type="text" id="fmYomi" maxlength="6" placeholder="キセイ">
        <div class="hint">2〜6文字。濁点・小さい字も正式表記で（ガ・ッ など）</div>
      </div>
      <div class="field">
        <label>漢字表記（任意）</label>
        <input type="text" id="fmHyoki" maxlength="16" placeholder="規制">
        <div class="hint">ひらがな語・慣用句はそのまま／空でも可。<b>同音異義語（保証・保障・補償）はここで区別する</b></div>
      </div>
    </div>
    <div class="field">
      <label>語釈（カギ本文）</label>
      <textarea id="fmGloss" maxlength="255" placeholder="きまりを 決めて 行動を 制限すること"></textarea>
    </div>
    <div class="two">
      <div class="field">
        <label>レベル</label>
        <select id="fmLevel">
          <option value="1">小学低学年</option><option value="2">小学中学年</option>
          <option value="3">小学高学年</option><option value="4">中学 やさしい</option>
          <option value="5">中学 ふつう</option><option value="6">中学 むずかしい</option>
        </select>
      </div>
      <div class="field">
        <label>分野</label>
        <select id="fmCat">
          <option value="">（なし）</option>
          <option value="daily">日常語</option><option value="science">理科</option>
          <option value="society">社会</option><option value="math">算数・数学</option>
          <option value="kokugo">国語</option><option value="hyoron">評論</option>
          <option value="idiom">慣用句</option><option value="yojijukugo">四字熟語</option>
        </select>
      </div>
    </div>

    <div class="field">
      <label>段階ヒント（上から順に出ます）</label>
      <div id="hintList"></div>
      <button class="addhint" id="addHint">＋ ヒントを追加</button>
      <div class="autohint" id="autoHint"></div>
    </div>

    <div class="err" id="panelErr"></div>
    <div class="actions">
      <button class="btn ghost" id="btnCancel">キャンセル</button>
      <button class="btn primary" id="btnSave">保存</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
(function(){
"use strict";
var API='/api/vocab_admin.php';
/* 編集権限（super_admin / classroom_admin）。サーバ側でも弾くので、これは表示の出し分けだけ */
var CAN_EDIT=<?= $canEdit ? 'true' : 'false' ?>;
var $=function(id){return document.getElementById(id);};
var LEVEL_NAME={1:'小低',2:'小中',3:'小高',4:'中易',5:'中普',6:'中難'};
var HINT_TYPES=[['example','例文'],['synonym','類義語'],['antonym','対義語'],['free','自由']];

function api(action, payload){
  return fetch(API,{
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(Object.assign({action:action}, payload||{}))
  }).then(function(r){ return r.json().catch(function(){ return {ok:false,error:'応答エラー'}; }); });
}
function toast(msg){ var t=$('toast'); t.textContent=msg; t.classList.add('show');
  setTimeout(function(){ t.classList.remove('show'); },1600); }

/* ---- 一覧 ---- */
function load(){
  var p={ level:$('fLevel').value||undefined, category:$('fCat').value||undefined,
          q:$('fQ').value.trim()||undefined, limit:300 };
  api('list',p).then(function(r){
    if(!r.ok){ toast(r.error||'読み込み失敗'); return; }
    var rows=$('rows'); rows.innerHTML='';
    $('count').textContent = r.total + ' 語';
    $('empty').style.display = r.words.length? 'none':'block';
    r.words.forEach(function(w){
      var tr=document.createElement('tr');
      if(!Number(w.is_active)) tr.className='off';
      tr.innerHTML =
        '<td><span class="yomi mincho">'+esc(w.yomi)+'</span>'+
          (w.hyoki?'<span class="hyoki mincho">'+esc(w.hyoki)+'</span>':'')+'</td>'+
        '<td>'+esc(w.gloss)+'</td>'+
        '<td><span class="tag">'+(LEVEL_NAME[w.level]||w.level)+'</span>'+
          (w.category?' <span class="tag">'+esc(w.category)+'</span>':'')+'</td>'+
        '<td><span class="hintbadge">'+w.hint_count+'</span></td>'+
        '<td>'+(CAN_EDIT
          ? '<button class="rowbtn" data-edit="'+w.word_id+'">編集</button> '+
            '<button class="rowbtn del" data-del="'+w.word_id+'">削除</button>'
          : '')+'</td>';
      rows.appendChild(tr);
    });
  });
}
function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

/* ---- パネル ---- */
function openPanel(word){
  $('panelErr').textContent='';
  $('wid').value = word? word.word_id : '';
  $('panelTitle').textContent = word? '語を編集' : '語を追加';
  $('fmYomi').value  = word? word.yomi : '';
  $('fmHyoki').value = word? (word.hyoki||'') : '';
  $('fmGloss').value = word? word.gloss : '';
  $('fmLevel').value = word? word.level : ($('fLevel').value||'1');
  $('fmCat').value   = word? (word.category||'') : ($('fCat').value||'');
  renderHints(word && word.hints ? word.hints : []);
  updateAutoHint();
  $('overlay').classList.add('show');
  $('fmYomi').focus();
}
function closePanel(){ $('overlay').classList.remove('show'); }

function renderHints(hints){
  var box=$('hintList'); box.innerHTML='';
  hints.filter(function(h){ return h.hint_type!=='firstchar'; })
       .forEach(function(h){ addHintRow(h.hint_type||h.type, h.body); });
}
function addHintRow(type, body){
  var row=document.createElement('div'); row.className='hintrow';
  var sel='<select>'+HINT_TYPES.map(function(t){
    return '<option value="'+t[0]+'"'+(t[0]===type?' selected':'')+'>'+t[1]+'</option>';
  }).join('')+'</select>';
  row.innerHTML = sel + '<input type="text" maxlength="255" value="'+esc(body||'')+'" placeholder="ヒント本文">'+
                  '<button class="x">×</button>';
  row.querySelector('.x').onclick=function(){ row.remove(); };
  $('hintList').appendChild(row);
}
function updateAutoHint(){
  var y=$('fmYomi').value.trim();
  $('autoHint').textContent = y
    ? '頭文字ヒント「頭文字は「'+y.charAt(0)+'」」は自動で最後に付きます（手入力不要）。'
    : '頭文字ヒントは読みから自動生成されます。';
}
function collectHints(){
  var out=[], step=1;
  Array.prototype.forEach.call($('hintList').children, function(row){
    var type=row.querySelector('select').value;
    var body=row.querySelector('input').value.trim();
    if(body) out.push({step:step++, type:type, body:body});
  });
  return out;
}

function save(){
  var wid=$('wid').value;
  var yomi=$('fmYomi').value.trim();
  var gloss=$('fmGloss').value.trim();
  var err=$('panelErr'); err.textContent='';
  if(!/^[ァ-ヴー]{2,6}$/.test(yomi)){ err.textContent='読みはカタカナ2〜6文字で入力してください'; return; }
  if(!gloss){ err.textContent='語釈を入力してください'; return; }
  var payload={
    yomi:yomi, hyoki:$('fmHyoki').value.trim(), gloss:gloss,
    level:Number($('fmLevel').value), category:$('fmCat').value, hints:collectHints()
  };
  $('btnSave').disabled=true;
  var run = wid
    ? api('update', Object.assign({word_id:Number(wid)}, payload))
        .then(function(r){ if(!r.ok) return r;
          return api('save_hints',{word_id:Number(wid), hints:payload.hints}); })
    : api('create', payload);
  run.then(function(r){
    $('btnSave').disabled=false;
    if(!r.ok){ err.textContent=r.error||'保存に失敗しました'; return; }
    closePanel(); toast(wid?'更新しました':'追加しました'); load();
  });
}

/* ---- イベント ---- */
$('btnSearch').onclick=load;
$('fQ').addEventListener('keydown',function(e){ if(e.key==='Enter') load(); });
$('fLevel').onchange=load; $('fCat').onchange=load;
/* 「＋ 語を追加」は編集権限がある時だけ出ている（無いと null なので触らない） */
if($('btnNew')) $('btnNew').onclick=function(){ openPanel(null); };
$('btnCancel').onclick=closePanel;
$('overlay').addEventListener('click',function(e){ if(e.target===$('overlay')) closePanel(); });
$('addHint').onclick=function(){ addHintRow('example',''); };
$('fmYomi').addEventListener('input',updateAutoHint);
$('btnSave').onclick=save;

$('rows').addEventListener('click',function(e){
  var ed=e.target.getAttribute('data-edit'), del=e.target.getAttribute('data-del');
  if(ed){ api('get',{word_id:Number(ed)}).then(function(r){ if(r.ok) openPanel(r.word); else toast(r.error); }); }
  if(del){
    if(!confirm('この語を削除します。よろしいですか？')) return;
    api('delete',{word_id:Number(del)}).then(function(r){
      if(r.ok){ toast('削除しました'); load(); } else toast(r.error||'削除失敗'); });
  }
});

load();
})();
</script>
</body>
</html>
