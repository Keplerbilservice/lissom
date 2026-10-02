// Mobilkalenderen i admin-ny (K3, eieren godkjente 2. oktober 2026, 390 px).
// «Går nå» og «Neste» øverst, Dag · Uke · Måned, ukestripe man sveiper mellom ukene,
// «I dag»-pille, timekort som åpner hendelsesarket, søk og type bak ett søkeikon.
// Kjøres på 390 px med ekte berøring (CDP-touch: sveip sidelengs mot loddrett rulling)
// og på 900 og 1280 px, der PC-kalenderen med tidsakse (K4) tegnes. Mobil gjelder til og med 760 px. Sender aldri SMS eller e-post.
// Samlet kalender (2. oktober 2026): «Går nå» regner kurs over midnatt riktig, «Går nå» og «Neste» oppdateres hvert minutt,
// og «Neste» letes etter høyst seks måneder fram. De tre sjekkes med egne kalenderdata (page.route) og styrt klokke.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {mkdirSync} from 'node:fs';
const {chromium}=createRequire(import.meta.url)('playwright');
const ADR=process.env.E2E_ADRESSE||'http://lokal.lissom.no:8140';
const BILDER=process.env.KALENDER_BILDER||'';
if(BILDER)mkdirSync(BILDER,{recursive:true});
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/samling-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const pluss=(d,n)=>{const t=new Date(d+'T12:00:00Z');t.setUTCDate(t.getUTCDate()+n);return t.toISOString().slice(0,10);};
const mandag=d=>{const t=new Date(d+'T12:00:00Z');return pluss(d,-((t.getUTCDay()+6)%7));};
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
let s;
try{
 s=fixture('seed');
 // Klokka står midt i dag 1 av todagerskurset (17:00–20:00 Oslo), så «Går nå» er testkurset.
 const naa=new Date(`${s.d1}T16:30:00Z`);

 // ── 390 px med berøring ───────────────────────────────────────────
 {
  const c=await browser.newContext({viewport:{width:390,height:640},hasTouch:true,isMobile:true,deviceScaleFactor:2});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));
  await p.clock.setFixedTime(naa);
  const cdp=await c.newCDPSession(p);
  // Fingeren stopper før den løftes (stopp=true): da blir det ikke noe kast, og neste trykk blir et trykk.
  // Et trykk midt i et kast stopper bare kastet, som på en ekte telefon.
  const beroer=async(x0,y0,x1,y1,stopp=true)=>{await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:x0,y:y0}]});for(let i=1;i<=12;i++){await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:x0+(x1-x0)*i/12,y:y0+(y1-y0)*i/12}]});await p.waitForTimeout(16);}if(stopp)for(let i=0;i<4;i++){await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:x1,y:y1}]});await p.waitForTimeout(40);}await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await p.waitForTimeout(350);};
  const midt=async l=>{const b=await l.boundingBox();return[b.x+b.width/2,b.y+b.height/2];};
  // Skjermbilde av hele siden uten at den faste mobilmenyen legger seg midt i bildet.
  const bilde=async navn=>{if(!BILDER)return;const y=await p.evaluate(()=>scrollY);const h=await p.evaluate(()=>document.documentElement.scrollHeight);await p.setViewportSize({width:390,height:h});await p.screenshot({path:`${BILDER}/${navn}.png`});await p.setViewportSize({width:390,height:640});await p.evaluate(y=>scrollTo({top:y,behavior:'instant'}),y);};
  const forsteDag=()=>p.locator('.kalm-uke .kalm-ukedag').first().getAttribute('aria-label');

  await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  await p.locator('.kalm').waitFor();
  assert.equal(await p.locator('.calendar').count(),0,'PC-rutenettet tegnes ikke på mobil');
  assert.equal(await p.getByLabel('Kalendervisning').locator('select').count()+await p.locator('select[aria-label="Kalendervisning"]').count(),0,'ingen nedtrekksliste for visning på mobil');

  // Går nå og Neste øverst, før styringen.
  const gaar=p.locator('.kalm-na.gaar');await gaar.waitFor();
  const gt=await gaar.innerText();assert.match(gt,/Går nå/i);assert.ok(gt.includes(`${s.tag} Dreiekurs`),'Går nå viser kurset');assert.match(gt,/1\/8 påmeldt/);assert.match(gt,/17:00/);
  const nt=await p.locator('.kalm-na.neste').innerText();assert.match(nt,/Neste/i);assert.ok(nt.includes(s.tag),'Neste viser neste kursdag');assert.match(nt,/17:00/);
  const rekke=await p.evaluate(()=>['.kalm-na.gaar','.kalm-na.neste','.kalm-styr','.kalm-uke'].map(q=>document.querySelector(q).getBoundingClientRect().top));
  assert.deepEqual([...rekke].sort((a,b)=>a-b),rekke,'rekkefølge: Går nå, Neste, styring, ukestripe');
  console.log('390 px: «Går nå» og «Neste» øverst med kurs, 1/8 påmeldt og tid');

  // Regler: 44 px, ingen piler, ingen «Åpne», ingenting forhåndsvalgt i filteret.
  const smaa=await p.locator('.kalm button, .kalm input').evaluateAll(l=>l.filter(n=>n.offsetParent).map(n=>{const r=n.getBoundingClientRect();return{t:n.textContent.trim()||n.getAttribute('aria-label'),h:r.height,w:r.width};}).filter(r=>r.h<44||r.w<44));
  assert.deepEqual(smaa,[],'alle trykkflater er minst 44 px');
  const tekst=await p.locator('.kalm').innerText();assert.ok(!/[←→‹›]/.test(tekst),'ingen piler');assert.equal(await p.locator('.kalm').getByRole('button',{name:/^Åpne/}).count(),0,'ingen Åpne-knapper');
  assert.deepEqual(await p.locator('.kalm-seg .kalm-pille').allInnerTexts(),['Dag','Uke','Måned']);
  console.log('390 px: trykkflater ≥ 44 px, ingen piler, ingen «Åpne», segment Dag · Uke · Måned');

  // Ukestripen: sju dager, i dag valgt og merket, prikk på dagen med kurs.
  const dager=p.locator('.kalm-uke .kalm-ukedag');assert.equal(await dager.count(),7);
  const idag=p.locator('.kalm-ukedag[aria-current="date"]');assert.equal(await idag.getAttribute('aria-pressed'),'true');
  assert.equal(await idag.locator('.kalm-prikk:not(.tom)').count(),1,'prikk på dagen med kurs');
  assert.ok((await forsteDag()).length>0);
  await bilde('390-dag');

  // Hele timekortet åpner arket som finnes fra før.
  const kort=p.locator('button.kalm-kort',{hasText:s.tag}).first();await kort.tap();
  const ark=p.getByRole('dialog',{name:`${s.tag} Dreiekurs`});await ark.waitFor();await ark.getByRole('button',{name:'Se deltakerne',exact:true}).waitFor();
  await bilde('390-ark');
  await ark.locator('.close').tap();await ark.waitFor({state:'detached'});
  console.log('390 px: hele timekortet åpner hendelsesarket');

  // Sveip sidelengs blar uka; loddrett sveip på stripa ruller siden og blar ikke.
  // Stripa midt på skjermen (mobilmenyen dekker bunnen): en sidelengs bevegelse med litt loddrett glipp skal ikke rulle siden.
  const midtPaa=()=>p.locator('.kalm-uke').evaluate(n=>n.scrollIntoView({block:'center',behavior:'instant'}));await midtPaa();const for1=await forsteDag();const y0=await p.evaluate(()=>scrollY);
  const [sx,sy]=await midt(p.locator('.kalm-uke'));
  await beroer(sx+120,sy,sx-120,sy-8);
  const etter1=await forsteDag();assert.notEqual(etter1,for1,'sveip mot venstre blar til neste uke');
  assert.equal(await p.evaluate(()=>scrollY),y0,'sidelengs sveip ruller ikke siden');
  const forventet=pluss(mandag(s.d1),7);assert.ok(await p.locator(`.kalm-uke .kalm-ukedag`).first().evaluate((n,d)=>n.getAttribute('aria-label').includes(String(Number(d.slice(8)))),forventet),'neste uke starter mandagen etter');
  await beroer(sx-120,sy,sx+120,sy-8);assert.equal(await p.evaluate(()=>scrollY),y0);assert.equal(await forsteDag(),for1,'sveip mot høyre blar tilbake');
  const [vx,vy]=await midt(p.locator('.kalm-uke'));
  await beroer(vx,vy+40,vx+6,vy-200,false);
  assert.equal(await forsteDag(),for1,'loddrett sveip blar ikke uka');
  assert.ok(await p.evaluate(()=>scrollY)>y0,'loddrett sveip ruller siden');
  // Vent til kastet (fling) har stoppet: et trykk midt i et kast stopper bare rullingen, som på en ekte telefon.
  for(let forrige=-1,naa;(naa=await p.evaluate(()=>scrollY))!==forrige;forrige=naa)await p.waitForTimeout(250);
  console.log('390 px: sveip sidelengs blar uka, loddrett sveip ruller siden');

  // «I dag» tilbake fra en annen uke.
  await midtPaa();const [ax,ay]=await midt(p.locator('.kalm-uke'));
  await beroer(ax+120,ay,ax-120,ay);await beroer(ax+120,ay,ax-120,ay);assert.notEqual(await forsteDag(),for1);
  await p.locator('.kalm-styr').getByRole('button',{name:'I dag',exact:true}).tap();
  await p.locator(`.kalm-uke .kalm-ukedag[aria-label="${for1}"]`).waitFor({timeout:4000});assert.equal(await forsteDag(),for1,'I dag går tilbake til denne uka');assert.equal(await p.locator('.kalm-ukedag[aria-current="date"]').getAttribute('aria-pressed'),'true');
  console.log('390 px: «I dag» går tilbake til i dag');

  // Uke: bare dager med noe står i lista.
  await p.locator('.kalm-seg').getByRole('button',{name:'Uke',exact:true}).tap();
  const medPrikk=await p.locator('.kalm-uke .kalm-prikk:not(.tom)').count();
  assert.equal(await p.locator('.kalm-innhold .kalm-dag').count(),medPrikk,'tomme dager vises ikke i ukesoversikten');
  assert.equal(await p.locator('.kalm-innhold').getByText('Ingen hendelser').count(),0);
  await bilde('390-uke');
  console.log(`390 px: Uke viser ${medPrikk} dag(er) med noe, ingen tomme`);

  // Måned: rute med alle dagene, sveip blar måned.
  await p.locator('.kalm-seg').getByRole('button',{name:'Måned',exact:true}).tap();
  const mnd0=await p.locator('.kalm-mnd').innerText();
  const antall=new Date(Number(s.d1.slice(0,4)),Number(s.d1.slice(5,7)),0).getDate();
  assert.equal(await p.locator('.kalm-mnd-rute .kalm-celle').count(),antall,'alle dagene i måneden');
  await bilde('390-maaned');
  await p.locator('.kalm-mnd-rute').evaluate(n=>n.scrollIntoView({block:'center',behavior:'instant'}));const [mx,my]=await midt(p.locator('.kalm-mnd-rute'));await beroer(mx+120,my,mx-120,my);
  assert.notEqual(await p.locator('.kalm-mnd').innerText(),mnd0,'sveip blar måned');
  await p.locator('.kalm-styr').getByRole('button',{name:'I dag',exact:true}).tap();assert.equal(await p.locator('.kalm-mnd').innerText(),mnd0);
  await p.locator('.kalm-seg').getByRole('button',{name:'Dag',exact:true}).tap();
  console.log('390 px: Måned viser hele måneden, sveip blar måned');

  // Søk og type bak ett søkeikon. Ingenting er valgt på forhånd.
  const ikon=p.getByRole('button',{name:'Søk i kalender',exact:true});
  assert.equal(await p.locator('.kalm-panel').isVisible(),false,'søket er skjult til ikonet trykkes');
  await ikon.tap();assert.equal(await ikon.getAttribute('aria-expanded'),'true');
  assert.equal(await p.getByRole('searchbox',{name:'Søk i kalender'}).inputValue(),'','søkefeltet er tomt');
  const typeknapper=p.locator('.kalm-typer .kalm-pille');assert.ok(await typeknapper.count()>0);
  assert.deepEqual([...new Set(await typeknapper.evaluateAll(l=>l.map(n=>n.getAttribute('aria-pressed'))))],['false'],'ingen type valgt på forhånd');
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill('finnesikke-'+s.tag);
  assert.equal(await p.locator('button.kalm-kort').count(),0,'søket filtrerer timekortene');assert.equal(await p.locator('.kalm-ukedag .kalm-prikk:not(.tom)').count(),0,'og prikkene');
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);await p.locator('button.kalm-kort',{hasText:s.tag}).first().waitFor();
  await typeknapper.filter({hasText:/^kurs$/}).tap();assert.equal(await typeknapper.filter({hasText:/^kurs$/}).getAttribute('aria-pressed'),'true');
  await p.locator('button.kalm-kort',{hasText:s.tag}).first().waitFor();
  await bilde('390-sok');
  await typeknapper.filter({hasText:/^kurs$/}).tap();await p.getByRole('searchbox',{name:'Søk i kalender'}).fill('');
  console.log('390 px: søk og typefilter bak søkeikonet, ingenting forhåndsvalgt');

  // Velg dato i søkepanelet (samme vei som samlingstesten bruker).
  await p.getByLabel('Velg dato').fill(s.d2);await p.locator('.kalm-ukedag[aria-pressed="true"]').waitFor();
  assert.ok((await p.locator('.kalm-ukedag[aria-pressed="true"]').getAttribute('aria-label')).includes(String(Number(s.d2.slice(8)))));
  await ikon.tap();assert.equal(await p.locator('.kalm-panel').isVisible(),false);
  // Nytt kalendernotat og Ny kursdato foreslår dagen som er valgt i mobilkalenderen (ikke PC-kalenderens dag). Ingenting lagres.
  await p.locator('.kalm-handlinger').getByRole('button',{name:'Nytt kalendernotat',exact:true}).tap();
  const notatArk=p.getByRole('dialog',{name:'Nytt kalendernotat'});await notatArk.waitFor();
  assert.equal(await notatArk.getByLabel('Dato',{exact:true}).inputValue(),s.d2,'Nytt kalendernotat foreslår valgt dag');
  await notatArk.locator('.close').tap();await notatArk.waitFor({state:'detached'});
  await p.locator('.kalm-handlinger').getByRole('button',{name:'Ny kursdato',exact:true}).tap();
  const datoArk=p.getByRole('dialog',{name:'Ny kursdato'});await datoArk.waitFor();
  assert.equal(await datoArk.getByLabel('Starter',{exact:true}).inputValue(),`${s.d2}T18:00`,'Ny kursdato foreslår valgt dag');
  assert.equal(await datoArk.getByLabel('Slutter',{exact:true}).inputValue(),`${s.d2}T20:00`);
  await datoArk.locator('.close').tap();await datoArk.waitFor({state:'detached'});
  console.log('390 px: Nytt kalendernotat og Ny kursdato foreslår dagen som er valgt i mobilkalenderen');

  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen sideveis rulling');
  assert.deepEqual(feil,[]);await c.close();
 }

 // ── 900 og 1280 px: PC-kalenderen med tidsakse (K4), og bytte ved 760/761 px ──
 for(const width of [900,1280]){
  const c=await browser.newContext({viewport:{width,height:900}});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));await p.clock.setFixedTime(naa);
  await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  await p.locator('.kp[data-visning="uke"]').waitFor();assert.equal(await p.locator('.kalm').count(),0,`mobilvisningen tegnes ikke på ${width} px`);
  for(const n of ['← Forrige','I dag','Neste →','Ny kursdato','Nytt kalendernotat','Abonner på kalenderen'])await p.getByRole('button',{name:n,exact:true}).waitFor();
  await p.getByRole('group',{name:'Kalendervisning'}).waitFor();await p.getByRole('searchbox',{name:'Søk i kalender'}).waitFor();
  assert.equal(await p.locator('.kp[data-visning="uke"] .kp-kolhode').count(),7,'uke med sju dager og tidsakse');
  if(BILDER)await p.screenshot({path:`${BILDER}/${width}-pc.png`});
  await p.getByRole('button',{name:'Abonner på kalenderen',exact:true}).click();await p.getByRole('dialog',{name:'Abonner på verkstedkalenderen'}).waitFor();await p.locator('dialog .close').click();
  console.log(`${width} px: PC-kalenderen med tidsakse (piler, segmentpille, abonner)`);
  await p.setViewportSize({width:760,height:800});await p.locator('.kalm').waitFor();assert.equal(await p.locator('.kp').count(),0,'760 px: mobil');
  await p.setViewportSize({width:761,height:800});await p.locator('.kp').waitFor();assert.equal(await p.locator('.kalm').count(),0,'761 px: tidsakse');
  await p.setViewportSize({width:390,height:800});await p.locator('.kalm').waitFor();
  await p.setViewportSize({width,height:900});await p.locator('.kp').waitFor();
  console.log('Skjermbredden krysser 760/761 px: visningen bytter av seg selv');
  assert.deepEqual(feil,[]);await c.close();
 }

 // ── «Går nå» og «Neste» med egne kalenderdata og styrt klokke (390 px) ──
 // Oslo er UTC+1 i november. «I dag» er tirsdag 10. november 2026.
 const hendelse=(id,dato,tid,slutt,tittel)=>({id,oktId:id,dato,tid,slutt,tittel:`${s.tag} ${tittel}`,type:'kurs',holder:'',kap:8,pameldt:2,deltakere:[],venteliste:[],avlyst:false});
 const medData=async(hendelser,tid,hver)=>{
  const c=await browser.newContext({viewport:{width:390,height:800},hasTouch:true,isMobile:true});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));const maaneder=[];
  await p.route('**/api/admin/kalender.php*',async rute=>{const u=new URL(rute.request().url());const fra=u.searchParams.get('fra'),til=u.searchParams.get('til');maaneder.push(fra);
   await rute.fulfill({contentType:'application/json',body:JSON.stringify({hendelser:hendelser.filter(e=>e.dato>=fra&&e.dato<=til),stengte:{},kursholdere:[]})});});
  await p.clock.install({time:new Date(tid)});
  await p.goto(`${ADR}/admin-ny.html#kalender`);await p.locator('.kalm').waitFor();
  try{await hver(p,maaneder);}finally{assert.deepEqual(feil,[]);await c.close();}
 };
 // Over midnatt: kurset startet i går 22:00 og slutter 01:00. Klokka er 00:30.
 await medData([hendelse(9001,'2026-11-09','22:00','01:00','Nattkurs')],'2026-11-09T23:30:00Z',async p=>{
  const g=p.locator('.kalm-na.gaar');await g.waitFor();assert.ok((await g.innerText()).includes(`${s.tag} Nattkurs`),'kurs over midnatt går nå');
  if(BILDER)await p.screenshot({path:`${BILDER}/390-over-midnatt.png`});
 });
 await medData([hendelse(9002,'2026-11-10','22:00','01:00','Kveldskurs')],'2026-11-10T22:30:00Z',async p=>{
  const g=p.locator('.kalm-na.gaar');await g.waitFor();assert.ok((await g.innerText()).includes(`${s.tag} Kveldskurs`),'kurs som slutter etter midnatt går nå 23:30 samme dag');
 });
 console.log('390 px: «Går nå» regner kurs over midnatt riktig (før og etter midnatt)');
 // Hvert minutt: 10:29 går kurset 10:00–10:30; to minutter senere er det slutt, og «Neste» står.
 await medData([hendelse(9003,'2026-11-10','10:00','10:30','Formiddag'),hendelse(9004,'2026-11-10','14:00','16:00','Ettermiddag')],'2026-11-10T09:29:00Z',async p=>{
  await p.locator('.kalm-na.gaar',{hasText:'Formiddag'}).waitFor();assert.ok((await p.locator('.kalm-na.neste').innerText()).includes('Ettermiddag'));
  await p.clock.runFor(125000);
  await p.locator('.kalm-na.gaar').waitFor({state:'detached',timeout:5000});
  assert.ok((await p.locator('.kalm-na.neste').innerText()).includes('Ettermiddag'),'«Neste» står etter oppdateringen');
  assert.equal(await p.locator('.kalm-na').count(),1);
  // Når kalenderen ikke vises, stopper timeren: ingen nye kall og ingen feil.
  await p.evaluate(()=>{location.hash='idag';});await p.locator('.kalm').waitFor({state:'detached'});
  await p.clock.runFor(180000);
 });
 console.log('390 px: «Går nå» og «Neste» oppdateres hvert minutt');
 // Neste fire måneder fram: hentes. Bare åtte måneder fram: ingen «Neste», og ingenting hentes lenger fram enn seks måneder.
 await medData([hendelse(9005,'2027-03-15','18:00','20:00','Vårkurs')],'2026-11-10T09:00:00Z',async p=>{
  const n=p.locator('.kalm-na.neste');await n.waitFor();assert.ok((await n.innerText()).includes(`${s.tag} Vårkurs`),'«Neste» fire måneder fram');
 });
 await medData([hendelse(9006,'2027-07-15','18:00','20:00','Sommerkurs')],'2026-11-10T09:00:00Z',async(p,maaneder)=>{
  await p.locator('.kalm-styr').waitFor();await p.waitForTimeout(500);
  assert.equal(await p.locator('.kalm-na.neste').count(),0,'ingen «Neste» lenger fram enn seks måneder');
  assert.ok(maaneder.length&&maaneder.every(f=>f<'2027-06-01'),`hentet ikke lenger fram enn mai 2027: ${maaneder.join(', ')}`);
  assert.ok(maaneder.includes('2027-05-01'),'letet til og med seks måneder fram');
 });
 console.log('390 px: «Neste» letes etter høyst seks måneder fram');
 assert.equal(fixture('inspect',s).varsler,0,'ingen SMS eller e-post');
 console.log('Ingen varsler lagt i køen.');
}finally{await browser.close();if(s)fixture('cleanup',s);}
