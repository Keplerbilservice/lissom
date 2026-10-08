// Penger i admin-ny: omsetningen uten mva og hvor pengene kommer fra (eieren, 8. oktober 2026).
// Fanene «I dag», «Denne måneden» og «Forrige måned»; stort tall «Omsetning uten mva»; kortet
// «Hvor pengene kommer fra» med de seks kildene (Kurs, Paint on Pots, Medlemskap, Nettbutikk,
// Kasse i verkstedet, Gavekort), hver med beløp uten mva og en stolpe. Sjekker tallene mot
// oversikt.php, at kildene summerer til tallet, at en kilde åpnes og viser salgene bak, at
// ingen mva-tekst står på siden, og at mobil (390) ikke ruller sideveis. Skjermbilder ved 390 og 1280.
//
//   bash tests/nettleser/kjor.sh admin-ny   (eller alene med serveren oppe)
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {mkdirSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
const {chromium}=createRequire(import.meta.url)('playwright');
const adresse=process.env.E2E_ADRESSE||'http://lokal.lissom.no:8140';
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'})||'null');
const s=fixture('seed');
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const root=process.env.LISSOM_TEST_RAPPORT||join(tmpdir(),'lissom-nyadmin-kontroll');mkdirSync(root,{recursive:true});
const kr=t=>Number(String(t).replace(/[^\d-]/g,''));
const krOre=o=>Math.round(o/100);
const KILDER=['Kurs','Paint on Pots','Medlemskap','Nettbutikk','Kasse i verkstedet','Gavekort'];
try{
 for(const width of [390,1280]){
  const c=await browser.newContext({viewport:{width,height:950}});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.goto(`${adresse}/admin-ny.html#penger`);
  await p.getByRole('heading',{name:'Penger',exact:true}).waitFor();
  await p.getByRole('heading',{name:'Hvor pengene kommer fra',exact:true}).waitFor();
  const api=await p.evaluate(async()=>(await (await fetch('/api/admin/oversikt.php')).json()).omsetning);
  assert.deepEqual(await p.locator('[data-periode]').allInnerTexts(),['I dag','Denne måneden','Forrige måned']);
  for(const [periode,navn] of [['idag','I dag'],['maned','Denne måneden'],['forrige','Forrige måned']]){
   const fane=p.locator(`[data-periode="${periode}"]`);await fane.click();
   assert.equal(await fane.getAttribute('aria-pressed'),'true',`${navn}: fanen er valgt`);
   const k=api.kilder[periode];
   assert.deepEqual(k.kilder.map(x=>x.navn),KILDER);
   assert.equal(k.kilder.reduce((a,x)=>a+x.eksOre,0),k.sumEksOre,`${navn}: kildene summerer til omsetningen (øre)`);
   const sum=await p.locator('.penger-sum .stat').innerText();
   assert.equal(kr(sum),krOre(k.sumEksOre),`${navn}: stort tall = omsetning uten mva`);
   const rader=await p.locator('.penger-kilder button.kilde').evaluateAll(bs=>bs.map(b=>({navn:b.querySelector('strong').textContent,belop:b.querySelector('.num').textContent,stolpe:!!b.querySelector('.kilde-stolpe>span')})));
   assert.deepEqual(rader.map(r=>r.navn),KILDER);
   for(const [j,r] of rader.entries()){assert.equal(kr(r.belop),krOre(k.kilder[j].eksOre),`${navn} ${r.navn}: beløp`);assert.ok(r.stolpe,`${navn} ${r.navn}: stolpe`);}
   if(k.sumEksOre===0)assert.equal(kr(sum),0,`${navn}: tom periode viser kr 0`);
   assert.doesNotMatch(await p.locator('#arbeid').innerText(),/mva å betale|inkl\. mva|mva-sats/i,`${navn}: ingen mva-tekst`);
  }
  // Åpne «Kurs» i dag: testbookingen (kr 100, kurs uten mva) står med dato og hva.
  await p.locator('[data-periode="idag"]').click();
  const knapp=p.locator('.penger-kilder button.kilde[data-kilde="kurs"]');
  await knapp.click();assert.equal(await knapp.getAttribute('aria-expanded'),'true');
  const post=p.locator('.kilde-post').filter({hasText:s.tag});
  await post.waitFor();const ptekst=await post.innerText();
  assert.match(ptekst,/\d\d\.\d\d\. \d\d:\d\d/);assert.match(ptekst,/kr/);
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen sideveis rulling');
  await p.screenshot({path:`${root}/penger-${width}.png`,fullPage:true});
  await knapp.click();assert.equal(await knapp.getAttribute('aria-expanded'),'false');assert.equal(await post.isVisible(),false);
  assert.ok(await p.getByRole('link',{name:'Medlemmer som mangler betaling'}).isVisible(),'Mangler betaling står fortsatt på Penger');
  assert.deepEqual(errors,[]);
  console.log(`Penger ${width}: tre faner, omsetning uten mva, seks kilder med stolpe, kilde åpnes med salgene.`);
  await c.close();
 }
}finally{await browser.close();fixture('cleanup',s);}
console.log('Skjermbilder i '+root+'.');
