// Admin-ny 03.10: «Stemple inn / ut» i toppen, Markedsføring i menyen,
// Innboks blant oppgavene på I dag, og Oppskrifter / Keramikk maler / Skisser
// som kort under Verksted. Kjøres via tests/nettleser/kjor.sh (testbasen).
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
// Én ventende kommentar i innboksen, så pillen på I dag kan sees. Fjernes etterpå.
const php=kode=>execFileSync('php',['-r',`require 'tests/nettleser/testdatabase.php';krev_testdatabase(getcwd());require 'app/bootstrap.php';${kode}`],{encoding:'utf8'});
const kid='e2e-stemple-'+Date.now();
const s=fixture('seed');const b=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const harTabell=php(`echo DB::harTabell('meta_kommentarer')?'ja':'nei';`)==='ja';
try{
 if(harTabell)php(`DB::settInn('meta_kommentarer',['kommentar_id'=>'${kid}','kanal'=>'Instagram','status'=>'venter','kommentar'=>'Test']);`);
 for(const width of [390,1280]){
  const c=await b.newContext({viewport:{width,height:900},...(width<760?{hasTouch:true,isMobile:true}:{})});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
  const status=()=>p.evaluate(async()=>(await (await fetch('/api/stempling.php',{credentials:'same-origin',cache:'no-store'})).json()).innstemplet);
  await p.goto('http://lokal.lissom.no:8140/admin-ny.html#idag');await p.getByRole('heading',{name:'I dag',exact:true}).waitFor();

  // Stemple inn → ut, mot testbasen. Knappen synes i toppen også på mobil.
  const inn=p.getByRole('button',{name:'○ Stemple inn',exact:true});await inn.waitFor();assert.ok(await inn.isVisible(),`${width}: Stemple inn synes`);
  assert.equal(await status(),false);
  await inn.click();const ut=p.getByRole('button',{name:'● Stemple ut',exact:true});await ut.waitFor();
  assert.equal(await status(),true,'stemplet inn i basen');assert.match(await ut.getAttribute('title'),/^Verkstedet er åpent · siden \d\d:\d\d$/);
  await ut.click();await inn.waitFor();assert.equal(await status(),false,'stemplet ut i basen');
  console.log(`${width} px: Stemple inn → Stemple ut virker`);

  // I dag (enklere admin 08.10.2026): innboksen er en sak i flisen «Meldinger» under «Må gjøres», med rødt merke når noe venter.
  if(harTabell){const flis=p.locator('main [data-gruppe="Meldinger"]');await flis.locator('.flis-merke').waitFor();await flis.click();await p.locator('.sak',{hasText:/^\d+ ubesvarte på Instagram og Facebook/}).click();await p.locator('dialog').last().getByRole('link',{name:'Svar',exact:true}).click();await p.getByRole('heading',{name:'Innboks · SoMe'}).waitFor();console.log(`${width} px: saken åpner Innboks`);}

  // Menyen har fem faste valg; Markedsføring nås fra Mer (enklere admin 08.10.2026).
  if(width>=760)assert.deepEqual((await p.locator('#meny .nav-link').allTextContents()).map(t=>t.trim().slice(1).trim()),['I dag','Kalender','Folk','Penger','Mer']);
  await p.locator(width>=760?'#meny .nav-link[data-route="mer"]':'#mobil a[data-route="mer"]').click();await p.locator('main a.flis').filter({hasText:'Markedsføring'}).click();
  await p.getByRole('heading',{name:'Markedsføring',exact:true}).waitFor();
  for(const n of ['SEO per side','GEO per side','Artikler','Referanser','Kursvelger','Videokurs','Mobilvisning','Tekstmaler'])assert.equal(await p.locator('main').getByRole('link',{name:n,exact:true}).count(),1,n);
  assert.equal(await p.locator('main').getByRole('link',{name:'Innboks',exact:true}).count(),0,'Innboks ligger ikke under Markedsføring');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  console.log(`${width} px: Markedsføring åpner med lenkene`);

  // SEO og GEO én gang: ikke i Innhold eller Oppsett. Skisser og Oppskrifter bare under Verksted.
  await p.goto('http://lokal.lissom.no:8140/admin-ny.html#innhold');await p.getByRole('heading',{name:'Innhold og synlighet'}).waitFor();
  for(const n of ['SEO','GEO','Referanser','Maler'])assert.equal(await p.locator('main .directory .flis-tittel').filter({hasText:new RegExp('^'+n+'$')}).count(),0,'Innhold: '+n);
  await p.goto('http://lokal.lissom.no:8140/admin-ny.html#oppsett');await p.getByRole('heading',{name:'Oppsett'}).waitFor();
  const alle=await p.locator('main .directory .flis-tittel').allTextContents();
  for(const n of ['SEO','GEO','Skisser','Oppskrifter'])assert.ok(!alle.includes(n),'Oppsett: '+n);
  await p.goto('http://lokal.lissom.no:8140/admin-ny.html#verksted');await p.getByRole('heading',{name:'Verksted',exact:true}).waitFor();
  const kort=await p.locator('main .verksted-kort strong').allTextContents();assert.deepEqual(kort.slice(0,3),['Oppskrifter →','Keramikk maler →','Skisser →']);/* Handlelister og Chat er kort under Verksted når de er slått på (eieren 04.10, cb6112e og 64fc7b9). */assert.ok(kort.slice(3).every(k=>['Handlelister →','Chat →'].includes(k)),'Verksted: '+kort.join(', '));
  assert.equal(await p.locator('main').getByRole('link',{name:'Oppskrifter',exact:true}).count(),0,'Oppskrifter står én gang');
  await p.locator('main .verksted-kort a').first().click();await p.waitForURL(/#oppskrifter$/);
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  console.log(`${width} px: Verksted har Oppskrifter, Keramikk maler og Skisser`);
  assert.deepEqual(errors,[]);await c.close();
 }
}finally{await b.close();if(harTabell)php(`DB::kjor("DELETE FROM meta_kommentarer WHERE kommentar_id=:k",['k'=>'${kid}']);`);fixture('cleanup',s);}
console.log('OK');
