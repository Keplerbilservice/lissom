// Penger i admin-ny: mva-en og hvor den kommer fra (eieren, 3. oktober 2026).
// «I dag» og «Denne måneden»: «Mva å betale», «Omsetning inkl. mva», og en
// tabell Kategori | Mva-sats | Grunnlag (ekskl. mva) | Mva med Sum-rad.
// Sjekker tallene mot oversikt.php, at radene summerer til Sum («Annet» tar
// differansen), at en rad kan åpnes og viser betalingene bak, og at mobil
// (390) ikke ruller sideveis. Skjermbilder ved 390 og 1280.
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
try{
 for(const width of [390,1280]){
  const c=await browser.newContext({viewport:{width,height:950}});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.goto(`${adresse}/admin-ny.html#penger`);
  await p.getByRole('heading',{name:'Penger',exact:true}).waitFor();
  await p.locator('table.mva-table').nth(1).waitFor();
  const api=await p.evaluate(async()=>(await (await fetch('/api/admin/oversikt.php')).json()).omsetning);
  const kort=await p.locator('section.card').evaluateAll(cs=>cs.filter(c=>c.querySelector('table.mva-table')).map(c=>({tittel:c.querySelector('h2').textContent,due:c.querySelector('.mva-due').textContent,inkl:c.querySelector('.mva-due+p').textContent,hode:[...c.querySelectorAll('thead th')].map(t=>t.textContent),rader:[...c.querySelectorAll('tbody tr:not(.mva-detail), tfoot tr')].map(r=>({navn:r.querySelector('th').textContent,tall:[...r.querySelectorAll('td')].map(t=>t.textContent),tab:getComputedStyle(r.querySelector('td:last-child')).fontVariantNumeric}))})));
  assert.deepEqual(kort.map(t=>t.tittel),['I dag','Denne måneden']);
  for(const [i,t] of kort.entries()){
   assert.deepEqual(t.hode,['Kategori','Mva-sats','Grunnlag (ekskl. mva)','Mva']);
   const linjer=i===0?api.linjerIdag:api.linjerMnd;
   const det=i===0?api.detaljerIdag:api.detaljerMnd;
   const [e,m,b]=i===0?[api.idagEksOre,api.idagMvaOre,api.idagOre]:[api.manedEksOre,api.manedMvaOre,api.manedOre];
   assert.equal(e+m,b,`${t.tittel}: API ekskl. + mva = inkl. (øre)`);
   assert.match(t.due,/^Mva å betale: /);assert.equal(kr(t.due),krOre(m));
   assert.match(t.inkl,/^Omsetning inkl\. mva: /);assert.equal(kr(t.inkl),krOre(b));
   const sumL=k=>linjer.reduce((a,l)=>a+(k==='mva'?(l.mvaOre??0):(l.eksOre??l.ore-(l.mvaOre??0))),0);
   const rest=[e-sumL('eks'),m-sumL('mva')];const harAnnet=rest.some(x=>x!==0);
   const sum=t.rader.at(-1);assert.equal(sum.navn,'Sum');
   assert.deepEqual(t.rader.slice(0,-1).map(r=>r.navn.replace(/\s*$/,'')),[...linjer.map(l=>l.navn),...(harAnnet?['Annet']:[])]);
   for(const [j,l] of linjer.entries()){
    const r=t.rader[j];
    assert.equal(r.tall[0],l.nokkel==='gavekort'?'Utenfor mva':l.mvaSats>0?`${l.mvaSats} %`:'0 % (fritatt)');
    assert.deepEqual([kr(r.tall[1]),kr(r.tall[2])],[krOre(l.eksOre),krOre(l.mvaOre)]);
    assert.equal((l.eksOre)+(l.mvaOre),l.ore,`${l.navn}: grunnlag + mva = inkl.`);
    assert.match(r.tab,/tabular-nums/);
    // Betalingene bak raden summerer til raden (inkl.), og mva per betaling er fra samme sats.
    const poster=det[l.nokkel]||[];assert.ok(poster.length>0,`${l.navn}: har betalinger bak`);
    assert.equal(poster.reduce((a,x)=>a+x.belopOre,0),l.ore,`${l.navn}: betalingene = raden`);
    assert.ok(Math.abs(poster.reduce((a,x)=>a+x.mvaOre,0)-l.mvaOre)<=poster.length,`${l.navn}: mva per betaling ≈ radens mva (øreavrunding)`);
   }
   if(harAnnet)assert.deepEqual(t.rader.at(-2).tall.slice(1).map(kr),rest.map(krOre));
   assert.deepEqual([kr(sum.tall[1]),kr(sum.tall[2])],[krOre(e),krOre(m)]);
   const kol=k=>t.rader.slice(0,-1).reduce((a,r)=>a+kr(r.tall[k]),0);
   assert.ok(Math.abs(kol(2)-kr(sum.tall[2]))<=t.rader.length,`${t.tittel}: mva-radene summerer til Sum`);
  }
  // Åpne «Kurs og events» i dag: testbookingen står med dato, hva, beløp og mva.
  const iDag=p.locator('section.card').filter({has:p.getByRole('heading',{name:'I dag',exact:true})});
  const knapp=iDag.getByRole('button',{name:'Kurs og events'});
  await knapp.click();assert.equal(await knapp.getAttribute('aria-expanded'),'true');
  const post=iDag.locator('.mva-post').filter({hasText:s.tag});
  await post.waitFor();const ptekst=await post.innerText();
  assert.match(ptekst,/\d\d\.\d\d\. \d\d:\d\d/);assert.match(ptekst,/100\s?kr/);assert.match(ptekst,/mva/);
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen sideveis rulling');
  assert.equal(await p.locator('table.mva-table').evaluateAll(ts=>ts.every(t=>t.getBoundingClientRect().right<=t.closest('.card').getBoundingClientRect().right-1)),true,'tabellen holder seg inne i kortet');
  if(width===390){
   const td=await p.locator('table.mva-table tbody tr:not(.mva-detail) td').first().evaluate(n=>({d:getComputedStyle(n).display,f:getComputedStyle(n,'::before').content}));
   assert.equal(td.d,'flex');assert.equal(td.f,'"Mva-sats"');
  }
  await p.screenshot({path:`${root}/penger-${width}.png`,fullPage:true});
  await knapp.click();assert.equal(await knapp.getAttribute('aria-expanded'),'false');assert.equal(await post.isVisible(),false);
  assert.deepEqual(errors,[]);
  console.log(`Penger ${width}: mva å betale, sats/grunnlag/mva per kategori, rad åpnes med betalingene (${kort.map(t=>t.rader.length).join('+')} rader).`);
  await c.close();
 }
}finally{await browser.close();fixture('cleanup',s);}
console.log('Skjermbilder i '+root+'.');
