// PC-kalenderen i admin-ny, bit K4 (eieren godkjente 2. oktober 2026).
// 1280 px: uke med tidsakse er standard, segmentpille Dag · Uke · Måned, 30-minuttersrader med data-dato/data-akse/data-kol,
// overlappende brikker side om side, hele brikka åpner arket, samling 2 har data-låst, dag har én kolonne per kursholder,
// måned er som før, søk og typefilter virker. 390 px: mobil er uendret (select, gammel rute). Skriver ingenting, sender ingenting.
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
const bilde=async(p,navn)=>{if(BILDER)await p.screenshot({path:`${BILDER}/${navn}.png`,fullPage:true});};
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
  // Måned er som før.
  await pille.getByRole('button',{name:'Måned'}).click();await p.locator('.calendar').waitFor();
  assert.equal(await p.locator('.kp').count(),0,'måned tegnes av den gamle visningen');
  assert.ok(await p.locator('.calendar button.event',{hasText:`${s.tag} Alfa`}).count(),'brikkene står i måneden');
  await bilde(p,'maaned-1280');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen sideveis rulling');
  // Krysses bredden, tegnes kalenderen på nytt (mobil får den gamle).
  await p.setViewportSize({width:390,height:900});await p.getByRole('combobox',{name:'Kalendervisning'}).waitFor();
  assert.equal(await p.locator('.kp-segment').count(),0,'smal skjerm: ingen segmentpille');
  assert.equal(poster,0,'kalenderen skrev ingenting');assert.deepEqual(feil,[]);await c.close();
  console.log('1280 px: uke med tidsakse, segmentpille, side om side, ark, låst samling 2, dag per kursholder, måned som før');
 }
 // ── 390 px: mobil uendret ─────────────────────────────────────────
 {
  const c=await browser.newContext({viewport:{width:390,height:900},hasTouch:true,isMobile:true});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));
  await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  const valg=p.getByRole('combobox',{name:'Kalendervisning'});await valg.waitFor();
  assert.equal(await valg.inputValue(),'uke');assert.equal(await p.locator('.kp, .kp-segment').count(),0,'ingen PC-visning på mobil');
  await p.getByLabel('Velg dato').fill(s.d);await p.locator('.calendar button.event',{hasText:`${s.tag} Alfa`}).waitFor();
  await bilde(p,'mobil-390');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  assert.deepEqual(feil,[]);await c.close();
  console.log('390 px: mobilvisningen er uendret');
 }
 assert.equal(fixture('inspect',s).varsler,0,'ingen SMS eller e-post');
}finally{await browser.close();fixture('cleanup',s);}
