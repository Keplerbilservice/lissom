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
  await p.goto('http://lokal.lissom.no:8140/admin-ny.html#idag');await p.getByRole('heading',{name:'God oversikt. God arbeidsdag.'}).waitFor();

  // Stemple inn → ut, mot testbasen. Knappen synes i toppen også på mobil.
  const inn=p.getByRole('button',{name:'○ Stemple inn',exact:true});await inn.waitFor();assert.ok(await inn.isVisible(),`${width}: Stemple inn synes`);
  assert.equal(await status(),false);
  await inn.click();const ut=p.getByRole('button',{name:'● Stemple ut',exact:true});await ut.waitFor();
  assert.equal(await status(),true,'stemplet inn i basen');assert.match(await ut.getAttribute('title'),/^Verkstedet er åpent · siden \d\d:\d\d$/);
  await ut.click();await inn.waitFor();assert.equal(await status(),false,'stemplet ut i basen');
  console.log(`${width} px: Stemple inn → Stemple ut virker`);

  // I dag: Innboks blant oppgavene, og pillen når noe venter.
  await p.getByRole('link',{name:'Innboks →'}).waitFor();
  if(harTabell){const pille=p.getByRole('link',{name:/^\d+ ubesvarte i innboks$/});await pille.waitFor();assert.match(await pille.getAttribute('class'),/danger/);await pille.click();await p.getByRole('heading',{name:'Innboks · SoMe'}).waitFor();console.log(`${width} px: pillen åpner Innboks`);}

  // Markedsføring i menyen (PC: mellom Innhold og Alle funksjoner).
  if(width>=760){const navn=await p.locator('#meny .nav-link').allTextContents();const i=navn.findIndex(t=>t.includes('Markedsføring'));assert.ok(navn[i-1].includes('Innhold')&&navn[i+1].includes('Alle funksjoner'),navn.join('|'));await p.locator('#meny .nav-link[data-route="marked"]').click();}
  else await p.goto('http://lokal.lissom.no:8140/admin-ny.html#marked');
  await p.getByRole('heading',{name:'Markedsføring',exact:true}).waitFor();
  for(const n of ['SEO','GEO','Artikler','Referanser','Kursvelger','Videokurs','Mobilvisning','Tekstmaler'])assert.equal(await p.locator('main').getByRole('link',{name:n,exact:true}).count(),1,n);
  assert.equal(await p.locator('main').getByRole('link',{name:'Innboks',exact:true}).count(),0,'Innboks ligger ikke under Markedsføring');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  console.log(`${width} px: Markedsføring åpner med lenkene`);

  // SEO og GEO én gang: ikke i Innhold eller Alle funksjoner. Skisser og Oppskrifter bare under Verksted.
  await p.goto('http://lokal.lissom.no:8140/admin-ny.html#innhold');await p.getByRole('heading',{name:'Innhold og synlighet'}).waitFor();
  for(const n of ['SEO →','GEO →','Referanser →','Maler →'])assert.equal(await p.locator('main .directory a').filter({hasText:n}).count(),0,'Innhold: '+n);
  await p.goto('http://lokal.lissom.no:8140/admin-ny.html#alle');await p.getByRole('heading',{name:'Alle funksjoner'}).waitFor();
  const alle=await p.locator('main .directory strong').allTextContents();
  for(const n of ['SEO →','GEO →','Skisser →','Oppskrifter →'])assert.ok(!alle.includes(n),'Alle: '+n);
  assert.ok(alle.includes('Markedsføring →'));
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
