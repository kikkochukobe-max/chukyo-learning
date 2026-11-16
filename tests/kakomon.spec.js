/* 私立高校 過去問の記録（kakomon.php ＝ 生徒が入力する画面）の回帰テスト
   PHP はここでは動かないので、kakomon.php の後半（ログイン済みの画面のHTML）をそのまま読み、
   #kk-boot の JSON だけを差し替えて配信する。保存API(/api/save_kakomon.php)は
   api/save_kakomon.php と同じ約束で動く偽物（makeFake）で受ける。
   ⚠ API の応答の形や検証を変えたら makeFake も合わせること（合っていないと
     「テストは通るが本番で動かない」状態になる）。
   ・進み具合を押すと保存して表示が変わる
   ・高校の追加（リストから／手入力）→ 記録 → 進捗表に出る
   ・同じ高校・年度・科目の次の周目が自動で選ばれ、前の周の「できなかったところ」が出る
   ・得点率で入れる／なおす／けす
   ・記録がある高校は外せない、同じ回は二重に記録できない（サーバーの 409 を文言で出す）
   ・生徒が書いた文はタグとして描かれない                                              */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SRC = fs.readFileSync(path.resolve(__dirname, '..', 'kakomon.php'), 'utf8');
// 未ログイン用の小さなページが先にあるので、最後の DOCTYPE からがログイン済みの画面
const TEMPLATE = SRC.slice(SRC.lastIndexOf('<!DOCTYPE html>'));
const PAGE = '/kakomon-test.html';

const YEARS = [];
for (let y = 2026; y > 2016; y--) YEARS.push({ y, label: y + '年度' });

const META = {
  pub: { max: 22, groups: [{ key: 'A', label: 'Aグループ' }, { key: 'B', label: 'Bグループ' }], abLastYear: 2022, maxSchools: 2 },
  publicSchools: [{ id: 31, name: 'ひがし高校', region: null }, { id: 32, name: 'にし高校', region: null }, { id: 33, name: 'みなみ高校', region: null }],
  subjects: [
    { key: 'japanese', label: '国語' }, { key: 'math', label: '数学' }, { key: 'english', label: '英語' },
    { key: 'science', label: '理科' }, { key: 'social', label: '社会' },
  ],
  laps: 4,
  paces: [
    { key: 'fast', label: '早い', desc: '予定より進んでいる' },
    { key: 'normal', label: '普通', desc: '予定どおり' },
    { key: 'slow', label: '遅い', desc: '予定より遅れている' },
  ],
  years: YEARS,
  regions: [{ key: 'nagoya', label: '名古屋地区' }, { key: 'owari', label: '尾張地区' }, { key: 'mikawa', label: '三河地区' }],
  privateSchools: [
    { id: 11, name: 'さくら学園', region: 'nagoya' }, { id: 15, name: 'つばさ高校', region: 'nagoya' },
    { id: 12, name: 'みどり高校', region: 'owari' }, { id: 13, name: 'あおば高校', region: 'mikawa' },
    { id: 14, name: 'そら高校', region: null },
  ],
  textMax: 500,
  maxSchools: 3,
  scoreMax: 1000,
};
const PACE_LABEL = { fast: '早い', normal: '普通', slow: '遅い' };

const emptyState = () => ({
  pace: null, pace_label: null, pace_updated_label: null, schools: [], logs: [],
  pub: { pace: null, pace_label: null, pace_updated_label: null, schools: [], logs: [] },
});

// PHP の json_encode(JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS) と同じく < > & ' を \u 形式にする
function bootJson(boot) {
  return JSON.stringify(boot)
    .replace(/</g, '\\u003C').replace(/>/g, '\\u003E').replace(/&/g, '\\u0026').replace(/'/g, '\\u0027');
}

// api/save_kakomon.php と同じ約束で動く偽物
function makeFake(init) {
  const st = JSON.parse(JSON.stringify(init));
  let nextSchool = 100;
  let nextLog = 1000;
  const calls = [];
  const out = (extra) => {
    st.schools.forEach(s => { s.log_count = st.logs.filter(l => l.school_id === s.id).length; });
    return { status: 200, body: Object.assign({ ok: true, state: JSON.parse(JSON.stringify(st)) }, extra || {}) };
  };
  const fail = (error, status) => ({ status: status || 400, body: { ok: false, error } });
  const own = id => st.schools.some(s => s.id === id);
  function handle(p) {
    calls.push(p);
    if (p.action === 'pace') {
      if (!PACE_LABEL[p.pace]) return fail('invalid_pace');
      st.pace = p.pace; st.pace_label = PACE_LABEL[p.pace]; st.pace_updated_label = '11/16';
      return out();
    }
    if (p.action === 'add_school') {
      let name = String(p.name || '').trim();
      let tid = p.target_school_id || null;
      if (tid) {
        const m = META.privateSchools.find(s => s.id === tid);
        if (!m) return fail('invalid_school');
        name = m.name;
      } else if (!name) {
        return fail('invalid_school_name');
      }
      if (st.schools.some(s => (tid && s.target_school_id === tid) || s.name === name)) return fail('duplicate_school', 409);
      if (st.schools.length >= META.maxSchools) return fail('too_many_schools', 409);
      const id = nextSchool++;
      st.schools.push({ id, target_school_id: tid, name, log_count: 0 });
      return out({ school_id: id });
    }
    if (p.action === 'remove_school') {
      if (!own(p.school_id)) return fail('not_found', 404);
      if (st.logs.some(l => l.school_id === p.school_id)) return fail('school_has_logs', 409);
      st.schools = st.schools.filter(s => s.id !== p.school_id);
      return out();
    }
    if (p.action === 'save_log') {
      if (!own(p.school_id)) return fail('invalid_school');
      if (!p.year) return fail('invalid_year');
      if (!p.subject) return fail('invalid_subject');
      if (!(p.lap >= 1 && p.lap <= 4)) return fail('invalid_lap');
      const hasScore = p.score !== undefined && p.score !== null && String(p.score).trim() !== '';
      let rate;
      if (hasScore) {
        if (!(p.max_score >= 1) || p.score > p.max_score) return fail('invalid_score');
        rate = Math.round(100 * p.score / p.max_score);
      } else {
        if (!/^\d{1,3}(\.\d+)?$/.test(String(p.rate || ''))) return fail('invalid_rate');
        rate = Math.round(parseFloat(p.rate));
        if (rate > 100) return fail('invalid_rate');
      }
      const dup = st.logs.find(l => l.school_id === p.school_id && l.year === p.year
        && l.subject === p.subject && l.lap === p.lap && l.id !== p.log_id);
      if (dup) return fail('duplicate_log', 409);
      const rec = {
        school_id: p.school_id, year: p.year, subject: p.subject, lap: p.lap,
        score: hasScore ? p.score : null, max_score: hasScore ? p.max_score : null, rate,
        good: String(p.good || '').trim() || null, bad: String(p.bad || '').trim() || null,
        created_at: '2026-11-16 20:00:00', date_label: '11/16',
      };
      if (p.log_id) {
        const l = st.logs.find(x => x.id === p.log_id);
        if (!l) return fail('not_found', 404);
        Object.assign(l, rec);
        return out({ log_id: p.log_id });
      }
      rec.id = nextLog++;
      st.logs.unshift(rec);
      return out({ log_id: rec.id });
    }
    if (p.action === 'delete_log') {
      const before = st.logs.length;
      st.logs = st.logs.filter(l => l.id !== p.log_id);
      if (st.logs.length === before) return fail('not_found', 404);
      return out();
    }
    // ---- 公立 ----
    if (/^public_/.test(p.action) && !st.pub) return fail('schema_not_ready', 503);
    if (p.action === 'public_pace') {
      if (!PACE_LABEL[p.pace]) return fail('invalid_pace');
      st.pub.pace = p.pace; st.pub.pace_label = PACE_LABEL[p.pace]; st.pub.pace_updated_label = '11/16';
      return out();
    }
    if (p.action === 'public_add_school') {
      let name = String(p.name || '').trim();
      const tid = p.target_school_id || null;
      if (tid) {
        const m = META.publicSchools.find(x => x.id === tid);
        if (!m) return fail('invalid_school');
        name = m.name;
      } else if (!name) {
        return fail('invalid_school_name');
      }
      if (st.pub.schools.some(x => (tid && x.target_school_id === tid) || x.name === name)) return fail('duplicate_school', 409);
      if (st.pub.schools.length >= META.pub.maxSchools) return fail('too_many_public_schools', 409);
      const id = nextSchool++;
      st.pub.schools.push({ id, target_school_id: tid, name });
      return out({ school_id: id });
    }
    if (p.action === 'public_remove_school') {
      const before = st.pub.schools.length;
      st.pub.schools = st.pub.schools.filter(x => x.id !== p.school_id);
      if (st.pub.schools.length === before) return fail('not_found', 404);
      return out();
    }
    if (p.action === 'public_save_log') {
      if (!p.year) return fail('invalid_year');
      let group = String(p.group || '');
      if (p.year <= META.pub.abLastYear) {
        if (group !== 'A' && group !== 'B') return fail('invalid_group');
      } else {
        group = '';
      }
      if (!p.subject) return fail('invalid_subject');
      if (!(p.lap >= 1 && p.lap <= 4)) return fail('invalid_lap');
      if (!Number.isInteger(p.score) || p.score < 0 || p.score > META.pub.max) return fail('invalid_public_score');
      const dup = st.pub.logs.find(l => l.year === p.year && l.group === group && l.subject === p.subject
        && l.lap === p.lap && l.id !== p.log_id);
      if (dup) return fail('duplicate_log', 409);
      const rec = {
        year: p.year, group, subject: p.subject, lap: p.lap, score: p.score,
        rate: Math.round(100 * p.score / META.pub.max),
        good: String(p.good || '').trim() || null, bad: String(p.bad || '').trim() || null,
        created_at: '2026-11-16 20:00:00', date_label: '11/16',
      };
      if (p.log_id) {
        const l = st.pub.logs.find(x => x.id === p.log_id);
        if (!l) return fail('not_found', 404);
        Object.assign(l, rec);
        return out({ log_id: p.log_id });
      }
      rec.id = nextLog++;
      st.pub.logs.unshift(rec);
      return out({ log_id: rec.id });
    }
    if (p.action === 'public_delete_log') {
      const before = st.pub.logs.length;
      st.pub.logs = st.pub.logs.filter(l => l.id !== p.log_id);
      if (st.pub.logs.length === before) return fail('not_found', 404);
      return out();
    }
    return fail('invalid_action');
  }
  return { st, calls, handle };
}

async function open(page, boot) {
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  page.on('dialog', d => d.accept());
  const fake = makeFake(boot.state || emptyState());
  const html = TEMPLATE.replace('<?= $bootJson ?>', bootJson(boot));
  await page.route(/fonts\.(googleapis|gstatic)\.com|chukyokobetsu\.com/, r => r.abort());
  await page.route('**/kakomon-test.html', r => r.fulfill({ contentType: 'text/html; charset=utf-8', body: html }));
  await page.route('**/api/save_kakomon.php', async r => {
    const res = fake.handle(JSON.parse(r.request().postData() || '{}'));
    await r.fulfill({ status: res.status, contentType: 'application/json', body: JSON.stringify(res.body) });
  });
  await page.goto(PAGE);
  return { fake, errors };
}

const readyBoot = (state) => ({
  ready: true, student: { name: 'テスト太郎', classroom: '焼山' }, state: state || emptyState(), meta: META,
});

async function addSchoolFromList(page, id) {
  await page.click('#kkAddOpen');
  await page.selectOption('#kkAddSel', String(id));
  await page.click('#kkAddBtn');
}

test('テンプレートの PHP は boot の JSON 1か所だけ（ほかに式を足すとこのテストが読めなくなる）', async () => {
  const tags = TEMPLATE.match(/<\?/g) || [];
  expect(tags).toEqual(['<?']);
  expect(TEMPLATE).toContain('<?= $bootJson ?>');
});

test('テーブルが無い（準備前）は「準備中」だけを出す', async ({ page }) => {
  const { errors } = await open(page, { ready: false, student: { name: 'テスト太郎', classroom: '焼山' }, state: null, meta: null });
  await expect(page.locator('#kkNotReady')).toBeVisible();
  await expect(page.locator('#kkMain')).toBeHidden();
  expect(errors).toEqual([]);
});

test('進み具合は高校を登録してから出し、押すと保存して表示が変わる', async ({ page }) => {
  const { fake, errors } = await open(page, readyBoot());
  await expect(page.locator('#kkWho')).toHaveText('テスト太郎 さん・焼山教室');
  // 高校が無いうちは出さない
  await expect(page.locator('#kkPaceCard')).toBeHidden();
  await addSchoolFromList(page, 11);
  await expect(page.locator('#kkPaceCard')).toBeVisible();
  await expect(page.locator('#kkPaceNote')).toContainText('まだえらんでいません');
  await expect(page.locator('#kkPaces .pace')).toHaveText([/早い/, /普通/, /遅い/]);

  await page.click('#kkPaces .pace[data-pace="slow"]');
  await expect(page.locator('#kkPaces .pace[data-pace="slow"]')).toHaveClass(/on/);
  await expect(page.locator('#kkPaceNote')).toContainText('「遅い」');
  expect(fake.calls.at(-1)).toEqual({ action: 'pace', pace: 'slow' });
  expect(errors).toEqual([]);
});

test('高校を追加して記録すると進捗表に出て、次の周目と前回のできなかったところが出る', async ({ page }) => {
  const { fake, errors } = await open(page, readyBoot());
  // 高校が無いうちはフォームを出さない
  await expect(page.locator('#kkNoSchool')).toBeVisible();
  await expect(page.locator('#kkForm')).toBeHidden();

  // 追加のプルダウンは地区ごとに区切り、地区の無い学校は最後の「その他」にまとめる
  const groups = page.locator('#kkAddSel optgroup');
  await expect(groups).toHaveCount(4);
  expect(await groups.evaluateAll(gs => gs.map(g => g.label + ':' + [...g.children].map(o => o.textContent).join(','))))
    .toEqual(['名古屋地区:さくら学園,つばさ高校', '尾張地区:みどり高校', '三河地区:あおば高校', 'その他:そら高校']);
  await expect(page.locator('#kkAddSel > option')).toHaveText(['高校をえらぶ', 'リストにない高校（自分で入力）']);

  // リストから追加 → 1校だけなので記録フォームで選ばれた状態になる
  await addSchoolFromList(page, 11);
  expect(fake.calls.at(-1)).toEqual({ action: 'add_school', target_school_id: 11 });
  await expect(page.locator('#kkSchools .sch')).toHaveCount(1);
  await expect(page.locator('#kkSchools .sch')).toContainText('さくら学園');
  await expect(page.locator('#kkForm')).toBeVisible();
  await expect(page.locator('#kkFSchool .chip.on')).toHaveText('さくら学園');
  // 追加した高校は追加用の選択肢から消える
  await page.click('#kkAddOpen');
  await expect(page.locator('#kkAddSel option[value="11"]')).toHaveCount(0);
  // リストに無い高校は手入力
  await page.selectOption('#kkAddSel', 'other');
  await expect(page.locator('#kkAddName')).toBeVisible();
  await page.fill('#kkAddName', 'ひかり高校');
  await page.click('#kkAddBtn');
  expect(fake.calls.at(-1)).toEqual({ action: 'add_school', name: 'ひかり高校' });
  await expect(page.locator('#kkSchools .sch')).toHaveCount(2);
  await expect(page.locator('#kkAddForm')).toBeHidden();
  // 足した高校がそのまま選ばれる
  await expect(page.locator('#kkFSchool .chip.on')).toHaveText('ひかり高校');

  // 年度は西暦だけ（和暦を併記しない）
  await expect(page.locator('#kkFYear option[value="2025"]')).toHaveText('2025年度');

  // 年度・科目を選ぶと 1周目 が自動で選ばれる
  await page.click('#kkFSchool .chip:has-text("さくら学園")');
  await page.selectOption('#kkFYear', '2025');
  await page.click('#kkFSubj .chip[data-key="math"]');
  await expect(page.locator('#kkFLap .chip.on')).toHaveText('1周目');
  await page.fill('#kkScore', '72');
  await expect(page.locator('#kkCalc')).toHaveText('→ 72%');
  await page.fill('#kkGood', '大問1の計算');
  await page.fill('#kkBad', '大問4の関数(2)');
  await page.click('#kkSave');
  await expect(page.locator('#kkFormMsg')).toHaveText('記録しました: 2025年度 数学 1周目');
  expect(fake.calls.at(-1)).toEqual({
    action: 'save_log', school_id: 100, year: 2025, subject: 'math', lap: 1,
    good: '大問1の計算', bad: '大問4の関数(2)', score: 72, max_score: 100,
  });

  // 高校・年度・満点は残り、科目と点数は空になる（同じ年度の次の科目をすぐ書ける）
  await expect(page.locator('#kkFSchool .chip.on')).toHaveText('さくら学園');
  await expect(page.locator('#kkFYear')).toHaveValue('2025');
  await expect(page.locator('#kkFSubj .chip.on')).toHaveCount(0);
  await expect(page.locator('#kkScore')).toHaveValue('');
  await expect(page.locator('#kkMax')).toHaveValue('100');

  // 進捗表: さくら学園の 2025年度×数学 に ①72%
  const board = page.locator('#kkBoards .board', { hasText: 'さくら学園' });
  await expect(board.locator('.b-sum')).toContainText('記録 1回・平均 72%');
  await expect(board.locator('table.grid thead th')).toHaveText(['年度', '数学']);
  await expect(board.locator('table.grid tbody th')).toHaveText(['2025']);
  await expect(board.locator('table.grid .lp')).toHaveText('①72%');
  await expect(board.locator('table.grid .lp')).toHaveClass(/r-mid/);
  await expect(page.locator('#kkSchools .sch', { hasText: 'さくら学園' })).toContainText('1回');
  await expect(page.locator('#kkBoards .board', { hasText: 'ひかり高校' })).toContainText('まだ記録がありません');

  // もう一度 数学 → 1周目は「済」で押せず、2周目が選ばれ、1周目のできなかったところが出る
  await page.click('#kkFSubj .chip[data-key="math"]');
  await expect(page.locator('#kkFLap .chip[data-lap="1"]')).toBeDisabled();
  await expect(page.locator('#kkFLap .chip[data-lap="1"]')).toContainText('済');
  await expect(page.locator('#kkFLap .chip.on')).toHaveText('2周目');
  await expect(page.locator('#kkPrev')).toBeVisible();
  await expect(page.locator('#kkPrev')).toContainText('1周目のときは 72%');
  await expect(page.locator('#kkPrev')).toContainText('大問4の関数(2)');
  // 別の年度にすると 1周目 に戻る
  await page.selectOption('#kkFYear', '2024');
  await expect(page.locator('#kkFLap .chip.on')).toHaveText('1周目');
  await expect(page.locator('#kkPrev')).toBeHidden();
  expect(errors).toEqual([]);
});

test('得点率で入れる・なおす・けす', async ({ page }) => {
  const state = emptyState();
  state.schools = [{ id: 5, target_school_id: 12, name: 'みどり高校', log_count: 1 }];
  state.logs = [{
    id: 50, school_id: 5, year: 2026, subject: 'english', lap: 1, score: 45, max_score: 100, rate: 45,
    good: null, bad: '長文', created_at: '2026-11-10 19:00:00', date_label: '11/10',
  }];
  const { fake, errors } = await open(page, readyBoot(state));

  const board = page.locator('#kkBoards .board', { hasText: 'みどり高校' });
  await expect(board.locator('table.grid .lp')).toHaveText('①45%');
  await expect(board.locator('table.grid .lp')).toHaveClass(/r-low/);

  // 得点率で 2周目 を入れる
  await page.selectOption('#kkFYear', '2026');
  await page.click('#kkFSubj .chip[data-key="english"]');
  await expect(page.locator('#kkFLap .chip.on')).toHaveText('2周目');
  await page.click('#kkModes .mode[data-mode="rate"]');
  await expect(page.locator('#kkPointRow')).toBeHidden();
  await page.fill('#kkRate', '85');
  await page.click('#kkSave');
  expect(fake.calls.at(-1)).toEqual({
    action: 'save_log', school_id: 5, year: 2026, subject: 'english', lap: 2, good: '', bad: '', rate: '85',
  });
  await expect(board.locator('table.grid .lp')).toHaveText(['①45%', '②85%']);
  await expect(board.locator('table.grid .lp').nth(1)).toHaveClass(/r-good/);

  // くわしい記録から 1周目 をなおす
  await board.locator('details.logs summary').click();
  await expect(board.locator('.lg')).toHaveCount(2);
  await board.locator('.lg', { hasText: '1周目' }).locator('[data-edit]').click();
  await expect(page.locator('#kkRec')).toHaveClass(/editing/);
  await expect(page.locator('#kkFormTitle')).toHaveText('記録をなおす（2026年度 英語 1周目）');
  await expect(page.locator('#kkFYear')).toHaveValue('2026');
  await expect(page.locator('#kkFSubj .chip.on')).toHaveText('英語');
  await expect(page.locator('#kkFLap .chip.on')).toHaveText('1周目');
  // なおしている回のほかに 2周目 が埋まっている
  await expect(page.locator('#kkFLap .chip[data-lap="2"]')).toBeDisabled();
  await expect(page.locator('#kkModes .mode.on')).toHaveText('点数で入れる');
  await expect(page.locator('#kkScore')).toHaveValue('45');
  await expect(page.locator('#kkBad')).toHaveValue('長文');
  await page.fill('#kkScore', '52');
  await page.click('#kkSave');
  expect(fake.calls.at(-1)).toEqual({
    action: 'save_log', school_id: 5, year: 2026, subject: 'english', lap: 1, good: '', bad: '長文',
    log_id: 50, score: 52, max_score: 100,
  });
  await expect(page.locator('#kkFormMsg')).toHaveText('なおしました: 2026年度 英語 1周目');
  await expect(page.locator('#kkRec')).not.toHaveClass(/editing/);
  await expect(page.locator('#kkFormTitle')).toHaveText('結果を記録する');
  await expect(board.locator('table.grid .lp').first()).toHaveText('①52%');
  // 描き直しても「くわしい記録」は開いたまま
  await expect(board.locator('details.logs')).toHaveAttribute('open', '');

  // けす（確認ダイアログは accept）
  await board.locator('.lg', { hasText: '2周目' }).locator('[data-del]').click();
  expect(fake.calls.at(-1)).toEqual({ action: 'delete_log', log_id: 1000 });
  await expect(board.locator('table.grid .lp')).toHaveText(['①52%']);
  expect(errors).toEqual([]);
});

test('記録がある高校は外せない・同じ回は二重に記録できない', async ({ page }) => {
  const state = emptyState();
  state.schools = [
    { id: 5, target_school_id: 12, name: 'みどり高校', log_count: 1 },
    { id: 6, target_school_id: null, name: 'ひかり高校', log_count: 0 },
  ];
  state.logs = [{
    id: 50, school_id: 5, year: 2026, subject: 'math', lap: 1, score: null, max_score: null, rate: 60,
    good: null, bad: null, created_at: '2026-11-10 19:00:00', date_label: '11/10',
  }];
  const { fake, errors } = await open(page, readyBoot(state));

  // 記録がある高校の × は API を呼ばずに案内だけ
  await page.locator('#kkSchools .sch', { hasText: 'みどり高校' }).locator('.x').click();
  await expect(page.locator('#kkSchoolMsg')).toContainText('記録があるので外せません');
  expect(fake.calls).toEqual([]);
  // 記録が無い高校は外せる
  await page.locator('#kkSchools .sch', { hasText: 'ひかり高校' }).locator('.x').click();
  expect(fake.calls.at(-1)).toEqual({ action: 'remove_school', school_id: 6 });
  await expect(page.locator('#kkSchools .sch')).toHaveCount(1);

  // 同じ高校をもう一度足すと、サーバーの 409 を文言で出す
  await page.click('#kkAddOpen');
  await page.selectOption('#kkAddSel', 'other');
  await page.fill('#kkAddName', 'みどり高校');
  await page.click('#kkAddBtn');
  await expect(page.locator('#kkSchoolMsg')).toHaveText('その高校はもう登録してあります');

  // 未選択のまま保存すると、送らずに案内する
  await page.click('#kkSave');
  await expect(page.locator('#kkFormMsg')).toHaveText('年度をえらんでください');
  const before = fake.calls.length;
  await page.selectOption('#kkFYear', '2026');
  await page.click('#kkFSubj .chip[data-key="math"]');
  await page.fill('#kkScore', '120');
  await page.click('#kkSave');
  await expect(page.locator('#kkFormMsg')).toContainText('満点より大きくはできません');
  expect(fake.calls.length).toBe(before);

  // 画面の状態が古くて同じ回を送ってしまった場合も、サーバーの 409 を文言で出す
  fake.st.logs.push({
    id: 51, school_id: 5, year: 2026, subject: 'math', lap: 2, score: null, max_score: null, rate: 70,
    good: null, bad: null, created_at: '2026-11-11 19:00:00', date_label: '11/11',
  });
  await expect(page.locator('#kkFLap .chip.on')).toHaveText('2周目');
  await page.fill('#kkScore', '80');
  await page.click('#kkSave');
  await expect(page.locator('#kkFormMsg')).toContainText('その回はもう記録してあります');
  expect(errors).toEqual([]);
});

test('高校は3校まで（3校目を足すと追加の入口を閉じ、1校外すと戻る）', async ({ page }) => {
  const state = emptyState();
  state.schools = [
    { id: 5, target_school_id: 12, name: 'みどり高校', log_count: 0 },
    { id: 6, target_school_id: null, name: 'ひかり高校', log_count: 0 },
  ];
  const { fake, errors } = await open(page, readyBoot(state));
  await expect(page.locator('#kkSchoolFull')).toBeHidden();
  await addSchoolFromList(page, 13);
  expect(fake.calls.at(-1)).toEqual({ action: 'add_school', target_school_id: 13 });
  await expect(page.locator('#kkSchools .sch')).toHaveCount(3);
  await expect(page.locator('#kkAddOpen')).toBeHidden();
  await expect(page.locator('#kkAddForm')).toBeHidden();
  await expect(page.locator('#kkSchoolFull')).toHaveText('高校は3校まで登録できます（入れかえるときは × で外してね）');

  await page.locator('#kkSchools .sch', { hasText: 'ひかり高校' }).locator('.x').click();
  await expect(page.locator('#kkSchools .sch')).toHaveCount(2);
  await expect(page.locator('#kkAddOpen')).toBeVisible();
  await expect(page.locator('#kkSchoolFull')).toBeHidden();
  expect(errors).toEqual([]);
});

test('地区の無い環境（region 列が無い）は区切らずに並べる', async ({ page }) => {
  const boot = readyBoot();
  boot.meta = Object.assign({}, META, {
    privateSchools: META.privateSchools.map(p => ({ id: p.id, name: p.name, region: null })),
  });
  const { errors } = await open(page, boot);
  await expect(page.locator('#kkAddSel optgroup')).toHaveCount(0);
  await expect(page.locator('#kkAddSel option')).toHaveText([
    '高校をえらぶ', 'さくら学園', 'つばさ高校', 'みどり高校', 'あおば高校', 'そら高校', 'リストにない高校（自分で入力）',
  ]);
  expect(errors).toEqual([]);
});

test('私立／公立のタブを切りかえ、最後に開いた方を覚えておく', async ({ page }) => {
  const { errors } = await open(page, readyBoot());
  await expect(page.locator('#kkTabs .ktab.on')).toHaveText('私立高校');
  await expect(page.locator('#kkMain')).toBeVisible();
  await expect(page.locator('#kkPub')).toBeHidden();
  await page.click('#kkTabs .ktab[data-tab="public"]');
  await expect(page.locator('#kkMain')).toBeHidden();
  await expect(page.locator('#kkPub')).toBeVisible();
  await expect(page.locator('#kkPubBody')).toBeVisible();
  await expect(page.locator('#kkPubSchools')).toContainText('まだ登録していません');
  await page.reload();
  await expect(page.locator('#kkTabs .ktab.on')).toHaveText('公立高校');
  await expect(page.locator('#kkPub')).toBeVisible();
  expect(errors).toEqual([]);
});

test('公立: 22点満点で記録し、2022年度まではA/Bを選ぶ。表に5科の合計が出る', async ({ page }) => {
  const { fake, errors } = await open(page, readyBoot());
  await page.click('#kkTabs .ktab[data-tab="public"]');
  await expect(page.locator('#kkPubSub')).toHaveText('愛知県公立高校入試（各教科22点満点）');
  await expect(page.locator('#kkPMaxLbl')).toHaveText('点 ／ 22点');

  // 受ける公立高校: 登録するまで進み具合は出さない。2校まで（3校目の入口は閉じる）
  await expect(page.locator('#kkPubPaceCard')).toBeHidden();
  await page.click('#kkPubAddOpen');
  await page.selectOption('#kkPubAddSel', '31');
  await page.click('#kkPubAddBtn');
  expect(fake.calls.at(-1)).toEqual({ action: 'public_add_school', target_school_id: 31 });
  await expect(page.locator('#kkPubPaceCard')).toBeVisible();
  await page.click('#kkPubAddOpen');
  await expect(page.locator('#kkPubAddSel option[value="31"]')).toHaveCount(0);
  await page.selectOption('#kkPubAddSel', 'other');
  await page.fill('#kkPubAddName', 'きた高校');
  await page.click('#kkPubAddBtn');
  expect(fake.calls.at(-1)).toEqual({ action: 'public_add_school', name: 'きた高校' });
  await expect(page.locator('#kkPubSchools .sch')).toHaveText(['ひがし高校×', 'きた高校×']);
  await expect(page.locator('#kkPubAddOpen')).toBeHidden();
  await expect(page.locator('#kkPubSchoolFull')).toHaveText('公立高校は2校まで登録できます（入れかえるときは × で外してね）');
  // 公立の高校は記録とひもづかないので、いつでも外せる
  await page.locator('#kkPubSchools .sch', { hasText: 'きた高校' }).locator('.x').click();
  expect(fake.calls.at(-1)).toEqual({ action: 'public_remove_school', school_id: 101 });
  await expect(page.locator('#kkPubSchools .sch')).toHaveCount(1);
  await expect(page.locator('#kkPubAddOpen')).toBeVisible();

  // 進み具合
  await page.click('#kkPubPaces .pace[data-pace="normal"]');
  expect(fake.calls.at(-1)).toEqual({ action: 'public_pace', pace: 'normal' });
  await expect(page.locator('#kkPubPaceNote')).toContainText('「普通」');

  // 2024年度（1回だけの年度）は A/B を出さない
  await page.selectOption('#kkPFYear', '2024');
  await expect(page.locator('#kkPFGroupRow')).toBeHidden();
  await page.click('#kkPFSubj .chip[data-key="math"]');
  await expect(page.locator('#kkPFLap .chip.on')).toHaveText('1周目');
  await page.fill('#kkPScore', '15');
  await expect(page.locator('#kkPCalc')).toHaveText('→ 68%');
  await page.fill('#kkPBad', '大問3の図形');
  await page.click('#kkPSave');
  await expect(page.locator('#kkPFormMsg')).toHaveText('記録しました: 2024年度 数学 1周目');
  expect(fake.calls.at(-1)).toEqual({
    action: 'public_save_log', year: 2024, group: '', subject: 'math', lap: 1, score: 15, good: '', bad: '大問3の図形',
  });
  // 年度は残り、科目と点数は空になる
  await expect(page.locator('#kkPFYear')).toHaveValue('2024');
  await expect(page.locator('#kkPFSubj .chip.on')).toHaveCount(0);
  await expect(page.locator('#kkPScore')).toHaveValue('');

  // 23点は入れられない
  await page.click('#kkPFSubj .chip[data-key="english"]');
  await page.fill('#kkPScore', '23');
  const before = fake.calls.length;
  await page.click('#kkPSave');
  await expect(page.locator('#kkPFormMsg')).toHaveText('点数は0〜22点で入れてください');
  expect(fake.calls.length).toBe(before);
  await page.fill('#kkPScore', '20');
  await page.click('#kkPSave');

  // 2022年度は A/B を選ぶまで保存しない
  await page.selectOption('#kkPFYear', '2022');
  await expect(page.locator('#kkPFGroupRow')).toBeVisible();
  await expect(page.locator('#kkPFGroup .chip')).toHaveText(['Aグループ', 'Bグループ']);
  await page.click('#kkPFSubj .chip[data-key="math"]');
  await page.fill('#kkPScore', '11');
  await page.click('#kkPSave');
  await expect(page.locator('#kkPFormMsg')).toHaveText('AグループかBグループかをえらんでください');
  await page.click('#kkPFGroup .chip[data-key="B"]');
  await page.fill('#kkPScore', '11');
  await page.click('#kkPSave');
  expect(fake.calls.at(-1)).toEqual({
    action: 'public_save_log', year: 2022, group: 'B', subject: 'math', lap: 1, score: 11, good: '', bad: '',
  });
  // 2023年度以降にもどすと A/B は消える（送るときは ''）
  await page.selectOption('#kkPFYear', '2024');
  await expect(page.locator('#kkPFGroupRow')).toBeHidden();
  // 2024年度の数学は 1周目が済み → 2周目、前回のできなかったところが出る
  await page.click('#kkPFSubj .chip[data-key="math"]');
  await expect(page.locator('#kkPFLap .chip[data-lap="1"]')).toBeDisabled();
  await expect(page.locator('#kkPFLap .chip.on')).toHaveText('2周目');
  await expect(page.locator('#kkPPrev')).toContainText('1周目のときは 15点');
  await expect(page.locator('#kkPPrev')).toContainText('大問3の図形');

  // 表: 年度（グループ）× 5科目 ＋ 合計（そろっていない周は何科ぶんか）
  const board = page.locator('#kkPubBoard .board');
  await expect(board.locator('.b-name')).toHaveText('愛知県公立高校入試');
  await expect(board.locator('table.grid thead th')).toHaveText(['年度', '国語', '数学', '英語', '理科', '社会', '合計／110']);
  await expect(board.locator('table.grid tbody th')).toHaveText(['2024', '2022Bグループ']);
  const row2024 = board.locator('table.grid tbody tr').first();
  await expect(row2024.locator('td').nth(1)).toHaveText('①15');
  await expect(row2024.locator('td').nth(2)).toHaveText('①20');
  await expect(row2024.locator('td.total')).toHaveText('①35（2科）');
  await expect(row2024.locator('td').nth(1).locator('.lp')).toHaveClass(/r-mid/);
  expect(errors).toEqual([]);
});

test('公立: なおす・けす', async ({ page }) => {
  const state = emptyState();
  state.pub.logs = [
    { id: 70, year: 2022, group: 'A', subject: 'social', lap: 1, score: 9, rate: 41, good: null, bad: '歴史の並べかえ',
      created_at: '2026-11-10 19:00:00', date_label: '11/10' },
  ];
  const { fake, errors } = await open(page, readyBoot(state));
  await page.click('#kkTabs .ktab[data-tab="public"]');
  const board = page.locator('#kkPubBoard .board');
  await expect(board.locator('table.grid tbody th')).toHaveText(['2022Aグループ']);
  await expect(board.locator('table.grid td.total')).toHaveText('①9（1科）');

  await board.locator('details.logs summary').click();
  await expect(board.locator('.lg1')).toContainText('2022年度 Aグループ');
  await board.locator('[data-pedit="70"]').click();
  await expect(page.locator('#kkPubRec')).toHaveClass(/editing/);
  await expect(page.locator('#kkPubFormTitle')).toHaveText('記録をなおす（2022年度 Aグループ 社会 1周目）');
  await expect(page.locator('#kkPFYear')).toHaveValue('2022');
  await expect(page.locator('#kkPFGroup .chip.on')).toHaveText('Aグループ');
  await expect(page.locator('#kkPScore')).toHaveValue('9');
  await page.fill('#kkPScore', '13');
  await page.click('#kkPSave');
  expect(fake.calls.at(-1)).toEqual({
    action: 'public_save_log', year: 2022, group: 'A', subject: 'social', lap: 1, score: 13, good: '', bad: '歴史の並べかえ', log_id: 70,
  });
  await expect(page.locator('#kkPubRec')).not.toHaveClass(/editing/);
  await expect(board.locator('table.grid td.total')).toHaveText('①13（1科）');
  await expect(board.locator('details.logs')).toHaveAttribute('open', '');

  await board.locator('[data-pdel="70"]').click();
  expect(fake.calls.at(-1)).toEqual({ action: 'public_delete_log', log_id: 70 });
  await expect(board).toContainText('まだ記録がありません');
  expect(errors).toEqual([]);
});

test('公立のテーブルが無い（準備前）は公立タブだけ「準備中」', async ({ page }) => {
  const state = emptyState();
  state.pub = null;
  const { errors } = await open(page, readyBoot(state));
  await expect(page.locator('#kkMain')).toBeVisible();
  await page.click('#kkTabs .ktab[data-tab="public"]');
  await expect(page.locator('#kkPubNotReady')).toBeVisible();
  await expect(page.locator('#kkPubBody')).toBeHidden();
  expect(errors).toEqual([]);
});

test('生徒が書いた文はタグとして描かれない', async ({ page }) => {
  const state = emptyState();
  state.schools = [{ id: 5, target_school_id: null, name: '<b>太字</b>高校', log_count: 1 }];
  state.logs = [{
    id: 50, school_id: 5, year: 2026, subject: 'math', lap: 1, score: null, max_score: null, rate: 60,
    good: '<img src=x onerror="window.__xss=1">', bad: '</script><script>window.__xss=2</script>',
    created_at: '2026-11-10 19:00:00', date_label: '11/10',
  }];
  const { errors } = await open(page, readyBoot(state));
  await expect(page.locator('#kkSchools .sch')).toContainText('<b>太字</b>高校');
  const board = page.locator('#kkBoards .board');
  await board.locator('details.logs summary').click();
  await expect(board.locator('.lgt').first()).toContainText('<img src=x');
  await expect(board.locator('.lgt').nth(1)).toContainText('</script>');
  expect(await page.evaluate(() => window.__xss)).toBeUndefined();
  await expect(page.locator('#kkSchools b')).toHaveCount(0);
  expect(errors).toEqual([]);
});
