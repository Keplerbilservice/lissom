// «Start kurset» i tre steg (kalenderplanen, bølge 2), bak bryteren «Vis/kursstart3».
// Åpnes fra økt-arket: den gule knappen i arkhodet (390 px med berøring) og Kursdagen-fanen (1280 px).
// Steg 1: hilsen og deltakerne med betalt/ubetalt; «Vis QR-kode» (dobbeltklikk gir én QR-kode, vist stort), 400 fra Vipps
// gir tydelig feil og Kontant virker, kontant mens QR-koden venter stopper den. «Send Vipps-krav» finnes ikke (slått av
// 3. oktober 2026). Steg 2: punktene fra kursstart-kortet som avkrysning (bare i nettleseren). Steg 3: bare status for
// e-postene, ingen send-knapper; «Kurset er ferdig» lukker.
// 1280: QR-vinduet bytter fra «Venter på Vipps» til «Betalt» av seg selv. Bryteren av: kursstarten som før, og QR nektes.
// Alt går mot den falske Vippsen (tests/falsk-vipps.mjs); ingen ekte Vipps, e-post eller SMS. Til slutt: ingen varsler i kø.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const ADR=process.env.E2E_ADRESSE||'http://lokal.lissom.no:8140';
const fixture=(mode,s,...x)=>JSON.parse(execFileSync('php',['tests/nettleser/kursstart-fixture.php',mode,JSON.stringify(s||{}),...x],{encoding:'utf8'}));
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const s=fixture('seed');
async function apne(c){
 await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
 const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));
 // Røde linjer i konsollen fra veilederens endepunkt (brukertesten: 502 ved 400 fra Vipps).
 p.on('response',r=>{if(r.status()>=400&&r.url().includes('/api/admin/kursstart3.php'))feil.push('HTTP '+r.status()+' '+r.url());});
 await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
 return {p,feil};
}
const ark=p=>p.locator('dialog.kal-ark[open]');
const ks=p=>p.locator('dialog.ks[open]');
// .ks-del = kortet i steg 1 «Deltakerne», .kal-delt = raden i «Velkommen».
const rad=(p,navn)=>ks(p).locator('.kal-delt, .ks-del',{hasText:navn});
const se=()=>fixture('inspect',s);
const qrVindu=p=>p.locator('dialog.sheet[open]',{has:p.locator('.ks-qr')});
async function kontant(p,navn,trykk){
 await trykk(rad(p,navn).getByRole('button',{name:'Kontant',exact:true}));
 const d=p.getByRole('dialog',{name:'Registrer betalingen?',exact:true});await d.waitFor();
 await trykk(d.getByRole('button',{name:'Registrer',exact:true}));await d.waitFor({state:'detached'});
}
try{
 // ── 390 px med berøring: den gule knappen i arkhodet ──────────────
 {
  const c=await browser.newContext({viewport:{width:390,height:800},hasTouch:true,isMobile:true,deviceScaleFactor:2});
  const {p,feil}=await apne(c);
  const tap=l=>l.tap();
  await p.getByRole('button',{name:'Søk i kalender'}).tap();
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);
  await p.getByLabel('Velg dato').fill(s.d);
  const kort=p.locator('button.kalm-kort',{hasText:`${s.tag} Dreiekurs`});await kort.waitFor();await kort.tap();
  await ark(p).waitFor();
  const gul=ark(p).locator('.kal-ark-hode').getByRole('button',{name:'▶ Start kurset'});
  assert.equal(await gul.evaluate(b=>getComputedStyle(b).backgroundColor),'rgb(255, 207, 56)','390: Start kurset er gul i arkhodet');
  await gul.tap();await ks(p).waitFor();
  assert.equal(await ark(p).count(),0,'390: økt-arket lukkes');
  const steg=ks(p).locator('.ks-steg button');
  assert.deepEqual((await steg.allInnerTexts()).map(t=>t.replace(/\s+/g,' ').trim()),['1 Deltakerne','2 Velkommen','3 Praktisk','4 Etter kurset'],'390: fire steg');
  assert.equal(await steg.first().getAttribute('aria-current'),'step','390: steg 1 er valgt');
  // ── Steg 1 «Deltakerne» (eieren, 7. oktober 2026) ──
  const sumTekst=async()=>(await ks(p).locator('.ks-sum').innerText()).replace(/\s+/g,' ');
  assert.match(await sumTekst(),/5 påmeldte 5 møtt 1 mangler e-post/,'390: sammendraget');
  assert.equal(await ks(p).getByRole('button',{name:'+ Legg til deltaker'}).count(),1,'390: «+ Legg til deltaker» i steg 1');
  assert.match(await rad(p,'Nils Utennummer').getAttribute('class'),/mangel/,'390: Nils er markert');
  assert.match(await rad(p,'Nils Utennummer').innerText(),/Trengs for kursbevis/,'390: «Trengs for kursbevis»');
  assert.equal(await rad(p,'Ingrid Berg').getByText('Trengs for kursbevis').isVisible(),false,'390: Ingrid har e-post');
  assert.match(await rad(p,'Ingrid Berg').innerText(),/1 plass[\s\S]*Betalt/,'390: plasser og Betalt');
  assert.equal(await rad(p,'Ingrid Berg').getByRole('button').count(),0,'390: ingen betalingsknapper hos den som har betalt (steg 1)');
  assert.match(await rad(p,'Marte Sol').innerText(),/Ikke betalt/,'390: Ikke betalt');
  assert.equal(await rad(p,'Marte Sol').getByRole('button',{name:'Vis QR-kode'}).count(),1,'390: QR i steg 1');
  assert.equal(await rad(p,'Marte Sol').getByRole('button',{name:'Kontant',exact:true}).count(),1,'390: Kontant i steg 1');
  assert.ok(await rad(p,'Marte Sol').getByRole('checkbox',{name:'Møtt'}).isChecked(),'390: møtt er standard');
  {const b=await p.evaluate(()=>({side:document.documentElement.scrollWidth}));assert.ok(b.side<=390,'390: steg 1 er innenfor skjermen');}
  {const h=await ks(p).locator('.ks-del input:not([type=checkbox]), .ks-del button, .ks-mott, .ks-leggtil').evaluateAll(b=>b.map(x=>x.getBoundingClientRect().height));
   assert.ok(h.every(x=>x>=44),'390: felt og knapper i steg 1 er minst 44 px '+JSON.stringify(h));}
  // Etter kurset: hvem som får kursbevis, og hvem som mangler e-post; «Legg inn e-post» går til steg 1.
  await steg.nth(3).tap();
  assert.match(await ks(p).locator('.ks-bevis').innerText(),/Kursbevis sendes til 1 av 5/,'390: kursbevis 1 av 5');
  assert.match(await ks(p).locator('.ks-mangler').innerText(),/Mangler e-post[\s\S]*Nils Utennummer/,'390: Nils mangler e-post');
  await ks(p).getByRole('button',{name:'Legg inn e-post'}).tap();
  assert.equal(await steg.first().getAttribute('aria-current'),'step','390: tilbake i steg 1');
  const nilsEpost=rad(p,'Nils Utennummer').getByLabel('E-post');
  assert.equal(await nilsEpost.evaluate(x=>x===document.activeElement),true,'390: e-postfeltet til Nils har fokus');
  // Ugyldig e-post: feilmelding, ingenting lagret. Gyldig: «Lagret.», og kortet er ikke lenger markert.
  await nilsEpost.fill('nils@');await nilsEpost.blur();
  await rad(p,'Nils Utennummer').locator('.ks-lagret.feil').waitFor();
  assert.match(await rad(p,'Nils Utennummer').locator('.ks-lagret').innerText(),/gyldig e-postadresse/,'390: feilmelding ved ugyldig e-post');
  assert.equal(se().b.nils.epost,'','390: ugyldig e-post er ikke lagret');
  await nilsEpost.fill(`nils.${s.tag}@e2e.lissom.test`);await nilsEpost.blur();
  await rad(p,'Nils Utennummer').locator('.ks-lagret.ok').waitFor();
  assert.equal(await rad(p,'Nils Utennummer').locator('.ks-lagret').innerText(),'Lagret.','390: «Lagret.»');
  assert.equal(se().b.nils.epost,`nils.${s.tag}@e2e.lissom.test`,'390: e-posten er lagret på påmeldingen');
  assert.doesNotMatch(await rad(p,'Nils Utennummer').getAttribute('class'),/mangel/,'390: Nils er ikke lenger markert');
  assert.match(await sumTekst(),/Alle har e-post/,'390: alle har e-post');
  const nilsTlf=rad(p,'Nils Utennummer').getByLabel('Mobil');
  await nilsTlf.fill('91000003');await nilsTlf.blur();
  {let t='';for(let n=0;n<30&&t!=='91000003';n++){await p.waitForTimeout(100);t=se().b.nils.tlf;}
   assert.equal(t,'91000003','390: mobilen er lagret');}
  // «Møtt» av og på: status ikke_mott og tilbake til det den var.
  await rad(p,'Ingrid Berg').getByRole('checkbox',{name:'Møtt'}).tap();
  await p.waitForFunction(()=>/4 møtt/.test(document.querySelector('dialog.ks .ks-sum')?.innerText||''));
  assert.equal(se().b.ingrid.status,'ikke_mott','390: Ingrid er ikke møtt');
  await steg.nth(3).tap();
  assert.match(await ks(p).locator('.ks-bevis').innerText(),/Kursbevis sendes til 0 av 4/,'390: ikke møtt får ikke kursbevis');
  await steg.first().tap();
  await rad(p,'Ingrid Berg').getByRole('checkbox',{name:'Møtt'}).tap();
  await p.waitForFunction(()=>/5 møtt/.test(document.querySelector('dialog.ks .ks-sum')?.innerText||''));
  assert.equal(se().b.ingrid.status,'betalt','390: Ingrid er betalt igjen');
  await rad(p,'Marte Sol').getByRole('checkbox',{name:'Møtt'}).tap();
  await p.waitForFunction(()=>/4 møtt/.test(document.querySelector('dialog.ks .ks-sum')?.innerText||''));
  await rad(p,'Marte Sol').getByRole('checkbox',{name:'Møtt'}).tap();
  await p.waitForFunction(()=>/5 møtt/.test(document.querySelector('dialog.ks .ks-sum')?.innerText||''));
  assert.equal(se().b.marte.status,'reservert','390: Marte er reservert igjen');
  // Videre til «Velkommen», som før.
  await steg.nth(1).tap();
  assert.equal(await steg.nth(1).getAttribute('aria-current'),'step','390: Velkommen');
  assert.match(await ks(p).locator('.ks-si').first().innerText(),new RegExp(`Hei og velkommen til ${s.tag} Dreiekurs`),'390: hilsenen');
  assert.match(await ks(p).locator('.ks-topp').innerText(),/5 påmeldt[\s\S]*4 har ikke betalt/,'390: påmeldt og ubetalt');
  assert.match(await rad(p,'Ingrid Berg').innerText(),/Betalt/,'390: Ingrid er betalt');
  assert.equal(await rad(p,'Ingrid Berg').getByRole('button').count(),0,'390: ingen betalingsknapper hos den som har betalt');
  // «Send Vipps-krav» er slått av: ingen slik knapp. QR-koden trenger ikke mobilnummer, så Nils har den også.
  assert.equal(await ks(p).getByRole('button',{name:'Send Vipps-krav'}).count(),0,'390: ingen «Send Vipps-krav»');
  assert.equal(await rad(p,'Nils Utennummer').getByRole('button',{name:'Vis QR-kode'}).count(),1,'390: uten mobil: «Vis QR-kode»');
  for(const navn of ['Nils Utennummer','Marte Sol']){const nb=await rad(p,navn).locator('.kal-info b').boundingBox();
   for(const k of await rad(p,navn).locator('.ks-betal > *').all()){const kb=await k.boundingBox();
    const over=!(kb.x>=nb.x+nb.width||kb.x+kb.width<=nb.x||kb.y>=nb.y+nb.height||kb.y+kb.height<=nb.y);
    assert.ok(!over,`390: ingenting ligger over navnet til ${navn}`);}}
  // Målt etter at vinduet har glidd inn (animasjonen «enter»), med et par forsøk mens skriftene lastes.
  await ks(p).evaluate(d=>Promise.all(d.getAnimations({subtree:true}).map(x=>x.finished)));
  let hoyder=[];for(let n=0;n<10;n++){hoyder=await ks(p).locator('.kal-delt button, .ks-fot button, .ks-steg button').evaluateAll(b=>b.map(x=>x.getBoundingClientRect().height));if(hoyder.every(h=>h>=44))break;await p.waitForTimeout(100);}
  assert.ok(hoyder.every(h=>h>=44),'390: alt som kan trykkes er minst 44 px '+JSON.stringify(hoyder));
  const bredde=await p.evaluate(()=>({side:document.documentElement.scrollWidth,ark:document.querySelector('dialog.ks').getBoundingClientRect().width}));
  assert.ok(bredde.side<=390&&bredde.ark<=390,'390: veilederen og siden er innenfor skjermen');
  // «Vis QR-kode» med dobbeltklikk: én QR-kode, vist stort, med beløpet og «Venter på Vipps».
  await rad(p,'Marte Sol').getByRole('button',{name:'Vis QR-kode'}).dblclick();
  const qv=qrVindu(p);await qv.waitFor();
  assert.match(await qv.locator('img').getAttribute('src'),/^data:image\/svg\+xml;base64,/,'390: QR-bildet vises');
  const qb=await qv.locator('img').boundingBox();assert.ok(qb.width>=300,'390: QR-koden er stor ('+qb.width+')');
  assert.match(await qv.innerText(),/500[\s\S]*Venter på Vipps/,'390: beløpet og «Venter på Vipps»');
  let i=se();
  assert.deepEqual(i.b.marte.krav,[{status:'venter',belop:50000}],'390: dobbeltklikk gir én QR-betaling på 500 kr');
  assert.equal(i.b.marte.status,'reservert','390: Marte er ikke betalt ennå');
  await qv.locator('.close').tap();await qv.waitFor({state:'detached'});
  await rad(p,'Marte Sol').getByText('Venter på Vipps').waitFor();
  // 400 fra Vipps: tydelig feil, og Kontant virker.
  fixture('vipps',s,'CREATED','ja');
  await rad(p,'Olga Feil').getByRole('button',{name:'Vis QR-kode'}).tap();
  const feilBoks=ks(p).locator('.ks-feil');await feilBoks.waitFor({state:'visible'});
  assert.match(await feilBoks.innerText(),/Fikk ikke laget QR-koden[\s\S]*kontant/,'390: tydelig feilmelding ved 400');
  assert.doesNotMatch(await feilBoks.innerText(),/Falsk|Bad Request/,'390: bare den norske teksten');
  assert.equal(await qrVindu(p).count(),0,'390: ingen QR-kode ved 400');
  assert.equal(se().b.olga.krav.length,0,'390: ingen betaling lagret etter 400');
  // Dobbelttrykk på «Registrer»: én betaling, og ingen dialog blir stående.
  await rad(p,'Olga Feil').getByRole('button',{name:'Kontant',exact:true}).tap();
  const reg=p.getByRole('dialog',{name:'Registrer betalingen?',exact:true});await reg.waitFor();
  const rb=await reg.getByRole('button',{name:'Registrer',exact:true}).boundingBox();
  // Det andre trykket havnet på «Kontant» i en annen rad mens det første ble lagret (brukertesten). Lagringen holdes
  // igjen litt, så trykket garantert kommer mens den pågår.
  await p.route('**/api/admin/kursbetaling.php',async r=>{await new Promise(x=>setTimeout(x,800));await r.continue();});
  await p.touchscreen.tap(rb.x+rb.width/2,rb.y+rb.height/2);
  await rad(p,'Marte Sol').getByRole('button',{name:'Kontant',exact:true}).tap();
  await p.waitForTimeout(300);
  assert.equal(await p.locator('dialog[open]',{hasText:'Registrer bare penger'}).count(),0,'390: et ekstra trykk mens betalingen lagres åpner ingen ny dialog');
  await rad(p,'Olga Feil').getByText('Betalt',{exact:true}).waitFor();
  await p.unroute('**/api/admin/kursbetaling.php');
  await p.waitForTimeout(500);
  assert.equal(await p.locator('dialog[open]',{hasText:'Registrer bare penger'}).count(),0,'390: dobbelttrykk: ingen dialog blir stående');
  i=se();assert.equal(i.b.olga.status,'betalt','390: Olga betalt kontant');assert.equal(i.b.olga.sum,50000);assert.equal(i.b.olga.kontant,1,'390: dobbelttrykk gir én betaling');
  fixture('vipps',s,'CREATED','nei');
  // Kontant mens QR-koden venter: den stoppes, ingen dobbel betaling.
  await kontant(p,'Marte Sol',tap);
  await rad(p,'Marte Sol').getByText('Betalt',{exact:true}).waitFor();
  i=se();
  assert.deepEqual(i.b.marte.krav,[{status:'avbrutt',belop:50000}],'390: QR-betalingen er stoppet');
  assert.equal(i.b.marte.kontant,1,'390: én kontantbetaling');assert.equal(i.b.marte.sum,50000,'390: betalt 500 kr, ikke 1 000');
  assert.match(await ks(p).locator('.ks-topp').innerText(),/2 har ikke betalt/,'390: telleren følger med');
  // «+ Noen kom uten påmelding»: Legg til deltaker fra økt-arket, og den nye står med «ny».
  await ks(p).getByRole('button',{name:'+ Noen kom uten påmelding'}).tap();
  const lt=p.getByRole('dialog',{name:'Legg til deltaker'});await lt.waitFor();
  await lt.getByLabel('Navn').fill('Ulla Uten');await lt.getByLabel('Betaling').selectOption('Ikke betalt');
  await lt.getByRole('button',{name:'Legg til',exact:true}).tap();await lt.waitFor({state:'detached'});
  await rad(p,'Ulla Uten').waitFor();
  assert.equal(await rad(p,'Ulla Uten').locator('.kal-m-ny').innerText(),'ny','390: den nye har «ny»-pillen');
  assert.match(await ks(p).locator('.ks-topp').innerText(),/6 påmeldt[\s\S]*3 har ikke betalt/,'390: telleren tar med den nye');
  assert.equal(await ks(p).count(),1,'390: veilederen står åpen etter Legg til');
  // Steg 2: punktene som avkrysning, bare i nettleseren.
  await ks(p).locator('.ks-fot').getByRole('button',{name:'Videre (3 ubetalt)'}).tap();
  assert.equal(await steg.nth(2).getAttribute('aria-current'),'step','390: Praktisk');
  assert.match(await steg.nth(1).getAttribute('class'),/ferdig/,'390: Velkommen er merket ferdig');
  const k=await p.evaluate(async()=>(await (await fetch('/api/admin/kursstart.php',{credentials:'same-origin'})).json()).kort);
  const k1=k.find(x=>x.nr===1);const linjer=k1.paa?k1.tekst.split(/\n+/).map(x=>x.trim()).filter(Boolean):[];
  const punkter=ks(p).locator('.ks-punkt');
  assert.deepEqual(await punkter.allInnerTexts(),linjer,'390: linjene fra kursstart-kortet');
  if(linjer.length){await punkter.first().tap();assert.match(await punkter.first().getAttribute('class'),/ok/,'390: avkrysset');
   await ks(p).locator('.ks-fot').getByRole('button',{name:'Tilbake'}).tap();await ks(p).locator('.ks-fot').getByRole('button',{name:/^Videre/}).tap();
   assert.ok(await punkter.first().locator('input').isChecked(),'390: avkrysningen huskes mens siden er åpen');}
  // Steg 3: bare status.
  await ks(p).locator('.ks-fot').getByRole('button',{name:'Videre'}).tap();
  const ep=ks(p).locator('.ks-epost');
  assert.deepEqual(await ep.locator('b').allInnerTexts(),['Påminnelse','Google-anmeldelse','Medlemstilbud','Keramikken er klar'],'390: e-postene etter kurset');
  assert.match(await ep.nth(1).innerText(),/Sendes neste dag kl\. 10/,'390: anmeldelsen neste dag kl. 10');
  // Kursbevis: møtt = alle 6 (med Ulla), betalt med e-post = Ingrid, Olga og Marte. Ulla mangler e-post.
  assert.match(await ks(p).locator('.ks-bevis').innerText(),/Kursbevis sendes til 3 av 6/,'390: kursbevis 3 av 6');
  assert.match(await ks(p).locator('.ks-bevis').innerText(),/Sendes neste dag kl\. 10 sammen med spørsmålet om anmeldelse\.|Sendes ikke nå: «Google-anmeldelse» står som/,'390: når kursbeviset sendes');
  assert.match(await ks(p).locator('.ks-mangler').innerText(),/Ulla Uten/,'390: Ulla mangler e-post');
  assert.deepEqual(await ks(p).locator('.ks-innhold').getByRole('button').allInnerTexts(),['Legg inn e-post'],'390: ingen send-knapper i steg 3 (Etter kurset), bare «Legg inn e-post»');
  await ks(p).getByRole('button',{name:'Kurset er ferdig'}).tap();
  await ks(p).waitFor({state:'detached'});
  assert.deepEqual(feil,[],'390: ingen feil i siden');
  await c.close();
 }
 // ── 1280 px: Kursdagen-fanen, og «Betalt» av seg selv ─────────────
 {
  const c=await browser.newContext({viewport:{width:1280,height:900}});
  const {p,feil}=await apne(c);
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);
  await p.getByLabel('Velg dato').fill(s.d);
  const A=p.locator(`.kp[data-visning="uke"] .kp-brikke[data-id="${s.okt}"]`);await A.waitFor();await A.click();
  await ark(p).waitFor();await ark(p).getByRole('tab',{name:'Kursdagen'}).click();
  await ark(p).locator('.kal-verktoy').getByRole('button',{name:'▶ Start kurset'}).click();
  await ks(p).waitFor();
  const w=await ks(p).evaluate(d=>d.getBoundingClientRect().width);assert.ok(w>=500&&w<=620,'1280: veilederen er et vindu midt på ('+w+')');
  await rad(p,'Per Vipps').getByRole('button',{name:'Vis QR-kode'}).click();
  const qv=qrVindu(p);await qv.waitFor();
  await qv.getByText('Venter på Vipps').waitFor();
  fixture('vipps',s,'AUTHORIZED','nei');
  // Kunden skanner og betaler: vinduet bytter til «Betalt» og lukker seg, og raden står betalt.
  await qv.getByText('Betalt',{exact:true}).waitFor({timeout:30000});
  await qv.waitFor({state:'detached',timeout:10000});
  await rad(p,'Per Vipps').getByText('Betalt',{exact:true}).waitFor({timeout:30000});
  const i=se();
  assert.deepEqual(i.b.per.krav,[{status:'betalt',belop:50000}],'1280: QR-betalingen er betalt');
  assert.equal(i.b.per.status,'betalt','1280: Per er betalt');assert.equal(i.b.per.sum,50000);
  fixture('vipps',s,'CREATED','nei');
  // Avbestilt mens QR-koden står oppe: «Påmeldingen er avbestilt.», aldri «Betalt», og vinduet lukker seg.
  await rad(p,'Nils Utennummer').getByRole('button',{name:'Vis QR-kode'}).click();
  const qn=qrVindu(p);await qn.waitFor();await qn.getByText('Venter på Vipps').waitFor();
  fixture('avbestill',s,'nils');
  await qn.getByText('Påmeldingen er avbestilt.',{exact:true}).waitFor({timeout:30000});
  assert.equal(await qn.getByText('Betalt',{exact:true}).count(),0,'1280: avbestilt viser ikke «Betalt»');
  await qn.waitFor({state:'detached',timeout:10000});
  assert.equal(await rad(p,'Nils Utennummer').count(),0,'1280: Nils er borte fra lista');
  assert.equal(se().b.nils.status,'avbestilt','1280: Nils er avbestilt, ikke betalt');
  // Sveip/tilbake-knappen: Tilbake er skjult på steg 1.
  assert.equal(await ks(p).locator('.ks-fot button',{hasText:'Tilbake'}).evaluate(b=>getComputedStyle(b).visibility),'hidden','1280: ingen Tilbake på steg 1');
  await ks(p).locator('.close').click();await ks(p).waitFor({state:'detached'});
  // Bryteren av: kursstarten som før, og QR-koden nektes av serveren.
  fixture('bryter',s,'nei');
  await p.reload();await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);await p.getByLabel('Velg dato').fill(s.d);
  await A.waitFor();await A.click();await ark(p).waitFor();
  await ark(p).locator('.kal-ark-hode').getByRole('button',{name:'▶ Start kurset'}).click();
  await p.locator('dialog.sheet[open]',{hasText:'Kort 1 av'}).waitFor();
  assert.equal(await ks(p).count(),0,'av: den gamle kursstarten');
  const nei=await p.evaluate(async b=>{const r=await fetch('/api/admin/kursstart3.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({handling:'qr',bookingId:b})});return r.status;},s.b.nils);
  assert.equal(nei,403,'av: QR nektes');
  // Den 403-en er med vilje: den skal ikke telle som en rød linje.
  feil.splice(0,feil.length,...feil.filter(x=>!x.startsWith('HTTP 403 ')));
  assert.deepEqual(feil,[],'1280: ingen feil i siden');
  await c.close();
 }
 const slutt=se();
 assert.equal(slutt.varsler,0,'ingen e-post eller SMS lagt i kø');
 assert.equal(slutt.b.nils.sum,0,'Nils (avbestilt) har ikke betalt noe');
 console.log('nyadmin-kursstart: OK (390 med berøring og 1280)');
}finally{
 await browser.close();
 fixture('cleanup',s);
}
