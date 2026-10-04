// Toppen i admin-ny (eieren 04.10.2026, GO): diskret søk til venstre, Stemple og Ovn samlet til høyre ved Logg ut,
// «Hvem er inne?» bare på I dag. Mobil: knappene først, søket under.
import assert from 'node:assert/strict';import{createRequire}from'node:module';import{execFileSync}from'node:child_process';
const{chromium}=createRequire(import.meta.url)('playwright');
const fixture=(m,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',m,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed'),browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try{
 for(const width of[390,820,1280]){
  const c=await browser.newContext({viewport:{width,height:900}});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.goto('http://lokal.lissom.no:8140/admin-ny.html#idag');await p.getByRole('heading',{name:'Kjøpt i dag',exact:true}).waitFor();
  const topp=p.locator('#topp');await topp.locator('[data-stemple]').waitFor();
  assert.equal(await topp.locator('[data-inne]').count(),0,'Hvem er inne? er ikke i toppen');
  assert.equal(await topp.getByText(/Hvem er inne/).count(),0);
  await p.getByRole('heading',{name:'Hvem er inne?',exact:true}).waitFor();// kortet på I dag står
  const m=await p.evaluate(()=>{const r=e=>document.querySelector(e).getBoundingClientRect();const i=document.querySelector('.search-wrap input'),cs=getComputedStyle(i);
   return{sok:r('.search-wrap'),hoyre:r('.topp-hoyre'),stemple:r('[data-stemple]'),ovn:r('[data-ovn]'),bg:cs.backgroundColor,kant:cs.borderTopWidth,bunn:cs.borderBottomWidth,vw:innerWidth,rull:document.documentElement.scrollWidth<=innerWidth};});
  assert.equal(m.rull,true,width+' px: ingen sideveis rulling');
  assert.equal(m.bg,'rgba(0, 0, 0, 0)','søket har ingen fylt boks');assert.equal(m.kant,'0px');assert.equal(m.bunn,'1px','bare en tynn strek under');
  assert.ok(Math.abs(m.stemple.top-m.ovn.top)<2,'Stemple og Ovn på samme linje');
  if(width>760){assert.ok(m.sok.width<=381,'søket er smalt');assert.ok(m.vw-m.hoyre.right<60,'knappene står til høyre');assert.ok(m.stemple.left>m.sok.right,'Stemple etter søket');await topp.getByRole('button',{name:'Logg ut',exact:true}).waitFor();}
  else{assert.ok(m.hoyre.bottom<=m.sok.top+1,'mobil: knappene over søket');}
  // Søket virker som før.
  await p.getByRole('searchbox',{name:'Søk etter personer og funksjoner'}).fill('kurs');await p.locator('.search-results a').first().waitFor();
  assert.equal(await p.evaluate(()=>getComputedStyle(document.querySelector('.search-wrap input')).backgroundColor)!=='rgba(0, 0, 0, 0)',true,'boksen kommer når du trykker i søket');
  if(width===1280)await p.screenshot({path:`/tmp/toppen-${width}.png`}).catch(()=>{});
  assert.deepEqual(errors,[]);console.log(width+' px: toppen — diskret søk, Stemple og Ovn samlet, Hvem er inne? bare på I dag.');await c.close();
 }
}finally{await browser.close();fixture('cleanup',s);}
