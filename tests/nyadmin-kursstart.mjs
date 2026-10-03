// «Start kurset» i tre steg (kalenderplanen, bølge 2), bak bryteren «Vis/kursstart3».
// Åpnes fra økt-arket: den gule knappen i arkhodet (390 px med berøring) og Kursdagen-fanen (1280 px).
// Steg 1: hilsen og deltakerne med betalt/ubetalt; «Send Vipps-krav» (dobbeltklikk gir ett krav), 400 fra Vipps gir
// tydelig feil og Kontant virker, kontant mens kravet venter avbryter kravet. Steg 2: punktene fra kursstart-kortet
// som avkrysning (bare i nettleseren). Steg 3: bare status for e-postene, ingen send-knapper; «Kurset er ferdig» lukker.
// 1280: kravet blir «Betalt» av seg selv når Vipps sier godkjent. Bryteren av: kursstarten som før, og krav nektes.
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
 await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
 return {p,feil};
}
const ark=p=>p.locator('dialog.kal-ark[open]');
const ks=p=>p.locator('dialog.ks[open]');
const rad=(p,navn)=>ks(p).locator('.kal-delt',{hasText:navn});
const se=()=>fixture('inspect',s);
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
  assert.deepEqual((await steg.allInnerTexts()).map(t=>t.replace(/\s+/g,' ').trim()),['1 Velkommen','2 Praktisk','3 Etter kurset'],'390: tre steg');
  assert.equal(await steg.first().getAttribute('aria-current'),'step','390: steg 1 er valgt');
  assert.match(await ks(p).locator('.ks-si').first().innerText(),new RegExp(`Hei og velkommen til ${s.tag} Dreiekurs`),'390: hilsenen');
  assert.match(await ks(p).locator('.ks-topp').innerText(),/5 påmeldt[\s\S]*4 har ikke betalt/,'390: påmeldt og ubetalt');
  assert.match(await rad(p,'Ingrid Berg').innerText(),/Betalt/,'390: Ingrid er betalt');
  assert.equal(await rad(p,'Ingrid Berg').getByRole('button').count(),0,'390: ingen betalingsknapper hos den som har betalt');
  assert.ok(await rad(p,'Nils Utennummer').getByRole('button',{name:'Send Vipps-krav'}).isDisabled(),'390: uten mobil kan det ikke sendes krav');
  // Målt etter at vinduet har glidd inn (animasjonen «enter»), med et par forsøk mens skriftene lastes.
  await ks(p).evaluate(d=>Promise.all(d.getAnimations({subtree:true}).map(x=>x.finished)));
  let hoyder=[];for(let n=0;n<10;n++){hoyder=await ks(p).locator('.kal-delt button, .ks-fot button, .ks-steg button').evaluateAll(b=>b.map(x=>x.getBoundingClientRect().height));if(hoyder.every(h=>h>=44))break;await p.waitForTimeout(100);}
  assert.ok(hoyder.every(h=>h>=44),'390: alt som kan trykkes er minst 44 px '+JSON.stringify(hoyder));
  const bredde=await p.evaluate(()=>({side:document.documentElement.scrollWidth,ark:document.querySelector('dialog.ks').getBoundingClientRect().width}));
  assert.ok(bredde.side<=390&&bredde.ark<=390,'390: veilederen og siden er innenfor skjermen');
  // Vipps-krav med dobbeltklikk: ett krav.
  await rad(p,'Marte Sol').getByRole('button',{name:'Send Vipps-krav'}).dblclick();
  await rad(p,'Marte Sol').getByText('Venter på Vipps').waitFor();
  let i=se();
  assert.deepEqual(i.b.marte.krav,[{status:'venter',belop:50000}],'390: dobbeltklikk gir ett krav på 500 kr');
  assert.equal(i.b.marte.status,'reservert','390: Marte er ikke betalt ennå');
  // 400 fra Vipps: tydelig feil, og Kontant virker.
  fixture('vipps',s,'CREATED','ja');
  await rad(p,'Olga Feil').getByRole('button',{name:'Send Vipps-krav'}).tap();
  const feilBoks=ks(p).locator('.ks-feil');await feilBoks.waitFor({state:'visible'});
  assert.match(await feilBoks.innerText(),/Fikk ikke sendt Vipps-kravet[\s\S]*Salgsenheten har ikke lov[\s\S]*kontant/,'390: tydelig feilmelding ved 400');
  assert.equal(se().b.olga.krav.length,0,'390: ingen krav lagret etter 400');
  await kontant(p,'Olga Feil',tap);
  await rad(p,'Olga Feil').getByText('Betalt',{exact:true}).waitFor();
  i=se();assert.equal(i.b.olga.status,'betalt','390: Olga betalt kontant');assert.equal(i.b.olga.sum,50000);
  fixture('vipps',s,'CREATED','nei');
  // Kontant mens kravet venter: kravet avbrytes, ingen dobbel betaling.
  await kontant(p,'Marte Sol',tap);
  await rad(p,'Marte Sol').getByText('Betalt',{exact:true}).waitFor();
  i=se();
  assert.deepEqual(i.b.marte.krav,[{status:'avbrutt',belop:50000}],'390: kravet er avbrutt');
  assert.equal(i.b.marte.kontant,1,'390: én kontantbetaling');assert.equal(i.b.marte.sum,50000,'390: betalt 500 kr, ikke 1 000');
  assert.match(await ks(p).locator('.ks-topp').innerText(),/2 har ikke betalt/,'390: telleren følger med');
  // Steg 2: punktene som avkrysning, bare i nettleseren.
  await ks(p).locator('.ks-fot').getByRole('button',{name:'Videre (2 ubetalt)'}).tap();
  assert.equal(await steg.nth(1).getAttribute('aria-current'),'step','390: steg 2');
  assert.match(await steg.first().getAttribute('class'),/ferdig/,'390: steg 1 er merket ferdig');
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
  assert.equal(await ks(p).locator('.ks-innhold').getByRole('button').count(),0,'390: ingen send-knapper i steg 3');
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
  await rad(p,'Per Vipps').getByRole('button',{name:'Send Vipps-krav'}).click();
  await rad(p,'Per Vipps').getByText('Venter på Vipps').waitFor();
  fixture('vipps',s,'AUTHORIZED','nei');
  await rad(p,'Per Vipps').getByText('Betalt',{exact:true}).waitFor({timeout:30000});
  const i=se();
  assert.deepEqual(i.b.per.krav,[{status:'betalt',belop:50000}],'1280: kravet er betalt');
  assert.equal(i.b.per.status,'betalt','1280: Per er betalt');assert.equal(i.b.per.sum,50000);
  fixture('vipps',s,'CREATED','nei');
  // Sveip/tilbake-knappen: Tilbake er skjult på steg 1.
  assert.equal(await ks(p).locator('.ks-fot button',{hasText:'Tilbake'}).evaluate(b=>getComputedStyle(b).visibility),'hidden','1280: ingen Tilbake på steg 1');
  await ks(p).locator('.close').click();await ks(p).waitFor({state:'detached'});
  // Bryteren av: kursstarten som før, og krav nektes av serveren.
  fixture('bryter',s,'nei');
  await p.reload();await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);await p.getByLabel('Velg dato').fill(s.d);
  await A.waitFor();await A.click();await ark(p).waitFor();
  await ark(p).locator('.kal-ark-hode').getByRole('button',{name:'▶ Start kurset'}).click();
  await p.locator('dialog.sheet[open]',{hasText:'Kort 1 av'}).waitFor();
  assert.equal(await ks(p).count(),0,'av: den gamle kursstarten');
  const nei=await p.evaluate(async b=>{const r=await fetch('/api/admin/kursstart3.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({handling:'krav',bookingId:b})});return r.status;},s.b.nils);
  assert.equal(nei,403,'av: krav nektes');
  assert.deepEqual(feil,[],'1280: ingen feil i siden');
  await c.close();
 }
 const slutt=se();
 assert.equal(slutt.varsler,0,'ingen e-post eller SMS lagt i kø');
 assert.equal(slutt.b.nils.krav.length,0,'Nils (uten mobil) fikk aldri krav');
 console.log('nyadmin-kursstart: OK (390 med berøring og 1280)');
}finally{
 await browser.close();
 fixture('cleanup',s);
}
