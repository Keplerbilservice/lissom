// Regresjon for tre feil i nytt admin (eieren, 2. oktober 2026):
//  1. «Flytt tidspunkt» paa samling 2 flyttet hele okta, ikke bare samlingen.
//  2. «Rediger påmelding» sendte ?booking=, men paamelding utenfor lista aapnet ikke.
//  3. Ingen forhaandsvalg: tomme velgere, bare kommende datoer, Lagre graa til noe er valgt.
// Kjoeres paa 390 px med beroering og paa 1280 px. Sender aldri SMS eller e-post:
// testen sjekker at ingen varsler er lagt i koen.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const ADR=process.env.E2E_ADRESSE||'http://lokal.lissom.no:8140';
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/samling-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const nesteDag=(d,n=1)=>{const t=new Date(d+'T12:00:00Z');t.setUTCDate(t.getUTCDate()+n);return t.toISOString().slice(0,10);};
const vent=async(f,ms=8000)=>{const slutt=Date.now()+ms;let siste;while(Date.now()<slutt){siste=f();if(siste.ok)return siste;await new Promise(r=>setTimeout(r,200));}return siste;};
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
let s;
try{
 for(const width of [390,900,1280]){
  s=fixture('seed');const mobil=width<500;
  const c=await browser.newContext({viewport:{width,height:900},hasTouch:mobil,isMobile:mobil});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const feil=[];p.on('pageerror',e=>feil.push(e.message));
  const trykk=l=>mobil?l.tap():l.click();
  const aapneDag=async dag=>{
   // Mobil: via #idag, så en oppfriskning som henger etter et lagret skjema ikke tegner over kalenderen vi trykker i.
   if(mobil){await p.goto(`${ADR}/admin-ny.html#idag`);await p.locator(".kalm").waitFor({state:"detached"});}
   await p.goto(`${ADR}/admin-ny.html#kalender`);if(mobil)await p.locator(".kalm").waitFor();await p.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
   // Mobil (K3): datoen velges bak søkeikonet, og timekortene er .kalm-kort.
   if(mobil){const ikon=p.getByRole('button',{name:'Søk i kalender',exact:true});if(await ikon.getAttribute('aria-expanded')!=='true')await trykk(ikon);await p.getByLabel('Velg dato').fill(dag);}
   else{const dagKnapp=p.getByRole('group',{name:'Kalendervisning'}).getByRole('button',{name:'Dag',exact:true});if(await dagKnapp.getAttribute('aria-pressed')!=='true')await trykk(dagKnapp);/* PC (K4): segmentpille Dag · Uke · Måned */await p.getByLabel('Velg dato').fill(dag);}
   const brikke=p.locator(mobil?'button.kalm-kort':'button.event',{hasText:s.tag});await brikke.first().waitFor();return brikke.first();
  };
  const flytt=async(dag,start,slutt,forvent)=>{
   await trykk(await aapneDag(dag));
   await trykk(p.getByRole('dialog').getByRole('button',{name:'Flytt tidspunkt',exact:true}));
   const skjema=p.getByRole('dialog',{name:'Flytt kursdato'});
   if(forvent){assert.equal(await skjema.getByLabel('Starter').inputValue(),forvent[0],'Starter fylt fra samlingen');assert.equal(await skjema.getByLabel('Slutter').inputValue(),forvent[1],'Slutter fylt fra samlingen, ikke siste dag');}
   await skjema.getByLabel('Starter').fill(start);await skjema.getByLabel('Slutter').fill(slutt);
   await trykk(skjema.getByRole('button',{name:'Lagre',exact:true}));
   await trykk(p.getByRole('dialog',{name:'Flytte datoen?'}).getByRole('button',{name:'Flytt dato',exact:true}));
   await skjema.waitFor({state:'detached'});
  };

  // ── 1. Samling 2 flyttes alene ─────────────────────────────────────
  const d3=nesteDag(s.d2);
  await flytt(s.d2,`${d3}T18:00`,`${d3}T21:00`,[`${s.d2}T17:00`,`${s.d2}T20:00`]);
  let r=await vent(()=>{const v=fixture('inspect',s);return {...v,ok:v.samlinger[1]?.[0]===d3};});
  assert.deepEqual(r.samlinger,[[s.d1,'17:00','20:00','Dag en'],[d3,'18:00','21:00','Dag to']],'bare samling 2 er flyttet');
  assert.equal(r.start,`${s.d1} 17:00`,'okta starter fortsatt paa dag 1');
  assert.equal(r.slutt,`${d3} 21:00`,'okta slutter der samling 2 slutter');
  console.log(`${width} px: samling 2 flyttet alene, dag 1 urørt`);

  // Dag 1 (hovedbrikka) flyttes: samling 2 blir staaende.
  const d0=nesteDag(s.d1,-1);
  // Brikka for dag 1 har slutt 21:00 (siste dag); skjemaet skal vise dag 1 sin 20:00.
  await flytt(s.d1,`${d0}T16:00`,`${d0}T19:00`,[`${s.d1}T17:00`,`${s.d1}T20:00`]);
  r=await vent(()=>{const v=fixture('inspect',s);return {...v,ok:v.samlinger[0]?.[0]===d0};});
  assert.deepEqual(r.samlinger,[[d0,'16:00','19:00','Dag en'],[d3,'18:00','21:00','Dag to']],'dag 1 flyttet, samling 2 urørt');
  assert.equal(r.start,`${d0} 16:00`);assert.equal(r.slutt,`${d3} 21:00`,'todagerskurset er fortsatt to dager');
  assert.equal(r.varsler,0,'ingen SMS eller e-post lagt i koen');
  console.log(`${width} px: dag 1 flyttet, samling 2 urørt, ingen varsler`);

  // Samling 2 til en stengt feriedag: samme ferieadvarsel som dag 1 faar.
  await trykk(await aapneDag(d3));
  await trykk(p.getByRole('dialog').getByRole('button',{name:'Flytt tidspunkt',exact:true}));
  const fskjema=p.getByRole('dialog',{name:'Flytt kursdato'});await fskjema.getByLabel('Starter').fill(`${s.ferie}T18:00`);await fskjema.getByLabel('Slutter').fill(`${s.ferie}T21:00`);
  const lagreFlytt=async()=>{await trykk(fskjema.getByRole('button',{name:'Lagre',exact:true}));await trykk(p.getByRole('dialog',{name:'Flytte datoen?'}).getByRole('button',{name:'Flytt dato',exact:true}));};
  await lagreFlytt();let ferie=p.getByRole('dialog',{name:'Kurs på stengt dag?'});await ferie.waitFor();
  assert.match(await ferie.textContent(),/er ferie/,'advarselen sier at dagen er ferie');
  await trykk(ferie.getByRole('button',{name:'Avbryt',exact:true}));await fskjema.getByText('Avbrutt.').waitFor();
  r=fixture('inspect',s);assert.equal(r.samlinger[1][0],d3,'avbrutt: samling 2 er ikke flyttet');
  await lagreFlytt();ferie=p.getByRole('dialog',{name:'Kurs på stengt dag?'});await ferie.waitFor();
  await trykk(ferie.getByRole('button',{name:'Legg til likevel',exact:true}));await fskjema.waitFor({state:'detached'});
  r=await vent(()=>{const v=fixture('inspect',s);return {...v,ok:v.samlinger[1]?.[0]===s.ferie};});
  assert.deepEqual(r.samlinger,[[d0,'16:00','19:00','Dag en'],[s.ferie,'18:00','21:00','Dag to']],'samling 2 lagt paa feriedagen etter ja');
  assert.equal(r.ferieOk,1,'okta merket som ferieunntak');assert.equal(r.varsler,0);
  console.log(`${width} px: samling 2 på feriedag gir advarsel, avbryt lagrer ikke, «Legg til likevel» lagrer`);

  // Rekkefølgen: samling 2 før dag 1, og dag 1 forbi samling 2, avvises i skjemaet uten å spørre eller lagre.
  const lukkFlytt=async dlg=>{await trykk(dlg.getByRole('button',{name:'Avbryt',exact:true}));const f=p.getByRole('dialog',{name:'Forkaste endringene?'});if(await f.count())await trykk(f.getByRole('button',{name:'Forkast',exact:true}));await dlg.waitFor({state:'detached'});};
  for(const [fraDag,til] of [[s.ferie,nesteDag(d0,-1)],[d0,nesteDag(s.ferie,1)]]){
   await trykk(await aapneDag(fraDag));
   await trykk(p.getByRole('dialog').getByRole('button',{name:'Flytt tidspunkt',exact:true}));
   const rs=p.getByRole('dialog',{name:'Flytt kursdato'});await rs.getByLabel('Starter').fill(`${til}T17:00`);await rs.getByLabel('Slutter').fill(`${til}T20:00`);
   await trykk(rs.getByRole('button',{name:'Lagre',exact:true}));await rs.getByText('Dagene må ligge i rekkefølge.').waitFor();
   assert.equal(await p.getByRole('dialog',{name:'Flytte datoen?'}).count(),0,'ingen bekreftelse når rekkefølgen er feil');
   await lukkFlytt(rs);
  }
  r=fixture('inspect',s);assert.deepEqual(r.samlinger.map(x=>x[0]),[d0,s.ferie],'feil rekkefølge lagrer ingenting');
  // Samme sperre i kurs.php.
  const svar=await p.evaluate(async([oktId,a,b])=>{const res=await fetch('/api/admin/kurs.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({handling:'dato',oktId,samlinger:[{dato:a,fra:'17:00',til:'20:00'},{dato:b,fra:'17:00',til:'20:00'}]})});return {status:res.status,d:await res.json()};},[s.okt,s.ferie,d0]);
  assert.ok(svar.status>=400,'kurs.php avviser feil rekkefølge');assert.equal(svar.d.feil,'Dagene må ligge i rekkefølge.');
  r=fixture('inspect',s);assert.deepEqual(r.samlinger.map(x=>x[0]),[d0,s.ferie],'kurs.php lagret ingenting');
  console.log(`${width} px: feil rekkefølge avvist i skjemaet og i kurs.php`);

  // Start og slutt paa ulike dager i et kurs med samlinger avvises.
  await trykk(await aapneDag(s.ferie));
  await trykk(p.getByRole('dialog').getByRole('button',{name:'Flytt tidspunkt',exact:true}));
  const sd=p.getByRole('dialog',{name:'Flytt kursdato'});await sd.getByLabel('Starter').fill(`${s.ferie}T17:00`);await sd.getByLabel('Slutter').fill(`${nesteDag(s.ferie)}T20:00`);
  await trykk(sd.getByRole('button',{name:'Lagre',exact:true}));await sd.getByText('Start og slutt må være samme dag.').waitFor();
  assert.equal(await p.getByRole('dialog',{name:'Flytte datoen?'}).count(),0,'ingen bekreftelse når start og slutt er ulike dager');
  await lukkFlytt(sd);
  r=fixture('inspect',s);assert.deepEqual(r.samlinger[1],[s.ferie,'18:00','21:00','Dag to'],'ulike dager lagrer ingenting');
  console.log(`${width} px: start og slutt på ulike dager avvist`);

  // Samlingseditoren i kurslista: samme ferieadvarsel med «Legg til likevel».
  const naar=await p.evaluate(async oktId=>{const d=await (await fetch('/api/admin/kurs.php',{credentials:'same-origin'})).json();for(const k of d.kurs)for(const o of k.datoer)if(o.oktId===oktId)return o.naar;},s.okt);
  await p.goto(`${ADR}/admin-ny.html#kurs`);await p.getByRole('heading',{name:'Kurs',exact:true}).waitFor();
  await p.getByRole('searchbox').last().fill(s.tag);
  await trykk(p.locator('article',{hasText:s.tag}).first().getByRole('button',{name:'Datoer',exact:true}));
  const datoark=p.getByRole('dialog',{name:`${s.tag} Dreiekurs`});await datoark.waitFor();
  await trykk(datoark.locator('article',{hasText:naar}).getByRole('button',{name:'Samlinger',exact:true}));
  const se=p.getByRole('dialog',{name:'Samlinger i flerdagerskurs'});await se.waitFor();
  await se.getByLabel('Samling 2 · Dato',{exact:true}).fill(s.ferie2);
  await trykk(se.getByRole('button',{name:'Lagre',exact:true}));
  await trykk(p.getByRole('dialog',{name:'Lagre samlingene?'}).getByRole('button',{name:'Lagre samlinger',exact:true}));
  const fe=p.getByRole('dialog',{name:'Kurs på stengt dag?'});await fe.waitFor();assert.match(await fe.textContent(),/er ferie/);
  await trykk(fe.getByRole('button',{name:'Legg til likevel',exact:true}));await se.waitFor({state:'detached'});
  r=await vent(()=>{const v=fixture('inspect',s);return {...v,ok:v.samlinger[1]?.[0]===s.ferie2};});
  assert.equal(r.samlinger[1][0],s.ferie2,'samlingseditoren lagret etter «Legg til likevel»');assert.equal(r.varsler,0);
  console.log(`${width} px: samlingseditoren gir ferieadvarsel og lagrer etter «Legg til likevel»`);

  // ── 2. «Rediger påmelding» aapner akkurat den paameldingen ─────────
  await trykk(await aapneDag(d0));
  await trykk(p.getByRole('dialog').getByRole('button',{name:'Se deltakerne',exact:true}));
  await trykk(p.getByRole('dialog').getByRole('link',{name:'Rediger påmelding',exact:true}));
  await p.getByRole('dialog',{name:`${s.tag} Framover`}).waitFor();
  assert.equal(await p.getByRole('dialog').count(),1,'bare paameldingen er aapen');
  await p.goto(`${ADR}/admin-ny.html#idag`);await p.goto(`${ADR}/admin-ny.html#pameldte?booking=${s.gammelBooking}`);
  const gml=p.getByRole('dialog',{name:`${s.tag} Gammel`});await gml.waitFor();await gml.evaluate(d=>Promise.all([...d.getAnimations({subtree:true})].map(x=>x.finished)));
  await trykk(gml.getByRole('button',{name:'Flytt til annen dato',exact:true}));
  const gflytt=p.getByRole('dialog',{name:'Flytt påmelding'});await gflytt.waitFor();
  const gv=await gflytt.getByLabel('Kursdato',{exact:true}).evaluate(x=>[...x.options].map(o=>o.value));
  assert.ok(gv.includes(String(s.kommende)),'gammel påmelding: kommende dato på samme kurs vises');
  assert.ok(!gv.includes(String(s.passert))&&!gv.includes(String(s.gammel)),'gammel påmelding: ingen passerte datoer');
  assert.equal(await gflytt.getByLabel('Kursdato',{exact:true}).evaluate(x=>x.selectedIndex),0,'gammel påmelding: ingenting valgt');
  await trykk(gflytt.getByRole('button',{name:'Avbryt',exact:true}));await gflytt.waitFor({state:'detached'});
  console.log(`${width} px: «Rediger påmelding» åpner riktig påmelding, også eldre enn lista`);

  // ── 3. Ingen forhaandsvalg ─────────────────────────────────────────
  const ingenValgt=async(dlg,etiketter)=>{for(const e of etiketter){const v=dlg.getByLabel(e,{exact:true});assert.equal(await v.evaluate(x=>x.selectedIndex),0,`${e}: ingenting valgt`);assert.equal(await v.inputValue(),'',`${e}: tom verdi`);}};
  const lagre=dlg=>dlg.getByRole('button',{name:'Lagre',exact:true});
  const lukk=async dlg=>{await trykk(dlg.getByRole('button',{name:'Avbryt',exact:true}));const f=p.getByRole('dialog',{name:'Forkaste endringene?'});if(await f.count())await trykk(f.getByRole('button',{name:'Forkast',exact:true}));await dlg.waitFor({state:'detached'});};

  await p.goto(`${ADR}/admin-ny.html#pameldte`);await p.getByRole('heading',{name:'Påmeldte og deltakere'}).waitFor();
  await trykk(p.getByRole('button',{name:'Ny påmelding',exact:true}));
  let dlg=p.getByRole('dialog',{name:'Ny påmelding'});await dlg.waitFor();
  await ingenValgt(dlg,['Kursdato','Betaling']);
  assert.equal(await dlg.getByLabel('Send bekreftelse').isChecked(),false,'Send bekreftelse er ikke avkrysset');
  let verdier=await dlg.getByLabel('Kursdato',{exact:true}).evaluate(x=>[...x.options].map(o=>o.value));
  assert.ok(!verdier.includes(String(s.passert)),'passert dato vises ikke');assert.ok(verdier.includes(String(s.kommende)),'kommende dato vises');
  assert.equal(await lagre(dlg).isDisabled(),true,'Lagre er graa før noe er valgt');
  await dlg.getByLabel('Kursdato',{exact:true}).selectOption(String(s.kommende));assert.equal(await lagre(dlg).isDisabled(),true,'Lagre graa til betaling er valgt');
  await dlg.getByLabel('Betaling',{exact:true}).selectOption('Kontant');assert.equal(await lagre(dlg).isDisabled(),false,'Lagre aktiv naar alt er valgt');
  await lukk(dlg);

  const rad=p.locator('article',{hasText:`${s.tag} Framover`});await trykk(rad.getByRole('button',{name:'Åpne deltaker'}));
  await trykk(p.getByRole('dialog',{name:`${s.tag} Framover`}).getByRole('button',{name:'Flytt til annen dato',exact:true}));
  dlg=p.getByRole('dialog',{name:'Flytt påmelding'});await dlg.waitFor();await ingenValgt(dlg,['Kursdato']);
  verdier=await dlg.getByLabel('Kursdato',{exact:true}).evaluate(x=>[...x.options].map(o=>o.value));
  assert.ok(!verdier.includes(String(s.passert)),'passert dato vises ikke i Flytt');assert.ok(verdier.includes(String(s.kommende)));
  assert.equal(await lagre(dlg).isDisabled(),true);await lukk(dlg);
  console.log(`${width} px: Ny påmelding og Flytt uten forhåndsvalg, bare kommende datoer`);

  await p.goto(`${ADR}/admin-ny.html#kalender`);await trykk(p.getByRole('button',{name:'Ny kursdato',exact:true}));
  dlg=p.getByRole('dialog',{name:'Ny kursdato'});await dlg.waitFor();await ingenValgt(dlg,['Kurs']);assert.equal(await lagre(dlg).isDisabled(),true);await lukk(dlg);

  await p.goto(`${ADR}/admin-ny.html#gaver`);await trykk(p.getByRole('button',{name:'Gi gave',exact:true}));
  dlg=p.getByRole('dialog',{name:'Gi medlemsgave'});await dlg.waitFor();await ingenValgt(dlg,['Mottaker','Medlem','Gavetype']);
  assert.equal(await lagre(dlg).isDisabled(),true);
  await dlg.getByLabel('Gavetype',{exact:true}).selectOption('Ekstra timer');await dlg.getByLabel('Mottaker',{exact:true}).selectOption('Ett medlem');
  assert.equal(await lagre(dlg).isDisabled(),true,'Ett medlem krever at medlemmet velges');
  await dlg.getByLabel('Mottaker',{exact:true}).selectOption('Alle medlemmer');assert.equal(await lagre(dlg).isDisabled(),false,'Alle medlemmer trenger ikke medlem');
  await lukk(dlg);
  console.log(`${width} px: Ny kursdato og Gi medlemsgave uten forhåndsvalg`);

  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen sideveis rulling');
  assert.equal(fixture('inspect',s).varsler,0,'ingen SMS eller e-post');
  assert.deepEqual(feil,[]);await c.close();fixture('cleanup',s);s=null;
 }
}finally{await browser.close();if(s)fixture('cleanup',s);}
