// 常識バトルの問題ファイルを機械チェックする。
//   node validate.js kotoba_lv1.json        … 1ファイル
//   node validate.js --all                  … このフォルダの *_lv*.json 全部＋ファイルをまたぐ重複
//
// 1ファイル = 1カテゴリ×1難易度 = 問題の配列。1問は
//   { "q":"問題文", "a":"正解", "w":["誤答1","誤答2","誤答3","誤答4","誤答5"], "e":"解説" }
// 正解は a に分けて持つ（選択肢の並びは出題のたびにサーバーが混ぜる＝位置の偏りが出ない）。
// build_seed.js が付ける "id"（例 kotoba_lv1_001。DBの行と結びつく鍵なので変えない）と、
// 出題を止める "off":true も持てる（まちがいが見つかった問題は消さずに off にする）。
// "calc":true は計算が要る問題（難しい・超難で制限時間が長くなる。api/battle_common.php の calc_sec）。
'use strict';
const fs = require('fs');
const path = require('path');

const DIR = __dirname;
const Q_MAX = { 1: 60, 2: 60, 3: 80, 4: 140 };
const CHOICE_MAX = 24, CHOICE_WARN = 18, E_MAX = 100;
const NG_CHOICE = /すべて|全部正しい|どれでもない|どれも|以上のどれ|上記/;

const len = s => [...s].length;
const norm = s => s.replace(/[\s　、。,.・「」『』（）()？?！!]/g, '').normalize('NFKC');

function checkFile(file) {
  const base = path.basename(file);
  const m = base.match(/^([a-z]+)_lv([1-4])\.json$/);
  const errors = [], warns = [];
  if (!m) return { base, errors: ['ファイル名が {cat}_lv{1-4}.json ではない'], warns, items: [] };
  const lv = +m[2];
  let items;
  try { items = JSON.parse(fs.readFileSync(file, 'utf8')); }
  catch (e) { return { base, errors: ['JSONとして読めない: ' + e.message], warns, items: [] }; }
  if (!Array.isArray(items)) return { base, errors: ['配列ではない'], warns, items: [] };

  const seen = new Map();
  let longest = 0;
  items.forEach((it, i) => {
    const at = `#${i + 1}`;
    for (const k of ['q', 'a', 'e']) {
      if (typeof it[k] !== 'string' || !it[k].trim()) errors.push(`${at} ${k} が空/文字列でない`);
      else if (it[k] !== it[k].trim()) errors.push(`${at} ${k} の前後に空白`);
    }
    if (!Array.isArray(it.w) || it.w.length !== 5) { errors.push(`${at} w が5個の配列でない`); return; }
    const extra = Object.keys(it).filter(k => !['q', 'a', 'w', 'e', 'id', 'off', 'calc'].includes(k));
    if (extra.length) errors.push(`${at} 余計なキー: ${extra.join(',')}`);
    if ('id' in it && !(typeof it.id === 'string' && it.id.startsWith(`${m[1]}_lv${lv}_`) && /^[a-z]+_lv[1-4]_\d{3,}$/.test(it.id)))
      errors.push(`${at} id の形がおかしい: ${it.id}`);
    if ('off' in it && typeof it.off !== 'boolean') errors.push(`${at} off は true/false`);
    if ('calc' in it && it.calc !== true) errors.push(`${at} calc は付けるなら true だけ`);
    if (typeof it.q !== 'string' || typeof it.a !== 'string') return;
    const choices = [it.a, ...it.w];
    if (choices.some(c => typeof c !== 'string' || !c.trim())) { errors.push(`${at} 空の選択肢`); return; }
    if (new Set(choices.map(norm)).size !== 6) errors.push(`${at} 選択肢が重複（正解と誤答の重複を含む）: ${choices.join(' / ')}`);
    choices.forEach(c => {
      if (len(c) > CHOICE_MAX) errors.push(`${at} 選択肢が長すぎ(${len(c)}字>${CHOICE_MAX}): ${c}`);
      else if (len(c) > CHOICE_WARN) warns.push(`${at} 選択肢がやや長い(${len(c)}字): ${c}`);
      if (NG_CHOICE.test(c)) errors.push(`${at} 「すべて／どれでもない」型の選択肢: ${c}`);
    });
    if (len(it.q) > Q_MAX[lv]) errors.push(`${at} 問題文が長すぎ(${len(it.q)}字>${Q_MAX[lv]})`);
    if (typeof it.e === 'string' && len(it.e) > E_MAX) errors.push(`${at} 解説が長すぎ(${len(it.e)}字>${E_MAX})`);
    if (it.w.every(c => len(it.a) > len(c))) longest++;
    const key = norm(it.q);
    if (seen.has(key)) errors.push(`${at} 問題文が #${seen.get(key)} と重複`);
    else seen.set(key, i + 1);
  });
  if (items.length !== 100) warns.push(`問題数 ${items.length}（目標100）`);
  // id は build_seed.js が一度に振る。一部だけ無い＝差しかえのときに id を落とした
  // （そのままだと新しい id が振られ、古い問題がDBで出題され続ける）
  const withId = items.filter(it => it && it.id).length;
  if (withId && withId !== items.length) errors.push(`id の無い問題がある（${items.length - withId}問）。差しかえた問題には元の id を付け直す`);
  if (items.length && longest / items.length > 0.3)
    warns.push(`正解がいちばん長い選択肢になっている問題が ${longest}/${items.length}（長さで当てられる。誤答も同じくらいの長さに）`);
  return { base, errors, warns, items };
}

function report(r) {
  const head = `${r.base}: ${r.items.length}問 / エラー${r.errors.length} / 注意${r.warns.length}`;
  console.log(head);
  r.errors.forEach(s => console.log('  [エラー] ' + s));
  r.warns.forEach(s => console.log('  [注意] ' + s));
}

const args = process.argv.slice(2);
if (!args.length) { console.log('使い方: node validate.js <file.json> | --all'); process.exit(1); }
let bad = 0;
if (args[0] === '--all') {
  const files = fs.readdirSync(DIR).filter(f => /^[a-z]+_lv[1-4]\.json$/.test(f)).sort();
  const all = new Map(), ids = new Map();
  let total = 0;
  for (const f of files) {
    const r = checkFile(path.join(DIR, f));
    report(r); bad += r.errors.length; total += r.items.length;
    r.items.forEach((it, i) => {
      if (typeof it.q !== 'string') return;
      const key = norm(it.q);
      if (all.has(key)) { console.log(`  [重複] ${f} #${i + 1} = ${all.get(key)}: ${it.q}`); bad++; }
      else all.set(key, `${f} #${i + 1}`);
      if (it.id) {
        if (ids.has(it.id)) { console.log(`  [id重複] ${f} #${i + 1} = ${ids.get(it.id)}: ${it.id}`); bad++; }
        else ids.set(it.id, `${f} #${i + 1}`);
      }
    });
  }
  const byLv = {};
  files.forEach(f => { const lv = f.match(/_lv(\d)/)[1]; byLv[lv] = (byLv[lv] || 0) + JSON.parse(fs.readFileSync(path.join(DIR, f), 'utf8')).length; });
  console.log(`\n合計 ${total}問  ` + Object.keys(byLv).sort().map(k => `lv${k}=${byLv[k]}`).join(' '));
} else {
  for (const a of args) { const r = checkFile(path.resolve(a)); report(r); bad += r.errors.length; }
}
process.exit(bad ? 1 : 0);
