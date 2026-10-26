/* SYNCED COPY. Source of truth: familywell-site/public/mascot/homer.js (README.md there has the full API).
 * To update, copy that file over this one as-is; keep only this header. Loaded lazily by components/homer.tsx
 * (it defines a custom element at import, which must not run during Inertia SSR). */

/* Homer, the FamilyWell mascot: the house on legs.
 * One dependency-free ES module. It is the same renderer as the Homer character sheet
 * (https://claude.ai/artifact/Lhnr1i7XUZKSwVECqKH3Yg), so the live mascot, the WebP stills in this
 * folder and the sheet always agree. Usage and the event list: README.md next to this file.
 *
 * Sections: renderer (copied from the sheet, kept compact on purpose so it diffs cleanly against it),
 * then still() for image export, then the <homer-mascot> element and its reactions. */

const TAU=Math.PI*2, RAD=Math.PI/180;
const W=1.7, H=1.15, D=0.92, LEG=0.55, RE=0.2, PIVOT=1.12;
const EYE_INK='#141210';
const clamp=(v,a=0,b=1)=>Math.min(b,Math.max(a,v));
const lerp=(a,b,t)=>a+(b-a)*t;
const ease=t=>t<.5?2*t*t:1-(-2*t+2)**2/2;
const smooth=(a,b,x)=>{const t=clamp((x-a)/(b-a));return t*t*(3-2*t)};
const env=(p,a)=>smooth(0,a,p)*(1-smooth(1-a,1,p));
const backOut=q=>{const c1=1.70158,c3=c1+1;return 1+c3*(q-1)**3+c1*(q-1)**2};
const kf=(p,keys)=>{for(let i=1;i<keys.length;i++){if(p<=keys[i][0]){const[a,va]=keys[i-1],[b,vb]=keys[i];return lerp(va,vb,ease(clamp((p-a)/(b-a))))}}return keys[keys.length-1][1]};
const hash=n=>{const x=Math.sin(n*127.1)*43758.5453;return x-Math.floor(x)};

/* ---------- body mesh: superellipse profile, rounded front/back edges ---------- */
const meshes={};
function mesh(N,K){
  const key=N+'/'+K; if(meshes[key])return meshes[key];
  const zs=[];
  for(let k=K;k>=0;k--){const a=k/K*Math.PI/2;zs.push([-(D/2-RE+RE*Math.sin(a)),RE*(1-Math.cos(a))])}
  for(let k=0;k<=K;k++){const a=k/K*Math.PI/2;zs.push([D/2-RE+RE*Math.sin(a),RE*(1-Math.cos(a))])}
  const n=2/4.6;
  return meshes[key]=zs.map(([z,ins])=>{const a=W/2-ins,b=H/2-ins,pts=[];
    for(let i=0;i<N;i++){const t=i/N*TAU,c=Math.cos(t),s=Math.sin(t);pts.push([a*Math.sign(c)*Math.abs(c)**n,H/2+b*Math.sign(s)*Math.abs(s)**n,z])}
    return pts});
}
/* toon-ish shading: shadow -> base -> highlight, quantised so it reads as illustration */
const SH=[214,176,0], BASE=[248,216,12], HI=[255,233,92];
const shadeCache={};
function shade(k){
  const q=Math.round(clamp(k,-1,1)*14)/14;
  if(shadeCache[q])return shadeCache[q];
  let c; if(q<.55){const t=clamp((q+.25)/.8);c=SH.map((v,i)=>lerp(v,BASE[i],t))}else{const t=clamp((q-.55)/.45);c=BASE.map((v,i)=>lerp(v,HI[i],t))}
  return shadeCache[q]=`rgb(${c.map(Math.round).join(',')})`;
}
const L=(()=>{const v=[-.42,.72,.56],m=Math.hypot(...v);return v.map(x=>x/m)})();

/* ---------- arms: [ctrl x,y,z, hand x,y,z], x measured out from the side wall ---------- */
const ARMS={
  down:  [.19,.66,0,  .24,.07,.02],
  up:    [.27,.98,.03, .3,1.46,.06],
  raised:[.34,.62,.04, .27,1.24,.06],
  out:   [.24,.68,.03, .48,.5,.06],
  fist:  [.32,.32,.12, .33,.74,.14],
  chin:  [.14,.3,.34,  -.42,.36,D/2+.07],
  low:   [.14,.42,.02, .2,.01,.05],
  roof:  [.32,1.02,.12, .02,1.4,.16],
  ear:   [.42,.5,.08,  .12,.9,.1],  /* Homer has no ears, so the hand cups beside the top of the wall, elbow out */
};
const ROOF_Y=H+.1;

/* ---------- faces ---------- */
const FACES={
  neutral:{n:'Neutral',note:'Default. Two eyes, no mouth, roof on.'},
  happy:{n:'Happy',note:'Arms up, a kick, a smile. Task done.',roll:-7,armL:'up',armR:'up',eyes:['happy','happy'],mouth:'smile',fx:['wiggle'],
    dyn:(r,T)=>{r.py+=.05*Math.abs(Math.sin(T*5));r.feet[3][1]+=.08;r.feet[3][2]+=.05}},
  surprised:{n:'Surprised',note:'The roof pops off its head.',armL:'raised',armR:'raised',eyes:['big','big'],mouth:'o',roofLift:.17,sy:1.03,fx:['pop']},
  wink:{n:'Wink',note:'One eye, a lean. Inside joke.',roll:6,armR:'out',eyes:['oval','wink'],gx:.2},
  sleepy:{n:'Sleepy',note:'Lids half down. Late-night lists.',sy:.96,armL:'low',armR:'low',eyes:['lid','lid'],gy:-.3,
    dyn:(r,T)=>{r.roll+=2*Math.sin(T*.9)}},
  focused:{n:'Focused',note:'Leans in, fist ready. On it.',pitch:6,armR:'fist',eyes:['angry','angry'],
    dyn:(r)=>{r.feet[0][2]+=.1;r.feet[3][2]-=.1}},
  skeptical:{n:'Skeptical',note:'Hand on chin, one brow up.',roll:-4,armR:'chin',eyes:['skepL','skepR'],gx:-.35},
  thinking:{n:'Thinking',note:'Looks up and off to the side.',roll:3,armR:'chin',eyes:['shine','shine'],gx:.55,gy:.65},
  confused:{n:'Confused',note:'Arms out, tilted. “Which list?”',roll:-8,armL:'out',armR:'out',eyes:['shineS','shine'],gy:.1,
    dyn:(r)=>{r.feet[0][0]-=.08;r.feet[3][0]+=.08}},
  dizzy:{n:'Dizzy',note:'Spiral eyes, wobbling in circles.',armL:'out',armR:'out',eyes:['spiral','spiral'],fx:['wiggle'],
    dyn:(r,T)=>{r.roll+=8*Math.sin(T*3.5);r.px+=.06*Math.cos(T*3.5);r.byaw+=7*Math.sin(T*3.5+1)}},
  asleep:{n:'Asleep',note:'Folds its legs. Zero notifications.',sy:.84,py:-.32,armL:'low',armR:'low',eyes:['closed','closed'],roofLift:-.03,fx:['zzz'],
    dyn:(r,T)=>{r.sy*=1+.025*Math.sin(T*1.4)}},
  /* voice input ("Say your list"): the eye on the ear side opens wide and the other narrows, he leans the ear in */
  listening:{n:'Listening',note:'Hand to the ear, one big eye. Go on, say it.',roll:-7,armR:'ear',eyes:['small','big'],gx:.3,gy:.1,fx:['listen']},
  glitch:{n:'Glitch',note:'Bolts for eyes. Something broke.',armR:'fist',eyes:['bolt','bolt'],
    dyn:(r,T)=>{const f=Math.floor(T*12);if(hash(f)>.62){r.px+=(hash(f+1)-.5)*.12;r.roll+=(hash(f+2)-.5)*9;r.glitch=1}}},
};

/* ---------- actions ---------- */
const armsTo=(r,side,pose,w)=>{const a=ARMS[pose],k=side<0?'armL':'armR';for(let i=0;i<6;i++)r[k][i]=lerp(r[k][i],a[i],clamp(w))};
const ACTIONS={
  hop:{n:'Hop',note:'Squash, jump, stretch, land.',dur:1.15,fn(p,r){
    if(p<.2){const q=ease(p/.2);r.sy*=1-.2*q;r.sx*=1+.12*q;r.sz*=1+.12*q;armsTo(r,-1,'out',q*.5);armsTo(r,1,'out',q*.5)}
    else if(p<.64){const q=(p-.2)/.44,y=(r.hopH||.95)*4*q*(1-q);r.py+=y;const st=q<.18?lerp(.8,1.12,ease(q/.18)):lerp(1.12,.88,ease((q-.18)/.82));r.sy*=st;r.sx/=Math.sqrt(st);r.sz/=Math.sqrt(st);
      for(const f of r.feet)f[1]+=y*.82;const o=.5*(1-smooth(0,.3,q)),u=Math.sin(q*Math.PI);armsTo(r,-1,'out',o);armsTo(r,1,'out',o);armsTo(r,-1,'up',u);armsTo(r,1,'up',u);if(y>.35)r.fx.push('speed')}
    else{const q=(p-.64)/.36,sq=1-.12*Math.exp(-q*5)*Math.cos(q*12)-.1*Math.sin(clamp(q/.25)*Math.PI);r.sy*=sq;r.sx/=Math.sqrt(sq);r.sz/=Math.sqrt(sq);if(q<.35)r.fx.push('dust')}}},
  spin:{n:'Spin',note:'Winds up, turns once, overshoots.',dur:1.8,fn(p,r){
    const a=p<.22?-28*ease(p/.22):-28+388*backOut((p-.22)/.78);r.spin+=a;
    const w=Math.sin(clamp(p*1.15)*Math.PI);armsTo(r,-1,'out',w);armsTo(r,1,'out',w);r.py+=.14*Math.sin(clamp((p-.22)/.4)*Math.PI)}},
  nod:{n:'Nod',note:'Tips forward twice. Yes, got it.',dur:1.2,fn(p,r){const s=Math.sin(p*TAU)**2;r.pitch+=14*s;r.py-=.03*s}},
  no:{n:'No',note:'Shakes side to side, settling.',dur:1.3,fn(p,r){r.byaw+=24*Math.sin(p*Math.PI*6)*(1-p)**1.2;r.eyes=['closed','closed']}},
  wave:{n:'Wave',note:'Right arm up, three waves.',dur:2,fn(p,r){const e=env(p,.18);armsTo(r,1,'up',e);const w=Math.sin(p*TAU*3)*e;r.armR[3]+=.16*w;r.armR[0]+=.06*w;r.roll-=3*w;if(e>.4){r.eyes=['happy','happy']}}},
  walk:{n:'Walk',note:'Diagonal legs step together.',dur:2.4,fn(p,r){const e=env(p,.08),ph=p*TAU*2,c=Math.cos(ph),s=Math.sin(ph);
    for(const i of[0,2]){r.feet[i][2]+=.14*c*e;r.feet[i][1]+=Math.max(0,s)*.11*e;r.knee[i]=.05*Math.max(0,s)*e}
    for(const i of[1,3]){r.feet[i][2]-=.14*c*e;r.feet[i][1]+=Math.max(0,-s)*.11*e;r.knee[i]=.05*Math.max(0,-s)*e}
    r.py+=.035*Math.abs(c)*e;r.roll+=3*s*e;r.armL[5]+=.18*c*e;r.armR[5]-=.18*c*e}},
  tip:{n:'Tip roof',note:'Lifts the roof like a hat.',dur:2.3,fn(p,r){const a=env(p,.25);armsTo(r,1,'roof',a);const b=env(clamp((p-.18)/.64),.3);
    r.roofLift+=.24*b;r.roofRoll+=14*b;r.pitch+=9*b;r.armR[4]+=.24*b;if(b>.45)r.eyes=['happy','happy']}},
  dance:{n:'Dance',note:'Sways while the arms take turns.',dur:2.4,fn(p,r){const e=env(p,.1),ph=p*TAU*2,s=Math.sin(ph);
    r.roll+=9*s*e;r.py+=.07*Math.abs(s)*e;armsTo(r,-1,'up',e*Math.max(0,s)*1.4);armsTo(r,1,'up',e*Math.max(0,-s)*1.4);
    r.feet[0][1]+=Math.max(0,s)*.09*e;r.feet[1][1]+=Math.max(0,s)*.05*e;r.feet[3][1]+=Math.max(0,-s)*.09*e;r.feet[2][1]+=Math.max(0,-s)*.05*e;
    if(e>.3)r.eyes=['happy','happy']}},
  look:{n:'Look around',note:'Eyes lead, the body follows.',dur:2.6,fn(p,r){
    const K=[[0,0],[.14,-1],[.4,-1],[.55,1],[.82,1],[.96,0]];r.gx=kf(p,K);r.byaw+=16*kf(clamp(p-.07),K)}},
  stretch:{n:'Stretch',note:'Reaches up tall, then relaxes.',dur:2.2,fn(p,r){const e=env(p,.32);armsTo(r,-1,'up',e);armsTo(r,1,'up',e);
    r.sy*=1+.15*e;r.sx*=1-.06*e;r.py+=.05*e;if(e>.6)r.eyes=['closed','closed']}},
};
const VIEWS={
  front:{n:'Front',note:'Head on. The logo view.',yaw:0,tilt:6},
  three:{n:'Three-quarter',note:'The default stage camera.',yaw:-28,tilt:14},
  side:{n:'Side',note:'Profile. Roof becomes a tent.',yaw:-90,tilt:6},
  back:{n:'Back',note:'No face, all roof and legs.',yaw:180,tilt:8},
  above:{n:'Above',note:'Looking down on the ridge.',yaw:-32,tilt:48},
  low:{n:'Low',note:'Hero shot, camera on the floor.',yaw:-24,tilt:-14},
  corner:{n:'Corner',note:'Square on the front-left edge.',yaw:45,tilt:24},
  mirror:{n:'Mirror',note:'Three-quarter from the right.',yaw:30,tilt:12},
};

function basePose(f){return{roll:f.roll||0,pitch:f.pitch||0,sy:f.sy||1,py:f.py||0,roofLift:f.roofLift||0,gx:f.gx||0,gy:f.gy||0,
  armL:[...ARMS[f.armL||'down']],armR:[...ARMS[f.armR||'down']]}}

export class Homer{
  constructor(cv,o={}){
    Object.assign(this,{cv,ctx:cv.getContext('2d'),yaw:-28,tilt:14,face:'neutral',zoom:1,loop:null,loopOff:0,gaze:[0,0],eye:1.4,detail:[44,4],dirty:true,seed:1,act:null,offY:0,
      line:'#121110',roof:'rgba(18,17,16,.06)',shadow:'rgba(30,28,20,.13)',paper:'#fff',accent:'#2F5D46',w:0,h:0,dpr:1},o);
    this.cam={yaw:this.yaw,tilt:this.tilt};this.cur=basePose(FACES[this.face]);
  }
  size(w,h,dpr=1){this.w=w;this.h=h;this.dpr=dpr;this.cv.width=Math.max(1,Math.round(w*dpr));this.cv.height=Math.max(1,Math.round(h*dpr));this.dirty=true}
  play(name){this.act={name,t0:T};this.dirty=true}
  update(dt){
    const k=dt?1-Math.exp(-dt*9):1, tgt=basePose(FACES[this.face]), c=this.cur;
    for(const key in tgt){if(Array.isArray(tgt[key]))for(let i=0;i<6;i++)c[key][i]=lerp(c[key][i],tgt[key][i],k);else c[key]=lerp(c[key],tgt[key],k)}
    const kc=dt?1-Math.exp(-dt*8):1;this.cam.yaw=lerp(this.cam.yaw,this.yaw,kc);this.cam.tilt=lerp(this.cam.tilt,this.tilt,kc);
    return Math.abs(this.cam.yaw-this.yaw)>.05||Math.abs(this.cam.tilt-this.tilt)>.05;
  }
  rig(){
    const f=FACES[this.face],c=this.cur;
    const r={sx:1,sy:c.sy,sz:1,roll:c.roll,pitch:c.pitch,byaw:0,spin:0,px:0,py:c.py,pz:0,roofLift:c.roofLift,roofRoll:0,
      gx:c.gx,gy:c.gy,armL:[...c.armL],armR:[...c.armR],feet:[[0,0,0],[0,0,0],[0,0,0],[0,0,0]],knee:[0,0,0,0],
      eyes:f.eyes||['oval','oval'],mouth:f.mouth||null,fx:[...(f.fx||[])],blink:1,glitch:0};
    r.sy*=1+.012*Math.sin(T*2.2+this.seed);
    const ph=(T+this.seed)%3.9;if(ph<.17)r.blink=1-Math.sin(ph/.17*Math.PI)*.92;
    f.dyn&&f.dyn(r,T);
    let a=null,p=0;
    if(this.loop){const A=ACTIONS[this.loop],cyc=A.dur+.7,t=(T+this.loopOff)%cyc;if(t<A.dur){a=A;p=t/A.dur}}
    else if(this.act){const A=ACTIONS[this.act.name];p=(T-this.act.t0)/A.dur;if(p>=1){this.act=null;this.onEnd&&this.onEnd()}else a=A}
    a&&a.fn(p,r,T);
    /* scenes: Homer stands left of centre, the scene directs him on its own clock */
    if(this.sc){this.st=(T-this.sc0)%this.sc.dur;r.px-=.55;r.hopH=.5;/* lower hop so the roof stays inside the scene frame */this.sc.direct&&this.sc.direct(r,this.st)}
    /* scene cards: Homer stands left of centre, the scene directs him on its own clock */
    r.gx=clamp(r.gx+this.gaze[0],-1.2,1.2);r.gy=clamp(r.gy+this.gaze[1],-1.2,1.2);
    if(this.gaze[0])r.byaw+=8*this.gaze[0];
    return r;
  }
  draw(dt){
    const moving=this.update(dt);
    if(!this.w)return;
    const ctx=this.ctx,r=this.rig(),{w,h}=this,s=Math.min(w/3.1,h/2.75)*this.zoom,cx=w/2,cy=h/2+this.offY*s;
    ctx.setTransform(this.dpr,0,0,this.dpr,0,0);ctx.clearRect(0,0,w,h);
    ctx.lineCap='round';ctx.lineJoin='round';
    const lw=Math.max(1.1,.042*s);
    /* camera */
    const cyw=Math.cos(this.cam.yaw*RAD),syw=Math.sin(this.cam.yaw*RAD),ct=Math.cos(this.cam.tilt*RAD),st=Math.sin(this.cam.tilt*RAD),cs=Math.cos(r.spin*RAD),ss=Math.sin(r.spin*RAD);
    const cam=(x,y,z)=>{const X=x*cs+z*ss,Z=-x*ss+z*cs,X2=X*cyw+Z*syw,Z2=-X*syw+Z*cyw,yy=y-PIVOT,Y3=yy*ct-Z2*st,Z3=yy*st+Z2*ct;return[cx+X2*s,cy-Y3*s,X2,Y3,Z3]};
    /* body transform (y measured from the body's bottom; pivot = bottom centre) */
    const cr=Math.cos(r.roll*RAD),sr=Math.sin(r.roll*RAD),cp=Math.cos(r.pitch*RAD),sp=Math.sin(r.pitch*RAD),cb=Math.cos(r.byaw*RAD),sb=Math.sin(r.byaw*RAD);
    const bw=(x,y,z)=>{x*=r.sx;y*=r.sy;z*=r.sz;const x1=x*cr-y*sr,y1=x*sr+y*cr,y2=y1*cp-z*sp,z2=y1*sp+z*cp,x3=x1*cb+z2*sb,z3=-x1*sb+z2*cb;return[x3+r.px,y2+r.py+LEG,z3+r.pz]};
    const P=q=>cam(...bw(q[0],q[1],q[2]));
    const C=P([0,H/2,0]);
    /* arms */
    const arm=side=>{const a=side<0?r.armL:r.armR,ex=W/2-.03;const S=P([side*ex,.6*H,0]),Cc=P([side*(W/2+a[0]),a[1],a[2]]),Hh=P([side*(W/2+a[3]),a[4],a[5]]);
      return{S,Cc,Hh,behind:S[4]<C[4]-.25&&Hh[4]<C[4]}};
    const arms=[arm(-1),arm(1)];
    const api=this.sc&&{ctx,s,lw,cam,r,arms,t:this.st,w,h,homer:this};
    /* shadow */
    const lift=Math.max(0,r.py);ctx.fillStyle=this.shadow;ctx.globalAlpha=clamp(1-lift*.7,.25,1);ctx.beginPath();
    for(let i=0;i<=24;i++){const t=i/24*TAU,q=cam(Math.cos(t)*(1.05-lift*.25)+r.px,0,Math.sin(t)*(.62-lift*.12)+r.pz);i?ctx.lineTo(q[0],q[1]):ctx.moveTo(q[0],q[1])}
    ctx.fill();ctx.globalAlpha=1;
    if(r.fx.includes('speed')){ctx.strokeStyle=this.line;ctx.lineWidth=lw*.7;const g=cam(r.px,0,r.pz);for(const dx of[-.09,0,.09]){ctx.beginPath();ctx.moveTo(g[0]+dx*s,g[1]-(.05+(dx?0:.06))*s);ctx.lineTo(g[0]+dx*s,g[1]-(dx?.2:.32)*s);ctx.stroke()}}
    api&&this.sc.back&&this.sc.back(api,this.st);
    /* legs */
    const HIPS=[[-.33*W,.2],[-.11*W,-.07],[.11*W,.07],[.33*W,-.2]];
    const legs=HIPS.map(([hx,hz],i)=>{const hw=bw(hx,.08,hz),f=r.feet[i],fw=[hx*1.06+f[0]+r.px,f[1],hz+f[2]+r.pz];
      const len=Math.hypot(hw[0]-fw[0],hw[1]-fw[1],hw[2]-fw[2]),comp=clamp(1-len/(LEG+.08),0,.85),out=Math.sign(hx)*(Math.abs(hx)>.3?1:.55);
      const mid=[(hw[0]+fw[0])/2+out*comp*.42,(hw[1]+fw[1])/2,(hw[2]+fw[2])/2+r.knee[i]*2.4];
      return{h:cam(...hw),m:cam(...mid),f:cam(...fw)}}).sort((a,b)=>a.f[4]-b.f[4]);
    ctx.strokeStyle=this.line;ctx.fillStyle=this.line;
    for(const l of legs){ctx.lineWidth=lw;ctx.beginPath();ctx.moveTo(l.h[0],l.h[1]);ctx.quadraticCurveTo(2*l.m[0]-(l.h[0]+l.f[0])/2,2*l.m[1]-(l.h[1]+l.f[1])/2,l.f[0],l.f[1]);ctx.stroke();
      ctx.beginPath();ctx.ellipse(l.f[0],l.f[1],.078*s,.044*s,0,0,TAU);ctx.fill()}
    const drawArm=a=>{ctx.strokeStyle=this.line;ctx.fillStyle=this.line;ctx.lineWidth=lw;ctx.beginPath();ctx.moveTo(a.S[0],a.S[1]);ctx.quadraticCurveTo(a.Cc[0],a.Cc[1],a.Hh[0],a.Hh[1]);ctx.stroke();ctx.beginPath();ctx.arc(a.Hh[0],a.Hh[1],.068*s,0,TAU);ctx.fill()};
    arms.filter(a=>a.behind).forEach(drawArm);
    /* body */
    const rings=mesh(...this.detail),V=rings.map(rg=>rg.map(P)),N=rings[0].length;
    const quad=(pts)=>{const a=pts[0],b=pts[1],c2=pts[2],d=pts[pts.length-1];
      let nx=(c2[3]-a[3])*(d[4]-b[4])-(c2[4]-a[4])*(d[3]-b[3]),ny=(c2[4]-a[4])*(d[2]-b[2])-(c2[2]-a[2])*(d[4]-b[4]),nz=(c2[2]-a[2])*(d[3]-b[3])-(c2[3]-a[3])*(d[2]-b[2]);
      let mx=0,my=0,mz=0;for(const q of pts){mx+=q[2];my+=q[3];mz+=q[4]}mx/=pts.length;my/=pts.length;mz/=pts.length;
      if(nx*(mx-C[2])+ny*(my-C[3])+nz*(mz-C[4])<0){nx=-nx;ny=-ny;nz=-nz}
      const m=Math.hypot(nx,ny,nz)||1;return[nx/m,ny/m,nz/m]};
    const fillPoly=(pts,col)=>{ctx.fillStyle=col;ctx.strokeStyle=col;ctx.lineWidth=.9;ctx.beginPath();pts.forEach((q,i)=>i?ctx.lineTo(q[0],q[1]):ctx.moveTo(q[0],q[1]));ctx.closePath();ctx.fill();ctx.stroke()};
    for(let i=0;i<V.length-1;i++)for(let j=0;j<N;j++){const j2=(j+1)%N,pts=[V[i][j],V[i][j2],V[i+1][j2],V[i+1][j]];const n=quad(pts);if(n[2]<=0)continue;fillPoly(pts,shade(n[0]*L[0]+n[1]*L[1]+n[2]*L[2]))}
    let frontVis=0;
    for(const idx of[0,V.length-1]){const pts=V[idx],n=quad([pts[0],pts[Math.floor(N/4)],pts[Math.floor(N/2)],pts[Math.floor(3*N/4)]]);if(n[2]<=0)continue;fillPoly(pts,shade(n[0]*L[0]+n[1]*L[1]+n[2]*L[2]));if(idx)frontVis=n[2]}
    /* silhouette: convex hull of every projected vertex (the body is convex) */
    const hp=[];for(const rg of V)for(const q of rg)hp.push(q);hp.sort((a,b)=>a[0]-b[0]||a[1]-b[1]);
    const cross=(o,a,b)=>(a[0]-o[0])*(b[1]-o[1])-(a[1]-o[1])*(b[0]-o[0]);const lo=[],up=[];
    for(const q of hp){while(lo.length>1&&cross(lo[lo.length-2],lo[lo.length-1],q)<=0)lo.pop();lo.push(q)}
    for(let i=hp.length-1;i>=0;i--){const q=hp[i];while(up.length>1&&cross(up[up.length-2],up[up.length-1],q)<=0)up.pop();up.push(q)}
    const hull=lo.slice(0,-1).concat(up.slice(0,-1));
    ctx.strokeStyle=this.line;ctx.lineWidth=lw;ctx.beginPath();hull.forEach((q,i)=>i?ctx.lineTo(q[0],q[1]):ctx.moveTo(q[0],q[1]));ctx.closePath();ctx.stroke();
    /* face */
    if(frontVis>.04){ctx.globalAlpha=clamp((frontVis-.04)*7);this.face2d(ctx,r,(u,v)=>P([u,H/2+v,D/2+.004]),s);ctx.globalAlpha=1}
    /* roof: wire gable floating above the body */
    const ry0=ROOF_Y+r.roofLift,ex=W/2+.06,rz=D*.44,rc=Math.cos(r.roofRoll*RAD),rs=Math.sin(r.roofRoll*RAD),py0=ry0+.2;
    const R=(x,y,z)=>{const yy=y-py0;return P([x*rc-yy*rs,x*rs+yy*rc+py0,z])};
    const FL=R(-ex,ry0,rz),FA=R(0,ry0+.44,rz),FR=R(ex,ry0,rz),BL=R(-ex,ry0,-rz),BA=R(0,ry0+.44,-rz),BR=R(ex,ry0,-rz);
    ctx.fillStyle=this.roof;for(const pl of[[FL,FA,BA,BL],[FA,FR,BR,BA]]){ctx.beginPath();pl.forEach((q,i)=>i?ctx.lineTo(q[0],q[1]):ctx.moveTo(q[0],q[1]));ctx.closePath();ctx.fill()}
    const seg=(pts,wd)=>{ctx.lineWidth=wd;ctx.beginPath();pts.forEach((q,i)=>i?ctx.lineTo(q[0],q[1]):ctx.moveTo(q[0],q[1]));ctx.stroke()};
    ctx.strokeStyle=this.line;const back=FA[4]>BA[4];
    seg(back?[BL,BA,BR]:[FL,FA,FR],lw*.75);seg([FL,BL],lw*.75);seg([FR,BR],lw*.75);seg([FA,BA],lw*.85);seg(back?[FL,FA,FR]:[BL,BA,BR],lw);
    arms.filter(a=>!a.behind).forEach(drawArm);
    /* fx */
    this.fx(ctx,r,s,lw,hull,FA[4]>BA[4]?FA:BA,legs,arms);
    if(api&&this.sc.front){api.apex=FA[4]>BA[4]?FA:BA;api.roofRoll=r.roofRoll;this.sc.front(api,this.st)}
    this.dirty=moving;
  }
  face2d(ctx,r,M,s){
    const ez=this.eye,rx=.052*ez,ry=.106*ez;
    const path=(pts,close)=>{ctx.beginPath();pts.forEach(([u,v],i)=>{const q=M(u,v);i?ctx.lineTo(q[0],q[1]):ctx.moveTo(q[0],q[1])});close&&ctx.closePath()};
    const ell=(cx,cy,ax,ay,clip,n=22)=>{const o=[];for(let i=0;i<n;i++){const t=i/n*TAU;let u=cx+ax*Math.cos(t),v=cy+ay*Math.sin(t);if(clip)v=Math.min(v,clip(u));o.push([u,v])}return o};
    ctx.fillStyle=EYE_INK;ctx.strokeStyle=EYE_INK;ctx.lineWidth=Math.max(1,.032*s);
    const fill=p=>{path(p,true);ctx.fill()},line=p=>{path(p,false);ctx.stroke()};
    const shine=(cx,cy,k)=>{ctx.fillStyle='#fff';fill(ell(cx+rx*.3*k,cy+ry*.42*k,rx*.36*k,ry*.2*k,null,12));ctx.fillStyle=EYE_INK};
    [-1,1].forEach((side,i)=>{
      const kind=r.eyes[i],cx=side*.27+r.gx*.09,cy=.08+r.gy*.07,b=r.blink;
      const oval=k=>{if(b<.22)line([[cx-rx*1.2*k,cy],[cx+rx*1.2*k,cy]]);else fill(ell(cx,cy,rx*k,ry*k*b))};
      switch(kind){
        case'big':oval(1.18);break;
        case'small':oval(.62);break;
        case'shine':oval(1.05);if(b>.5)shine(cx,cy,1.05);break;
        case'shineS':oval(.82);if(b>.5)shine(cx,cy+.02,.82);break;
        case'wink':line([[cx-rx*1.4,cy-ry*.12],[cx+rx*1.5,cy-ry*.02]]);break;
        case'happy':{const o=[];for(let t=0;t<=12;t++){const a=Math.PI-t/12*Math.PI;o.push([cx+rx*1.45*Math.cos(a),cy-ry*.3+ry*.62*Math.sin(a)])}line(o);break}
        case'lid':{const o=[[cx-rx*1.6,cy-ry*.1]];for(let t=0;t<=12;t++){const a=Math.PI+t/12*Math.PI;o.push([cx+rx*1.6*Math.cos(a),cy-ry*.1+ry*.5*Math.sin(a)])}fill(o);break}
        case'angry':{const cut=u=>cy+ry*.25+(u-cx)*1.25*side;fill(ell(cx,cy,rx*1.05,ry*1.02*b,cut));break}
        case'skepL':fill(ell(cx,cy,rx*1.1,ry*b,()=>cy+ry*.12));line([[cx-rx*1.5,cy+ry*.5],[cx+rx*1.4,cy+ry*.5]]);break;
        case'skepR':fill(ell(cx,cy,rx*1.1,ry*b,()=>cy+ry*.3));line([[cx-rx*1.4,cy+ry*.82],[cx+rx*1.5,cy+ry*1.12]]);break;
        case'spiral':{const o=[];for(let t=0;t<=40;t++){const k=t/40,a=k*2.6*TAU*side+T*7;o.push([cx+Math.cos(a)*ry*.95*k,cy+Math.sin(a)*ry*.95*k])}line(o);break}
        case'bolt':{const B=[[.35,1],[-.55,-.08],[.08,-.08],[-.3,-1],[.6,.16],[-.02,.16]];fill(B.map(([u,v])=>[cx+u*rx*1.5,cy+v*ry*1.05]));break}
        case'closed':line([[cx-rx*1.5,cy-ry*.15],[cx+rx*1.5,cy-ry*.15]]);break;
        default:oval(1);
      }
    });
    /* happy mouth: wide D, flat top, with a yellow tongue in the bottom (from the notepad illustration) */
    if(r.mouth==='smile'){const mx=r.gx*.05,o=[[mx-.15,-.06]];for(let t=0;t<=16;t++){const a=Math.PI+t/16*Math.PI;o.push([mx+.15*Math.cos(a),-.06+.16*Math.sin(a)])}
      fill(o);ctx.save();path(o,true);ctx.clip();ctx.fillStyle='#E9C62A';fill(ell(mx+.035,-.205,.11,.075,null,18));ctx.restore();ctx.fillStyle=EYE_INK}
    if(r.mouth==='o')fill(ell(r.gx*.05,-.2,.03,.042,null,14));
  }
  fx(ctx,r,s,lw,hull,apex,legs,arms){
    if(!r.fx.length)return;
    let minX=1e9,maxX=-1e9,minY=1e9,maxY=-1e9;for(const q of hull){minX=Math.min(minX,q[0]);maxX=Math.max(maxX,q[0]);minY=Math.min(minY,q[1]);maxY=Math.max(maxY,q[1])}
    const my=(minY+maxY)/2;ctx.strokeStyle=this.line;ctx.fillStyle=this.line;ctx.lineWidth=lw*.7;
    if(r.fx.includes('wiggle')){const a=.55+.45*Math.sin(T*9);ctx.globalAlpha=a;for(const side of[-1,1]){const x=side<0?minX-.3*s:maxX+.3*s;
      for(const [k,rr] of[[0,.12],[1,.2]]){ctx.beginPath();const o=side*k*.08*s;if(side>0)ctx.arc(x-.15*s+o,my,rr*s,-.5,.5);else ctx.arc(x+.15*s+o,my,rr*s,Math.PI-.5,Math.PI+.5);ctx.stroke()}}ctx.globalAlpha=1}
    if(r.fx.includes('pop')){for(const d of[-158,-128,-52,-22]){const a=d*RAD,j=.03*Math.sin(T*14+d);ctx.beginPath();ctx.moveTo(apex[0]+Math.cos(a)*(.42+j)*s,apex[1]+Math.sin(a)*(.3+j)*s+.1*s);ctx.lineTo(apex[0]+Math.cos(a)*(.56+j)*s,apex[1]+Math.sin(a)*(.42+j)*s+.1*s);ctx.stroke()}}
    if(r.fx.includes('zzz')){ctx.font=`600 ${Math.max(9,.2*s)}px ${getComputedStyle(document.body).fontFamily}`;for(let i=0;i<3;i++){const t=((T*.45+i/3)%1);ctx.globalAlpha=Math.sin(t*Math.PI);ctx.font=`600 ${Math.max(8,(.13+t*.14)*s)}px Inter,system-ui,sans-serif`;ctx.fillText('z',apex[0]+(.25+t*.45)*s,apex[1]-(t*.5-.05)*s)}ctx.globalAlpha=1}
    /* sound arriving at the cupped hand: three arcs opening away from the body, drifting inward */
    if(r.fx.includes('listen')){const h=arms[1].Hh;for(let i=0;i<3;i++){const k=(i+1-((T*.8)%1))/3;ctx.globalAlpha=Math.sin(k*Math.PI)*.9+.1;ctx.beginPath();ctx.arc(h[0],h[1],(.16+.22*k*1.6)*s,-.6,.6);ctx.stroke()}ctx.globalAlpha=1}
    if(r.fx.includes('dust')){const fs=legs.map(l=>l.f[0]),fy=Math.max(...legs.map(l=>l.f[1]));const L0=Math.min(...fs),R0=Math.max(...fs);
      for(const [x,dir] of[[L0-.16*s,-1],[R0+.16*s,1]]){for(const [dy,len] of[[-.08,.12],[.02,.1]]){ctx.beginPath();ctx.moveTo(x,fy+dy*s);ctx.lineTo(x+dir*len*s,fy+(dy-.04*dir*dir)*s);ctx.stroke()}}}
  }
}

/* ---------- shared clock: one rAF for every mascot on the page ---------- */
// Reduced motion: the clock stops and reactions only swap the face, no movement.
const reduced=matchMedia('(prefers-reduced-motion: reduce)');
const live=new Set();
let T=0,last=0,raf=0;
function frame(now){const dt=Math.min(.05,(now-last)/1000);last=now;if(!reduced.matches)T+=dt;
  for(const el of live)el._tick(reduced.matches?0:dt);raf=live.size?requestAnimationFrame(frame):0}
const wake=()=>{if(!raf&&live.size){last=performance.now();raf=requestAnimationFrame(frame)}};

/* ---------- ink: line colours for light and dark backgrounds ---------- */
export const INK={
  dark:{line:'#121110',roof:'rgba(18,17,16,.06)',shadow:'rgba(30,28,20,.13)',paper:'#fff',accent:'#2F5D46'},  // for light backgrounds (default)
  light:{line:'#F1EFE7',roof:'rgba(241,239,231,.07)',shadow:'rgba(0,0,0,.35)',paper:'#2B2A25',accent:'#86BE9C'}, // for dark backgrounds
};

/* ---------- scenes: filled in by scenes.js (kept separate so pages without scenes don't load the props) ---------- */
// SCENES[module][state] = {dur, title, body, direct(r,t), back(api,t), front(api,t)}; addressed as 'module-state'.
export const SCENES={};
const sceneOf=name=>{const i=name.lastIndexOf('-');return SCENES[name.slice(0,i)]?.[name.slice(i+1)]};
// camera the sheet's scene cards use: a little more front-on, pulled back and down to make room for props
const SCENE_CAM={yaw:-12,tilt:8,zoom:.86,offY:.12};
// renderer internals the scene props need
export const kit={TAU,RAD,EYE_INK,clamp,lerp,ease,env,backOut,kf,hash,ARMS,ACTIONS,armsTo};

/* ---------- still(): one frame on a fresh canvas, for exporting images ---------- */
// at = how far into the action (0..1). Pins the clock so the blink and wobble are the same every export.
// scene = 'shopping-empty' etc. (load scenes.js first), t = seconds into the scene. Scenes are 16:10.
// yaw/tilt override view; bg paints a solid background (avatar tiles), otherwise it is transparent.
export function still({face='neutral',view='three',yaw,tilt,action=null,at=.5,scene=null,t=2,size=512,width=size,height=size,ink='dark',gaze=[0,0],zoom,offY,bg=null}={}){
  const cv=document.createElement('canvas'),v=VIEWS[view],sc=scene&&sceneOf(scene);
  const h=new Homer(cv,{face,yaw:yaw??(sc?SCENE_CAM.yaw:v.yaw),tilt:tilt??(sc?SCENE_CAM.tilt:v.tilt),detail:[60,5],gaze,...INK[ink],zoom:zoom??(sc?SCENE_CAM.zoom:1),offY:offY??(sc?SCENE_CAM.offY:0)});
  h.size(width,height);const keep=T;T=sc?t:2;
  if(action)h.act={name:action,t0:T-at*ACTIONS[action].dur};
  if(sc){h.sc=sc;h.sc0=0}
  h.draw(0);T=keep;
  if(bg){const c=h.ctx;c.setTransform(1,0,0,1,0,0);c.globalCompositeOperation='destination-over';c.fillStyle=bg;c.fillRect(0,0,cv.width,cv.height)}
  return cv;
}

/* ---------- reactions: what Homer does when something happens on the site ---------- */
// face is held for `hold` seconds (default: the action's length plus a beat), then he goes back to
// the face set on the element. Persistent states (empty, loading) are attributes, not reactions.
export const REACTIONS={
  hello:    {face:'happy',action:'wave'},
  success:  {face:'happy',action:'hop'},
  celebrate:{face:'happy',action:'dance'},
  error:    {face:'glitch',action:'no'},
  notfound: {face:'confused',action:'look'},
  yes:      {action:'nod'},
  no:       {face:'skeptical',action:'no'},
  thinking: {face:'thinking',hold:2.5},
  surprise: {face:'surprised',hold:1.2},
  wink:     {face:'wink',hold:1.4},
  bye:      {action:'tip'},
};

/** Fire a reaction on every <homer-mascot> on the page. Same as dispatching a `homer` event on document. */
export const react=name=>document.dispatchEvent(new CustomEvent('homer',{detail:name}));

// Declarative triggers, no JS needed on the page: <button data-homer-click="success">, <a data-homer-hover="thinking">.
// Delegated once at module load, so they work for elements added later too.
addEventListener('click',e=>{const t=e.target.closest?.('[data-homer-click]');t&&react(t.dataset.homerClick)});
addEventListener('pointerover',e=>{const t=e.target.closest?.('[data-homer-hover]');if(t&&!t.contains(e.relatedTarget))react(t.dataset.homerHover)});

const FUN=['hop','spin','dance'];
const idleMs=el=>(+el.getAttribute('idle')||0)*1000;

class HomerMascot extends HTMLElement{
  static observedAttributes=['face','view','loop','yaw','tilt','scene'];
  constructor(){
    super();
    const root=this.attachShadow({mode:'open'});
    root.innerHTML='<style>:host{display:inline-block;width:160px;height:160px;-webkit-tap-highlight-color:transparent}canvas{display:block;width:100%;height:100%}</style><canvas part="canvas"></canvas>';
    this.canvas=root.querySelector('canvas');
    this.h=new Homer(this.canvas,{seed:Math.random()*10});
    this.visible=false;
    this._ro=new ResizeObserver(()=>{const r=this.canvas.getBoundingClientRect();this.h.size(r.width,r.height,Math.min(2,devicePixelRatio||1));this.draw()});
    this._io=new IntersectionObserver(([e])=>{
      this.visible=e.isIntersecting;this.h.dirty=true;
      // greet: wave once, the first time he scrolls into view
      if(this.visible&&this.hasAttribute('greet')&&!this._greeted){this._greeted=true;this.react('hello')}
    });
    this._onReact=e=>this.react(e.detail);
    this._onPointer=e=>{
      this._poke();
      if(!this.hasAttribute('follow'))return;
      // eyes (and a little of the body) follow the cursor, measured from Homer's own centre
      const r=this.getBoundingClientRect();
      this.h.gaze=[clamp((e.clientX-r.left-r.width/2)/(innerWidth*.35),-1,1),clamp(-(e.clientY-r.top-r.height*.45)/(innerHeight*.35),-1,1)];this.h.dirty=true;
    };
    this._poke=()=>{
      // any input wakes him from idle sleep, with a small start
      if(this._asleep){this._asleep=false;this.react('surprise')}
      clearTimeout(this._idleT);if(idleMs(this))this._idleT=setTimeout(()=>{this._asleep=true;this._hold('sleepy',0);this._idleT=setTimeout(()=>this._asleep&&this._hold('asleep',0),idleMs(this))},idleMs(this));
    };
    this.addEventListener('click',()=>{if(!this.hasAttribute('inert-click'))this.play(FUN[Math.floor(Math.random()*FUN.length)])});
    this.h.onEnd=()=>this.dispatchEvent(new CustomEvent('homer-end',{bubbles:true}));
  }
  connectedCallback(){
    this.setAttribute('role',this.getAttribute('role')||'img');
    if(!this.hasAttribute('aria-label'))this.setAttribute('aria-label','Homer, the FamilyWell mascot');
    this._ro.observe(this.canvas);this._io.observe(this);
    document.addEventListener('homer',this._onReact);
    addEventListener('pointermove',this._onPointer,{passive:true});addEventListener('keydown',this._poke);addEventListener('scroll',this._poke,{passive:true});
    this._ink=matchMedia('(prefers-color-scheme: dark)');this._inkFn=()=>this.readInk();this._ink.addEventListener('change',this._inkFn);
    this.readInk();this.sync();this._poke();live.add(this);wake();
  }
  disconnectedCallback(){
    this._ro.disconnect();this._io.disconnect();document.removeEventListener('homer',this._onReact);
    removeEventListener('pointermove',this._onPointer);removeEventListener('keydown',this._poke);removeEventListener('scroll',this._poke);
    this._ink.removeEventListener('change',this._inkFn);clearTimeout(this._idleT);clearTimeout(this._holdT);live.delete(this);
  }
  attributeChangedCallback(){this.sync()}
  /** Re-read the --homer-* colours (call after your site flips its own theme). */
  readInk(){const cs=getComputedStyle(this),g=(k,d)=>cs.getPropertyValue(k).trim()||d;
    Object.assign(this.h,{line:g('--homer-line',INK.dark.line),roof:g('--homer-roof',INK.dark.roof),shadow:g('--homer-shadow',INK.dark.shadow),paper:g('--homer-paper',INK.dark.paper),accent:g('--homer-accent',INK.dark.accent)});this.draw()}
  sync(){
    // scene="shopping-empty": plays that module scene on loop (needs scenes.js), with the scene camera
    const sc=this.hasAttribute('scene')&&sceneOf(this.getAttribute('scene'));
    if(sc!==this.h.sc){this.h.sc=sc||null;this.h.sc0=T;Object.assign(this.h,sc?SCENE_CAM:{zoom:1,offY:0})}
    const v=VIEWS[this.getAttribute('view')]||VIEWS.three,num=(k,d)=>this.hasAttribute(k)?+this.getAttribute(k):d;
    this.h.yaw=num('yaw',sc?SCENE_CAM.yaw:v.yaw);this.h.tilt=num('tilt',sc?SCENE_CAM.tilt:v.tilt);
    const loop=this.getAttribute('loop');this.h.loop=ACTIONS[loop]&&!reduced.matches?loop:null;
    if(!this._holdT)this.h.face=FACES[this.getAttribute('face')]?this.getAttribute('face'):'neutral';
    this.draw();
  }
  /** Play one action from ACTIONS (hop, spin, nod, no, wave, walk, tip, dance, look, stretch). */
  play(name){if(ACTIONS[name]&&!reduced.matches){this.h.play(name);wake()}}
  /** Run a named reaction from REACTIONS, or pass your own {face, action, hold}. */
  react(r){
    r=typeof r==='string'?REACTIONS[r]:r;if(!r)return;
    if(r.action)this.play(r.action);
    if(r.face)this._hold(r.face,r.hold??((r.action?ACTIONS[r.action].dur:1)+.4));
  }
  // hold a face for `sec` seconds, then return to the attribute face; sec=0 holds until the next input
  _hold(face,sec){clearTimeout(this._holdT);this._holdT=0;this.h.face=face;this.draw();
    if(sec)this._holdT=setTimeout(()=>{this._holdT=0;this.sync()},sec*1000)}
  draw(){this.h.dirty=true;wake()}
  _tick(dt){const h=this.h;if(this.visible&&(dt||h.dirty))h.draw(dt)}
}
if(!customElements.get('homer-mascot'))customElements.define('homer-mascot',HomerMascot);

export {FACES,ACTIONS,VIEWS};
