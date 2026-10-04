// «Kjøpt i dag» på I dag viser kursdatoen og ledige plasser på kurskjøp (eieren 04.10.2026, GO).
import assert from 'node:assert/strict';import{createRequire}from'node:module';import{execFileSync}from'node:child_process';
const{chromium}=createRequire(import.meta.url)('playwright');
const fixture=(m,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',m,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed'),browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try{
 for(const width of[390,820,1280]){
  const c=await browser.newContext({viewport:{width,height:950}});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.goto('http://lokal.lissom.no:8140/admin-ny.html#idag');await p.getByRole('heading',{name:'Kjøpt i dag',exact:true}).waitFor();
  const d=await p.evaluate(async()=>await(await fetch('/api/admin/oversikt.php')).json());
  const b=d.dagensBestillinger.find(r=>r.slag==='kurs'&&r.id===s.booking);assert.ok(b,'testkjøpet står i dagens bestillinger');
  assert.ok(b.kursNaar&&b.kursNaar.length>5,'kursdato med');assert.equal(typeof b.ledige,'number');assert.ok(b.ledige>=0&&b.ledige<=8);
  const linje=p.locator('.row',{hasText:s.tag}).first();await linje.waitFor();const tekst=await linje.innerText();
  assert.ok(tekst.includes(b.kursNaar),'datoen vises: '+tekst);assert.ok(tekst.includes(b.ledige+' ledige'),'ledige vises: '+tekst);
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,width+' px');assert.deepEqual(errors,[]);
  console.log(width+' px: Kjøpt i dag viser «'+tekst.replace(/\s+/g,' ').trim()+'»');
  // Samme linje i gamle admin (Dagens bestillinger) — eieren 04.10: «du må endre overalt».
  await p.goto('http://lokal.lissom.no:8140/admin');const g=p.locator('.lx-ovrad',{hasText:s.tag}).first();await g.waitFor({timeout:20000});const tg=await g.innerText();
  assert.ok(tg.includes(b.kursNaar)&&tg.includes(b.ledige+' ledige'),'gamle admin: '+tg);
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,width+' px gamle admin');assert.deepEqual(errors,[]);
  console.log(width+' px: gamle admin viser kursdato og ledige.');await c.close();
 }
}finally{await browser.close();fixture('cleanup',s);}
