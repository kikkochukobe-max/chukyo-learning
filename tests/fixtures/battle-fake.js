// 一般常識バトルのテスト用: api/battle_play.php と同じ約束で動く偽サーバー（tests/joshiki-battle.spec.js が使う）。
// 時刻は start_ms からの計算、正解は締め切り＋猶予のあとでしか返さない。1問2秒・正解発表1秒・カウントダウン1.5秒。
// opts.teams にチーム数を渡すとチーム戦の部屋になる（自分は team アクションで選ぶ。ライバルは2番のチーム）。
// opts.calc に seq の配列を渡すと、その問題だけ制限時間が calcSec 秒になる（計算問題。PHP の battle_schedule と同じ積み上げ）。
const PAGE = '/learning/game/game_es_joshiki_battle.html';

function makeFake(opts = {}) {
  const count = opts.count || 3, limit = 2, calcSec = 4, reveal = 1, grace = 300, countdown = opts.countdown || 1500;
  const calc = new Set(opts.calc || []);
  const schedule = [];
  for (let i = 0, off = 0; i < count; i++) { const lim = calc.has(i) ? calcSec : limit; schedule.push([off, lim]); off += (lim + reveal) * 1000; }
  const endOff = schedule[count - 1][0] + (schedule[count - 1][1] + reveal) * 1000;
  const openAt = (seq) => st.start + schedule[seq][0];
  const closeAt = (seq) => openAt(seq) + schedule[seq][1] * 1000;
  const qs = Array.from({ length: count }, (_, i) => ({
    text: '問題' + (i + 1) + 'のぶん',
    choices: ['あ', 'い', 'う', 'え', 'お', 'か'].map((c) => c + (i + 1)),
    correct: (i * 2) % 6,
    explanation: '解説' + (i + 1),
  }));
  const TEAM_DEF = [['赤', '#D9483B'], ['青', '#2F6FB5'], ['黄', '#C99A00'], ['緑', '#3E8E5A']];
  const teams = Array.from({ length: opts.teams || 0 }, (_, i) => ({ team: i + 1, name: TEAM_DEF[i][0], color: TEAM_DEF[i][1] }));
  const st = { status: 'lobby', start: null, me: 'none', answers: {}, dqReason: null, team: null };
  const roomPub = () => ({
    room_id: 9, code: '1234', level: 1, level_label: '易しい', count, limit_sec: limit, calc_sec: calcSec, reveal_sec: reveal, schedule,
    grace_ms: grace, point: 10, teams, practice: !!opts.practice, status: st.status, start_ms: st.start, phase: null, seq: null, now_ms: Date.now(),
  });
  const mePub = () => ({ status: st.me, team: st.team,dq_reason: st.me === 'dq' ? (st.dqReason || '画面を開き直した') : null, dq_no: null });
  const tick = () => { if (st.status === 'playing' && Date.now() >= st.start + endOff) st.status = 'finished'; };
  const fake = {
    st, qs,
    // 届いた通信の記録 [{action, t, failed}] と、わざと失敗させるしかけ。
    // trouble = { action: 'state' | '*', mode: '500' | 'hang', count: 回数 }
    //   '500' = サーバーのエラーを返す / 'hang' = 応答しない（画面側の打ち切りを確かめる）
    calls: [], trouble: null,
    start() { st.status = 'playing'; st.start = Date.now() + countdown; if (st.me === 'waiting') st.me = 'playing'; },
    handle(action, p) {
      tick();
      const now = Date.now();
      const base = { now_ms: now };
      switch (action) {
        case 'mine':
          return { ok: true, room: st.me === 'none' ? null : roomPub(), me: mePub(), ...base };
        case 'join':
          if (p.code !== '1234') return { ok: false, error: 'room_not_found', ...base };
          st.me = 'waiting';
          return { ok: true, room: roomPub(), me: mePub(), ...base };
        case 'leave':
          st.me = 'left';
          return { ok: true, ...base };
        case 'team':
          if (st.status !== 'lobby') return { ok: false, error: 'already_started', ...base };
          if (!(+p.team >= 1 && +p.team <= teams.length)) return { ok: false, error: 'invalid_team', ...base };
          st.team = +p.team;
          return { ok: true, room: roomPub(), me: mePub(), ...base };
        case 'state':
          return { ok: true, room: roomPub(), me: mePub(),
            players: [{ name: 'テスト太郎', classroom: '焼山', team: st.team }, { name: 'ライバル', classroom: '一社', team: teams.length ? 2 : null }], ...base };
        case 'question': {
          if (st.me !== 'playing' || st.status !== 'playing') return { ok: false, error: 'not_playing', room: roomPub(), me: mePub(), ...base };
          const seq = +p.seq, open = openAt(seq);
          if (now < open - 500) return { ok: false, error: 'not_yet', wait_ms: open - now, ...base };
          const q = qs[seq];
          return { ok: true, seq, question: { text: q.text, choices: q.choices, category: 'ことば' },
            open_ms: open, close_ms: closeAt(seq), answered: st.answers[seq] ?? null, ...base };
        }
        case 'answer': {
          if (st.me !== 'playing') return { ok: false, error: 'not_playing', room: roomPub(), me: mePub(), ...base };
          const seq = +p.seq, close = closeAt(seq);
          if (now > close + grace) return { ok: false, error: 'closed', ...base };
          st.answers[seq] = +p.choice;   // 締め切りまでは選び直すたびに上書き（PHP と同じ）
          return { ok: true, accepted: true, ...base };   // 正誤は返さない
        }
        case 'reveal': {
          const seq = +p.seq, close = closeAt(seq);
          if (now < close + grace) return { ok: false, error: 'not_yet', wait_ms: close + grace - now, ...base };
          const q = qs[seq], mine = st.answers[seq] ?? null;
          const correct = Object.keys(st.answers).filter((k) => +k <= seq && st.answers[k] === qs[k].correct).length;
          return { ok: true, seq, correct: q.correct, mine, is_correct: mine === q.correct, explanation: q.explanation,
            n_correct: mine === q.correct ? 1 : 0, n_playing: 1, score: correct * 10, me: mePub(), ...base };
        }
        case 'dq':
          // PHP と同じく、カウントダウン中（start_ms より前）の失格は受け付けない
          if (st.me === 'playing' && now >= st.start) { st.me = 'dq'; st.dqReason = p.reason; }
          return { ok: true, me: mePub(), ...base };
        case 'result': {
          if (st.status !== 'finished') return { ok: false, error: 'not_yet', wait_ms: Math.max(0, st.start + endOff - now), room: roomPub(), me: mePub(), ...base };
          const correct = qs.filter((q, i) => st.answers[i] === q.correct).length;
          const dq = st.me === 'dq';
          const mine = { name: 'テスト太郎', classroom: '焼山', correct, answered: Object.keys(st.answers).length, score: correct * 10,
            rank: dq ? null : 1, dq, dq_reason: dq ? '失格' : null, dq_no: null, me: true, team: st.team };
          const rival = { name: 'ライバル', classroom: '一社', correct: 0, answered: 0, score: 0, rank: dq ? 1 : 2, dq: false,
            dq_reason: null, dq_no: null, me: false, team: teams.length ? 2 : null };
          // opts.others: ほかの参加者 [{name, team, score}]（人数のちがうチームを作るため）
          const others = (opts.others || []).map((o) => ({ name: o.name, classroom: '吉根', correct: o.score / 10, answered: 1,
            score: o.score, rank: null, dq: false, dq_reason: null, dq_no: null, me: false, team: o.team }));
          // チームの順位（PHP の battle_rank_teams と同じ約束。平均点＝合計÷人数を小数1けたで比べ、高い順。
          // 同じ平均点は同じ順位で、並びは合計点の多い順→番号の若い順）
          const team_standings = teams.map((t) => {
            const mem = [mine, rival].concat(others).filter((s) => s.team === t.team);
            const total = mem.reduce((a, s) => a + s.score, 0);
            const avg10 = mem.length ? Math.round(10 * total / mem.length) : 0;
            return { ...t, members: mem.length, total, correct: total / 10, n_dq: mem.filter((s) => s.dq).length, avg10, avg: avg10 / 10 };
          }).filter((t) => t.members > 0).sort((a, b) => b.avg10 - a.avg10 || b.total - a.total || a.team - b.team);
          team_standings.forEach((t, i, arr) => { t.rank = i > 0 && arr[i - 1].avg10 === t.avg10 ? arr[i - 1].rank : i + 1; delete t.avg10; });
          return { ok: true, room: roomPub(), me: mePub(), mine, team_standings,
            standings: [mine, rival].concat(others),
            review: qs.map((q, i) => ({ no: i + 1, text: q.text, choices: q.choices, category: 'ことば', correct: q.correct,
              explanation: q.explanation, n_correct: 0, mine: st.answers[i] ?? null })), ...base };
        }
      }
      return { ok: false, error: 'unknown_action', ...base };
    },
  };
  return fake;
}

async function boot(page, fake, { loggedIn = true } = {}) {
  // sendBeacon は記録だけする（中身を確かめるため）。偽サーバーにも渡す
  await page.addInitScript(() => {
    window.__beacons = [];
    navigator.sendBeacon = function (url, body) { window.__beacons.push({ url: String(url), body: String(body) }); return true; };
  });
  await page.route('**/api/whoami.php', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify(loggedIn ? { ok: true, actor: { type: 'student', id: 1, name: 'テスト太郎' } } : { ok: false }),
  }));
  await page.route('**/api/battle_play.php**', (route) => {
    const req = route.request();
    let p;
    if (req.method() === 'POST') p = JSON.parse(req.postData() || '{}');
    else p = Object.fromEntries(new URL(req.url()).searchParams);
    const t = fake.trouble;
    const hit = !!(t && t.count > 0 && (t.action === '*' || t.action === p.action));
    fake.calls.push({ action: p.action, t: Date.now(), failed: hit });
    if (hit) {
      t.count--;
      if (t.mode === 'hang') return;   // 応答しない（route を放っておく）
      route.fulfill({ status: 500, contentType: 'text/html', body: '<h1>500 Internal Server Error</h1>' });
      return;
    }
    const body = fake.handle(p.action, p);
    route.fulfill({ status: body.ok ? 200 : 409, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto(PAGE);
}

module.exports = { makeFake, boot, PAGE };
