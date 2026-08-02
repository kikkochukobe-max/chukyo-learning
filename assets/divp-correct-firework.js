/*!
 * divp-correct-firework.js — 小学生(es)向け 正解エフェクト「花火」
 *   1) 中心から絵文字が360度に弾け、空気ていこうで失速したあと重力で落下しながらフェードアウト
 *   2) 同時に「せいかい！」が1文字ずつ外へ散り、集まって元の並びに戻ってから消える
 *   3) 画面内で消えた記号は 画面下に積もっていく(pile)。ページを閉じるまで残る
 *   4) 当たりが 2段階。jumboChance(既定 1/25)で特大の記号、
 *      superChance(既定 1/100)で さらに大きい「スペシャル」(絵文字)が 1つだけ降る。
 *      どちらも 画面外へ飛んでも 山がいっぱいでも かならず積もる（手元に残るごほうび）。
 *      その大きさぶん まわりの列の高さを 予約するので、あとの記号は
 *      当たりに かぶらず その上に 積もっていく
 *
 * 依存なし。単体で読み込めば動きます(divp-core.js より後ろに置くこと)。
 * 学年ではなく「エフェクト名」でファイルを分ける規約（旧 divp-correct-es2.js。
 * es2 が小2に読めたため改名。小2でも小3でも学年に関係なく読める）。
 * 自分を window.DivpEffects.firework に登録するので、エフェクトを増やすときは
 * divp-correct-〇〇.js を足して data-effect="〇〇" を書く。1ファイルに詰めない
 * （divp-correct.js は9ツール・divp-correct-jh.js は22ツールが読んでいるため、
 * 　まとめると1回の上書きミスの巻き添えがそのままツール数になる）。
 *
 * 使い方
 *   <body data-theme="es" data-effect="firework">
 *   <script src="/assets/divp-correct-firework.js" defer></script>
 *   → data-effect="firework" のとき Divp.correct() をこの演出に差し替えます。
 *      Divp が無い環境(file://など)でも window.DivpFirework() で直接呼べます。
 *
 * 個別に呼ぶ
 *   DivpFirework();                       // 既定(「せいかい！」)
 *   DivpFirework({text:"せいかい！"});     // 文字を変える
 *   DivpFirework({text:"", count:140});   // 文字なし・粒だけ多め
 *   DivpFirework({hold:true});            // 文字を消さずに出したまま
 *   DivpFirework.hide();                  // hold:true で出した文字を消す
 *   DivpFirework.clearPile();             // 積もった記号を消す
 *
 * 積もる山について
 *   pileZIndex:5 で「白い面(カード)より前面・ボタンより背面」に積もります。
 *   問題文・解説・ボタンなどが埋もれないよう、pileRaiseUI:true のとき
 *   それらを z-index:10 に持ち上げるCSSを自動で入れます(詳細度0の :where() なので
 *   ツール側のCSSでいくらでも上書きできます)。
 *   自前で制御したいときは pileRaiseUI:false、
 *   いちばん後ろに置きたいだけなら pileZIndex:-1 を指定してください。
 *
 * 既定値を丸ごと変える
 *   window.DIVP_FIREWORK_OPTS = {count:60, gravity:1400};  // 読み込み前に定義
 *
 * 既存の divp-correct.js(星バースト)とは排他です。両方読むと後勝ちになります。
 */
(function (w, d) {
  "use strict";

  var DEF = {
    text: "せいかい！",  // 中央に出す文字。"" なら粒だけ
    hold: false,        // true なら文字を消さずに出したままにする(DivpFirework.hide()で消す)
    count: 138,         // 粒の数
    gravity: 1000,      // 重力 px/s^2 … 大きいほど早く落ちる
    drag: 1.0,          // 空気ていこう /s … 大きいほどその場でパッと開いて止まる
    speedMin: 950,      // 初速の下限 px/s
    speedMax: 1700,     // 初速の上限 px/s
    lifeMin: 1.20,      // 粒の寿命(秒)
    lifeMax: 1.85,
    slowRatio: 0.34,    // ふわっと落ちる粒の割合
    slowSpeedMin: 330,  // ふわっと粒の初速
    slowSpeedMax: 720,
    slowSizeMin: 11,    // ふわっと粒の文字サイズ(小さめ)
    slowSizeMax: 20,
    slowGravity: 0.38,  // ふわっと粒への重力のきき方(1=ふつう)
    jumboChance: 1 / 25, // 特大の記号が1つまざる確率(1回の演出につき)
    jumboSizeMin: 64,   // 特大の記号の大きさ
    jumboSizeMax: 104,
    // さらにレアな「スペシャル」。特大より大きく、ゆっくり落ちてくる。
    // superChance が先に判定され、当たると その演出に jumbo は出ない
    superChance: 1 / 100,
    superSizeMin: 150,  // 画面はばの半分を こえない範囲で 使われる
    superSizeMax: 230,
    superMarks: ["👑", "🏆", "💎", "🌈", "🎉"],
    originY: 0.40,      // 画面の高さに対する発生位置(0=上, 1=下)
    sizeMin: 16,        // 粒の文字サイズ px
    sizeMax: 34,
    textDistMin: 240,   // 文字が弾けて離れるきょり px
    textDistMax: 430,
    zIndex: 60,
    marks: ["★", "✦", "✧", "❀", "✿", "❁", "♪"],
    hues: ["#FFD447", "#FFB01F", "#FF7EB6", "#FF5FA2", "#5FD8F0",
           "#39C0E8", "#8CE05B", "#57C93E", "#C79BFF", "#A46BFF"],
    textColor: "#FF3B4E",
    pile: true,         // 画面内で消えた記号を 下に積もらせる
    pileZIndex: 5,      // 5=カードより前面/ボタンより背面, -1=いちばん後ろ
    // 山は うすく。積もるほど 記号が かさなって 濃く見えるので、
    // ここを上げると 上にのった 文字や図が 読みにくくなる
    pileOpacity: 0.3,
    pileCol: 17,        // 列のはば px
    pileStep: 8,        // 1段の高さ px(記号より小さくして重ねる)
    pileWin: 6,         // ならす範囲(左右何列まで見るか)
    pileMax: 2600,
    // 山の高さの上限(画面の高さに対する割合)。1 にすると 画面いっぱいまで積もるが、
    // ①ツールの表示に かぶって 読みにくい ②当たりの置き場所が 画面の上に
    // はみ出して かさなる ので、下の帯だけに おさめる
    pileMaxRatio: 0.4,
    // 山より前面に出す要素。白い面(カード)は背面のまま、その中身とボタンだけ前に出す。
    // :where() を使っているので詳細度は0。ツール側のCSSで自由に上書きできる。
    pileRaiseUI: true,
    pileRaiseSelector: "button,a[href],input,select,textarea,label,table," +
      "h1,h2,h3,h4,h5,h6,p,ul,ol,dl,pre,blockquote,figure,svg,canvas,img," +
      "[class*='card']>*,[class*='panel']>*,[class*='box']>*," +
      "[class*='btn'],[class*='key'],[class*='chip'],[class*='question'],[class*='exp']"
  };
  var CFG = merge(DEF, w.DIVP_FIREWORK_OPTS || {});

  var CSS_ID = "divp-fw-style", LAYER_ID = "divp-fw-layer", TEXT_ID = "divp-fw-text";
  var PILE_ID = "divp-fw-pile";
  var raf = null, hideTimer = null;
  var PILE = [], pileH = [], pileCfg = CFG;

  function merge(a, b) { var o = {}, k; for (k in a) o[k] = a[k]; for (k in b) o[k] = b[k]; return o; }
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
      "#" + LAYER_ID + "{position:fixed;inset:0;pointer-events:none;z-index:" + cfg.zIndex + ";display:none}" +
      "#" + LAYER_ID + ".on{display:block}" +
      "#" + LAYER_ID + " span{position:absolute;left:0;top:0;will-change:transform,opacity;" +
        "text-shadow:0 1px 2px rgba(58,52,44,.18)}" +
      "#" + TEXT_ID + "{position:fixed;left:50%;top:42%;transform:translate(-50%,-50%);" +
        "z-index:" + (cfg.zIndex + 1) + ";display:none;white-space:nowrap;letter-spacing:.04em;" +
        "pointer-events:none;font-family:inherit;font-weight:900;" +
        "font-size:clamp(64px,22vw,150px);color:" + cfg.textColor + ";" +
        "text-shadow:0 0 12px rgba(255,255,255,.9),0 5px 0 #fff,0 8px 16px rgba(58,52,44,.22)}" +
      "#" + TEXT_ID + ".on{display:block}" +
      "#" + PILE_ID + "{position:fixed;inset:0;pointer-events:none;overflow:hidden;" +
        "z-index:" + cfg.pileZIndex + ";opacity:" + cfg.pileOpacity + ";" +
        "transform:translateZ(0);contain:layout paint}" +
      "#" + PILE_ID + " span{position:absolute;line-height:1;display:block}" +
      (cfg.pileRaiseUI
        ? ":where(" + cfg.pileRaiseSelector + "){position:relative;z-index:10}"
        : "") +
      "#" + TEXT_ID + " span{display:inline-block;will-change:transform,opacity;" +
        "animation:divpFwChip 2.1s cubic-bezier(.16,1.1,.3,1) forwards}" +
      "@keyframes divpFwChip{" +
        "0%{opacity:0;transform:translate(0,0) scale(.3) rotate(0)}" +
        "6%{opacity:1}" +
        "24%{opacity:1;transform:translate(var(--sx),var(--sy)) scale(1.7) rotate(var(--sr))}" +
        "46%{opacity:1;transform:translate(var(--sx),var(--sy)) scale(1.7) rotate(var(--sr))}" +
        "78%{transform:translate(0,0) scale(1.22) rotate(0)}" +
        "88%{transform:translate(0,0) scale(1)}" +
        "94%{opacity:1;transform:translate(0,0) scale(1.03)}" +
        "100%{opacity:0;transform:translate(0,0) scale(1.14)}}" +
      "@keyframes divpFwChipHold{" +
        "0%{opacity:0;transform:translate(0,0) scale(.3) rotate(0)}" +
        "6%{opacity:1}" +
        "24%{opacity:1;transform:translate(var(--sx),var(--sy)) scale(1.7) rotate(var(--sr))}" +
        "46%{opacity:1;transform:translate(var(--sx),var(--sy)) scale(1.7) rotate(var(--sr))}" +
        "78%{transform:translate(0,0) scale(1.22) rotate(0)}" +
        "88%{transform:translate(0,0) scale(1)}" +
        "100%{opacity:1;transform:translate(0,0) scale(1)}}" +
      "@media (prefers-reduced-motion:reduce){" +
        "#" + TEXT_ID + " span{animation:none;opacity:1}}";
    (d.head || d.documentElement).appendChild(st);
  }

  function layer(cfg) {
    var el = d.getElementById(LAYER_ID);
    if (!el) { el = d.createElement("div"); el.id = LAYER_ID; d.body.appendChild(el); }
    return el;
  }
  function textBox(cfg) {
    var el = d.getElementById(TEXT_ID);
    if (!el) {
      el = d.createElement("div"); el.id = TEXT_ID;
      el.setAttribute("aria-live", "polite");
      d.body.appendChild(el);
    }
    return el;
  }

  /* ---------- 降った記号がたまる山 ----------
     落ちた場所の近くで いちばん低い列に置く。自分の列だけに積むと
     棒グラフのように細い柱が立つので、まわり ±pileWin 列を見てならす。
     さらに1段ごとに半列ずらし(れんが積み)、左右にもばらけさせて すき間をうめる。 */
  function pileBox() {
    var el = d.getElementById(PILE_ID);
    if (!el) { el = d.createElement("div"); el.id = PILE_ID; el.setAttribute("aria-hidden", "true");
               d.body.appendChild(el); }
    return el;
  }
  function pileCols(cfg) { return Math.max(1, Math.ceil(w.innerWidth / cfg.pileCol)); }
  function pileStack(cfg) {
    return Math.max(4, Math.floor((w.innerHeight * cfg.pileMaxRatio - 6) / cfg.pileStep));
  }
  function pileCol(xr, cfg) {
    var n = pileCols(cfg), st = pileStack(cfg), i, h, ad, t2;
    var c = Math.min(n - 1, Math.max(0, Math.round(xr * w.innerWidth / cfg.pileCol)));
    var best = -1, bh = Infinity, bd = Infinity;
    for (i = -cfg.pileWin; i <= cfg.pileWin; i++) {
      t2 = c + i; if (t2 < 0 || t2 >= n) continue;
      h = pileH[t2] || 0; if (h >= st) continue;
      ad = Math.abs(i);
      if (h < bh || (h === bh && ad < bd)) { bh = h; bd = ad; best = t2; }
    }
    if (best >= 0) return best;
    for (i = cfg.pileWin + 1; i < n; i++) {          // 近くが満杯なら さらに外側へ
      if (c - i >= 0 && (pileH[c - i] || 0) < st) return c - i;
      if (c + i < n && (pileH[c + i] || 0) < st) return c + i;
    }
    return -1;                                        // どの列もいっぱい
  }
  /* 当たり(特大・スペシャル)は 1段(pileStep)には おさまらないので、その高さまで
     まわりの列を 予約する。あとから降ってくる粒は この高さから 積みはじめるので、
     当たりに かぶらず その上に たまっていく。topSteps = 予約する高さ(段数)。 */
  function pileReserve(c, topSteps, it, cfg) {
    // 記号の 大きさぶん + 1列。となりの列の 粒が はしだけ かすめるのを ふせぐ
    var span = Math.max(1, Math.round(it.size / cfg.pileCol)),  // よこの 列数
        n = pileCols(cfg), half = Math.floor(span / 2) + 1, i, t;
    for (i = -half; i <= half; i++) {
      t = c + i; if (t < 0 || t >= n) continue;
      if ((pileH[t] || 0) < topSteps) pileH[t] = topSteps;
    }
  }
  /* 当たりの置き場所。落ちたあたりの 山の上に置き、すでに置いた 当たりと
     かさなるときは かさならなくなるまで 上へ ずらす。
     列モデル(1段8px)では 当たりは 何段ぶんもの 大きさがあり、山がいっぱいに
     なると 全部が 底に置かれて 前の当たりを 上書きしてしまうため、
     当たりだけは 列の高さではなく 実際の座標で 場所をさがす。 */
  function bigSpot(it, cfg) {
    var n = pileCols(cfg),
        c = Math.min(n - 1, Math.max(0, Math.round(it.xr * w.innerWidth / cfg.pileCol))),
        x = Math.min(w.innerWidth - it.size / 2 - 2,
            Math.max(it.size / 2 + 2, c * cfg.pileCol + cfg.pileCol / 2 + it.jx)),
        b = 4 + (pileH[c] || 0) * cfg.pileStep + it.dy,
        ceil = Math.max(4, w.innerHeight - it.size - 4),
        guard = 0, k, a, hit;
    do {
      hit = false;
      for (k = 0; k < PILE.length; k++) {
        a = PILE[k];
        if (!a.big || a.px == null) continue;
        // 四角どうしの あたり判定。よこは 中心きょり、たては 下ばし＋大きさで見る
        // （下ばしどうしを 中心きょりの しきい値で くらべると すきまが たりない）
        if (Math.abs(a.px - x) < (a.size + it.size) / 2 &&
            b < a.pb + a.size && a.pb < b + it.size) {
          b = a.pb + a.size + 2;   // ぶつかった当たりの すぐ上へ 逃がす
          hit = true; break;
        }
      }
    } while (hit && b < ceil && guard++ < 60);
    if (b > ceil) b = ceil;      // 画面の上まで いっぱい → いちばん上に そろえる
    return { c: c, x: x, b: b };
  }
  function pilePlace(it, el, cfg) {
    var c, h, x, bottom;
    if (it.big) {
      var spot = bigSpot(it, cfg);
      c = spot.c; x = spot.x; bottom = spot.b;
      it.px = x; it.pb = bottom;   // ほかの当たりとの あたり判定に つかう
      pileReserve(c, Math.ceil((bottom + it.size) / cfg.pileStep), it, cfg);
    } else {
      c = pileCol(it.xr, cfg);
      if (c < 0) return false;     // どの列もいっぱい。ふつうの粒は あきらめる
      h = pileH[c] || 0; pileH[c] = h + 1;
      x = c * cfg.pileCol + cfg.pileCol / 2 + ((h % 2) ? cfg.pileCol / 2 : 0) + it.jx;
      bottom = 4 + h * cfg.pileStep + it.dy;
    }
    it.col = c;
    el.style.left = Math.min(w.innerWidth - 5, Math.max(5, x)) + "px";
    el.style.bottom = bottom + "px";
    el.style.color = it.color;
    el.style.fontSize = it.size + "px";
    el.style.transform = "translate(-50%,0) rotate(" + it.rot + "deg)";
    el.textContent = it.mark;
    return true;
  }
  function pileFull(cfg) {
    var n = pileCols(cfg), st = pileStack(cfg), i;
    for (i = 0; i < n; i++) if ((pileH[i] || 0) < st) return false;
    return true;
  }
  function pileAdd(it, cfg) {
    if (!it.big && (PILE.length >= cfg.pileMax || pileFull(cfg))) return;
    var el = d.createElement("span");
    if (!pilePlace(it, el, cfg)) return;
    PILE.push(it); pileBox().appendChild(el);
  }
  function pileRender() {
    var cfg = pileCfg, box = pileBox(), keep = [], frag = d.createDocumentFragment();
    box.innerHTML = ""; pileH = [];
    // 置きなおす前に 当たりの座標を 消す。残っていると 自分自身や 古い位置と
    // ぶつかったと 判定されて、当たりが どんどん 上へ 追いやられてしまう
    PILE.forEach(function (it) { it.px = null; it.pb = null; });
    PILE.forEach(function (it) {
      var el = d.createElement("span");
      if (pilePlace(it, el, cfg)) { frag.appendChild(el); keep.push(it); }
    });
    PILE.length = 0; keep.forEach(function (x) { PILE.push(x); });
    box.appendChild(frag);
  }
  w.addEventListener("resize", function () { if (PILE.length) pileRender(); });

  /* ---------- 「せいかい！」を1文字ずつ弾けさせて元に戻す ---------- */
  function showText(cfg) {
    var m = textBox(cfg);
    m.innerHTML = "";
    if (!cfg.text) { m.classList.remove("on"); return; }
    var chars = String(cfg.text).split(""), i;
    // 文字数に合わせて画面からはみ出さない大きさにする
    m.style.fontSize = Math.max(40, Math.min(150, w.innerWidth * 0.92 / chars.length)) + "px";
    for (i = 0; i < chars.length; i++) {
      var sp = d.createElement("span");
      sp.textContent = chars[i];
      var ang = R(0, 359) * Math.PI / 180,
          dist = R(cfg.textDistMin, cfg.textDistMax);
      sp.style.setProperty("--sx", Math.round(Math.cos(ang) * dist) + "px");
      sp.style.setProperty("--sy", Math.round(Math.sin(ang) * dist) + "px");
      sp.style.setProperty("--sr", R(-140, 140) + "deg");
      sp.style.animationDelay = (i * 0.045) + "s";
      if (cfg.hold) sp.style.animationName = "divpFwChipHold";
      m.appendChild(sp);
    }
    m.classList.add("on");
  }

  /* ---------- 本体 ---------- */
  function fire(opts) {
    var cfg = merge(CFG, opts || {});
    pileCfg = cfg;
    if (!d.body) return;
    injectCSS(cfg);

    var box = layer(cfg);
    box.classList.add("on");
    box.innerHTML = "";
    showText(cfg);
    if (raf) { cancelAnimationFrame(raf); raf = null; }
    if (hideTimer) { clearTimeout(hideTimer); }

    // 動きをへらす設定のときは文字だけ静かに出す
    if (reduced()) {
      box.classList.remove("on");
      if (!cfg.hold) hideTimer = setTimeout(function () { textBox(cfg).classList.remove("on"); }, 900);
      return;
    }

    var cx = w.innerWidth / 2, cy = w.innerHeight * cfg.originY,
        N = cfg.count, ps = [], i;
    // 当たりを1つだけ まぜる。superChance(いちばんレア) を先に引き、
    // はずれたら jumboChance を引く（同じ演出に 2つは 出さない）
    var sup = -1, jumbo = -1;
    if (Math.random() < cfg.superChance) sup = R(0, N - 1);
    else if (Math.random() < cfg.jumboChance) jumbo = R(0, N - 1);
    // スペシャルは 画面はばの半分を こえない大きさにする(小さい端末で はみ出さないように)
    var supSize = Math.min(R(cfg.superSizeMin, cfg.superSizeMax),
                           Math.round(w.innerWidth * 0.5));
    for (i = 0; i < N; i++) {
      // 360度どの向きにも同じ勢いで飛ばす(=真円に弾ける)
      // slowRatio のぶんは あまり弾けず、ふわっと ゆれながら落ちる粒にする(小さめ)
      var sp2 = (i === sup);              // スペシャル(1/100)
      var big = sp2 || (i === jumbo);     // 特大。どちらも かならず 山に残る
      var slow = big || Math.random() < cfg.slowRatio;
      var el = d.createElement("span");
      el.textContent = sp2 ? pick(cfg.superMarks) : pick(cfg.marks);
      // スペシャルは 絵文字そのものの色で 出す（色を指定すると 単色になる書体がある）
      if (!sp2) el.style.color = pick(cfg.hues);
      el.style.fontSize = (sp2 ? supSize
                          : big ? R(cfg.jumboSizeMin, cfg.jumboSizeMax)
                          : slow ? R(cfg.slowSizeMin, cfg.slowSizeMax)
                                 : R(cfg.sizeMin, cfg.sizeMax)) + "px";
      box.appendChild(el);
      var ang = ((i / N) * 360 + R(-10, 10)) * Math.PI / 180,
          sp = sp2 ? R(140, 300)
             : big ? R(240, 460)
             : slow ? R(cfg.slowSpeedMin, cfg.slowSpeedMax)
                    : R(cfg.speedMin, cfg.speedMax);
      ps.push({
        el: el, x: cx, y: cy, big: big,
        vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp,
        gm: sp2 ? 0.3 : big ? 0.5 : slow ? cfg.slowGravity : 1,
        sway: sp2 ? R(6, 16) : big ? R(10, 25) : slow ? R(25, 70) : 0, ph: R(0, 628) / 100,
        rot: R(0, 359),
        vr: sp2 ? R(-45, 45) : big ? R(-90, 90) : slow ? R(-150, 150) : R(-600, 600),
        sc: big ? 1 : R(80, 150) / 100,
        life: sp2 ? R(cfg.lifeMax * 130, cfg.lifeMax * 185) / 100
            : big ? R(cfg.lifeMax * 110, cfg.lifeMax * 155) / 100
            : slow ? R(cfg.lifeMax * 100, cfg.lifeMax * 145) / 100
                   : R(cfg.lifeMin * 100, cfg.lifeMax * 100) / 100, t: 0
      });
    }

    var last = performance.now();
    (function step(now) {
      var dt = Math.min(0.05, (now - last) / 1000); last = now;
      var alive = 0, j, p, dmp, r;
      for (j = 0; j < ps.length; j++) {
        p = ps[j];
        p.t += dt;
        if (p.t >= p.life) {
          p.el.style.opacity = 0;
          if (cfg.pile && !p.piled) {
            p.piled = true;
            // ふつうの粒は 画面内で消えたものだけ たまる。
            // 特大の記号は めったに出ないごほうびなので、画面の外へ飛んでいっても
            // 山がいっぱいでも かならず 積もらせる(force)。位置は 画面内へ寄せる。
            if (p.big || (p.x >= 0 && p.x <= w.innerWidth && p.y >= -40))
              pileAdd({ xr: Math.max(0, Math.min(1, p.x / w.innerWidth)),
                        dy: R(-3, 3), jx: R(-5, 5), rot: R(-40, 40),
                        mark: p.el.textContent, color: p.el.style.color,
                        size: parseInt(p.el.style.fontSize, 10),
                        big: p.big }, cfg);
          }
          continue;
        }
        alive++;
        dmp = Math.max(0, 1 - cfg.drag * dt);   // 弾けた勢いはおとろえ…
        p.vx *= dmp; p.vy *= dmp; p.vy += cfg.gravity * p.gm * dt;  // …あとは重力で落下
        var sw = p.sway ? Math.sin((p.t + p.ph) * 3.2) * p.sway : 0;  // ふわっと粒のよこゆれ
        p.x += (p.vx + sw) * dt; p.y += p.vy * dt; p.rot += p.vr * dt;
        r = p.t / p.life;
        p.el.style.opacity = r < 0.6 ? Math.min(1, p.t / 0.08) : (1 - (r - 0.6) / 0.4);
        p.el.style.transform =
          "translate(" + p.x.toFixed(1) + "px," + p.y.toFixed(1) + "px) " +
          "translate(-50%,-50%) rotate(" + p.rot.toFixed(0) + "deg) scale(" + p.sc + ")";
      }
      if (alive) { raf = requestAnimationFrame(step); }
      else { box.classList.remove("on"); box.innerHTML = ""; raf = null; }
    })(last);

    if (!cfg.hold) {
      hideTimer = setTimeout(function () { textBox(cfg).classList.remove("on"); }, 2150);
    }
  }

  /* ---------- 公開 / Divpへの結線 ---------- */
  fire.clearPile = function () {              // 積もった記号を消す
    var m = d.getElementById(PILE_ID);
    PILE.length = 0; pileH = [];
    if (m) m.innerHTML = "";
  };
  fire.hide = function () {                    // hold:true のとき 文字を消す
    var m = d.getElementById(TEXT_ID);
    if (m) { m.classList.remove("on"); m.innerHTML = ""; }
  };
  w.DivpFirework = fire;
  w.DivpEffects = w.DivpEffects || {};
  w.DivpEffects.firework = fire;

  function bind() {
    var eff = d.body && d.body.getAttribute("data-effect");
    if (eff !== "firework") return;             // data-effect で明示したときだけ差し替える
    if (w.Divp) { w.Divp.correct = fire; }
  }
  if (d.readyState === "loading") d.addEventListener("DOMContentLoaded", bind);
  else bind();
  w.addEventListener("load", bind);             // divp-core.js が後から入る場合の保険
})(window, document);
