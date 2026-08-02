/* menseki-fig.js — 円の面積マスター（unit_key: math_es6_en_menseki）の問題図SVG
 *
 * 単元専用モジュール。読み込むのは次の2箇所だけ:
 *   - learning/math/math_es6_en_menseki.html （出題時の図）
 *   - teacher.php                            （解き直しプリントの図）
 * 図の実装をここ1箇所に置くことで、画面と印刷で必ず同じ図になる。
 * 図を直すときはこのファイルだけを直す（ツール側に複製を作らない）。
 *
 * 使い方:
 *   MensekiFig.svg(fig)          fig = {t:'sqcircle', a:8} など内部表現から描く
 *   MensekiFig.fromParams(qp)    answer_logs.question_params ({m,s,d}) から描く
 *   MensekiFig.mini(kind)        解説用のちいさな図（正方形・円・半円…）
 */
(function (global) {
"use strict";

var PI = 3.14;

/* 3.14 のかけ算は浮動小数の誤差が出るので、表示は小数第4位でそろえる */
function fmt(x){
  var r = Math.round(x*10000)/10000;
  return String(r);
}
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

var C = {
  fill:'#F6DCD6', fill2:'#EFE9DC', paper:'#FFFDF7',
  edge:'#C73E2E', dim:'#2C5F8A', guide:'#A9A498', sub:'#8A5A2B'
};
/* 図の中の文字も丸ゴシックでそろえる（CSS変数はSVG属性に使えないので実体で持つ） */
var SVGFONT = "Zen Maru Gothic,Hiragino Maru Gothic ProN,HGMaruGothicMPRO,"
            + "M PLUS Rounded 1c,Yu Gothic,sans-serif";

function tx(x,y,s,color,anchor,size){
  return '<text x="'+x+'" y="'+y+'" text-anchor="'+(anchor||'middle')+'" dominant-baseline="middle"'
    + ' font-size="'+(size||14)+'" font-weight="700" font-family="'+SVGFONT+'"'
    + ' fill="'+(color||C.dim)+'" paint-order="stroke" stroke="'+C.paper+'" stroke-width="4.5"'
    + ' stroke-linejoin="round">'+esc(s)+'</text>';
}
/* 横方向の寸法線（下に文字） */
function dimH(x1,x2,y,label,color){
  var c = color||C.dim;
  return '<g stroke="'+c+'" stroke-width="1.6" fill="none">'
    + '<path d="M'+x1+','+(y-5)+' V'+(y+5)+'"/><path d="M'+x2+','+(y-5)+' V'+(y+5)+'"/>'
    + '<path d="M'+x1+','+y+' H'+x2+'"/></g>'
    + tx((x1+x2)/2, y+16, label, c);
}
/* 中心からのばす半径線（中心に点）。dx/dy はラベルを線の中点からずらす量 */
function radLine(cx,cy,tx2,ty2,label,color,dx,dy){
  var c = color||C.dim;
  if(dx === undefined) dx = 0;
  if(dy === undefined) dy = -11;
  return '<path d="M'+fmt(cx)+','+fmt(cy)+' L'+fmt(tx2)+','+fmt(ty2)+'" stroke="'+c+'" stroke-width="1.8" fill="none"/>'
    + '<circle cx="'+fmt(cx)+'" cy="'+fmt(cy)+'" r="3" fill="'+c+'"/>'
    + tx((cx+tx2)/2+dx, (cy+ty2)/2+dy, label, c);
}
function svgOpen(w,h,cls){
  return '<svg viewBox="0 0 '+fmt(w)+' '+fmt(h)+'" role="img"'
    + (cls?' class="'+cls+'"':'') + ' aria-label="問題の図">';
}

/* --- 円・半円・四分円（半径/直径/円周のモード用） --- */
function figCircle(f){
  var P = 40, r = f.r, part = f.part||'full';
  /* 四分円は縦横が半径ぶんしかないので、半径を大きめに取って図の大きさをそろえる */
  var sc = (part === 'quarter' ? 185/r : 218/(2*r));
  var R = r*sc, w, h, body='', deco='', padB = P;
  if(part === 'full'){
    w = 2*R; h = 2*R;
    body = '<circle cx="'+fmt(R)+'" cy="'+fmt(R)+'" r="'+fmt(R)+'" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.6"/>';
    if(f.show === 'r'){
      deco = radLine(R,R,2*R,R,'半径 '+r+'cm');
    }else if(f.show === 'd'){
      deco = '<path d="M0,'+fmt(R)+' H'+fmt(2*R)+'" stroke="'+C.dim+'" stroke-width="1.8"/>'
        + '<circle cx="'+fmt(R)+'" cy="'+fmt(R)+'" r="3" fill="'+C.dim+'"/>'
        + tx(R, R-13, '直径 '+f.dlabel+'cm');
    }else{  /* 円周 */
      deco = '<path d="M'+fmt(R)+','+fmt(2*R)+' V'+fmt(2*R+17)+'" stroke="'+C.dim+'" stroke-width="1.6" fill="none"/>'
        + tx(R, 2*R+30, 'まわりの長さ '+f.clabel+'cm');
      padB = P + 30;
    }
  }else if(part === 'half'){
    w = 2*R; h = R;
    body = '<path d="M0,'+fmt(R)+' A'+fmt(R)+','+fmt(R)+' 0 0,1 '+fmt(2*R)+','+fmt(R)+' Z"'
      + ' fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.6"/>';
    if(f.show === 'r'){
      /* 半円は横に引くと弧とラベルが重なるので、中心から真上へ引く */
      deco = radLine(R,R,R,0,'半径 '+r+'cm',null,42,0);
    }else{
      deco = dimH(0,2*R,R+18,'直径 '+f.dlabel+'cm');
      padB = P + 26;
    }
  }else{  /* quarter：中心は左下 */
    w = R; h = R;
    body = '<path d="M0,'+fmt(R)+' H'+fmt(R)+' A'+fmt(R)+','+fmt(R)+' 0 0,0 0,0 Z"'
      + ' fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.6"/>';
    deco = dimH(0,R,R+18,'半径 '+r+'cm') + '<circle cx="0" cy="'+fmt(R)+'" r="3" fill="'+C.dim+'"/>';
    padB = P + 26;
  }
  return svgOpen(w+2*P, h+P+padB)
    + '<g transform="translate('+P+','+P+')">' + body + deco + '</g></svg>';
}

/* --- 正方形から内接円をくり抜く --- */
function figSqCircle(f){
  var P = 40, a = f.a, sc = 216/a, A = a*sc, R = A/2;
  var g = '<path d="M0,0 H'+fmt(A)+' V'+fmt(A)+' H0 Z" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<circle cx="'+fmt(R)+'" cy="'+fmt(R)+'" r="'+fmt(R)+'" fill="'+C.paper+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + dimH(0,A,A+18,'1辺 '+a+'cm');
  return svgOpen(A+2*P, A+P+P+26) + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

/* --- 円の輪（ドーナツ） --- */
function figDonut(f){
  var P = 40, R0 = f.R, r0 = f.r, sc = 216/(2*R0);
  var Ro = R0*sc, Ri = r0*sc, cx = Ro, cy = Ro;
  var g = '<circle cx="'+fmt(cx)+'" cy="'+fmt(cy)+'" r="'+fmt(Ro)+'" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<circle cx="'+fmt(cx)+'" cy="'+fmt(cy)+'" r="'+fmt(Ri)+'" fill="'+C.paper+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    /* 外は真上・内は右へ。同じ向きに引くとラベルが重なるため向きを分ける */
    + radLine(cx,cy,cx,cy-Ro,'外の半径 '+R0+'cm',null,52,0)
    + radLine(cx,cy,cx+Ri,cy,'内の半径 '+r0+'cm',C.sub,0,17);
  return svgOpen(2*Ro+2*P, 2*Ro+2*P) + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

/* --- 葉っぱ形（四分円2つの重なり） --- */
function figLeaf(f){
  var P = 40, a = f.a, sc = 216/a, A = a*sc;
  var leaf = 'M'+fmt(A)+',0 A'+fmt(A)+','+fmt(A)+' 0 0,1 0,'+fmt(A)
           + ' A'+fmt(A)+','+fmt(A)+' 0 0,1 '+fmt(A)+',0 Z';
  var g = '<path d="M0,0 H'+fmt(A)+' V'+fmt(A)+' H0 Z" fill="'+C.paper+'" stroke="'+C.guide+'"'
        + ' stroke-width="1.8" stroke-dasharray="5 4"/>'
    + '<path d="'+leaf+'" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<circle cx="0" cy="0" r="3" fill="'+C.guide+'"/><circle cx="'+fmt(A)+'" cy="'+fmt(A)+'" r="3" fill="'+C.guide+'"/>'
    + dimH(0,A,A+18,'1辺 '+a+'cm');
  return svgOpen(A+2*P, A+P+P+26) + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

/* --- 弓形（四分円 − 直角三角形） --- */
function figBow(f){
  var P = 40, a = f.a, sc = 200/a, A = a*sc;
  var quarter = 'M0,'+fmt(A)+' H'+fmt(A)+' A'+fmt(A)+','+fmt(A)+' 0 0,0 0,0 Z';
  var bow = 'M'+fmt(A)+','+fmt(A)+' A'+fmt(A)+','+fmt(A)+' 0 0,0 0,0 Z';
  var g = '<path d="'+quarter+'" fill="'+C.paper+'" stroke="'+C.guide+'" stroke-width="1.8"/>'
    + '<path d="'+bow+'" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<path d="M0,0 L0,'+fmt(A)+' L'+fmt(A)+','+fmt(A)+' Z" fill="none" stroke="'+C.dim+'"'
    + ' stroke-width="1.8" stroke-dasharray="5 4"/>'
    + '<path d="M0,'+fmt(A-14)+' h14 v14" fill="none" stroke="'+C.dim+'" stroke-width="1.4"/>'
    + dimH(0,A,A+18,'半径 '+a+'cm')
    + '<circle cx="0" cy="'+fmt(A)+'" r="3" fill="'+C.dim+'"/>';
  return svgOpen(A+2*P, A+P+P+26) + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

/* --- 花びら4枚（正方形の各辺を直径とする半円4つの重なり） --- */
function figFlower4(f){
  var P = 40, a = f.a, sc = 216/a, A = a*sc, h = A/2;
  var petal = 'M0,0 A'+fmt(h)+','+fmt(h)+' 0 0,0 '+fmt(h)+','+fmt(h)
            + ' A'+fmt(h)+','+fmt(h)+' 0 0,0 0,0 Z';
  var arcs = ''
    + '<path d="M0,0 A'+fmt(h)+','+fmt(h)+' 0 0,0 '+fmt(A)+',0" fill="none" stroke="'+C.edge+'" stroke-width="1.6"/>'
    + '<path d="M'+fmt(A)+',0 A'+fmt(h)+','+fmt(h)+' 0 0,0 '+fmt(A)+','+fmt(A)+'" fill="none" stroke="'+C.edge+'" stroke-width="1.6"/>'
    + '<path d="M'+fmt(A)+','+fmt(A)+' A'+fmt(h)+','+fmt(h)+' 0 0,0 0,'+fmt(A)+'" fill="none" stroke="'+C.edge+'" stroke-width="1.6"/>'
    + '<path d="M0,'+fmt(A)+' A'+fmt(h)+','+fmt(h)+' 0 0,0 0,0" fill="none" stroke="'+C.edge+'" stroke-width="1.6"/>';
  var petals = '';
  [0,90,180,270].forEach(function(deg){
    petals += '<g transform="rotate('+deg+' '+fmt(h)+' '+fmt(h)+')"><path d="'+petal+'"'
      + ' fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.2"/></g>';
  });
  var g = '<path d="M0,0 H'+fmt(A)+' V'+fmt(A)+' H0 Z" fill="'+C.paper+'" stroke="'+C.guide+'"'
        + ' stroke-width="1.8" stroke-dasharray="5 4"/>'
    + arcs + petals + dimH(0,A,A+18,'1辺 '+a+'cm');
  return svgOpen(A+2*P, A+P+P+26) + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

/* --- 大きい半円＋小さい半円2つ（入れかえるともとの大半円） ---
   ※CSSで #swappiece を180°回転させるため、この図だけは
     ラッパーの translate を使わず座標に余白を焼き込む（transform-origin をそろえる） */
function figArbelos(f){
  var PX = 40, PT = 46, D = f.D, sc = 214/D;
  var d = D*sc, R = d/2, r = d/4;
  var ox = PX, oy = PT + R;                 /* 直径の線（基準線）の位置 */
  var W = d + 2*PX, H = oy + r + 44;
  function X(v){ return fmt(ox+v); }
  function Y(v){ return fmt(oy+v); }
  /* 大半円（上）から左の小半円（上）をくり抜いた部分＝動かさない側 */
  var stat = 'M'+X(0)+','+Y(0)
    + ' A'+fmt(R)+','+fmt(R)+' 0 0,1 '+X(d)+','+Y(0)
    + ' H'+X(d/2)
    + ' A'+fmt(r)+','+fmt(r)+' 0 0,0 '+X(0)+','+Y(0)+' Z';
  /* 右下の小半円＝動かす側。基準線の中点まわりに180°回すと左のへこみにぴったり入る */
  var mov = 'M'+X(d/2)+','+Y(0)
    + ' A'+fmt(r)+','+fmt(r)+' 0 0,0 '+X(d)+','+Y(0)+' Z';
  var g = '<path d="M'+X(0)+','+Y(0)+' H'+X(d)+'" stroke="'+C.guide+'" stroke-width="1.6"'
        + ' stroke-dasharray="5 4" fill="none"/>'
    + '<path d="'+stat+'" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<g class="swappiece rot" style="transform-origin:'+X(d/2)+'px '+Y(0)+'px">'
    +   '<path d="'+mov+'" fill="#EFC3B8" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '</g>'
    + '<circle cx="'+X(d/2)+'" cy="'+Y(0)+'" r="3" fill="'+C.dim+'"/>'
    + dimH(ox, ox+d, oy+r+16, '大きい半円の直径 '+D+'cm');
  return svgOpen(W, H, 'swapfig') + g + '</svg>';
}

/* --- 正方形＋半円の入れかえ（左をくり抜き、右へはみ出す） ---
   はみ出た半円を左へ 1辺ぶん平行移動させると、へこみにぴったり入る＝もとの正方形 */
function figSwapSq(f){
  var P = 40, a = f.a, sc = 178/a, A = a*sc, h = A/2;
  var sq    = 'M0,0 H'+fmt(A)+' V'+fmt(A)+' H0 Z';
  var notch = 'M0,0 A'+fmt(h)+','+fmt(h)+' 0 0,1 0,'+fmt(A)+' Z';
  var mov   = 'M'+fmt(A)+',0 A'+fmt(h)+','+fmt(h)+' 0 0,1 '+fmt(A)+','+fmt(A)+' Z';
  var g = '<path d="'+sq+'" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<path d="'+notch+'" fill="'+C.paper+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<g class="swappiece mv" style="--mv:'+fmt(-A)+'px">'
    +   '<path d="'+mov+'" fill="#EFC3B8" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '</g>'
    + '<path d="M'+fmt(A)+',0 V'+fmt(A)+'" stroke="'+C.guide+'" stroke-width="1.6"'
    + ' stroke-dasharray="5 4" fill="none"/>'
    + dimH(0,A,A+18,'1辺 '+a+'cm');
  return svgOpen(A+h+2*P, A+P+P+26, 'swapfig')
    + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

/* --- 正方形の4頂点から四分円4つをくり抜く（中央に星形が残る） --- */
function figStar4(f){
  var P = 40, a = f.a, sc = 216/a, A = a*sc, h = A/2;
  var q1 = 'M0,0 H'+fmt(h)+' A'+fmt(h)+','+fmt(h)+' 0 0,1 0,'+fmt(h)+' Z';
  var g = '<path d="M0,0 H'+fmt(A)+' V'+fmt(A)+' H0 Z" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>';
  [0,90,180,270].forEach(function(deg){
    g += '<g transform="rotate('+deg+' '+fmt(h)+' '+fmt(h)+')"><path d="'+q1+'"'
       + ' fill="'+C.paper+'" stroke="'+C.edge+'" stroke-width="1.8"/></g>';
  });
  g += dimH(0,A,A+18,'1辺 '+a+'cm');
  return svgOpen(A+2*P, A+P+P+26) + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

/* --- 四分円 − 半円（下の辺を直径とする半円をくり抜く） --- */
function figQuarterHalf(f){
  var P = 40, a = f.a, sc = 198/a, A = a*sc, h = A/2;
  var quarter = 'M0,'+fmt(A)+' H'+fmt(A)+' A'+fmt(A)+','+fmt(A)+' 0 0,0 0,0 Z';
  var half    = 'M0,'+fmt(A)+' A'+fmt(h)+','+fmt(h)+' 0 0,1 '+fmt(A)+','+fmt(A)+' Z';
  var g = '<path d="'+quarter+'" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<path d="'+half+'" fill="'+C.paper+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<path d="M0,'+fmt(A-14)+' h14 v14" fill="none" stroke="'+C.dim+'" stroke-width="1.4"/>'
    + dimH(0,A,A+18,'半径 '+a+'cm')
    + '<circle cx="0" cy="'+fmt(A)+'" r="3" fill="'+C.dim+'"/>';
  return svgOpen(A+2*P, A+P+P+26) + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

/* --- 4分の1の輪（同じ中心の四分円2つの差） --- */
function figQuarterRing(f){
  var P = 40, R0 = f.R, r0 = f.r, sc = 198/R0, Ro = R0*sc, Ri = r0*sc;
  var big   = 'M0,'+fmt(Ro)+' H'+fmt(Ro)+' A'+fmt(Ro)+','+fmt(Ro)+' 0 0,0 0,0 Z';
  var small = 'M0,'+fmt(Ro)+' H'+fmt(Ri)+' A'+fmt(Ri)+','+fmt(Ri)+' 0 0,0 0,'+fmt(Ro-Ri)+' Z';
  var g = '<path d="'+big+'" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<path d="'+small+'" fill="'+C.paper+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<path d="M0,'+fmt(Ro-14)+' h14 v14" fill="none" stroke="'+C.dim+'" stroke-width="1.4"/>'
    + dimH(0,Ri,Ro+18,'内 '+r0+'cm')
    + dimH(0,Ro,Ro+42,'外 '+R0+'cm')
    + '<circle cx="0" cy="'+fmt(Ro)+'" r="3" fill="'+C.dim+'"/>';
  return svgOpen(Ro+2*P, Ro+P+P+50) + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

/* --- 半円 − 内接する円 --- */
function figHalfCirc(f){
  var P = 40, dd = f.d, sc = 212/dd, dp = dd*sc, Rp = dp/2, rp = dp/4;
  var half = 'M0,'+fmt(Rp)+' A'+fmt(Rp)+','+fmt(Rp)+' 0 0,1 '+fmt(dp)+','+fmt(Rp)+' Z';
  var g = '<path d="'+half+'" fill="'+C.fill+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<circle cx="'+fmt(Rp)+'" cy="'+fmt(Rp-rp)+'" r="'+fmt(rp)+'"'
    + ' fill="'+C.paper+'" stroke="'+C.edge+'" stroke-width="2.4"/>'
    + '<path d="M'+fmt(Rp)+',0 V'+fmt(Rp)+'" stroke="'+C.guide+'" stroke-width="1.4"'
    + ' stroke-dasharray="5 4" fill="none"/>'
    + '<circle cx="'+fmt(Rp)+'" cy="'+fmt(Rp-rp)+'" r="2.6" fill="'+C.dim+'"/>'
    + dimH(0,dp,Rp+18,'直径 '+dd+'cm');
  return svgOpen(dp+2*P, Rp+P+P+26) + '<g transform="translate('+P+','+P+')">'+g+'</g></svg>';
}

function figSVG(f){
  if(!f) return '';
  switch(f.t){
    case 'circle':   return figCircle(f);
    case 'sqcircle': return figSqCircle(f);
    case 'donut':    return figDonut(f);
    case 'leaf':     return figLeaf(f);
    case 'bow':      return figBow(f);
    case 'flower4':  return figFlower4(f);
    case 'arbelos':  return figArbelos(f);
    case 'swapsq':   return figSwapSq(f);
    case 'star4':    return figStar4(f);
    case 'qhalf':    return figQuarterHalf(f);
    case 'qring':    return figQuarterRing(f);
    case 'halfcirc': return figHalfCirc(f);
  }
  return '';
}

/* --- 解説用のミニ図（「正方形 − 円」のように式を絵で見せる） --- */
function mini(kind){
  var s = '<svg viewBox="0 0 60 60" aria-hidden="true">', F = C.fill, E = C.edge;
  if(kind === 'square')    s += '<rect x="6" y="6" width="48" height="48" fill="'+F+'" stroke="'+E+'" stroke-width="2.2"/>';
  else if(kind === 'circle')  s += '<circle cx="30" cy="30" r="24" fill="'+F+'" stroke="'+E+'" stroke-width="2.2"/>';
  else if(kind === 'half')    s += '<path d="M4,42 A26,26 0 0,1 56,42 Z" fill="'+F+'" stroke="'+E+'" stroke-width="2.2"/>';
  else if(kind === 'quarter') s += '<path d="M6,54 H54 A48,48 0 0,0 6,6 Z" fill="'+F+'" stroke="'+E+'" stroke-width="2.2"/>';
  else if(kind === 'tri')     s += '<path d="M6,54 H54 L6,6 Z" fill="'+F+'" stroke="'+E+'" stroke-width="2.2"/>';
  else if(kind === 'leaf')    s += '<path d="M54,6 A48,48 0 0,1 6,54 A48,48 0 0,1 54,6 Z" fill="'+F+'" stroke="'+E+'" stroke-width="2.2"/>';
  return s + '</svg>';
}

/* answer_logs.question_params ({m:モード, s:サブ種, d:寸法}) → 図
   ツールの build() が組み立てる fig と同じものをここで再構成する。
   工夫モードは fig の型名 = サブ種、寸法キーも d とそのまま同じなので素通しでよい。 */
function fromParams(p){
  if(!p || !p.m || !p.d) return '';
  var d = p.d, s = p.s;
  if(p.m === 'kufu'){
    var f = { t:s };
    for(var k in d){ if(Object.prototype.hasOwnProperty.call(d,k)) f[k] = d[k]; }
    return figSVG(f);
  }
  if(p.m === 'hankei'){
    return figSVG({ t:'circle', r:d.r, show:'r',
      part:(s === 'half' ? 'half' : s === 'quarter' ? 'quarter' : 'full') });
  }
  if(p.m === 'chokkei'){
    return figSVG({ t:'circle', r:d.d/2, show:'d', dlabel:d.d,
      part:(s === 'half' ? 'half' : 'full') });
  }
  if(p.m === 'enshu'){
    return figSVG({ t:'circle', r:d.d/2, part:'full', show:'c', clabel:fmt(PI*d.d) });
  }
  return '';
}

global.MensekiFig = { svg: figSVG, mini: mini, fromParams: fromParams, colors: C };

})(window);
