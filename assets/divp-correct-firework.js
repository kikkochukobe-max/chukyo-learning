/*!
 * divp-correct-firework.js — 小学生(es)向け 正解エフェクト「花火」
 *   1) 中心から絵文字が360度に弾け、空気ていこうで失速したあと重力で落下しながらフェードアウト
 *   2) 同時に「せいかい！」が1文字ずつ外へ散り、集まって元の並びに戻ってから消える
 *   3) 記号は 画面下から 積もっていき(pile)、pileFull(既定100)回の正解で
 *      画面ぜんぶが うまる。1回に 何個 積もるかは 画面の広さから 自動で決まるので、
 *      端末が ちがっても 同じ回数で 同じ見た目になる。
 *      積もるのは 弾けてから pileDelay(既定0.75秒)たった あとで、pileSpread
 *      (既定1.0秒)かけて 少しずつ＝「はじける → 降る → つもる」の 流れになる。
 *      積もったものは ページ(ブラウザ)を 閉じるまで 残る
 *   4) 当たりが 2段階。記号は どれも 同じで 大きさだけが ちがう。
 *      特大は jumboEvery(既定25)回に1回 かならず 出る（運まかせにしない）。
 *      超特大は superFrom(既定30)回を こえてから superChance(既定1/40)の
 *      確率で 出る（回数ぴったりに すると 山が 満タンに なってから 出てきて、
 *      上に 積もらせる 余地が なくなるため）。
 *      当たりは 山の前面に 出して 埋もれないようにし、ふちに 小さい記号を
 *      すこし かけて 山に なじませる。解けば解くほど 当たりが たまっていく
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
 *   DivpFirework.flushPile();             // 積み残しを その場で 積む(「次へ」用)
 *                                         // ※画面をさわると 自動で呼ばれるので
 *                                         //   ふつうは ツール側で 書かなくてよい
 *
 * 積もる山について
 *   ペースの つまみは pileFull(画面が うまるまでの正解数、既定100)だけです。
 *   1回に積もる数は「画面の広さ ÷ pileFull」で自動計算されます
 *   (1280×800なら1回76個・全7524個。1920×1080なら1回152個)。
 *   描画は canvas 2枚(下=山 / 上=当たり)。DOMには積まないので個数が増えても軽いままです。
 *   pileZIndex:5 で「白い面(カード)より前面・ボタンより背面」に積もります。
 *   白地の上は 記号が 目立ちすぎるので、pileVeilSelector(既定 [class*='card'])に
 *   あたる面には うすい白を1枚かぶせて pileVeil(既定0.55)ぶん 薄く見せます。
 *   すきとおった入れものに かけると そこだけ 白い四角が浮くので、既定の対象は
 *   "card" を ふくむクラスだけです(不透明な白い面という 慣習にのっています)。
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
    jumboEvery: 25,     // 正解◯回に1回、特大の記号が かならず1つ 降って積もる
    jumboSizeMin: 64,   // 特大の記号の大きさ
    jumboSizeMax: 104,
    // さらにレアな「スペシャル」。記号は ふつうのものと同じで、大きさだけ 超特大。
    // 超特大だけは 回数ぴったりでは 決めない。pileFull(100)回ちょうどに すると
    // 山が 満タンに なってから 出てきて、上に 積もらせる 余地が なくなるため、
    // superFrom 回を こえてから superChance の 確率で ランダムに 出す。
    // 先に こちらを 判定し、当たった演出には jumbo は 出さない
    superFrom: 30,      // この回数を こえてから 出はじめる
    superChance: 1 / 40, // 1回の正解あたりの 出る確率
    superSizeMin: 150,  // 画面はばの半分を こえない範囲で 使われる
    superSizeMax: 230,
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
    pile: true,         // 消えた記号を 下に積もらせる
    // 積もるペースの つまみは これ1つ。pileFull 回の正解で 画面が うまる。
    // 1回に 何個 積もるかは「画面の広さ ÷ pileFull」で その場で 計算するので、
    // 画面が 広い端末ほど 1回に たくさん 降る＝どの端末でも 同じ回数で 同じ見た目になる。
    // 当たり(特大・超特大)は この数とは べつに かならず 積もる
    pileFull: 100,      // 画面が うまるまでの 正解回数
    // 「弾ける → 降る → 積もる」の 流れに 見えるよう、積もるのを 後ろへ ずらす。
    // 弾けきるのが 0.4秒くらい、粒が 落ちきるのが 1.2〜1.9秒あたり
    pileDelay: 0.75,    // 弾けてから 積もりはじめるまでの ま(秒)
    pileSpread: 1.0,    // 積もりきるまでに かける 時間(秒)
    pileZIndex: 5,      // 5=カードより前面/ボタンより背面, -1=いちばん後ろ
    // 山は うすく。積もるほど 記号が かさなって 濃く見えるので、
    // ここを上げると 上にのった 文字や図が 読みにくくなる
    pileOpacity: 0.3,
    pileCol: 17,        // 列のはば px
    pileStep: 8,        // 1段の高さ px(記号より小さくして重ねる)
    // 満タンのときの 高さ(画面の高さに対する割合)。1=画面いちばん上まで
    pileHeightMax: 1,
    // 当たりが 山の表面に どれだけ しずむか(1=しずまない / 0.9=大きさの1割)
    pileBite: 0.9,
    // 当たりを 置く高さの上限(画面の高さに対する割合)。
    // 山が 画面いっぱいまで 育っても、当たりは この高さより 上には 出さない
    // (そのままだと 画面の外に はみ出して 見えなくなる)
    bigStartMax: 0.75,
    // 問題の 白い面(カード)の 中だけ 山を うすくする。0=そのまま / 1=まっ白。
    // 白地の 上だと 記号が よく目立ち、問題文が 読みにくくなるため、
    // カードの上に うすい 白を 1枚 かぶせる(山より前・中身より背面)。
    // 対象は 中身が 不透明な 白い面だけ。すきとおった 入れものに かけると
    // そこだけ 白い四角が 浮くので、"box"や"panel"は 既定では 入れない
    pileVeil: 0.55,
    pileVeilSelector: "[class*='card']",
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
  var PILE_ID = "divp-fw-pile", BIG_ID = "divp-fw-pile-big";
  var raf = null, hideTimer = null;
  var live = null;      // いま飛んでいる粒 {ps, cfg}。次の演出で 打ち切るとき 山へ落とす
  var PILE = [], pileH = [], pileCfg = CFG;
  var BIGS = [];        // 積もった当たりだけの ひかえ(小さい記号を どちらの面に描くか 見る)
  var pend = [];        // これから 積もる記号の 待ち行列(演出のあいだに 少しずつ 出す)
  var pileReflow = null, pileVW = 0;   // 置きなおしの まとめ / 前回 描いたときの 画面はば
  var fireCount = 0;    // 何回目の正解か。当たりの周期(25回/100回)に つかう

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
      "#" + PILE_ID + ",#" + BIG_ID + "{position:fixed;left:0;top:0;width:100%;height:100%;" +
        "pointer-events:none;opacity:" + cfg.pileOpacity + "}" +
      // 当たりは うすい白(veil)より 前。veilは カードと いっしょに スクロールし、
      // 山は 画面に 固定なので、当たりを veilの 下に 置くと カードの ふちが
      // 通るたび 暗くなって「沈む/浮く」ように 見えてしまう
      "#" + PILE_ID + "{z-index:" + cfg.pileZIndex + "}" +
      "#" + BIG_ID + "{z-index:" + (cfg.pileZIndex + 2) + "}" +
      // 白い面の 中だけ 山を うすく。::before は ツール側で 使われていることが
      // あるので ::after を つかう(math_es3_all の .card::before など)
      (cfg.pileVeil > 0
        ? ":where(" + cfg.pileVeilSelector + "){position:relative}" +
          ":where(" + cfg.pileVeilSelector + ")::after{content:'';position:absolute;" +
            "inset:0;border-radius:inherit;pointer-events:none;" +
            "z-index:" + (cfg.pileZIndex + 1) + ";" +
            "background:rgba(255,255,255," + cfg.pileVeil + ")}" +
          // カードの ::before は 見出しの帯などの かざりに つかわれている。
          // そのままだと うすい白の 下に 入って ぼやけるので 上に出す
          ":where(" + cfg.pileVeilSelector + ")::before{z-index:" + (cfg.pileZIndex + 3) + "}"
        : "") +
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
     置きかた: つねに「いちばん低い列」に置く（同じ高さの列が いくつもあれば
     落ちたところに いちばん近い列）。こうすると 山は 1段ずつ 水平に 上がるので、
     どこかの列だけ のびる＝棒グラフのようには ならない。
     さらに1段ごとに半列ずらし(れんが積み)、±数pxの ゆらぎで すき間をうめる。

     満タン = 画面いっぱい(pileHeightMax)。そこまでを pileFull 回の正解で 埋めるので、
     1回で積もる数は「画面の広さ ÷ pileFull」から その場で 計算する
     （画面が 広いほど 1回に たくさん 降る。どの端末でも 同じ回数で 同じ見た目になる）。

     描くのは spanではなく canvas。画面いっぱいまで積もると 記号は
     1万個をこえるので、DOMに積むと 教室のタブレットで 目に見えて重くなる。
     canvasなら 何個 積もっても 要素は1つ、1個ぶんの 追加は fillText 1回で すむ。
     そのかわり 個々の記号は あとから 消せないので、満タン後は 消さずに
     上から かさねつづける（もう 画面は うまっているので 見た目は 変わらない）。

     canvasは 2枚。下=ふつうの記号の山 / 上=当たり。山は 最後には 画面ぜんぶを
     おおうので、1枚だと 当たりが 埋もれて 見えなくなってしまう。
     ただし 当たりを ただ 前に出すだけだと 山から 切りぬいたように うくので、
     当たりの ふちには 小さい記号を すこし かけて おく(bigTrim)。 */
  var pileCv = null, pileCtx = null, bigCv = null, bigCtx = null, PILE_FONT = "";
  function pileCanvas() {
    if (pileCv) return;
    pileCv = mkCanvas(PILE_ID); bigCv = mkCanvas(BIG_ID);
    pileSize();
  }
  function mkCanvas(id) {
    var el = d.getElementById(id);
    if (!el) {
      el = d.createElement("canvas"); el.id = id;
      el.setAttribute("aria-hidden", "true");
      d.body.appendChild(el);
    }
    return el;
  }
  /* canvasの 実ピクセルを 画面に合わせる(ぼやけ防止)。中身は 消えるので
     呼んだあとは 描きなおすこと */
  function sizeCanvas(cv) {
    var dpr = Math.min(2, w.devicePixelRatio || 1), ctx;
    cv.width = Math.max(1, Math.round(w.innerWidth * dpr));
    cv.height = Math.max(1, Math.round(w.innerHeight * dpr));
    ctx = cv.getContext("2d");
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.textAlign = "center"; ctx.textBaseline = "bottom";
    return ctx;
  }
  function pileSize() {
    pileCtx = sizeCanvas(pileCv); bigCtx = sizeCanvas(bigCv);
    pileVW = w.innerWidth;        // 置きなおしが いるのは はばが 変わったときだけ
    if (!PILE_FONT) PILE_FONT =
      (w.getComputedStyle && d.body ? w.getComputedStyle(d.body).fontFamily : "") || "sans-serif";
  }
  function drawMark(ctx, x, bottom, size, rot, color, mark) {
    if (!ctx) return;
    ctx.save();
    ctx.translate(x, w.innerHeight - bottom);   // 下ばし基準 → canvas座標
    ctx.rotate(rot * Math.PI / 180);
    ctx.font = "900 " + size + "px " + PILE_FONT;
    ctx.fillStyle = color;
    ctx.fillText(mark, 0, 0);
    ctx.restore();
  }
  /* 当たりの 下がわの ふちに 小さい記号を ちらして かける。
     これで 当たりの輪郭に 小さいものが すこし かさなり、山に なじむ */
  function bigTrim(it, cfg) {
    var r = it.size * 0.42, i, n, ph, t;
    if (!it.trim) {
      it.trim = []; n = Math.max(7, Math.round(it.size / 8));
      for (i = 0; i < n; i++) {
        ph = (-105 + 210 * (i + R(0, 80) / 100) / n) * Math.PI / 180;   // 下がわの弧
        it.trim.push({ mark: pick(cfg.marks), color: pick(cfg.hues),
                       size: R(cfg.slowSizeMin, cfg.slowSizeMax), rot: R(-40, 40),
                       dx: Math.sin(ph), dy: -Math.cos(ph) });
      }
    }
    for (i = 0; i < it.trim.length; i++) {
      t = it.trim[i];
      drawMark(bigCtx, it.x + t.dx * r,
               it.bottom + it.size / 2 + t.dy * r - t.size / 2,
               t.size, t.rot, t.color, t.mark);
    }
  }
  function pileCols(cfg) { return Math.max(1, Math.ceil(w.innerWidth / cfg.pileCol)); }
  /* 満タンのときの 段数＝画面の高さぶん */
  function pileRows(cfg) {
    return Math.max(2, Math.floor((w.innerHeight * cfg.pileHeightMax - 6) / cfg.pileStep));
  }
  function pileCap(cfg) { return pileCols(cfg) * pileRows(cfg); }
  /* 1回の正解で 積もる数。画面ぜんぶを pileFull 回で 埋めきる ペース */
  function pilePer(cfg) { return Math.max(1, Math.ceil(pileCap(cfg) / cfg.pileFull)); }
  function pileCol(xr, cfg) {
    var n = pileCols(cfg),
        c0 = Math.min(n - 1, Math.max(0, Math.round(xr * w.innerWidth / cfg.pileCol))),
        best = c0, bh = Infinity, bd = Infinity, i, h, dd;
    for (i = 0; i < n; i++) {
      h = pileH[i] || 0; dd = Math.abs(i - c0);
      if (h < bh || (h === bh && dd < bd)) { bh = h; bd = dd; best = i; }
    }
    return best;
  }
  /* すでに置いた 当たりと ぶつかるか。四角どうしの あたり判定
     （よこは すこし かさなってよい。ぴったり あけると すぐ 場所が なくなる） */
  function bigHits(x, b, it) {
    for (var k = 0; k < PILE.length; k++) {
      var a = PILE[k];
      if (!a.big || a.px == null) continue;
      if (Math.abs(a.px - x) < (a.size + it.size) / 2 * 0.92 &&
          b < a.pb + a.size && a.pb < b + it.size) return true;
    }
    return false;
  }
  /* 当たり(特大・超特大)を 1段(pileStep)ぶんでは 表せないので、その大きさぶん
     まわりの列の高さを 予約する。あとから 降ってくる 記号は この高さから
     積みはじめる＝当たりの 上に 積もっていく。
     予約は 四角ではなく まるい形に そって はしほど 低くする（四角のまま だと
     記号の 左右ななめ上に 何もない すきまが できて 不自然に見える）。
     さらに pileBite ぶん 内がわで 止めるので、上に 積もる記号は
     当たりの ふちに 少し かさなる。 */
  function pileReserve(c, baseSteps, it, cfg) {
    var r = it.size / 2,
        cols = Math.floor(r / cfg.pileCol),   // 中心から 左右 何列ぶん 見るか
        n = pileCols(cfg), i, t, dx, top;
    for (i = -cols; i <= cols; i++) {
      t = c + i; if (t < 0 || t >= n) continue;
      dx = Math.min(1, Math.abs(i) * cfg.pileCol / r);        // 0=中心 1=はし
      // まるい形の 高さ。中心では 記号の 全高、はしでは 半分ぐらいまで さがる
      top = baseSteps + Math.ceil(it.size * (0.5 + 0.5 * Math.sqrt(1 - dx * dx))
                                  * cfg.pileBite / cfg.pileStep);
      if ((pileH[t] || 0) < top) pileH[t] = top;
    }
  }
  /* 当たりの置き場所。落ちたあたりの 山の表面に、pileBite ぶん しずめて 置く。
     山が 育つほど 高いところに 出るので、解きつづけると ごほうびが 上へ 広がる。
     場所が うまっていたら まず よこに ずらして さがす。上へ にがすのは 最後の手段
     （当たりを 上へ 積むと 山から はなれて 空中に うかんで 見えるため）。 */
  function bigSpot(it, cfg) {
    var x0 = Math.min(w.innerWidth - it.size / 2 - 2,
             Math.max(it.size / 2 + 2, it.xr * w.innerWidth + it.jx)),
        c0 = Math.min(pileCols(cfg) - 1, Math.max(0, Math.round(x0 / cfg.pileCol))),
        // 画面の 上まで 行かないよう 頭打ち(そこから先は かさねる＝塔にしない)
        top = Math.max(4, Math.min(Math.round(w.innerHeight * cfg.bigStartMax),
                                   Math.round(w.innerHeight * 0.9) - it.size)),
        // 山の表面。pileBite ぶん しずめて 山に 食いこませる
        face = Math.max(4, Math.min(top, 4 + (pileH[c0] || 0) * cfg.pileStep
                                         - Math.round(it.size * (1 - cfg.pileBite)))),
        lo = it.size / 2 + 2, hi = w.innerWidth - it.size / 2 - 2,
        b = face, i, x;
    while (b <= top) {
      for (i = 0; i * 24 < w.innerWidth * 2; i++) {   // x0 から 左右へ こうごに はなれる
        x = x0 + ((i % 2) ? 1 : -1) * Math.ceil(i / 2) * 24;
        if (x < lo || x > hi) continue;
        if (!bigHits(x, b, it)) return { x: x, b: b };
      }
      b += Math.round(it.size * 0.7) + 2;             // どこも うまっていた → 一段 上へ
    }
    return { x: x0, b: top };
  }
  function pilePlace(it, cfg) {
    var c, h, x, bottom;
    if (it.big) {
      var spot = bigSpot(it, cfg);
      x = spot.x; bottom = spot.b;
      c = Math.min(pileCols(cfg) - 1, Math.max(0, Math.round(x / cfg.pileCol)));
      it.px = x; it.pb = bottom;   // ほかの当たりとの あたり判定に つかう
      // 大きさぶん まわりの列を 予約する＝あとの記号は この上に 積もる
      pileReserve(c, Math.floor(bottom / cfg.pileStep), it, cfg);
    } else {
      c = pileCol(it.xr, cfg);
      h = pileH[c] || 0;
      // 画面の上まで 積もりきった列には かさねて置く(高さは のばさない)。
      // いちばん上にだけ ためると「そこで 止まった」ように 見えるので ばらけさせる
      var rows = pileRows(cfg);
      if (h >= rows) h = R(0, rows - 1);
      else pileH[c] = h + 1;
      x = c * cfg.pileCol + cfg.pileCol / 2 + ((h % 2) ? cfg.pileCol / 2 : 0) + it.jx;
      bottom = 4 + h * cfg.pileStep + it.dy;
    }
    it.x = Math.min(w.innerWidth - 5, Math.max(5, x));
    it.bottom = bottom;
  }
  /* すでに置いた 当たりに かかるか。かかるものは 当たりと同じ面に 描く */
  function onBig(it) {
    for (var k = 0; k < BIGS.length; k++) {
      var a = BIGS[k];
      if (Math.abs(a.x - it.x) < (a.size + it.size) / 2 &&
          Math.abs((a.bottom + a.size / 2) - (it.bottom + it.size / 2))
            < (a.size + it.size) / 2) return true;
    }
    return false;
  }
  /* 当たりは 前面の面(bigCtx)に描く。うしろの面に描くと 山に うもれて
     見えなくなり、また スクロールで うすい白(veil)が 通るたび 暗くなる。
     ただし そのままだと 当たりの上に 積もった記号まで 当たりの うしろに
     まわってしまう＝「大きいものが 無いかのように」見える。
     そこで 当たりに かかる記号だけは 同じ 前面の面に、あとから 描く
     （同じ面なら 描いた順に かさなるので、そのまま 上に 積もって 見える）。 */
  function pileShow(it, cfg) {
    if (it.big) {
      drawMark(bigCtx, it.x, it.bottom, it.size, it.rot, it.color, it.mark);
      bigTrim(it, cfg);
      BIGS.push(it);
      return;
    }
    drawMark(onBig(it) ? bigCtx : pileCtx,
             it.x, it.bottom, it.size, it.rot, it.color, it.mark);
  }
  function pileAdd(it, cfg) {
    pileCanvas();
    pilePlace(it, cfg);
    pileShow(it, cfg);
    // 置きなおし(画面サイズ変更)用に おぼえておく。満タンをこえたぶんは
    // 記録しない＝もう 画面は うまっているので 置きなおしても 見た目は 同じ
    if (it.big || PILE.length < pileCap(cfg)) PILE.push(it);
  }
  /* 画面のはばが 変わったら 列の数も 変わるので、全部 置きなおして 描きなおす */
  function pileRender() {
    var cfg = pileCfg;
    pileCanvas(); pileSize(); pileH = []; BIGS.length = 0;
    // 置きなおす前に 当たりの座標を 消す。残っていると 自分自身や 古い位置と
    // ぶつかったと 判定されて、当たりが どんどん 上へ 追いやられてしまう
    PILE.forEach(function (it) { it.px = null; it.pb = null; });
    PILE.forEach(function (it) { pilePlace(it, cfg); pileShow(it, cfg); });
  }
  /* 置きなおさず 描きなおすだけ。canvasは 大きさを変えると 中身が 消えるので、
     高さだけ 変わったときも 描きなおしは いる */
  function pileRedraw() {
    pileCanvas(); pileSize(); BIGS.length = 0;
    PILE.forEach(function (it) { pileShow(it, pileCfg); });
  }
  /* スマホは スクロールで アドレスバーが 出入りするだけでも resize が とぶ。
     山は どれも 画面の下からの きょりで 置いてあるので、高さが 変わっても
     置きなおす 必要はない（置きなおすと 当たりの 場所が 変わって
     スクロールのたびに 沈んだり 浮いたりして 見える）。
     列の数が 変わる = はばが 変わったときだけ 置きなおす。 */
  w.addEventListener("resize", function () {          // 連続で来るので まとめる
    if (!PILE.length) return;
    clearTimeout(pileReflow);
    pileReflow = setTimeout(function () {
      if (w.innerWidth !== pileVW) pileRender();
      else pileRedraw();
    }, 200);
  });

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

  /* 当たり(特大・超特大)を 山に置く。寿命がきたときと、次の演出で 打ち切られる
     ときの 両方から呼ぶ。ふつうの記号は 粒とは 切りはなして pend で 数を決めるので
     ここでは あつかわない（1回に 100個ちかく 積もるため、飛んでいる粒の数や
     飛んだ向きに 数を 左右されると ペースが 決まらない）。 */
  function dropToPile(p, cfg) {
    if (!cfg.pile || p.piled) return;
    p.piled = true;
    if (!p.big) return;
    pileAdd({ xr: Math.min(1, Math.max(0, p.x / w.innerWidth)),
              dy: R(-3, 3), jx: R(-5, 5), rot: R(-40, 40),
              mark: p.el.textContent, color: p.el.style.color,
              size: parseInt(p.el.style.fontSize, 10),
              big: true }, cfg);
  }
  /* この演出で 積もる ふつうの記号を 待ち行列に入れる。
     数は「画面の広さ ÷ pileFull」＝ pileFull 回で 画面が うまるペース */
  function pendPush(cfg) {
    for (var i = 0, n = pilePer(cfg); i < n; i++)
      pend.push({ xr: Math.random(), dy: R(-3, 3), jx: R(-5, 5), rot: R(-40, 40),
                  mark: pick(cfg.marks), color: pick(cfg.hues),
                  size: R(cfg.slowSizeMin, cfg.slowSizeMax), big: false });
  }
  /* 待ち行列から n 個ぶん 積む。演出のあいだ 毎フレーム 少しずつ 呼ぶので
     「降ったぶんが たまっていく」ように 見える */
  function pendDrain(n, cfg) {
    while (n-- > 0 && pend.length) pileAdd(pend.shift(), cfg);
  }
  /* 次の演出が始まるとき、まだ飛んでいる当たりと 出しきれていない記号を
     先に 山へ入れる。これをしないと 子どもが 速く 解くほど 積もらなくなる
     （当たりは いちばん 寿命が長いので いちばん 打ち切られやすい）。 */
  function flushLive() {
    if (live) {
      var ps = live.ps, c = live.cfg, i;
      for (i = 0; i < ps.length; i++) dropToPile(ps[i], c);
      live = null;
    }
    pendDrain(pend.length, pileCfg);
  }

  /* ---------- 本体 ---------- */
  function fire(opts) {
    var cfg = merge(CFG, opts || {});
    pileCfg = cfg;
    if (!d.body) return;
    injectCSS(cfg);
    flushLive();      // 前の演出が まだ飛んでいたら 先に 山へ落とす

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
    // 当たりを1つだけ まぜる。特大は 回数ぴったり(25回に1回)、
    // 超特大は superFrom 回を こえてから 確率で。先に 超特大を 判定する
    fireCount++;
    var sup = -1, jumbo = -1;
    if (fireCount > cfg.superFrom && Math.random() < cfg.superChance) sup = R(0, N - 1);
    else if (cfg.jumboEvery > 0 && fireCount % cfg.jumboEvery === 0) jumbo = R(0, N - 1);
    // スペシャルは 画面はばの半分を こえない大きさにする(小さい端末で はみ出さないように)
    var supSize = Math.min(R(cfg.superSizeMin, cfg.superSizeMax),
                           Math.round(w.innerWidth * 0.5));
    for (i = 0; i < N; i++) {
      // 360度どの向きにも同じ勢いで飛ばす(=真円に弾ける)
      // slowRatio のぶんは あまり弾けず、ふわっと ゆれながら落ちる粒にする(小さめ)
      var sp2 = (i === sup);              // スペシャル(100回に1回)
      var big = sp2 || (i === jumbo);     // 特大。どちらも かならず 山に残る
      var slow = big || Math.random() < cfg.slowRatio;
      var el = d.createElement("span");
      el.textContent = pick(cfg.marks);   // スペシャルも 記号は ふつうのものと同じ
      el.style.color = pick(cfg.hues);
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

    // この演出のぶんを 待ち行列に入れる。出すのは pileDelay 秒 たってから、
    // pileSpread 秒 かけて 少しずつ（弾けている あいだは 積もらせない）
    if (cfg.pile) pendPush(cfg);
    var queued = pend.length, placed = 0, T = 0;

    live = { ps: ps, cfg: cfg };   // 打ち切られたときに 山へ落とせるように 覚えておく

    var last = performance.now();
    (function step(now) {
      var dt = Math.min(0.05, (now - last) / 1000); last = now;
      var alive = 0, j, p, dmp, r;
      for (j = 0; j < ps.length; j++) {
        p = ps[j];
        p.t += dt;
        if (p.t >= p.life) {
          p.el.style.opacity = 0;
          dropToPile(p, cfg);
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
      T += dt;                                  // 降ってきたぶんから 順に 積もらせる
      if (queued) {
        var want = Math.round(queued *
          Math.min(1, Math.max(0, (T - cfg.pileDelay) / cfg.pileSpread)));
        if (want > placed) { pendDrain(want - placed, cfg); placed = want; }
      }
      if (alive || pend.length) { raf = requestAnimationFrame(step); }
      else {
        box.classList.remove("on"); box.innerHTML = ""; raf = null;
        if (live && live.ps === ps) live = null;   // 全部 落ちきった
      }
    })(last);

    if (!cfg.hold) {
      hideTimer = setTimeout(function () { textBox(cfg).classList.remove("on"); }, 2150);
    }
  }

  /* ---------- 公開 / Divpへの結線 ---------- */
  fire.clearPile = function () {              // 積もった記号を消す
    PILE.length = 0; pileH = []; pend.length = 0; BIGS.length = 0;
    if (pileCtx) pileCtx.clearRect(0, 0, w.innerWidth, w.innerHeight);
    if (bigCtx) bigCtx.clearRect(0, 0, w.innerWidth, w.innerHeight);
  };
  /* 「次へ」などで 先に すすむとき、まだ 出しきっていない ぶんを その場で 積む。
     ほうっておいても 描画ループが 出しきる（ループは 待ち行列が 空になるまで
     止まらない）が、次の問題に 移ったあとで ぱらぱら 増えるのは 落ちつかないので、
     画面を さわられたら そこで 出しきる。飛んでいる当たりは そのまま
     （まだ 見えているので、着地を 待つ）。 */
  fire.flushPile = function () { if (pend.length) pendDrain(pend.length, pileCfg); };
  d.addEventListener("pointerdown", fire.flushPile, true);
  d.addEventListener("keydown", fire.flushPile, true);

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
