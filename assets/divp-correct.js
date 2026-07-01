/*! divp-correct.js — 正解エフェクト共通モジュール（分度器マスター方式）
 *  9色のカラフルな星が中央から放射状に散り、上からもパラパラ降り、
 *  画面中央に「せいかい！」の金文字がポンッと出る。
 *
 *  使い方:
 *    <script src="../assets/divp-correct.js"></script>
 *    Divp.correct();                                  // 既定
 *    Divp.correct({ target: el });                    // 星の発生源を要素中央に
 *    Divp.correct({ text:'よくできました！' });         // 文言を変更（'' で文字なし）
 *    Divp.correct({ count:90, speed:1.3, duration:1.4 });
 *    Divp.correct({ rain:false, sound:true });
 *
 *  調節できるオプション（すべて任意）:
 *    text     : 中央に出す文字（既定 '正解！'、空文字で非表示）
 *    count    : 星の総数（既定 100）
 *    speed    : 飛び散る速さの倍率（既定 1.1）
 *    gravity  : 落ちる速さ＝重力の倍率（既定 1）
 *    duration : 星の寿命の倍率＝長く残す（既定 1.3）
 *    rain     : 上から降らせるか（既定 true）
 *    colors   : 星の色の配列（既定は9色）
 *    sound    : キラッと効果音（既定 false）
 *    target   : 発生源。要素 or {x,y}（既定 画面中央）
 *
 *  依存なし / CSS自動注入 / 多重読み込み安全 / prefers-reduced-motion 配慮
 */
(function (global) {
  'use strict';
  var Divp = global.Divp = global.Divp || {};
  if (Divp.correct && Divp.correct._divp) return;

  var PFX = 'divp-fx';
  var reduce = !!(global.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  var STAR_COLORS = ['#FFD93D','#FF6BAA','#4DD0E1','#7CE07C','#B388FF','#FF9F4D','#FF5C5C','#FFE066','#5CE1E6'];

  /* ---------- CSS（1回だけ注入） ---------- */
  function injectCSS() {
    if (document.getElementById(PFX + '-style')) return;
    var c =
      '.'+PFX+'-layer{position:fixed;inset:0;z-index:2147483000;pointer-events:none;overflow:hidden;}'
    + '.'+PFX+'-canvas{position:absolute;inset:0;width:100%;height:100%;}'
    + '.'+PFX+'-text{position:absolute;left:50%;top:42%;transform:translate(-50%,-50%);'
        +'font-family:"Hiragino Sans","Yu Gothic UI","Yu Gothic",system-ui,sans-serif;'
        +'font-weight:900;font-size:clamp(40px,11vw,92px);letter-spacing:2px;white-space:nowrap;'
        +'color:#FFD93D;'
        +'text-shadow:0 0 2px #fff,2px 0 #fff,-2px 0 #fff,0 2px #fff,0 -2px #fff,'
          +'2px 2px #fff,-2px 2px #fff,2px -2px #fff,-2px -2px #fff,'
          +'0 6px 16px rgba(0,0,0,.28);'
        +'animation:'+PFX+'-pop .6s cubic-bezier(.2,1.5,.4,1) both;}'
    + '.'+PFX+'-text.fade{animation:'+PFX+'-fade .42s ease forwards;}'
    + '@keyframes '+PFX+'-pop{'
        +'0%{opacity:0;transform:translate(-50%,-50%) scale(.3) rotate(-12deg)}'
        +'45%{opacity:1;transform:translate(-50%,-50%) scale(1.16) rotate(-4deg)}'
        +'62%{transform:translate(-50%,-50%) scale(.95) rotate(-6deg)}'
        +'78%{transform:translate(-50%,-50%) scale(1.04) rotate(-5deg)}'
        +'100%{opacity:1;transform:translate(-50%,-50%) scale(1) rotate(-5deg)}}'
    + '@keyframes '+PFX+'-fade{from{opacity:1}to{opacity:0;transform:translate(-50%,-50%) scale(1.06) rotate(-5deg)}}';
    var s = document.createElement('style');
    s.id = PFX + '-style';
    s.textContent = c;
    (document.head || document.documentElement).appendChild(s);
  }

  /* ---------- オーバーレイ＋キャンバス ---------- */
  var layer, canvas, ctx, rafId = 0, parts = [], last = 0, vw = 0, vh = 0;
  function ensureLayer() {
    injectCSS();
    if (layer && layer.isConnected) return;
    layer = document.createElement('div');
    layer.className = PFX + '-layer';
    layer.setAttribute('aria-hidden', 'true');
    canvas = document.createElement('canvas');
    canvas.className = PFX + '-canvas';
    layer.appendChild(canvas);
    document.body.appendChild(layer);
    ctx = canvas.getContext('2d');
    resize();
    global.addEventListener('resize', resize, { passive: true });
  }
  function resize() {
    if (!canvas) return;
    var dpr = Math.min(global.devicePixelRatio || 1, 2);
    vw = global.innerWidth; vh = global.innerHeight;
    canvas.width = Math.floor(vw * dpr);
    canvas.height = Math.floor(vh * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  /* ---------- 星の形（分度器マスター由来） ---------- */
  function drawStar(r, points, inner) {
    ctx.beginPath();
    for (var i = 0; i < points * 2; i++) {
      var rad = (i % 2 === 0) ? r : r * inner;
      var a = Math.PI * i / points - Math.PI / 2;
      var x = Math.cos(a) * rad, y = Math.sin(a) * rad;
      if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    }
    ctx.closePath();
  }

  /* ---------- 星を生成 ---------- */
  function makeStar(o) {
    return {
      x: o.x, y: o.y, vx: o.vx, vy: o.vy, g: o.g,
      size: o.size, pts: o.pts, color: o.color,
      rot: Math.random() * Math.PI * 2, vrot: (Math.random() - 0.5) * 8,
      born: performance.now(), life: o.life,
      tw: Math.random() * Math.PI * 2, twv: 6 + Math.random() * 7
    };
  }
  function spawn(cx, cy, opts) {
    var colors = opts.colors || STAR_COLORS;
    var count = opts.count != null ? opts.count : (reduce ? 30 : 100);
    var speed = (opts.speed != null ? opts.speed : 1.1);
    var grav  = (opts.gravity != null ? opts.gravity : 1);
    var dur   = (opts.duration != null ? opts.duration : 1.3);
    var rain  = opts.rain !== false && !reduce;
    var burstN = rain ? Math.round(count * 0.62) : count;
    var rainN  = count - burstN;
    var i, col, size, pts;
    // 中央から放射状に飛び散る
    for (i = 0; i < burstN; i++) {
      var ang = Math.random() * Math.PI * 2;
      var sp = (280 + Math.random() * 470) * speed;
      col = colors[(Math.random() * colors.length) | 0];
      pts = Math.random() < 0.3 ? 4 : 5;
      size = 8 + Math.random() * 10;
      parts.push(makeStar({
        x: cx, y: cy,
        vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp,
        g: 720 * grav, size: size, pts: pts, color: col,
        life: (1500 + Math.random() * 650) * dur
      }));
    }
    // 上からパラパラ降る
    for (i = 0; i < rainN; i++) {
      col = colors[(Math.random() * colors.length) | 0];
      pts = Math.random() < 0.3 ? 4 : 5;
      size = 8 + Math.random() * 9;
      parts.push(makeStar({
        x: Math.random() * vw, y: -20 - Math.random() * vh * 0.3,
        vx: (Math.random() - 0.5) * 70, vy: (90 + Math.random() * 150) * speed,
        g: 240 * grav, size: size, pts: pts, color: col,
        life: (1600 + Math.random() * 700) * dur
      }));
    }
    if (!rafId) { last = performance.now(); rafId = requestAnimationFrame(tick); }
  }

  function tick(now) {
    var dt = Math.min((now - last) / 1000, 0.05); last = now;
    ctx.clearRect(0, 0, vw, vh);
    var live = [];
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i], age = now - p.born;
      if (age > p.life || p.y > vh + 80) continue;
      p.vy += p.g * dt; p.x += p.vx * dt; p.y += p.vy * dt;
      p.rot += p.vrot * dt; p.tw += p.twv * dt;
      var twinkle = 0.6 + 0.4 * Math.sin(p.tw);          // きらめき（明滅）
      var a = twinkle;
      var fade = p.life * 0.6;
      if (age > fade) a *= Math.max(0, 1 - (age - fade) / (p.life - fade));
      ctx.save();
      ctx.globalAlpha = a;
      ctx.translate(p.x, p.y); ctx.rotate(p.rot);
      ctx.shadowColor = p.color; ctx.shadowBlur = 14; ctx.fillStyle = p.color;
      drawStar(p.size, p.pts, 0.45); ctx.fill();
      ctx.shadowBlur = 0; ctx.globalAlpha *= 0.95; ctx.fillStyle = 'rgba(255,255,255,0.92)';
      drawStar(p.size * 0.42, p.pts, 0.45); ctx.fill();
      ctx.restore();
      live.push(p);
    }
    parts = live;
    rafId = parts.length ? requestAnimationFrame(tick) : 0;
    if (!rafId) ctx.clearRect(0, 0, vw, vh);
  }

  /* ---------- 「せいかい！」文字 ---------- */
  function showText(text) {
    if (!text) return;
    var t = document.createElement('div');
    t.className = PFX + '-text';
    t.textContent = text;
    layer.appendChild(t);
    setTimeout(function () { t.classList.add('fade'); }, reduce ? 1000 : 1500);
    setTimeout(function () { t.remove(); }, reduce ? 1400 : 1960);
  }

  /* ---------- 効果音（任意・キラッと2音） ---------- */
  var actx = null;
  function chime() {
    try {
      actx = actx || new (global.AudioContext || global.webkitAudioContext)();
      if (actx.state === 'suspended') actx.resume();
      var notes = [880, 1318.5];   // A5 → E6
      for (var i = 0; i < notes.length; i++) {
        var t = actx.currentTime + i * 0.09;
        var o = actx.createOscillator(), g = actx.createGain();
        o.type = 'triangle';
        o.frequency.setValueAtTime(notes[i], t);
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.16, t + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.32);
        o.connect(g).connect(actx.destination);
        o.start(t); o.stop(t + 0.34);
      }
    } catch (e) {}
  }

  /* ---------- 発生源の中心 ---------- */
  function centerOf(target) {
    if (target && target.nodeType === 1) {
      var r = target.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    }
    if (target && typeof target.x === 'number') return { x: target.x, y: target.y };
    return { x: global.innerWidth / 2, y: global.innerHeight * 0.42 };
  }

  /* ---------- 公開API ---------- */
  function correct(opts) {
    opts = opts || {};
    ensureLayer();
    var c = centerOf(opts.target);
    spawn(c.x, c.y, opts);
    showText(opts.text != null ? opts.text : '正解！');
    if (opts.sound === true) chime();
    return true;
  }
  correct._divp = true;
  correct.colors = STAR_COLORS;   // 既定パレットを参照・改変できるように公開
  Divp.correct = correct;
})(window);
