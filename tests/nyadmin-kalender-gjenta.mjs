// Kalenderen i admin-ny, bølge 3 (skissen nyKursDialog/gjentaDatoer), bak bryteren «Vis/kalendergjenta».
// På: «Ny kursdato» har kursholder, Gjentakelse (Én gang / Hver uke / Annenhver uke) og «Hvor lenge»; ingenting er
// forhåndsvalgt utover «Én gang», og Hvor lenge må velges før lagring. Forhåndsvisningen viser antall og første–siste,
// stengte dager står overstrøket og kan ikke velges, og et trykk på en dato tar den bort. Lagring går til «nydatoer».
// «Dupliser til neste uke» i økt-arket (Rediger) legger samme økt inn sju dager senere. Av: det gamle skjemaet, ingen dupliser.
// 390 px med berøring og 1280 px. Ingen e-post eller SMS: til slutt er ingen varsler lagt i kø.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const ADR=process.env.E2E_ADRESSE||'http://lokal.lissom.no:8140';
const fixture=(mode,s,...x)=>JSON.parse(execFileSync('php',['tests/nettleser/kalender-gjenta-fixture.php',mode,JSON.stringify(s||{}),...x],{encoding:'utf8'}));
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const s=fixture('seed');
const pluss=(d,n)=>{const x=new Date(`${d}T12:00:00`);x.setDate(x.getDate()+n);return `${x.getFullYear()}-${String(x.getMonth()+1).padStart(2,'0')}-${String(x.getDate()).padStart(2,'0')}`;};
async function apne(c){
 await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
 const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));
 await p.goto(`${ADR}/admin-ny.html#kalender`);await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
 return {p,feil};
}
const dlg=p=>p.locator('dialog.kal-gj-ark[open]');
const knapp=(p,gruppe,navn)=>dlg(p).getByRole('group',{name:gruppe}).getByRole('button',{name:navn,exact:true});
try{
 // ── 390 px med berøring ───────────────────────────────────────────
 {
  const c=await browser.newContext({viewport:{width:390,height:800},hasTouch:true,isMobile:true,deviceScaleFactor:2});
  const {p,feil}=await apne(c);
  await p.locator('.kalm-handlinger').getByRole('button',{name:'Ny kursdato',exact:true}).tap();
  const d=dlg(p);await d.waitFor();
  const lagre=d.getByRole('button',{name:'Bekreft og legg inn'});
  assert.equal(await knapp(p,'Gjentakelse','Én gang').getAttribute('aria-pressed'),'true','390: Én gang er valgt fra start');
  assert.equal(await d.getByRole('group',{name:'Hvor lenge'}).isVisible(),false,'390: Hvor lenge er skjult ved Én gang');
  assert.equal(await d.getByLabel('Kurs',{exact:true}).inputValue(),'','390: kurset er ikke forhåndsvalgt');
  assert.equal(await d.getByLabel('Kursholder',{exact:true}).inputValue(),'','390: kursholderen er ikke forhåndsvalgt');
  assert.ok(await lagre.isDisabled(),'390: Bekreft er grå til alt er valgt');
  await d.getByLabel('Kurs',{exact:true}).selectOption(String(s.kurs));
  await d.getByLabel('Kursholder',{exact:true}).selectOption(String(s.holder));
  await d.getByLabel('Første dag',{exact:true}).fill(s.d0);
  assert.ok(await lagre.isEnabled(),'390: Én gang kan lagres når kurs, dag og kursholder er valgt');
  await knapp(p,'Gjentakelse','Annenhver uke').tap();
  const lengder=d.getByRole('group',{name:'Hvor lenge'}).getByRole('button');
  assert.deepEqual(await lengder.allInnerTexts(),['1 mnd','2 mnd','3 mnd','6 mnd','Ut året','Til dato','Antall ganger'],'390: valgene for Hvor lenge');
  assert.deepEqual(await lengder.evaluateAll(b=>b.map(x=>x.getAttribute('aria-pressed'))),Array(7).fill('false'),'390: ingenting valgt under Hvor lenge');
  assert.ok(await lagre.isDisabled(),'390: Hvor lenge må velges');
  assert.match(await d.locator('.kal-gj-forhand').innerText(),/Velg hvor lenge/);
  await knapp(p,'Hvor lenge','2 mnd').tap();
  const datoer=d.locator('.kal-gj-dato');
  assert.ok(await datoer.count()>=4,'390: annenhver uke i 2 mnd gir minst fire datoer');
  assert.equal(await d.locator('.kal-gj-dato:disabled').count(),0,'390: annenhver uke treffer ikke den stengte uka');
  assert.ok(await lagre.isEnabled());
  // Alt som kan trykkes er minst 44 px høyt, og ingenting stikker ut til siden.
  const hoyder=await d.locator('.kal-gj-valg button, .kal-gj-dato, .sheet-footer button').evaluateAll(b=>b.map(x=>x.getBoundingClientRect().height));
  assert.ok(hoyder.every(h=>h>=44),'390: minst 44 px '+JSON.stringify(hoyder));
  const bredde=await p.evaluate(()=>({side:document.documentElement.scrollWidth,ark:document.querySelector('dialog.kal-gj-ark').getBoundingClientRect().width}));
  assert.ok(bredde.side<=390&&bredde.ark<=390,'390: arket er innenfor skjermen '+JSON.stringify(bredde));
  await d.getByRole('button',{name:'Avbryt',exact:true}).tap();await d.waitFor({state:'detached'});
  assert.deepEqual(fixture('inspect',s).okter.length,1,'390: Avbryt lagrer ingenting');
  assert.deepEqual(feil,[],'390: ingen feil i siden');await c.close();
  console.log('390 px: Ny kursdato med gjentakelse, ingenting forhåndsvalgt, 44 px og innenfor skjermen');
 }
 // ── 1280 px ───────────────────────────────────────────────────────
 {
  const c=await browser.newContext({viewport:{width:1280,height:900}});
  const {p,feil}=await apne(c);
  await p.getByRole('button',{name:'Ny kursdato',exact:true}).first().click();
  const d=dlg(p);await d.waitFor();
  await d.getByLabel('Kurs',{exact:true}).selectOption(String(s.kurs));
  await d.getByLabel('Kursholder',{exact:true}).selectOption(String(s.holder));
  await d.getByLabel('Første dag',{exact:true}).fill(s.d0);
  await d.getByLabel('Starter kl.',{exact:true}).fill('17:00');await d.getByLabel('Slutter kl.',{exact:true}).fill('19:30');
  await knapp(p,'Gjentakelse','Hver uke').click();
  await knapp(p,'Hvor lenge','Antall ganger').click();
  assert.equal(await knapp(p,'Hvor lenge','Antall ganger').getAttribute('aria-pressed'),'true');
  assert.ok(await d.getByRole('button',{name:'Bekreft og legg inn'}).isDisabled(),'1280: antall må fylles ut');
  await d.getByLabel('Antall ganger',{exact:true}).fill('5');
  const datoer=d.locator('.kal-gj-dato');
  assert.equal(await datoer.count(),5,'1280: fem datoer i forhåndsvisningen');
  const stengt=d.locator(`.kal-gj-dato[data-dato="${s.stengt}"]`);
  assert.ok(await stengt.isDisabled(),'1280: den stengte dagen kan ikke velges');
  assert.match(await stengt.innerText(),/· stengt$/);
  assert.equal(await stengt.evaluate(b=>getComputedStyle(b).textDecorationLine),'line-through','1280: stengt dag er overstrøket');
  const topp=()=>d.locator('.kal-gj-topp b').innerText();
  assert.match(await topp(),/^4 datoer · man \d+\. \w+ – man \d+\. \w+$/,'1280: antall og første–siste');
  // Trykk på siste dato tar den bort.
  const siste=pluss(s.d0,28);
  await d.locator(`.kal-gj-dato[data-dato="${siste}"]`).click();
  assert.equal(await d.locator(`.kal-gj-dato[data-dato="${siste}"]`).getAttribute('aria-pressed'),'false');
  assert.match(await topp(),/^3 datoer/,'1280: tre datoer etter at én er tatt bort');
  await d.getByRole('button',{name:'Bekreft og legg inn'}).click();await d.waitFor({state:'detached'});
  await p.locator('.toast',{hasText:'3 datoer for'}).waitFor();
  const okter=fixture('inspect',s).okter;
  assert.deepEqual(okter.filter(o=>o.start.startsWith(s.d0.slice(0,4))&&o.start>=s.d0).map(o=>o.start),[`${s.d0} 17:00`,`${pluss(s.d0,14)} 17:00`,`${pluss(s.d0,21)} 17:00`],'1280: tre økter lagt inn, den stengte og den bortvalgte hoppet over');
  assert.ok(okter.filter(o=>o.start>=s.d0).every(o=>o.holder===s.holder&&o.slutt==='19:30'),'1280: kursholder og sluttid lagret');
  console.log('1280 px: Hver uke × 5, stengt dag overstrøket, én tatt bort, tre lagret med kursholder');

  // Dupliser til neste uke fra økt-arket.
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);
  await p.getByLabel('Velg dato').fill(s.d);
  const brikke=p.locator(`.kp-brikke[data-id="${s.okt}"]`);await brikke.waitFor();await brikke.click();
  const ark=p.locator('dialog.kal-ark[open]');await ark.waitFor();
  await ark.getByRole('tab',{name:'Rediger'}).click();
  await ark.getByRole('button',{name:'Dupliser til neste uke'}).click();
  const sp=p.getByRole('dialog',{name:'Dupliser til neste uke?'});await sp.waitFor();
  assert.match(await sp.innerText(),new RegExp(`${s.tag} Dreiekurs legges inn \\w+ \\d+\\. \\w+ kl\\. 18:00\\.`));
  await sp.getByRole('button',{name:'Dupliser',exact:true}).click();
  await p.locator('.toast',{hasText:'er lagt inn'}).waitFor();
  const ny=fixture('inspect',s).okter.find(o=>o.start===`${pluss(s.d,7)} 18:00`);
  assert.deepEqual(ny,{start:`${pluss(s.d,7)} 18:00`,slutt:'20:00',holder:s.holder,kap:7},'1280: duplisert med samme tid, kursholder og plasser');
  console.log('1280 px: Dupliser til neste uke');

  // Bryteren av: det gamle skjemaet og ingen dupliser.
  fixture('bryter',s,'nei');await p.reload();await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  await p.getByRole('button',{name:'Ny kursdato',exact:true}).first().click();
  const gml=p.getByRole('dialog',{name:'Ny kursdato'});await gml.waitFor();
  assert.equal(await gml.getByLabel('Starter',{exact:true}).getAttribute('type'),'datetime-local','av: det gamle skjemaet');
  assert.equal(await gml.getByRole('group',{name:'Gjentakelse'}).count(),0,'av: ingen gjentakelse');
  await gml.locator('.close').click();await gml.waitFor({state:'detached'});
  await p.getByRole('searchbox',{name:'Søk i kalender'}).fill(s.tag);await p.getByLabel('Velg dato').fill(s.d);
  await p.locator(`.kp-brikke[data-id="${s.okt}"]`).click();await ark.waitFor();await ark.getByRole('tab',{name:'Rediger'}).click();
  assert.equal(await ark.getByRole('button',{name:'Dupliser til neste uke'}).count(),0,'av: ingen dupliser');
  assert.equal(fixture('inspect',s).varsler,0,'ingen varsler i kø');
  assert.deepEqual(feil,[],'1280: ingen feil i siden');await c.close();
  console.log('1280 px: bryteren av gir kalenderen som før');
 }
 console.log('✓ kalenderen, bølge 3: Ny kursdato med gjentakelse og Dupliser til neste uke (390 og 1280 px)');
}finally{
 fixture('cleanup',s);await browser.close();
}
