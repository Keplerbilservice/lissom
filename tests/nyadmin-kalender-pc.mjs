// PC-kalenderen i admin-ny, bit K4 (eieren godkjente 2. oktober 2026).
// 1280 px: uke med tidsakse er standard, segmentpille Dag · Uke · Måned, 30-minuttersrader med data-dato/data-akse/data-kol,
// overlappende brikker side om side, hele brikka åpner arket, samling 2 har data-låst, dag har én kolonne per kursholder,
// måned er som før, søk og typefilter virker. Skriver ingenting, sender ingenting.
// Samlet kalender (2. oktober 2026): mobil til og med 760 px (kalender-mobil.js), tidsakse fra 761 px, også på nettbrett (900 px).
// Dagsvisningen: korte kurs dekker ikke hverandre, kolonnene kobles på kursholderens id (to med samme navn),
// notater ligger som bånd over alle kolonnene, og «Ikke tildelt» står bare for kurs uten kursholder.
// Krysses bredden mens dataene lastes, tegnes riktig visning når de kommer.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {mkdirSync} from 'node:fs';
const {chromium}=createRequire(import.meta.url)('playwright');
const ADR=process.env.E2E_ADRESSE||'http://lokal.lissom.no:8140';
const BILDER=process.env.KALENDER_PC_BILDER||'';if(BILDER)mkdirSync(BILDER,{recursive:true});
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/kalender-pc-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const s=fixture('seed');
// Hele siden uten fullPage: fullPage i Chromium endrer bredden et øyeblikk, og da tegnes kalenderen på nytt (og et åpent ark lukkes).
// Her økes bare høyden, så bredden og visningen står.
const bilde=async(p,navn)=>{if(!BILDER)return;const v=p.viewportSize();const h=await p.evaluate(()=>document.documentElement.scrollHeight);if(h>v.height)await p.setViewportSize({width:v.width,height:h});await p.screenshot({path:`${BILDER}/${navn}.png`});if(h>v.height)await p.setViewportSize(v);};
try{
 // ── 1280 px ───────────────────────────────────────────────────────
 {
  const c=await browser.newContext({viewport:{width:1280,height:900}});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));let poster=0;p.on('request',r=>{if(r.method()==='POST')poster++;});
  await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  const pille=p.getByRole('group',{name:'Kalendervisning'});await pille.waitFor();
  assert.deepEqual(await pille.getByRole('button').allInnerTexts(),['Dag','Uke','Måned'],'segmentpille Dag · Uke · Måned');
  assert.equal(await pille.getByRole('button',{name:'Uke'}).getAttribute('aria-pressed'),'true','uke er standard');
  assert.equal(await p.getByRole('combobox',{name:'Kalendervisning'}).count(),0,'ingen nedtrekksliste for visning på PC');
  assert.equal(await p.getByRole('combobox',{name:'Hendelsestype'}).evaluate(x=>x.selectedIndex),0,'typefilteret står på alle');
  await p.getByLabel('Velg dato').fill(s.d);
  const uke=p.locator('.kp[data-visning="uke"]');await uke.locator(`.kp-celle[data-dato="${s.d}"]`).first().waitFor();
  assert.equal(await uke.locator('.kp-kolhode').count(),7,'sju dager');
  const celler=await uke.locator('.kp-celle').evaluateAll(cs=>cs.map(c=>[c.dataset.dato,c.dataset.akse,c.getAttribute('data-kol')]));
  assert.ok(celler.length>=7*20&&celler.length%7===0,'30-minuttersrader i alle sju kolonner');
  assert.ok(celler.every(([d,a,k])=>/^\d{4}-\d{2}-\d{2}$/.test(d)&&/^\d{2}:(00|30)$/.test(a)&&k===''),'cellene har data-dato, data-akse og data-kol');
  const dagens=celler.filter(x=>x[0]===s.d).map(x=>x[1]);
  assert.ok(dagens.includes('16:00')&&dagens.includes('16:30')&&dagens.includes('20:30'),'tidsaksen går fra en time før til en time etter');
  assert.ok(await uke.locator('.kp-akse span',{hasText:'17:00'}).count(),'klokkeslett på venstre side');
  const A=uke.locator('button.kp-brikke',{hasText:`${s.tag} Alfa`}),B=uke.locator('button.kp-brikke',{hasText:`${s.tag} Bravo`}),C=uke.locator('button.kp-brikke',{hasText:`${s.tag} Charlie`});
  const [a,b,cc]=[await A.boundingBox(),await B.boundingBox(),await C.boundingBox()];
  for(const [x,y,n] of [[a,b,'A/B'],[a,cc,'A/C'],[b,cc,'B/C']])assert.ok(x.x+x.width<=y.x+1||y.x+y.width<=x.x+1,`${n} ligger side om side, ikke oppå hverandre`);
  const rad=26;assert.ok(Math.abs((b.y-a.y)-2*rad)<=2,'B starter en time under A');assert.ok(Math.abs(a.height-6*rad)<=4,'A er tre timer høy');
  await bilde(p,'uke-1280');
  // Hele brikka åpner arket som finnes.
  await B.click({position:{x:5,y:Math.max(5,b.height-6)}});const ark=p.getByRole('dialog',{name:`${s.tag} Bravo`});await ark.waitFor();
  assert.ok(await ark.getByRole('button',{name:'Se deltakerne',exact:true}).count(),'arket er hendelsesarket');
  await bilde(p,'ark-1280');await ark.locator('.close').click();await ark.waitFor({state:'detached'});
  // Søk virker i ukevisningen.
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(`${s.tag} Bravo`);
  assert.equal(await uke.locator('button.kp-brikke',{hasText:s.tag}).count(),1,'søket viser bare B');
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);
  // Dag: én kolonne per kursholder.
  await pille.getByRole('button',{name:'Dag'}).click();
  assert.equal(await pille.getByRole('button',{name:'Dag'}).getAttribute('aria-pressed'),'true');
  const dag=p.locator('.kp[data-visning="dag"]');await dag.waitFor();
  const hoder=await dag.locator('.kp-kolhode').evaluateAll(h=>h.map(x=>[x.textContent,x.getAttribute('data-kol')]));
  const kol=n=>dag.locator('.kp-kol').nth(hoder.findIndex(h=>h[0]===n));
  assert.ok(hoder.some(h=>h[0]===`${s.tag} H2`&&h[1]===String(s.h2)),'standard kursholder har kolonne selv uten timer');
  assert.ok(hoder.some(h=>h[0]===`${s.tag} H1`&&h[1]===String(s.h1)),'kursholderen med timer har kolonne');
  assert.ok(hoder.some(h=>h[0]==='Ikke tildelt'&&h[1]==='0'),'det uten kursholder står i egen kolonne');
  assert.equal(await kol(`${s.tag} H1`).locator('button.kp-brikke',{hasText:s.tag}).count(),2,'A og B hos H1');
  assert.equal(await kol('Ikke tildelt').locator('button.kp-brikke',{hasText:`${s.tag} Charlie`}).count(),1,'C uten kursholder');
  assert.equal(await kol(`${s.tag} H2`).locator('button.kp-brikke').count(),0,'H2 har ingenting');
  const dc=await kol(`${s.tag} H1`).locator('.kp-celle').evaluateAll(cs=>cs.map(c=>c.getAttribute('data-kol')));
  assert.ok(dc.length&&dc.every(k=>k===String(s.h1)),'cellene i dagsvisningen har kursholderens id i data-kol');
  await bilde(p,'dag-1280');
  // Samling 2 er låst for flytting; dag 1 er ikke.
  await p.getByLabel('Velg dato').fill(s.d2);await p.locator(`.kp[data-visning="dag"] .kp-celle[data-dato="${s.d2}"]`).first().waitFor();
  const s2=p.locator('.kp button.kp-brikke',{hasText:`${s.tag} Delta`});
  assert.equal(await s2.count(),1);assert.equal(await s2.evaluate(b=>b.hasAttribute('data-låst')),true,'samling 2 har data-låst');
  assert.match(await s2.innerText(),/Samling 2 av 2/);
  await p.getByLabel('Velg dato').fill(s.d1);await p.locator(`.kp[data-visning="dag"] .kp-celle[data-dato="${s.d1}"]`).first().waitFor();
  assert.equal(await p.locator('.kp button.kp-brikke',{hasText:`${s.tag} Delta`}).evaluate(b=>b.hasAttribute('data-låst')),false,'dag 1 er ikke låst');
  // Dag med korte kurs, to kursholdere med samme navn og et notat.
  await p.getByLabel('Velg dato').fill(s.e);await p.locator(`.kp[data-visning="dag"] .kp-celle[data-dato="${s.e}"]`).first().waitFor();
  const dagE=p.locator('.kp[data-visning="dag"]');
  const [E,F]=[await dagE.locator('button.kp-brikke',{hasText:`${s.tag} Echo`}).boundingBox(),await dagE.locator('button.kp-brikke',{hasText:`${s.tag} Foxtrot`}).boundingBox()];
  assert.ok(E.height>=24&&F.height>=24,'korte kurs er minst 24 px høye');
  for(const n of ['Echo','Foxtrot']){const lav=await dagE.locator('button.kp-brikke',{hasText:`${s.tag} ${n}`}).evaluate(b=>{const [t1,t2]=[...b.children].map(c=>c.getBoundingClientRect());return{lav:b.classList.contains('kp-lav'),barn:b.children.length,enLinje:Math.abs(t1.top-t2.top)<2,ellipse:getComputedStyle(b.children[1]).textOverflow};});
   assert.deepEqual(lav,{lav:true,barn:2,enLinje:true,ellipse:'ellipsis'},`${n}: lav brikke viser bare tid og tittel på én linje med ellipse`);}
  assert.equal(await dagE.locator('button.kp-brikke.kp-lav',{hasText:`${s.tag} Golf`}).count(),0,'en time høy brikke er ikke lav');
  // Over midnatt: 22:00–01:00 vises til dagens slutt (to timer = fire rader), ikke én time.
  const H=dagE.locator('button.kp-brikke',{hasText:`${s.tag} Hotel`});const hb=await H.boundingBox();
  assert.ok(Math.abs(hb.height-(4*26-2))<=2,`kurs over midnatt er to timer høyt (fikk ${hb.height} px)`);
  const kolBunn=await H.evaluate(b=>{const k=b.parentElement.getBoundingClientRect(),r=b.getBoundingClientRect();return k.bottom-r.bottom;});assert.ok(kolBunn>=0&&kolBunn<=4,'kurset over midnatt slutter ved dagens slutt');
  assert.ok(E.x+E.width<=F.x+1||F.x+F.width<=E.x+1||E.y+E.height<=F.y+1||F.y+F.height<=E.y+1,'korte kurs som ligger tett dekker ikke hverandre');
  const hodeE=await dagE.locator('.kp-kolhode').evaluateAll(h=>h.map(x=>[x.textContent,x.getAttribute('data-kol')]));
  assert.deepEqual(hodeE.filter(h=>h[0]===`${s.tag} H1`).map(h=>h[1]).sort(),[String(s.h1),String(s.h3)].sort(),'to kursholdere med samme navn får hver sin kolonne (id)');
  assert.ok(!hodeE.some(h=>h[0]==='Ikke tildelt'),'ingen «Ikke tildelt» når bare notatet mangler kursholder');
  const kolId=id=>dagE.locator('.kp-kol').nth(hodeE.findIndex(h=>h[1]===String(id)));
  assert.equal(await kolId(s.h3).locator('button.kp-brikke',{hasText:`${s.tag} Golf`}).count(),1,'G står hos H3 (id), ikke hos H1');
  assert.equal(await kolId(s.h1).locator('button.kp-brikke',{hasText:`${s.tag} Golf`}).count(),0);
  assert.equal(await kolId(s.h1).locator('button.kp-brikke',{hasText:s.tag}).count(),2,'E og F hos H1');
  const notat=dagE.locator('.kp-baandlag button.kp-brikke',{hasText:`${s.tag} Notat`});assert.equal(await notat.count(),1,'notatet er et bånd');
  assert.equal(await dagE.locator('.kp-kol button.kp-brikke',{hasText:`${s.tag} Notat`}).count(),0,'notatet står ikke i en kolonne');
  const nb=await notat.boundingBox();const kb=await dagE.locator('.kp-kol').evaluateAll(k=>k.map(x=>{const r=x.getBoundingClientRect();return[r.left,r.right];}));
  assert.ok(nb.x<=kb[0][0]+4&&nb.x+nb.width>=kb[kb.length-1][1]-4,'båndet går over alle kolonnene');
  await bilde(p,'dag-notat-1280');
  await notat.click();const notatArk=p.getByRole('dialog',{name:`${s.tag} Notat`});await notatArk.waitFor();await notatArk.getByRole('button',{name:'Endre notat',exact:true}).waitFor();await notatArk.locator('.close').click();await notatArk.waitFor({state:'detached'});
  console.log('1280 px dag: korte kurs side om side, kolonner på id, notat som bånd over alle kolonnene');
  // Måned er som før.
  await pille.getByRole('button',{name:'Måned'}).click();await p.locator('.calendar').waitFor();
  assert.equal(await p.locator('.kp').count(),0,'måned tegnes av den gamle visningen');
  assert.ok(await p.locator('.calendar button.event',{hasText:`${s.tag} Alfa`}).count(),'brikkene står i måneden');
  await bilde(p,'maaned-1280');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen sideveis rulling');
  // Krysses bredden, tegnes kalenderen på nytt: til og med 760 px mobilkalenderen, fra 761 px tidsaksen.
  await p.setViewportSize({width:760,height:900});await p.locator('.kalm').waitFor();
  assert.equal(await p.locator('.kp-segment, .kp').count(),0,'760 px: mobilkalenderen');
  await p.setViewportSize({width:761,height:900});await p.locator('.kp-segment').waitFor();
  assert.equal(await p.locator('.kalm').count(),0,'761 px: PC-kalenderen');
  assert.equal(poster,0,'kalenderen skrev ingenting');assert.deepEqual(feil,[]);await c.close();
  console.log('1280 px: uke med tidsakse, segmentpille, side om side, ark, låst samling 2, dag per kursholder, måned som før');
 }
 // ── 900 px (nettbrett): tidsakse, ikke mobil ─────────────────────
 {
  const c=await browser.newContext({viewport:{width:900,height:1000},hasTouch:true});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));
  await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  const pille=p.getByRole('group',{name:'Kalendervisning'});await pille.waitFor();assert.equal(await p.locator('.kalm').count(),0,'900 px: ikke mobilkalenderen');
  await p.getByLabel('Velg dato').fill(s.d);const uke=p.locator('.kp[data-visning="uke"]');await uke.locator(`.kp-celle[data-dato="${s.d}"]`).first().waitFor();
  assert.equal(await uke.locator('.kp-kolhode').count(),7,'900 px: uke med tidsakse og sju dager');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'900 px: ingen sideveis rulling i uka');
  await bilde(p,'uke-900');
  await pille.getByRole('button',{name:'Dag'}).click();await p.locator('.kp[data-visning="dag"]').waitFor();
  assert.ok(await p.locator('.kp[data-visning="dag"] .kp-kolhode',{hasText:'Ikke tildelt'}).count(),'900 px: dag per kursholder');
  await bilde(p,'dag-900');
  await pille.getByRole('button',{name:'Måned'}).click();await p.locator('.calendar').waitFor();
  assert.equal(await p.locator('.calendar').evaluate(g=>getComputedStyle(g).gridTemplateColumns.split(' ').length),7,'900 px: måned har sju kolonner');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'900 px: ingen sideveis rulling i måneden');
  await bilde(p,'maaned-900');
  assert.deepEqual(feil,[]);await c.close();
  console.log('900 px: tidsakse i uke og dag, måned som før');
 }
 // ── Bredden krysses mens et ark står åpent: arket blir stående, kalenderen byttes når arket lukkes ──
 {
  const c=await browser.newContext({viewport:{width:1280,height:900}});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));
  await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByLabel('Velg dato').fill(s.d);
  await p.locator('.kp button.kp-brikke',{hasText:`${s.tag} Bravo`}).click();const ark=p.getByRole('dialog',{name:`${s.tag} Bravo`});await ark.waitFor();
  await p.setViewportSize({width:390,height:900});await p.waitForTimeout(800);
  assert.equal(await ark.isVisible(),true,'arket står åpent når bredden krysses');
  assert.equal(await p.locator('.kalm').count(),0,'kalenderen tegnes ikke på nytt mens arket er åpent');
  await ark.locator('.close').click();await ark.waitFor({state:'detached'});
  await p.locator('.kalm').waitFor();assert.equal(await p.locator('.kp').count(),0,'når arket lukkes, tegnes mobilkalenderen');
  assert.deepEqual(feil,[]);await c.close();
  console.log('1280 → 390 px med åpent ark: arket blir stående, kalenderen byttes når det lukkes');
 }
 // ── Bredden krysses mens dataene lastes ──────────────────────────
 for(const [fra,til,venter] of [[1280,390,'.kalm'],[390,1280,'.kp']]){
  const c=await browser.newContext({viewport:{width:fra,height:900}});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));
  let slipp;const holdt=new Promise(r=>slipp=r);let forste=true;
  await p.route('**/api/admin/kalender.php*',async rute=>{if(forste){forste=false;await holdt;}await rute.continue();});
  await p.goto(`${ADR}/admin-ny.html#kalender`);await p.waitForTimeout(800);
  await p.setViewportSize({width:til,height:900});await p.waitForTimeout(300);slipp();
  await p.locator(venter).first().waitFor();await p.waitForTimeout(500);
  assert.equal(await p.locator(venter==='.kp'?'.kalm':'.kp, .kp-segment').count(),0,`${fra}→${til} px under lasting: riktig visning når dataene kommer`);
  assert.deepEqual(feil,[]);await c.close();
  console.log(`${fra} → ${til} px mens dataene lastes: riktig visning tegnes`);
 }
 assert.equal(fixture('inspect',s).varsler,0,'ingen SMS eller e-post');
}finally{await browser.close();fixture('cleanup',s);}
