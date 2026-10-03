// Penger i admin-ny: «I dag» og «Denne måneden» som tabeller med
// Ekskl. mva | Mva | Inkl. mva per kategori og en Sum-rad (eieren, 3. oktober 2026).
// Sjekker at hver rad går opp (ekskl. + mva = inkl.), at radene summerer til
// Sum-raden, at tallene er de samme som oversikt.php gir, og at mobil (390)
// ikke ruller sideveis. Skjermbilder ved 390 og 1280.
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
const port=new URL(adresse).port;
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'})||'null');
const s=fixture('seed');
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const root=process.env.LISSOM_TEST_RAPPORT||join(tmpdir(),'lissom-nyadmin-kontroll');mkdirSync(root,{recursive:true});
const kr=t=>Number(String(t).replace(/[^\d-]/g,''));
try{
 for(const width of [390,1280]){
  const c=await browser.newContext({viewport:{width,height:950}});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.goto(`${adresse}/admin-ny.html#penger`);
  await p.getByRole('heading',{name:'Penger',exact:true}).waitFor();
  await p.locator('table.mva-table').nth(1).waitFor();
  const api=await p.evaluate(async()=>(await (await fetch('/api/admin/oversikt.php')).json()).omsetning);
  const tabeller=await p.locator('section.card').evaluateAll(cs=>cs.filter(c=>c.querySelector('table.mva-table')).map(c=>({tittel:c.querySelector('h2').textContent,hode:[...c.querySelectorAll('thead th')].map(t=>t.textContent),rader:[...c.querySelectorAll('tbody tr, tfoot tr')].map(r=>({navn:r.querySelector('th').textContent,tall:[...r.querySelectorAll('td')].map(t=>t.textContent),tab:getComputedStyle(r.querySelector('td')).fontVariantNumeric}))})));
  assert.deepEqual(tabeller.map(t=>t.tittel),['I dag','Denne måneden']);
  for(const [i,t] of tabeller.entries()){
   assert.deepEqual(t.hode,['Kategori','Ekskl. mva','Mva','Inkl. mva']);
   const sum=t.rader.at(-1);assert.equal(sum.navn,'Sum');
   const linjer=i===0?api.linjerIdag:api.linjerMnd;
   assert.deepEqual(t.rader.slice(0,-1).map(r=>r.navn),linjer.map(l=>l.navn));
   for(const r of t.rader){
    const [eks,mva,inkl]=r.tall.map(kr);
    assert.equal(eks+mva,inkl,`${t.tittel} ${r.navn}: ekskl. + mva = inkl.`);
    assert.match(r.tab,/tabular-nums/);
   }
   const kol=k=>t.rader.slice(0,-1).reduce((a,r)=>a+kr(r.tall[k]),0);
   const [sE,sM,sI]=sum.tall.map(kr);
   // Kronene er avrundet hver for seg; radene kan avvike med høyst 1 kr per rad.
   for(const [k,v] of [[0,sE],[1,sM],[2,sI]])assert.ok(Math.abs(kol(k)-v)<=t.rader.length,`${t.tittel}: radene summerer til Sum`);
   const [e,m,b]=i===0?[api.idagEksOre,api.idagMvaOre,api.idagOre]:[api.manedEksOre,api.manedMvaOre,api.manedOre];
   assert.equal(e+m,b,`${t.tittel}: API ekskl. + mva = inkl. (øre)`);
   assert.equal(sI,Math.round(b/100));
  }
  assert.ok(api.linjerIdag.some(l=>l.nokkel==='booking'),'Testbookingen i dag står under Kurs og events');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen sideveis rulling');
  if(width===390){
   const td=await p.locator('table.mva-table tbody td').first().evaluate(n=>({d:getComputedStyle(n).display,f:getComputedStyle(n,'::before').content}));
   assert.equal(td.d,'flex');assert.equal(td.f,'"Ekskl. mva"');
  }
  await p.screenshot({path:`${root}/penger-${width}.png`,fullPage:true});
  assert.deepEqual(errors,[]);
  console.log(`Penger ${width}: to tabeller, ekskl. + mva = inkl. på alle rader (${tabeller.map(t=>t.rader.length).join('+')} rader).`);
  await c.close();
 }
}finally{await browser.close();fixture('cleanup',s);}
console.log('Skjermbilder i '+root+' (port '+port+').');
