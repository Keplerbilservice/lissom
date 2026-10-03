// Resten av kalenderen i admin-ny (skissen: hMeny, visMeny, tomPlassMeny, dagMeny, notatMeny, venteMeny, koblePC, flyttBekreft),
// bak bryteren «Vis/kalendermeny». 1280 px: høyreklikk-meny med bokstavtaster, flytt ved å dra med konfliktvarsel og «Angre»,
// påmeldte nevnt i bekreftelsen, kopier/lim inn, notat flyttet og angret, venteliste og deltaker dratt inn på økt, kurs dratt
// fra sidelista, hurtigtaster, kursholderfilter, listevisning, dagsrapport som lastes ned, innsjekk skjult.
// 390 px med berøring: trykk åpner valgene i arket, «+»-knappen, kort for tynt besatte og avlyste økter.
// Av: kalenderen som før. gi-plass og pamelding flytt fanges (sender beskjed); resten kjøres ekte mot testbasen. Til slutt: ingen varsler i kø.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const ADR=process.env.E2E_ADRESSE||'http://lokal.lissom.no:8140';
const fixture=(mode,s,...x)=>JSON.parse(execFileSync('php',['tests/nettleser/kalender-meny-fixture.php',mode,JSON.stringify(s||{}),...x],{encoding:'utf8'}));
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const s=fixture('seed');
async function apne(c){
 await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
 const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));const fanget=[];
 await p.route(u=>['venteliste.php','pamelding.php'].includes(u.pathname.split('/').pop()),async r=>{const q=r.request();if(q.method()==='POST'){fanget.push(JSON.parse(q.postData()||'{}'));return r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,beskjed:'Testsvar.'})});}return r.continue();});
 await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
 return {p,feil,fanget};
}
const oslo=o=>s.okt[o];
const finnOkt=id=>fixture('inspect',s).okter.find(o=>o.id===id);
const midt=async l=>{const b=await l.boundingBox();return [b.x+b.width/2,b.y+b.height/2];};
// HTML5-dra med Playwrights dragTo (mus-dra starter ikke dragstart på en knapp). force: målet kan ligge under en brikke.
async function dra(p,fra,til){await fra.dragTo(til,{force:true});}
const celle=(p,dato,tid)=>p.locator(`.kp-celle[data-dato="${dato}"][data-akse="${tid}"]`);
const dialog=(p,navn)=>p.getByRole('dialog',{name:navn});
try{
 // ── 1280 px ───────────────────────────────────────────────────────
 {
  const c=await browser.newContext({viewport:{width:1280,height:1700},acceptDownloads:true});
  const {p,feil,fanget}=await apne(c);
  await p.getByLabel('Velg dato').fill(s.d);
  const brikke=id=>p.locator(`.kp-brikke[data-id="${id}"]`);
  await brikke(oslo('A1')).waitFor();
  assert.equal(await p.locator('.kal-sideliste').count(),1,'1280: sidelista vises');
  assert.equal(await p.locator('.kp-brikke',{hasText:'innsjekket'}).count(),0,'1280: innsjekk er skjult');
  assert.equal(await p.getByRole('group',{name:'Vis typer'}).getByRole('button',{name:'Verksted'}).count(),0,'1280: ingen Verksted-knapp');

  // Høyreklikk på økta: menyen med bokstavtaster; Esc lukker.
  await brikke(oslo('A1')).click({button:'right'});
  const meny=p.locator('.kal-hmeny');await meny.waitFor();
  assert.equal(await meny.getByRole('menuitem',{name:/Start kurset/}).locator('kbd').innerText(),'S');
  assert.ok(await meny.getByRole('menuitem',{name:/Flytt tidspunkt/}).count());
  await p.keyboard.press('Escape');await meny.waitFor({state:'detached'});

  // Dra Bravo til 10:00 samme dag: H1 har Alfa da → konfliktvarsel → Flytt likevel → Angre.
  await dra(p,brikke(oslo('B1')),celle(p,s.d,'10:00'));
  let dlg=dialog(p,'Flytte økta?');await dlg.waitFor();
  assert.match(await dlg.locator('.kal-konflikt').innerText(),/er opptatt da: .*Alfa 10:00–12:00/);
  await dlg.getByRole('button',{name:'Flytt likevel'}).click();await dlg.waitFor({state:'detached'});
  const angre=p.locator('.kal-angre');await angre.waitFor();
  assert.equal(finnOkt(oslo('B1')).start,`${s.d} 10:00`,'1280: Bravo flyttet til 10:00');
  assert.equal(finnOkt(oslo('B1')).slutt,`${s.d} 12:00`);
  await angre.getByRole('button',{name:'Angre'}).click();
  await p.locator('.toast',{hasText:'Angret.'}).waitFor();
  assert.equal(finnOkt(oslo('B1')).start,`${s.d} 18:00`,'1280: Angre flyttet tilbake');
  console.log('1280 px: dra økt, konfliktvarsel, Flytt likevel og Angre');

  // Alfa har to påmeldte: bekreftelsen sier det. Avbryt endrer ingenting.
  await dra(p,brikke(oslo('A1')),celle(p,s.d,'14:00'));
  dlg=dialog(p,'Flytte økta?');await dlg.waitFor();
  assert.match(await dlg.innerText(),/2 påmeldte\. De får ikke beskjed automatisk\./);
  await dlg.getByRole('button',{name:'Avbryt'}).click();await dlg.waitFor({state:'detached'});
  assert.equal(finnOkt(oslo('A1')).start,`${s.d} 10:00`);

  // Kopier (C) og lim inn (V) på en ledig plass.
  await brikke(oslo('A1')).click({button:'right'});await meny.waitFor();await p.keyboard.press('c');await meny.waitFor({state:'detached'});
  await celle(p,s.d2,'15:00').click({button:'right',force:true});await meny.waitFor();
  assert.ok(await meny.getByRole('menuitem',{name:/Lim inn/}).count(),'1280: Lim inn i menyen');
  await p.keyboard.press('v');
  dlg=dialog(p,'Lime inn økta?');await dlg.waitFor();await dlg.getByRole('button',{name:'Lim inn'}).click();
  await p.locator('.toast',{hasText:'er lagt inn'}).waitFor();
  const lim=fixture('inspect',s).okter.find(o=>o.start===`${s.d2} 15:00`);
  assert.deepEqual(lim&&{kurs:lim.kurs,slutt:lim.slutt,holder:lim.holder},{kurs:s.kurs.A,slutt:`${s.d2} 17:00`,holder:s.h1},'1280: limt inn med samme kurs, varighet og kursholder');
  console.log('1280 px: kopier og lim inn');

  // Notat: Flytt til neste dag (F), så Angre.
  await brikke(`notat-${s.notat}`).click({button:'right'});await meny.waitFor();await p.keyboard.press('f');
  await angre.waitFor();assert.equal(fixture('inspect',s).notat.dato,s.d1,'1280: notatet flyttet');
  await angre.getByRole('button',{name:'Angre'}).click();await p.locator('.toast',{hasText:'Angret.'}).waitFor();
  assert.equal(fixture('inspect',s).notat.dato,s.d,'1280: notatet tilbake');

  // Dagsmenyen på kolonnehodet: dagsrapport (R) som lastes ned.
  await p.locator(`.kp-kolhode[data-dato="${s.d}"] h3`).click({button:'right'});await meny.waitFor();
  assert.ok(await meny.getByRole('menuitem',{name:/Steng dagen/}).count());
  await p.keyboard.press('r');
  dlg=dialog(p,/^Dagsrapport/);await dlg.waitFor();
  assert.match(await dlg.innerText(),/Ingrid Berg[\s\S]*Marte Sol/);
  const [fil]=await Promise.all([p.waitForEvent('download'),dlg.getByRole('button',{name:'Last ned dagsrapport'}).click()]);
  assert.equal(fil.suggestedFilename(),`dagsrapport-${s.d}.csv`);
  await dlg.locator('.sheet-footer').getByRole('button',{name:'Lukk',exact:true}).click();await dlg.waitFor({state:'detached'});
  console.log('1280 px: notatmeny med Angre, dagsmeny og dagsrapport');

  // Venteliste fra sidelista inn på Alfa d+1: gi-plass med bekreftelse (fanget).
  const siri=p.locator('.kal-sideliste [data-vente]',{hasText:`${s.tag} Siri`});await siri.waitFor();
  await dra(p,siri,brikke(oslo('A2')));
  dlg=dialog(p,'Gi kursplass?');await dlg.waitFor();await dlg.getByRole('button',{name:'Gi plass'}).click();
  await p.locator('.toast',{hasText:'Testsvar.'}).waitFor();
  assert.deepEqual(fanget.at(-1),{handling:'gi-plass',id:s.w,oktId:s.okt.A2});

  // Deltaker (runding på brikka) dratt til en annen dato: pamelding flytt.
  await brikke(oslo('A1')).waitFor();
  const ingrid=brikke(oslo('A1')).locator(`[data-booking="${s.b.ingrid}"]`);
  await dra(p,ingrid,brikke(oslo('A2')));
  dlg=dialog(p,'Bytte dato?');await dlg.waitFor();await dlg.getByRole('button',{name:'Bytt dato'}).click();await dlg.waitFor({state:'detached'});
  // pamelding.php flytt sender deltakeren e-post om ny dato (som «Bytt dato» i arket); fanges her.
  for(let i=0;i<50&&fanget.length<2;i++)await p.waitForTimeout(100);
  assert.deepEqual(fanget.at(-1),{handling:'flytt',id:s.b.ingrid,oktId:s.okt.A2},'1280: Ingrid flyttes til A2');

  // Kurs fra sidelista til dag og tid: Ny kursdato forhåndsutfylt.
  await p.waitForLoadState('networkidle');
  const alfa=p.locator('.kal-sideliste [data-kurs]',{hasText:`${s.tag} Alfa`});
  // Sidelista tegnes på nytt etter forrige lagring; prøv igjen hvis draget startet mens den ble byttet ut.
  dlg=dialog(p,'Ny kursdato');
  for(let i=0;i<5&&!(await dlg.count());i++){try{await alfa.first().waitFor({state:'visible',timeout:5000});await dra(p,alfa.first(),celle(p,s.d2,'13:00'));}catch{await p.waitForTimeout(500);continue;}await dlg.waitFor({timeout:5000}).catch(()=>{});}
  await dlg.waitFor();
  assert.equal(await dlg.getByLabel('Starter',{exact:true}).inputValue(),`${s.d2}T13:00`);
  assert.equal(await dlg.getByLabel('Kurs',{exact:true}).inputValue(),String(s.kurs.A));
  await dlg.locator('.close').click();await dlg.waitFor({state:'detached'});
  console.log('1280 px: venteliste, deltaker og kurs dratt inn');

  // Hurtigtaster: 4 = liste, 1 = dag, / = søk, N = ny kursdato (Esc lukker).
  await p.locator('body').click({position:{x:5,y:5}});
  await p.keyboard.press('4');await p.locator('.kal-lr').first().waitFor();
  assert.equal(await p.locator('.kp-segment [data-modus="liste"]').getAttribute('aria-pressed'),'true');
  await p.keyboard.press('1');await p.locator('.kp[data-visning="dag"]').waitFor();
  await p.keyboard.press('/');assert.equal(await p.evaluate(()=>document.activeElement?.getAttribute('aria-label')),'Søk i kalender');
  await p.locator('body').click({position:{x:5,y:5}});
  await p.keyboard.press('n');dlg=dialog(p,'Ny kursdato');await dlg.waitFor();await p.keyboard.press('Escape');await dlg.waitFor({state:'detached'});
  await p.keyboard.press('2');await p.locator('.kp[data-visning="uke"]').waitFor();
  // Kursholderfilter: H2 skjuler Alfa (H1).
  await p.getByLabel('Kursholder',{exact:true}).selectOption(String(s.h2));
  assert.equal(await brikke(oslo('A1')).count(),0,'1280: filteret skjuler H1');
  await p.getByLabel('Kursholder',{exact:true}).selectOption('');
  // Sidelista kan skjules.
  await p.getByRole('button',{name:'Skjul sidelista'}).click();await p.locator('.kal-sideliste').waitFor({state:'detached'});
  await p.getByRole('button',{name:'Vis sidelista'}).click();await p.locator('.kal-sideliste').waitFor();
  console.log('1280 px: hurtigtaster, liste, kursholderfilter, sidelista av og på');

  // Bryteren av: kalenderen som før.
  fixture('bryter',s,'nei');await p.reload();await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  await p.getByLabel('Velg dato').fill(s.d);await brikke(oslo('A1')).waitFor();
  assert.equal(await p.locator('.kal-sideliste').count(),0,'av: ingen sideliste');
  assert.ok(await p.locator('.kp-brikke',{hasText:'innsjekket'}).count(),'av: innsjekk vises som før');
  await brikke(oslo('A1')).click({button:'right'});await p.waitForTimeout(200);
  assert.equal(await p.locator('.kal-hmeny').count(),0,'av: ingen høyreklikk-meny');
  assert.equal(await p.locator('.kp-segment [data-modus="liste"]').count(),0,'av: ingen liste');
  fixture('bryter',s,'ja');
  assert.deepEqual(feil,[],'1280: ingen feil i siden');await c.close();
  console.log('1280 px: bryteren av gir kalenderen som før');
 }
 // ── 390 px med berøring ───────────────────────────────────────────
 {
  const c=await browser.newContext({viewport:{width:390,height:844},hasTouch:true,isMobile:true,deviceScaleFactor:2});
  const {p,feil}=await apne(c);
  const tynt=p.locator('.kal-tynt',{hasText:`${s.tag} Tynn`});await tynt.waitFor();
  assert.match(await tynt.innerText(),/1\/10/);
  await p.locator('.kal-avl',{hasText:`${s.tag} Avlyst`}).waitFor();
  // Varselkortet åpner økt-arket.
  await tynt.tap();await p.locator('dialog.kal-ark[open]').waitFor();await p.locator('dialog.kal-ark[open] .close').tap();
  let ark;
  // «+»-knappen.
  const pluss=p.getByRole('button',{name:'Legg til',exact:true});
  const h=await pluss.boundingBox();assert.ok(h.height>=44&&h.x+h.width<=390,'390: + er minst 44 px og innenfor');
  await pluss.tap();ark=p.locator('dialog.kal-valgark[open]');await ark.waitFor();
  assert.deepEqual(await ark.getByRole('menuitem').allInnerTexts(),['Ny kursdato','Notat','Deltaker','Fra ventelista']);
  const hoyder=await ark.getByRole('menuitem').evaluateAll(b=>b.map(x=>x.getBoundingClientRect().height));
  assert.ok(hoyder.every(x=>x>=44),'390: valgene minst 44 px');
  await ark.locator('.close').tap();
  // Innsjekk er skjult på dagen med innsjekk.
  await p.getByRole('button',{name:'Søk i kalender'}).tap();await p.getByLabel('Velg dato').fill(s.t2);
  await p.locator('.kalm-kort',{hasText:`${s.tag} Tynn`}).waitFor();
  assert.equal(await p.locator('.kalm-kort',{hasText:'innsjekket'}).count(),0,'390: innsjekk er skjult');
  // Trykk på økta i dagen: de samme valgene som høyreklikk-menyen, i arket.
  await p.locator('.kalm-kort',{hasText:`${s.tag} Tynn`}).tap();
  ark=p.locator('dialog.kal-valgark[open]');await ark.waitFor();
  assert.ok(await ark.getByRole('menuitem',{name:'Flytt tidspunkt'}).count(),'390: valgene i arket');
  await ark.getByRole('menuitem',{name:'Åpne og se deltakerne'}).tap();
  await p.locator('dialog.kal-ark[open]').waitFor();await p.locator('dialog.kal-ark[open] .close').tap();
  assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=390),'390: ingenting stikker ut');
  assert.deepEqual(feil,[],'390: ingen feil i siden');await c.close();
  console.log('390 px: varselkort, valgene i arket, + og innsjekk skjult');
 }
 assert.equal(fixture('inspect',s).varsler,0,'ingen varsler i kø');
 console.log('✓ kalenderen: menyer, dra og slipp, sideliste, liste, dagsrapport og mobil (390 og 1280 px)');
}finally{
 fixture('cleanup',s);await browser.close();
}
