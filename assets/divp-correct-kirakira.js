/*!
 * divp-correct-kirakira.js — 小学生(es)向け 正解エフェクト「キラキラが積もる」
 *   1) 都道府県マスター(divp-correct.js)と同じ 9色の星バースト。
 *      中央から放射状に散り、上からもパラパラ降り、中央に金色の「正解！」がポンッと出る。
 *   2) その キラキラが 画面の下から 積もっていく(pile)。pileFull(既定100)回の正解で
 *      画面ぜんぶが うまる。1回に 何個 積もるかは 画面の広さから 自動で決まるので、
 *      端末が ちがっても 同じ回数で 同じ見た目になる。
 *      積もるのは 弾けてから pileDelay(既定0.6秒)たった あとで、pileSpread
 *      (既定1.0秒)かけて 少しずつ＝「はじける → 降る → つもる」の 流れになる。
 *      積もったものは ページ(ブラウザ)を 閉じるまで 残る。
 *
 * 依存なし。単体で読み込めば動きます(divp-core.js より後ろに置くこと)。
 * 学年ではなく「エフェクト名」でファイルを分ける規約に のっています。
 * 自分を window.DivpEffects.kirakira に登録するので、エフェクトを増やすときは
 * divp-correct-〇〇.js を足して data-effect="〇〇" を書く。1ファイルに詰めない
 * （divp-correct.js は9ツール・divp-correct-jh.js は22ツールが読んでいるため、
 * 　まとめると1回の上書きミスの巻き添えが そのまま ツール数になる）。
 *
 * ⚠ 星バーストの部分は divp-correct.js の写しです（「都道府県と同じ見た目」が
 *   このモジュールの前提なので、あちらの見た目を調整したら こちらも そろえること）。
 *   積もる山の置きかたは divp-correct-firework.js と同じ考え方です。
 *
 * 使い方
 *   <body data-theme="es" data-effect="kirakira">
 *   <script src="/assets/divp-correct-kirakira.js" defer></script>
 *   → data-effect="kirakira" のとき Divp.correct() をこの演出に差し替えます。
 *      Divp が無い環境(file://など)でも window.DivpKirakira() で直接呼べます。
 *
 * 個別に呼ぶ
 *   DivpKirakira();                      // 既定(「正解！」)
 *   DivpKirakira({text:"せいかい！"});    // 文字を変える
 *   DivpKirakira({text:"", count:140});  // 文字なし・星だけ多め
 *   DivpKirakira({target: el});          // 星の発生源を要素の中心に
 *   DivpKirakira.clearPile();            // 積もった星を消す
 *   DivpKirakira.flushPile();            // 積み残しを その場で 積む(「次へ」用)
 *                                        // ※画面をさわると 自動で呼ばれるので
 *                                        //   ふつうは ツール側で 書かなくてよい
 *
 * 積もる山について
 *   ペースの つまみは pileFull(画面が うまるまでの正解数、既定100)だけです。
 *   1回に積もる数は「画面の広さ ÷ pileFull」で自動計算されます。
 *   描画は canvas 1枚。DOMには積まないので個数が増えても軽いままです。
 *   pileZIndex:5 で「白い面(カード)より前面・ボタンより背面」に積もります。
 *   白地の上は 星が 目立ちすぎるので、pileVeilSelector(既定 [class*='card'])に
 *   あたる面には うすい白を1枚かぶせて pileVeil(既定0.55)ぶん 薄く見せます。
 *   問題文・解説・ボタンなどが埋もれないよう、pileRaiseUI:true のとき
 *   それらを z-index:10 に持ち上げるCSSを自動で入れます(詳細度0の :where() なので
 *   ツール側のCSSでいくらでも上書きできます)。
 *   ⚠ :where() は詳細度0＝セレクタから漏れた要素(div/spanだけで組んだ帯など)は
 *     ツール側CSSで前面に出すこと。
 *
 * 既定値を丸ごと変える
 *   window.DIVP_KIRAKIRA_OPTS = {pileFull:60, count:120};  // 読み込み前に定義
 *
 * 既存の divp-correct.js(星バースト・積もらない)とは排他です。両方読むと後勝ちになります。
 */
(function (w, d) {
  "use strict";

  var DEF = {
    /* ---- 星バースト（divp-correct.js と同じ既定値） ---- */
    text: "正解！",     // 中央に出す文字。"" なら星だけ
    count: 100,         // 星の総数
    speed: 1.1,         // 飛び散る速さの倍率
    gravity: 1,         // 落ちる速さ＝重力の倍率
    duration: 1.3,      // 星の寿命の倍率
    rain: true,         // 上からも降らせるか
    sound: false,       // キラッと効果音
    originY: 0.42,      // 発生位置(画面の高さに対する割合)
    zIndex: 2147483000,
    colors: ['#FFD93D', '#FF6BAA', '#4DD0E1', '#7CE07C', '#B388FF',
             '#FF9F4D', '#FF5C5C', '#FFE066', '#5CE1E6'],
    /* ---- 積もる山 ---- */
    pile: true,         // 消えた星を 下に積もらせる
    // 積もるペースの つまみは これ1つ。pileFull 回の正解で 画面が うまる。
    // 1回に 何個 積もるかは「画面の広さ ÷ pileFull」で その場で 計算するので、
    // 画面が 広い端末ほど 1回に たくさん 降る＝どの端末でも 同じ回数で 同じ見た目になる
    pileFull: 100,      // 画面が うまるまでの 正解回数
    // 「弾ける → 降る → 積もる」の 流れに 見えるよう、積もるのを 後ろへ ずらす
    pileDelay: 0.6,     // 弾けてから 積もりはじめるまでの ま(秒)
    pileSpread: 1.0,    // 積もりきるまでに かける 時間(秒)
    pileZIndex: 5,      // 5=カードより前面/ボタンより背面, -1=いちばん後ろ
    // 山は うすく。積もるほど 星が かさなって 濃く見えるので、
    // ここを上げると 上にのった 文字や図が 読みにくくなる
    pileOpacity: 0.32,
    pileCol: 16,        // 列のはば px
    pileStep: 8,        // 1段の高さ px(星より小さくして重ねる)
    pileSizeMin: 6,     // 積もる星の半径 px
    pileSizeMax: 11,
    // 満タンのときの 高さ(画面の高さに対する割合)。1=画面いちばん上まで
    pileHeightMax: 1,
    // 問題の 白い面(カード)の 中だけ 山を うすくする。0=そのまま / 1=まっ白。
    // 白地の 上だと 星が よく目立ち、問題文が 読みにくくなるため、
    // カードの上に うすい 白を 1枚 かぶせる(山より前・中身より背面)。
    // 対象は 中身が 不透明な 白い面だけ。すきとおった 入れものに かけると
    // そこだけ 白い四角が 浮くので、"box"や"panel"は 既定では 入れない
    pileVeil: 0.55,
    pileVeilSelector: "[class*='card']",
    // 山より前面に出す要素。白い面(カード)は背面のまま、その中身とボタンだけ前に出す。
    // :where() を使っているので詳細度は0。ツール側のCSSで自由に上書きできる
    pileRaiseUI: true,
    pileRaiseSelector: "button,a[href],input,select,textarea,label,table," +
      "h1,h2,h3,h4,h5,h6,p,ul,ol,dl,pre,blockquote,figure,svg,canvas,img," +
      "[class*='card']>*,[class*='panel']>*,[class*='box']>*," +
      "[class*='btn'],[class*='key'],[class*='chip'],[class*='question'],[class*='exp']"
  };

  function merge(a, b) { var o = {}, k; for (k in a) o[k] = a[k]; for (k in b) o[k] = b[k]; return o; }
  var CFG = merge(DEF, w.DIVP_KIRAKIRA_OPTS || {});

  var CSS_ID = "divp-kk-style", LAYER_ID = "divp-kk-layer", PILE_ID = "divp-kk-pile";
  var PFX = "divp-kk";

  function R(a, b) { return a + Math.floor(Math.random() * (b - a + 1)); }
  function pick(a) { return a[Math.floor(Math.random() * a.length)]; }
  function reduced() {
    return !!(w.matchMedia && w.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }

  /* ---------- CSS(初回だけ注入) ---------- */
  function injectCSS(cfg) {
    if (d.getElementById(CSS_ID)) return;
    var st = d.createElement("style");
    st.id = CSS_ID;
    st.textContent =
      "." + PFX + "-layer{position:fixed;inset:0;z-index:" + cfg.zIndex + ";" +
        "pointer-events:none;overflow:hidden}" +
      "." + PFX + "-canvas{position:absolute;inset:0;width:100%;height:100%}" +
      // 「正解！」の金文字（divp-correct.js と同じ）
      "." + PFX + "-text{position:absolute;left:50%;top:42%;transform:translate(-50%,-50%);" +
        "font-family:\"Hiragino Sans\",\"Yu Gothic UI\",\"Yu Gothic\",system-ui,sans-serif;" +
        "font-weight:900;font-size:clamp(40px,11vw,92px);letter-spacing:2px;white-space:nowrap;" +
        "color:#FFD93D;" +
        "text-shadow:0 0 2px #fff,2px 0 #fff,-2px 0 #fff,0 2px #fff,0 -2px #fff," +
          "2px 2px #fff,-2px 2px #fff,2px -2px #fff,-2px -2px #fff," +
          "0 6px 16px rgba(0,0,0,.28);" +
        "animation:" + PFX + "-pop .6s cubic-bezier(.2,1.5,.4,1) both}" +
      "." + PFX + "-text.fade{animation:" + PFX + "-fade .42s ease forwards}" +
      "@keyframes " + PFX + "-pop{" +
        "0%{opacity:0;transform:translate(-50%,-50%) scale(.3) rotate(-12deg)}" +
        "45%{opacity:1;transform:translate(-50%,-50%) scale(1.16) rotate(-4deg)}" +
        "62%{transform:translate(-50%,-50%) scale(.95) rotate(-6deg)}" +
        "78%{transform:translate(-50%,-50%) scale(1.04) rotate(-5deg)}" +
        "100%{opacity:1;transform:translate(-50%,-50%) scale(1) rotate(-5deg)}}" +
      "@keyframes " + PFX + "-fade{from{opacity:1}" +
        "to{opacity:0;transform:translate(-50%,-50%) scale(1.06) rotate(-5deg)}}" +
      // 積もった星の面
      "#" + PILE_ID + "{position:fixed;left:0;top:0;width:100%;height:100%;" +
        "pointer-events:none;opacity:" + cfg.pileOpacity + ";z-index:" + cfg.pileZIndex + "}" +
      // 白い面の 中だけ 山を うすく。::before は ツール側で 使われていることが
      // あるので ::after を つかう
      (cfg.pileVeil > 0
        ? ":where(" + cfg.pileVeilSelector + "){position:relative}" +
          ":where(" + cfg.pileVeilSelector + ")::after{content:'';position:absolute;" +
            "inset:0;border-radius:inherit;pointer-events:none;" +
            "z-index:" + (cfg.pileZIndex + 1) + ";" +
            "background:rgba(255,255,255," + cfg.pileVeil + ")}" +
          // カードの ::before は 見出しの帯などの かざりに つかわれている。
          // そのままだと うすい白の 下に 入って ぼやけるので 上に出す
          ":where(" + cfg.pileVeilSelector + ")::before{z-index:" + (cfg.pileZIndex + 2) + "}"
        : "") +
      (cfg.pileRaiseUI
        ? ":where(" + cfg.pileRaiseSelector + "){position:relative;z-index:10}"
        : "");
    (d.head || d.documentElement).appendChild(st);
  }

  /* ---------- 星の形（分度器マスター由来） ---------- */
  function starPath(ctx, r, points, inner) {
    ctx.beginPath();
    for (var i = 0; i < points * 2; i++) {
      var rad = (i % 2 === 0) ? r : r * inner;
      var a = Math.PI * i / points - Math.PI / 2;
      var x = Math.cos(a) * rad, y = Math.sin(a) * rad;
      if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    }
    ctx.closePath();
  }

  /* ---------- バースト用のオーバーレイ＋キャンバス ---------- */
  var layer = null, cv = null, ctx = null, raf = 0, parts = [], last = 0, vw = 0, vh = 0;
  var textEl = null, textT1 = null, textT2 = null;

  function ensureLayer(cfg) {
    injectCSS(cfg);
    if (layer && layer.isConnected) return;
    layer = d.createElement("div");
    layer.className = PFX + "-layer";
    layer.setAttribute("aria-hidden", "true");
    cv = d.createElement("canvas");
    cv.className = PFX + "-canvas";
    layer.appendChild(cv);
    d.body.appendChild(layer);
    ctx = cv.getContext("2d");
    burstResize();
    w.addEventListener("resize", burstResize, { passive: true });
  }
  function burstResize() {
    if (!cv) return;
    var dpr = Math.min(w.devicePixelRatio || 1, 2);
    vw = w.innerWidth; vh = w.innerHeight;
    cv.width = Math.floor(vw * dpr);
    cv.height = Math.floor(vh * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function makeStar(o) {
    return {
      x: o.x, y: o.y, vx: o.vx, vy: o.vy, g: o.g,
      size: o.size, pts: o.pts, color: o.color,
      rot: Math.random() * Math.PI * 2, vrot: (Math.random() - 0.5) * 8,
      born: performance.now(), life: o.life,
      tw: Math.random() * Math.PI * 2, twv: 6 + Math.random() * 7
    };
  }
  function spawn(cx, cy, cfg) {
    var colors = cfg.colors, rd = reduced();
    var count = rd ? Math.round(cfg.count * 0.3) : cfg.count;
    var rain = cfg.rain !== false && !rd;
    var burstN = rain ? Math.round(count * 0.62) : count;
    var rainN = count - burstN;
    var i, col, size, pts;
    // 中央から放射状に飛び散る
    for (i = 0; i < burstN; i++) {
      var ang = Math.random() * Math.PI * 2;
      var sp = (280 + Math.random() * 470) * cfg.speed;
      col = colors[(Math.random() * colors.length) | 0];
      pts = Math.random() < 0.3 ? 4 : 5;
      size = 8 + Math.random() * 10;
      parts.push(makeStar({
        x: cx, y: cy,
        vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp,
        g: 720 * cfg.gravity, size: size, pts: pts, color: col,
        life: (1500 + Math.random() * 650) * cfg.duration
      }));
    }
    // 上からパラパラ降る
    for (i = 0; i < rainN; i++) {
      col = colors[(Math.random() * colors.length) | 0];
      pts = Math.random() < 0.3 ? 4 : 5;
      size = 8 + Math.random() * 9;
      parts.push(makeStar({
        x: Math.random() * vw, y: -20 - Math.random() * vh * 0.3,
        vx: (Math.random() - 0.5) * 70, vy: (90 + Math.random() * 150) * cfg.speed,
        g: 240 * cfg.gravity, size: size, pts: pts, color: col,
        life: (1600 + Math.random() * 700) * cfg.duration
      }));
    }
  }

  function tick(now) {
    var dt = Math.min((now - last) / 1000, 0.05); last = now;
    ctx.clearRect(0, 0, vw, vh);
    var live = [], i;
    for (i = 0; i < parts.length; i++) {
      var p = parts[i], age = now - p.born;
      if (age > p.life || p.y > vh + 80) continue;
      p.vy += p.g * dt; p.x += p.vx * dt; p.y += p.vy * dt;
      p.rot += p.vrot * dt; p.tw += p.twv * dt;
      var a = 0.6 + 0.4 * Math.sin(p.tw);                 // きらめき(明滅)
      var fade = p.life * 0.6;
      if (age > fade) a *= Math.max(0, 1 - (age - fade) / (p.life - fade));
      ctx.save();
      ctx.globalAlpha = a;
      ctx.translate(p.x, p.y); ctx.rotate(p.rot);
      ctx.shadowColor = p.color; ctx.shadowBlur = 14; ctx.fillStyle = p.color;
      starPath(ctx, p.size, p.pts, 0.45); ctx.fill();
      ctx.shadowBlur = 0; ctx.globalAlpha *= 0.95; ctx.fillStyle = 'rgba(255,255,255,0.92)';
      starPath(ctx, p.size * 0.42, p.pts, 0.45); ctx.fill();
      ctx.restore();
      live.push(p);
    }
    parts = live;

    // 降ってきたぶんから 順に 積もらせる
    if (pQueued) {
      pT += dt;
      var want = Math.round(pQueued *
        Math.min(1, Math.max(0, (pT - pileCfg.pileDelay) / pileCfg.pileSpread)));
      if (want > pPlaced) { pendDrain(want - pPlaced); pPlaced = want; }
      if (pPlaced >= pQueued) pQueued = 0;
    }

    raf = (parts.length || pend.length) ? requestAnimationFrame(tick) : 0;
    if (!raf) ctx.clearRect(0, 0, vw, vh);
  }

  /* ---------- 「正解！」の文字 ---------- */
  function showText(cfg) {
    if (textEl) { textEl.remove(); textEl = null; }
    clearTimeout(textT1); clearTimeout(textT2);
    if (!cfg.text) return;
    var rd = reduced();
    var t = d.createElement("div");
    t.className = PFX + "-text";
    t.textContent = cfg.text;
    layer.appendChild(t);
    textEl = t;
    textT1 = setTimeout(function () { t.classList.add("fade"); }, rd ? 1000 : 1500);
    textT2 = setTimeout(function () {
      t.remove(); if (textEl === t) textEl = null;
    }, rd ? 1400 : 1960);
  }

  /* ---------- 効果音（任意・キラッと2音） ---------- */
  var actx = null;
  function chime() {
    try {
      actx = actx || new (w.AudioContext || w.webkitAudioContext)();
      if (actx.state === "suspended") actx.resume();
      var notes = [880, 1318.5];   // A5 → E6
      for (var i = 0; i < notes.length; i++) {
        var t = actx.currentTime + i * 0.09;
        var o = actx.createOscillator(), g = actx.createGain();
        o.type = "triangle";
        o.frequency.setValueAtTime(notes[i], t);
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.16, t + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.32);
        o.connect(g).connect(actx.destination);
        o.start(t); o.stop(t + 0.34);
      }
    } catch (e) {}
  }

  /* ---------- 積もる山 ----------
     置きかた: つねに「いちばん低い列」に置く（同じ高さの列が いくつもあれば
     落ちたところに いちばん近い列）。こうすると 山は 1段ずつ 水平に 上がるので、
     どこかの列だけ のびる＝棒グラフのようには ならない。
     さらに1段ごとに半列ずらし(れんが積み)、±数pxの ゆらぎで すき間をうめる。

     満タン = 画面いっぱい(pileHeightMax)。そこまでを pileFull 回の正解で 埋めるので、
     1回で積もる数は「画面の広さ ÷ pileFull」から その場で 計算する。

     描くのは spanではなく canvas。画面いっぱいまで積もると 星は 1万個をこえるので、
     DOMに積むと 教室のタブレットで 目に見えて重くなる。canvasなら 何個 積もっても
     要素は1つ、1個ぶんの 追加は fill 1回で すむ。そのかわり 個々の星は
     あとから 消せないので、満タン後は 消さずに 上から かさねつづける
     （もう 画面は うまっているので 見た目は 変わらない）。 */
  var pileCv = null, pileCtx = null, PILE = [], pileH = [], pileVW = 0;
  var pileCfg = CFG, pileReflow = null;
  var pend = [], pQueued = 0, pPlaced = 0, pT = 0;

  function pileCanvas() {
    if (pileCv) return;
    pileCv = d.getElementById(PILE_ID);
    if (!pileCv) {
      pileCv = d.createElement("canvas");
      pileCv.id = PILE_ID;
      pileCv.setAttribute("aria-hidden", "true");
      d.body.appendChild(pileCv);
    }
    pileSize();
  }
  /* canvasの 実ピクセルを 画面に合わせる(ぼやけ防止)。中身は 消えるので
     呼んだあとは 描きなおすこと */
  function pileSize() {
    var dpr = Math.min(2, w.devicePixelRatio || 1);
    pileCv.width = Math.max(1, Math.round(w.innerWidth * dpr));
    pileCv.height = Math.max(1, Math.round(w.innerHeight * dpr));
    pileCtx = pileCv.getContext("2d");
    pileCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
    pileVW = w.innerWidth;     // 置きなおしが いるのは はばが 変わったときだけ
  }
  /* 積もった星1つ。バーストの星と同じ「色の星＋白い小さな星」で キラキラに見せる。
     山は 何千個も 描きなおすことがあるので shadowBlur は 使わない */
  function pileDraw(it) {
    if (!pileCtx) return;
    pileCtx.save();
    pileCtx.translate(it.x, w.innerHeight - it.bottom - it.size);
    pileCtx.rotate(it.rot * Math.PI / 180);
    pileCtx.fillStyle = it.color;
    starPath(pileCtx, it.size, it.pts, 0.45); pileCtx.fill();
    pileCtx.fillStyle = "rgba(255,255,255,0.92)";
    starPath(pileCtx, it.size * 0.42, it.pts, 0.45); pileCtx.fill();
    pileCtx.restore();
  }
  function pileCols(cfg) { return Math.max(1, Math.ceil(w.innerWidth / cfg.pileCol)); }
  /* 満タンのときの 段数＝画面の高さぶん */
  function pileRows(cfg) {
    return Math.max(2, Math.floor((w.innerHeight * cfg.pileHeightMax - 6) / cfg.pileStep));
  }
  function pileCap(cfg) { return pileCols(cfg) * pileRows(cfg); }
  /* 1回の正解で 積もる数。画面ぜんぶを pileFull 回で 埋めきる ペース */
  function pilePer(cfg) { return Math.max(1, Math.ceil(pileCap(cfg) / cfg.pileFull)); }
  /* いちばん低い列。同じ高さなら 落ちたところに いちばん近い列 */
  function pileColOf(xr, cfg) {
    var n = pileCols(cfg),
        c0 = Math.min(n - 1, Math.max(0, Math.round(xr * w.innerWidth / cfg.pileCol))),
        best = c0, bh = Infinity, bd = Infinity, i, h, dd;
    for (i = 0; i < n; i++) {
      h = pileH[i] || 0; dd = Math.abs(i - c0);
      if (h < bh || (h === bh && dd < bd)) { bh = h; bd = dd; best = i; }
    }
    return best;
  }
  function pilePlace(it, cfg) {
    var c = pileColOf(it.xr, cfg), h = pileH[c] || 0, rows = pileRows(cfg);
    // 画面の上まで 積もりきった列には かさねて置く(高さは のばさない)。
    // いちばん上にだけ ためると「そこで 止まった」ように 見えるので ばらけさせる
    if (h >= rows) h = R(0, rows - 1);
    else pileH[c] = h + 1;
    var x = c * cfg.pileCol + cfg.pileCol / 2 + ((h % 2) ? cfg.pileCol / 2 : 0) + it.jx;
    it.x = Math.min(w.innerWidth - 5, Math.max(5, x));
    it.bottom = 4 + h * cfg.pileStep + it.dy;
  }
  function pileAdd(it, cfg) {
    pileCanvas();
    pilePlace(it, cfg);
    pileDraw(it);
    // 置きなおし(画面サイズ変更)用に おぼえておく。満タンをこえたぶんは
    // 記録しない＝もう 画面は うまっているので 置きなおしても 見た目は 同じ
    if (PILE.length < pileCap(cfg)) PILE.push(it);
  }
  /* この演出で 積もる 星を 待ち行列に入れる。
     数は「画面の広さ ÷ pileFull」＝ pileFull 回で 画面が うまるペース */
  function pendPush(cfg) {
    for (var i = 0, n = pilePer(cfg); i < n; i++)
      pend.push({ xr: Math.random(), dy: R(-3, 3), jx: R(-5, 5), rot: R(0, 359),
                  color: pick(cfg.colors), pts: (Math.random() < 0.3 ? 4 : 5),
                  size: R(cfg.pileSizeMin, cfg.pileSizeMax) });
  }
  /* 待ち行列から n 個ぶん 積む。演出のあいだ 毎フレーム 少しずつ 呼ぶので
     「降ったぶんが たまっていく」ように 見える */
  function pendDrain(n) {
    while (n-- > 0 && pend.length) pileAdd(pend.shift(), pileCfg);
  }
  /* 画面のはばが 変わったら 列の数も 変わるので、全部 置きなおして 描きなおす */
  function pileRender() {
    pileCanvas(); pileSize(); pileH = [];
    PILE.forEach(function (it) { pilePlace(it, pileCfg); pileDraw(it); });
  }
  /* 置きなおさず 描きなおすだけ。canvasは 大きさを変えると 中身が 消えるので、
     高さだけ 変わったときも 描きなおしは いる */
  function pileRedraw() {
    pileCanvas(); pileSize();
    PILE.forEach(function (it) { pileDraw(it); });
  }
  /* スマホは スクロールで アドレスバーが 出入りするだけでも resize が とぶ。
     山は どれも 画面の下からの きょりで 置いてあるので、高さが 変わっても
     置きなおす 必要はない。列の数が 変わる = はばが 変わったときだけ 置きなおす */
  w.addEventListener("resize", function () {
    if (!PILE.length) return;
    clearTimeout(pileReflow);
    pileReflow = setTimeout(function () {
      if (w.innerWidth !== pileVW) pileRender();
      else pileRedraw();
    }, 200);
  });

  /* ---------- 発生源の中心 ---------- */
  function centerOf(target, cfg) {
    if (target && target.nodeType === 1) {
      var r = target.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    }
    if (target && typeof target.x === "number") return { x: target.x, y: target.y };
    return { x: w.innerWidth / 2, y: w.innerHeight * cfg.originY };
  }

  /* ---------- 本体 ---------- */
  function fire(opts) {
    opts = opts || {};
    var cfg = merge(CFG, opts);
    pileCfg = cfg;
    if (!d.body) return false;
    injectCSS(cfg);
    flushPile();                 // 前の演出の 積み残しを 先に 積む
    ensureLayer(cfg);
    showText(cfg);

    if (cfg.pile) { pendPush(cfg); pQueued = pend.length; pPlaced = 0; pT = 0; }

    // 動きをへらす設定のときは 文字だけ静かに出して、山は その場で 積む
    if (reduced()) {
      pendDrain(pend.length); pQueued = 0;
    } else {
      var c = centerOf(opts.target, cfg);
      spawn(c.x, c.y, cfg);
    }
    if (cfg.sound === true) chime();
    if (!raf && (parts.length || pend.length)) {
      last = performance.now(); raf = requestAnimationFrame(tick);
    }
    return true;
  }
  fire._divp = true;
  fire.colors = CFG.colors;      // 既定パレットを参照・改変できるように公開

  /* ---------- 公開 / Divpへの結線 ---------- */
  fire.clearPile = function () {              // 積もった星を消す
    PILE.length = 0; pileH = []; pend.length = 0; pQueued = 0;
    if (pileCtx) pileCtx.clearRect(0, 0, w.innerWidth, w.innerHeight);
  };
  /* 「次へ」などで 先に すすむとき、まだ 出しきっていない ぶんを その場で 積む。
     ほうっておいても 描画ループが 出しきるが、次の問題に 移ったあとで
     ぱらぱら 増えるのは 落ちつかないので、画面を さわられたら そこで 出しきる */
  function flushPile() { if (pend.length) { pendDrain(pend.length); pQueued = 0; } }
  fire.flushPile = flushPile;
  d.addEventListener("pointerdown", flushPile, true);
  d.addEventListener("keydown", flushPile, true);

  w.DivpKirakira = fire;
  w.DivpEffects = w.DivpEffects || {};
  w.DivpEffects.kirakira = fire;

  function bind() {
    var eff = d.body && d.body.getAttribute("data-effect");
    if (eff !== "kirakira") return;             // data-effect で明示したときだけ差し替える
    if (w.Divp) { w.Divp.correct = fire; }
  }
  if (d.readyState === "loading") d.addEventListener("DOMContentLoaded", bind);
  else bind();
  w.addEventListener("load", bind);             // divp-core.js が後から入る場合の保険
})(window, document);
