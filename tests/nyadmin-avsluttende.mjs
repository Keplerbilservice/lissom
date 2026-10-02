import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed'),browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try {
 const c=await browser.newContext({viewport:{width:390,height:900}});
 await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
 const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
 // Kalenderen (samlet 2. oktober 2026): mobilkalenderen til og med 760 px, PC med tidsakse fra 761 px. Begge prøves.
 for(const width of [390,1280]){
  const mobil=width<500;
  const k=await browser.newContext({viewport:{width,height:900},hasTouch:mobil,isMobile:mobil});
  await k.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const q=await k.newPage(),kfeil=[];q.on('pageerror',e=>kfeil.push(e.message));
  const trykk=l=>mobil?l.tap():l.click();
  await q.goto('http://lokal.lissom.no:8140/admin-ny#kalender');await q.getByRole('heading',{name:'Kalender',exact:true}).waitFor();
  if(mobil){
   // Mobil: datoen velges bak søkeikonet; ukestripa går over månedsskiftet (mandag 26. oktober).
   await q.locator('.kalm').waitFor();
   await trykk(q.getByRole('button',{name:'Søk i kalender',exact:true}));
   await q.getByLabel('Velg dato').fill('2026-11-01');
   await q.locator('.kalm-ukedag[aria-pressed="true"][aria-label*="1. november"]').waitFor();
   assert.equal(await q.locator('.kalm-uke .kalm-ukedag').count(),7);
   assert.match(await q.locator('.kalm-uke .kalm-ukedag').first().getAttribute('aria-label'),/26/);
  }else{
   // PC: segmentpille Uke, uke med tidsakse over månedsskiftet.
   const uke=q.getByRole('group',{name:'Kalendervisning'}).getByRole('button',{name:'Uke',exact:true});if(await uke.getAttribute('aria-pressed')!=='true')await uke.click();
   await Promise.all([q.waitForResponse(r=>r.url().includes('kalender.php?fra=2026-10-26')),q.getByLabel('Velg dato').fill('2026-11-01')]);
   await q.locator('.kp[data-visning="uke"] .kp-kolhode[data-dato="2026-10-26"]').waitFor();
   assert.equal(await q.locator('.kp[data-visning="uke"] .kp-kolhode').count(),7);
  }
  await trykk(q.getByRole('button',{name:'Nytt kalendernotat',exact:true}));
  if(mobil)await q.getByLabel('Dato',{exact:true}).fill('2026-11-01');
  await q.getByRole('textbox',{name:'Notat',exact:true}).fill(s.tag+' kalender');
  await trykk(q.getByRole('button',{name:'Lagre',exact:true}));
  await q.getByRole('dialog',{name:'Nytt kalendernotat',exact:true}).waitFor({state:'detached'});
  const event=q.locator(mobil?'button.kalm-kort':'.kp button.kp-brikke').filter({hasText:s.tag+' kalender'});await event.waitFor();await trykk(event);
  assert.equal(await q.getByRole('button',{name:'Start kurset',exact:true}).count(),0);
  await trykk(q.getByRole('button',{name:'Slett notat',exact:true}));
  await trykk(q.getByRole('dialog',{name:'Slett notat',exact:true}).getByRole('button',{name:'Slett notat',exact:true}));
  await event.waitFor({state:'detached'});
  assert.deepEqual(kfeil,[]);await k.close();
  console.log(`Kalender ${width} px: uke over månedsskifte, lagring av notat, riktig hendelsestype og sletting bestått.`);
 }
 await p.goto('http://lokal.lissom.no:8140/admin-ny#idag');
 await p.locator('.row-link').filter({hasText:'Dugnad'}).click();
 await p.getByRole('heading',{name:'Dugnad',exact:true}).waitFor();
 await p.goto('http://lokal.lissom.no:8140/admin-ny#idag');
 const usage=await p.evaluate(async()=>await(await fetch('/api/admin/kortbruk.php')).json());assert.ok(usage.bruk.dugnad.antall>=1);
 await p.getByRole('button',{name:'Tilbakestill rekkefølgen',exact:true}).click();
 await p.getByRole('dialog',{name:'Tilbakestill snarveiene?',exact:true}).getByRole('button',{name:'Tilbakestill',exact:true}).click();
 await p.waitForResponse(r=>r.url().includes('kortbruk.php')&&r.request().method()==='POST');
 const reset=await p.evaluate(async()=>await(await fetch('/api/admin/kortbruk.php')).json());assert.deepEqual(reset.bruk,[]);
 console.log('Personlige snarveier: faktisk registrering og nullstilling bestått.');
 let sends=0;await p.route('**/api/admin/pamelding.php',route=>{const body=route.request().postDataJSON();if(body.send)sends++;return route.fulfill({json:{ok:true,antall:1,liste:[{id:s.booking,navn:s.tag,epost:s.tag+'@e2e.lissom.test',kurs:s.tag,dato:'1. oktober'}]}});});
 await p.goto('http://lokal.lissom.no:8140/admin-ny#pameldte');await p.getByRole('button',{name:'Månedens kursbevis',exact:true}).click();
 await p.getByRole('button',{name:'Send til disse deltakerne',exact:true}).click();
 await p.getByRole('dialog',{name:'Send kursbevis?',exact:true}).getByRole('button',{name:'Avbryt',exact:true}).click();assert.equal(sends,0);
 console.log('Samlede kursbevis: mottakere forhåndsvises, avbrutt utsending sender ingenting (simulert API).');
 let delivered;const responseDone=new Promise(resolve=>delivered=resolve);await p.route('**/api/admin/medlemmer.php?person=*',async route=>{try{const response=await route.fetch({url:route.request().url().replace('lokal.lissom.no','127.0.0.1')});await new Promise(resolve=>setTimeout(resolve,400));await route.fulfill({response});}catch(e){errors.push('Forsinket testforespørsel feilet: '+e.message.split('Call log:')[0]);await route.abort();}finally{delivered();}});await p.goto('http://lokal.lissom.no:8140/admin-ny#folk?person='+s.admin);await p.getByRole('heading',{name:'Folk',exact:true}).waitFor();await p.locator('#mobil a[data-route="kurs"]').click();await responseDone;await p.getByRole('heading',{name:'Kurs',exact:true}).waitFor();await p.waitForTimeout(100);assert.equal(await p.locator('dialog').count(),0);console.log('Raskt sideskift: forsinket personforespørsel åpner ingen dialog på den nye siden.');
 assert.deepEqual(errors,[]);
} finally {await browser.close();fixture('cleanup',s);}
