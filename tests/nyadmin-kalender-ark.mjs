// Kalenderen i admin-ny, bølge 1 (eieren godkjente skissen 3. oktober 2026), bak bryteren «Vis/kalenderark».
// På: fargekoder per type, typeknapper (brenning skjult fra start), merker på kortene (påmeldt/plasser, «1 ubetalt»,
// «+1 nye», merknad, initialer), svevekort på PC, Paint on Pots-tidene slått sammen med «Vis tidene», dagsoppsummering
// i kolonnehodet, og økt-arket med fanene Deltakere · Venteliste · Kursdagen · Rediger og ⋯ per deltaker.
// Av: kalenderen er som før (nedtrekk for type, gamle hendelsesarket).
// Kjøres på 390 px med berøring og på 1280 px. Alt som kan sende e-post eller SMS (bekreftelse, gi plass, beskjed,
// meld klar) fanges med page.route og når aldri serveren; det som bare lagrer (Møtte ikke, kursbevis, plasser,
// fullbooket, timer) kjøres ekte mot testbasen og sjekkes der. Til slutt: ingen varsler er lagt i kø.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const ADR=process.env.E2E_ADRESSE||'http://lokal.lissom.no:8140';
const fixture=(mode,s,...x)=>JSON.parse(execFileSync('php',['tests/nettleser/kalender-ark-fixture.php',mode,JSON.stringify(s||{}),...x],{encoding:'utf8'}));
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const s=fixture('seed');
const SENDER={'beskjed.php':null,'venteliste.php':null,'ferdigbrent.php':null,'pamelding.php':['bekreftelse','flytt','fjern','til-venteliste','legg-til']};
async function apne(c){
 await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
 const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));const fanget=[];
 await p.route('**/api/admin/*.php*',async r=>{const q=r.request();const fil=new URL(q.url()).pathname.split('/').pop();
  if(q.method()==='POST'&&fil in SENDER){const body=JSON.parse(q.postData()||'{}');if(SENDER[fil]===null||SENDER[fil].includes(body.handling)){fanget.push({fil,body});return r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,beskjed:'Testsvar.'})});}}
  return r.continue();});
 await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
 return {p,feil,fanget};
}
const ark=p=>p.locator('dialog.kal-ark[open]');
const ja=async(p,tittel,knapp)=>{const d=p.getByRole('dialog',{name:tittel,exact:true});await d.waitFor();await d.getByRole('button',{name:knapp,exact:true}).click();};
try{
 // ── 390 px med berøring ───────────────────────────────────────────
 {
  const c=await browser.newContext({viewport:{width:390,height:800},hasTouch:true,isMobile:true,deviceScaleFactor:2});
  const {p,feil,fanget}=await apne(c);
  await p.getByRole('button',{name:'Søk i kalender'}).tap();
  const typer=p.getByRole('group',{name:'Vis typer'});await typer.waitFor();
  assert.deepEqual(await typer.getByRole('button').allInnerTexts(),['Kurs','Event','Paint on Pots','Brenning','Verksted','Notat'],'390: typeknappene');
  assert.equal(await typer.getByRole('button',{name:'Brenning'}).getAttribute('aria-pressed'),'false','390: brenning er skjult fra start');
  // Søket holder testdataene for seg (seed.php kan ha andre kurs samme dag).
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);
  await p.getByLabel('Velg dato').fill(s.d);
  const alfa=p.locator('button.kalm-kort',{hasText:`${s.tag} Alfa`});await alfa.waitFor();
  assert.ok(await alfa.evaluate(b=>b.classList.contains('kal-t-kurs')),'390: kurset har kursfargen');
  assert.deepEqual(await alfa.locator('.kal-m').allInnerTexts(),['2/6','1 ubetalt','+1'],'390: merkene på kortet');
  assert.equal(await p.locator('.kalm-kort',{hasText:'ovn'}).count(),0,'390: brenningen er skjult');
  assert.match(await p.locator('.kalm-dag .kal-sum').first().innerText(),/^2 økter · 2 påmeldt$/,'390: dagsoppsummering');
  await alfa.tap();await ark(p).waitFor();
  assert.deepEqual(await ark(p).getByRole('tab').allInnerTexts(),['Deltakere (2)','Venteliste','Kursdagen','Rediger'],'390: fanene i arket');
  const bredde=await p.evaluate(()=>({side:document.documentElement.scrollWidth,ark:document.querySelector('dialog.kal-ark').getBoundingClientRect().width}));
  assert.ok(bredde.side<=390&&bredde.ark<=390,'390: arket og siden er innenfor skjermen');
  await ark(p).getByRole('button',{name:'Mer for Marte Sol'}).tap();
  const meny=ark(p).getByRole('menu');await meny.waitFor();
  const mb=await meny.boundingBox();assert.ok(mb.x>=0&&mb.x+mb.width<=390,'390: menyen er innenfor skjermen');
  await p.keyboard.press('Escape');await meny.waitFor({state:'detached'});assert.equal(await ark(p).count(),1,'Escape lukker bare menyen');
  await ark(p).locator('.close').tap();
  // Paint on Pots dagen etter: tidene på én linje, tiden uten booking vises ikke.
  await p.getByLabel('Velg dato').fill(s.d2);
  const pop=p.locator('.kalm-kort.kal-sammen');await pop.waitFor();
  assert.match(await pop.innerText(),/2 tider booket/,'390: Paint on Pots slått sammen');
  await pop.getByRole('button',{name:'Vis tidene'}).tap();
  assert.deepEqual(await pop.locator('.kal-tid').allInnerTexts(),['12:00 · 2 pers.','13:00 · 1 pers.'],'390: de bookede tidene');
  assert.deepEqual(feil,[],'390: ingen feil i siden');assert.deepEqual(fanget,[],'390: ingenting sendt');
  await c.close();
 }
 // ── 1280 px ───────────────────────────────────────────────────────
 {
  const c=await browser.newContext({viewport:{width:1280,height:900}});
  const {p,feil,fanget}=await apne(c);
  const typer=p.getByRole('group',{name:'Vis typer'});await typer.waitFor();
  assert.equal(await p.getByRole('combobox',{name:'Hendelsestype'}).count(),0,'typeknapper i stedet for nedtrekk');
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);
  await p.getByLabel('Velg dato').fill(s.d);
  const uke=p.locator('.kp[data-visning="uke"]');const A=uke.locator('.kp-brikke',{hasText:`${s.tag} Alfa`});await A.waitFor();
  assert.equal(await uke.locator('.kp-brikke',{hasText:'ovn'}).count(),0,'brenning skjult fra start');
  await typer.getByRole('button',{name:'Brenning'}).click();
  assert.equal(await uke.locator('.kp-brikke',{hasText:'ovn'}).count(),1,'trykk på Brenning viser den');
  assert.ok(await uke.locator('.kp-brikke',{hasText:'ovn'}).evaluate(b=>b.classList.contains('kal-t-brenning')),'brenningen er brun');
  await typer.getByRole('button',{name:'Brenning'}).click();
  assert.equal(await uke.locator('.kp-brikke',{hasText:'ovn'}).count(),0,'og skjuler den igjen');
  const farge=async l=>l.evaluate(b=>getComputedStyle(b).backgroundColor);
  assert.equal(await farge(A),'rgb(255, 207, 56)','kurs er gult');
  assert.ok(await uke.locator('.kp-brikke',{hasText:`${s.tag} Bravo`}).evaluate(b=>b.classList.contains('kal-t-event')),'event har eventfargen');
  assert.deepEqual(await A.locator('.kal-m').allInnerTexts(),['2/6','1 ubetalt','+1 nye','✎ merknad'],'merkene på brikka');
  assert.deepEqual(await A.locator('.kal-bilder span').allInnerTexts(),['I','M'],'deltakerinitialene');
  assert.equal(await uke.locator(`.kp-kolhode[data-dato="${s.d}"] .kal-sum`).innerText(),'2 økter · 2 påmeldt','dagsoppsummering i kolonnehodet');
  // Svevekort med deltakerne og betalingen.
  await A.hover();const sv=p.locator('.kal-sveve');await sv.waitFor({state:'visible'});
  assert.match(await sv.innerText(),/Ingrid Berg[\s\S]*Betalt[\s\S]*Marte Sol[\s\S]*Ubetalt/,'svevekortet viser deltakere og betaling');
  await p.mouse.move(5,5);await sv.waitFor({state:'hidden'});
  // Paint on Pots: slått sammen, ubookede skjult.
  await p.getByLabel('Velg dato').fill(s.d2);const pop=p.locator('.kp-brikke.kal-sammen');await pop.waitFor();
  assert.equal(await pop.count(),1,'én linje for Paint on Pots');assert.match(await pop.innerText(),/2 tider booket/);
  await pop.getByRole('button',{name:'Vis tidene'}).click();
  assert.deepEqual(await pop.locator('.kal-tid').allInnerTexts(),['12:00 · 2 pers.','13:00 · 1 pers.'],'tidene');
  assert.equal(await pop.getByRole('button',{name:'Skjul tidene'}).count(),1);
  await pop.locator('.kal-tid').first().click();await ark(p).waitFor();
  assert.match(await ark(p).innerText(),/Pop En/,'en tid åpner sitt eget ark');await ark(p).locator('.close').click();
  // Økt-arket.
  await p.getByLabel('Velg dato').fill(s.d);await A.waitFor();
  await A.click();await ark(p).waitFor();
  assert.equal(await ark(p).getByRole('tab',{name:'Deltakere (2)'}).getAttribute('aria-selected'),'true','Deltakere er valgt');
  assert.ok(await ark(p).getByRole('button',{name:'▶ Start kurset'}).count(),'Start kurset i arket');
  const rader=ark(p).locator('.kal-delt');
  assert.match(await rader.filter({hasText:'Ingrid Berg'}).innerText(),/Betalt/);
  assert.equal(await rader.filter({hasText:'Marte Sol'}).getByRole('button',{name:'Ta betalt'}).count(),1,'Ta betalt for den som ikke har betalt');
  assert.match(await rader.filter({hasText:'Marte Sol'}).innerText(),/✎ Allergisk mot latex/,'merknaden står i arket');
  await ark(p).getByRole('button',{name:'Mer for Marte Sol'}).click();
  assert.deepEqual(await ark(p).getByRole('menuitem').allInnerTexts(),['Bytt dato','Send bekreftelse på nytt','Møtte ikke','Sperr kursbevis','Sett på venteliste','Rediger påmelding (antall, rabatt)','Avbestill …'],'⋯ per deltaker');
  await ark(p).getByRole('menuitem',{name:'Send bekreftelse på nytt'}).click();await ja(p,'Send bekreftelse','Send bekreftelse');
  await ark(p).waitFor({state:'detached'});
  assert.deepEqual(fanget.pop(),{fil:'pamelding.php',body:{handling:'bekreftelse',id:s.b.marte}},'bekreftelsen går til pamelding.php (fanget)');
  // Møtte ikke og Sperr kursbevis lagres ekte.
  await A.click();await ark(p).waitFor();await ark(p).getByRole('button',{name:'Mer for Marte Sol'}).click();
  await ark(p).getByRole('menuitem',{name:'Møtte ikke'}).click();await ja(p,'Møtte ikke','Møtte ikke');await ark(p).waitFor({state:'detached'});
  await A.click();await ark(p).waitFor();await ark(p).getByRole('button',{name:'Mer for Ingrid Berg'}).click();
  await ark(p).getByRole('menuitem',{name:'Sperr kursbevis'}).click();await ja(p,'Sperr kursbevis','Sperr kursbevis');await ark(p).waitFor({state:'detached'});
  let db=fixture('inspect',s);
  assert.equal(db.status.marte,'ikke_mott','Møtte ikke er lagret');
  assert.equal(db.bevis.ingrid,1,'kursbeviset er sperret');
  // Venteliste: Gi plass (fanget).
  await A.click();await ark(p).waitFor();await ark(p).getByRole('tab',{name:'Venteliste'}).click();
  assert.match(await ark(p).locator('.kal-panel').innerText(),/Siri Dal/);
  await ark(p).getByRole('button',{name:'Gi plass'}).click();await ja(p,'Gi kursplass?','Gi plass');await ark(p).waitFor({state:'detached'});
  assert.deepEqual(fanget.pop(),{fil:'venteliste.php',body:{handling:'gi-plass',id:s.w,oktId:s.okt.A}},'Gi plass går til venteliste.php (fanget)');
  // Kursdagen: beskjed (bare e-post), meld klar (fanget), før timer (ekte), deltakerliste.
  await A.click();await ark(p).waitFor();await ark(p).getByRole('tab',{name:'Kursdagen'}).click();
  const kd=ark(p).locator('.kal-panel');
  assert.deepEqual(await kd.locator('.button').allInnerTexts(),['▶ Start kurset','Send beskjed til alle','Meld keramikken klar for henting',`Før 3 t på ${s.tag} H`,'Last ned deltakerliste'],'verktøyene på Kursdagen');
  assert.equal(await kd.getByRole('link',{name:'Last ned deltakerliste'}).getAttribute('href'),`/api/admin/deltakerliste.php?okt=${s.okt.A}`);
  await kd.getByRole('button',{name:'Send beskjed til alle'}).click();
  const bf=p.getByRole('dialog',{name:`Beskjed til alle på ${s.tag} Alfa`});await bf.waitFor();
  await bf.getByLabel('Melding').fill('Testbeskjed');await bf.getByRole('button',{name:'Send til 2'}).click();await ark(p).waitFor({state:'detached'});
  assert.deepEqual(fanget.pop(),{fil:'beskjed.php',body:{til:'okt',oktId:s.okt.A,tekst:'Testbeskjed',ogsaaSms:'nei'}},'beskjeden går som e-post, ikke SMS (fanget)');
  await A.click();await ark(p).waitFor();await ark(p).getByRole('tab',{name:'Kursdagen'}).click();
  await ark(p).getByRole('button',{name:'Meld keramikken klar for henting'}).click();await ja(p,'Meld keramikken klar','Send');await ark(p).waitFor({state:'detached'});
  assert.deepEqual(fanget.pop(),{fil:'ferdigbrent.php',body:{handling:'meld-alle',oktId:s.okt.A}},'meld klar går til ferdigbrent.php (fanget)');
  await A.click();await ark(p).waitFor();await ark(p).getByRole('tab',{name:'Kursdagen'}).click();
  await ark(p).getByRole('button',{name:`Før 3 t på ${s.tag} H`}).click();await ja(p,'Før arbeidstimer','Før timer');await ark(p).waitFor({state:'detached'});
  // Rediger: plasser og fullbooket lagres ekte.
  await A.click();await ark(p).waitFor();await ark(p).getByRole('tab',{name:'Rediger'}).click();
  await ark(p).getByLabel('Plasser').fill('7');await ark(p).getByRole('button',{name:'Lagre',exact:true}).click();await ark(p).waitFor({state:'detached'});
  await A.click();await ark(p).waitFor();assert.equal(await ark(p).getByRole('tab',{name:'Rediger'}).getAttribute('aria-selected'),'true','arket husker fanen');
  await ark(p).getByRole('button',{name:'Vis som fullbooket'}).click();await ja(p,'Endre bookingmuligheten?','Bekreft');await ark(p).waitFor({state:'detached'});
  db=fixture('inspect',s);
  assert.equal(db.kapasitet,7,'plassene er lagret');assert.equal(db.visFullt,1,'vises som fullbooket');
  assert.equal(db.timer.length,1,'timene er ført');assert.equal(Number(db.timer[0].timer),3);assert.equal(db.timer[0].dato,s.d);
  assert.equal(db.venter,'venter','Siri står fortsatt på ventelista (gi plass ble fanget)');
  assert.equal(db.varsler,0,'ingen varsler lagt i kø');
  assert.deepEqual(fanget,[],'alt som sender er sjekket');assert.deepEqual(feil,[],'1280: ingen feil i siden');
  // Bryteren av: kalenderen er som før.
  fixture('bryter',s,'nei');await p.reload();await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  await p.getByLabel('Velg dato').fill(s.d);await A.waitFor();
  assert.equal(await p.getByRole('group',{name:'Vis typer'}).count(),0,'av: ingen typeknapper');
  assert.equal(await p.getByRole('combobox',{name:'Hendelsestype'}).count(),1,'av: nedtrekket som før');
  assert.equal(await A.locator('.kal-m').count(),0,'av: ingen merker');
  await A.click();const gammel=p.getByRole('dialog',{name:`${s.tag} Alfa`});await gammel.waitFor();
  assert.equal(await gammel.getByRole('button',{name:'Se deltakerne',exact:true}).count(),1,'av: det gamle hendelsesarket');
  assert.equal(await p.locator('dialog.kal-ark').count(),0);
  await c.close();
 }
 console.log('✓ kalenderen, bølge 1: bryter, farger, typeknapper, merker, svevekort, Paint on Pots, dagsoppsummering og økt-arket (390 og 1280 px)');
}finally{
 fixture('cleanup',s);await browser.close();
}
