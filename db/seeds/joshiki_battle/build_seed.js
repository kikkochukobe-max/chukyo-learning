// 常識バトルの問題（このフォルダの *_lv{1-4}.json）から、DBに流すSQLを作る。
//   node build_seed.js          … id の無い問題に id を振って JSON に書き戻し、
//                                  db/seeds/seed_joshiki_battle_lv1.sql 〜 lv4.sql を作り直す
//   node build_seed.js --check  … 何も書かずに、振る予定の id の数と SQL の行数だけ出す
//
// id（例 kotoba_lv1_001）は battle_questions.src_key になる＝DBの行との結びつき。
// 一度振った id は変えない・使い回さない（並べ替えても、問題を直しても同じ id のまま）。
// SQL は INSERT … ON DUPLICATE KEY UPDATE なので、JSON を直して流し直すと同じ行が上書きされる
// （question_id は変わらない＝過去の対戦の記録もそのまま）。
// 問題を出題から外すときは JSON から消さずに "off": true を付ける（消すと DB には残り続ける）。
// "calc": true は計算が要る問題（needs_calc=1。難しい・超難で制限時間が長くなる）。
// needs_calc 列は db/migrations/migrate_joshiki_battle_calc.sql で足したもの（先に流すこと）。
'use strict';
const fs = require('fs');
const path = require('path');

const DIR = __dirname;
const OUT_DIR = path.join(DIR, '..');
const LABEL = { 1: '易しい', 2: '普通', 3: '難しい', 4: '超難' };
const CHUNK = 100;   // 1つの INSERT 文に入れる行数
const check = process.argv.includes('--check');

const files = fs.readdirSync(DIR).filter(f => /^[a-z]+_lv[1-4]\.json$/.test(f)).sort();
const byLevel = { 1: [], 2: [], 3: [], 4: [] };
let assigned = 0;

for (const f of files) {
  const [, cat, lvStr] = f.match(/^([a-z]+)_lv([1-4])\.json$/);
  const lv = +lvStr;
  const file = path.join(DIR, f);
  const items = JSON.parse(fs.readFileSync(file, 'utf8'));
  const prefix = `${cat}_lv${lv}_`;
  let max = 0;
  for (const it of items) {
    if (it.id) max = Math.max(max, +it.id.slice(prefix.length));
  }
  let changed = false;
  for (const it of items) {
    if (!it.id) {
      max++;
      it.id = prefix + String(max).padStart(3, '0');
      assigned++;
      changed = true;
    }
    byLevel[lv].push({ cat, it });
  }
  if (changed && !check) {
    // id を先頭に置き直して、1問1行で書き戻す（差分が読みやすいように）
    const lines = items.map(it => {
      const o = { id: it.id, q: it.q, a: it.a, w: it.w, e: it.e };
      if (it.calc) o.calc = true;
      if (it.off) o.off = true;
      return '  ' + JSON.stringify(o);
    });
    fs.writeFileSync(file, '[\n' + lines.join(',\n') + '\n]\n');
  }
}

function sq(s) {
  // MySQL の文字列リテラル（バックスラッシュと ' をエスケープ）
  return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "''").replace(/\r?\n/g, ' ') + "'";
}

for (const lv of [1, 2, 3, 4]) {
  const rows = byLevel[lv];
  if (!rows.length) continue;
  const values = rows.map(({ cat, it }) => '(' + [
    sq(it.id), lv, sq(cat), sq(it.q), sq(it.a),
    ...it.w.map(sq), sq(it.e), it.calc ? 1 : 0, it.off ? 0 : 1,
  ].join(', ') + ')');
  const out = path.join(OUT_DIR, `seed_joshiki_battle_lv${lv}.sql`);
  if (check) {
    console.log(`lv${lv}: ${rows.length}行 → ${path.basename(out)}`);
    continue;
  }
  let sql = `-- 常識バトルの問題 lv${lv}（${LABEL[lv]}）${rows.length}問\n`
    + '-- ⚠ 自動生成ファイル。手で直さない。db/seeds/joshiki_battle/*.json を直して\n'
    + '--   `node db/seeds/joshiki_battle/build_seed.js` で作り直す。\n'
    + '-- 先に db/migrations/migrate_joshiki_battle.sql を流しておくこと。何度流しても同じ行が上書きされるだけ。\n\n';
  for (let i = 0; i < values.length; i += CHUNK) {
    sql += 'INSERT INTO battle_questions\n'
      + '  (src_key, level, category, question_text, answer, wrong1, wrong2, wrong3, wrong4, wrong5, explanation, needs_calc, is_active)\nVALUES\n'
      + values.slice(i, i + CHUNK).join(',\n') + '\n'
      + 'ON DUPLICATE KEY UPDATE level = VALUES(level), category = VALUES(category), question_text = VALUES(question_text),\n'
      + '  answer = VALUES(answer), wrong1 = VALUES(wrong1), wrong2 = VALUES(wrong2), wrong3 = VALUES(wrong3),\n'
      + '  wrong4 = VALUES(wrong4), wrong5 = VALUES(wrong5), explanation = VALUES(explanation),\n'
      + '  needs_calc = VALUES(needs_calc), is_active = VALUES(is_active);\n\n';
  }
  fs.writeFileSync(out, sql);
  console.log(`lv${lv}: ${rows.length}問 → ${path.relative(path.join(OUT_DIR, '..', '..'), out)}`);
}
console.log(check ? `id を振る予定: ${assigned}問` : `id を振った問題: ${assigned}問`);
