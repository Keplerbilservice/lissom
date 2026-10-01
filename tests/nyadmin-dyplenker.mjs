import assert from 'node:assert/strict';import {createRequire} from 'node:module';import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed'),b=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try{for(const width of [390,1280]){
 const c=await b.newContext({viewport:{width,height:900}});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
 for(const [sti,tittel,selector] of [
  ['/admin/oppskrifter?apne=vakter','Vakter'],['/admin/oppskrifter?apne=brenninger','Brenning'],['/admin/oppskrifter?apne=oppskrifter','Oppskrifter'],
  ['/admin/butikk?apne=handlelister','Handlelister'],['/admin/butikk?apne=bestillmer','Internbutikk'],
  ['/admin/ubesvarte?apne=dugnad','Dugnad','#admin-dugnad'],['/admin/oppskrifter?apne=synlighet','Synlighet','.lx-synark']]){
  await p.goto('http://lokal.lissom.no:8140/admin2#vis'+sti);await p.locator('iframe').waitFor();const f=await (await p.locator('iframe').elementHandle()).contentFrame();
  if(selector)await (selector==='.lx-synark'?f.locator(selector).filter({has:f.getByText('Synlighet',{exact:true})}):f.locator(selector)).waitFor();else await f.getByRole('heading',{name:tittel,exact:true}).waitFor();
  assert.match(p.url(),/\/admin2#/);console.log(`${width} px: ${tittel} åpner riktig innhold`);
 }
 await p.goto('http://lokal.lissom.no:8140/admin2#i-dag');await p.getByRole('button',{name:'○ Stemple inn',exact:true}).waitFor();let post=0;p.on('request',r=>{if(r.method()==='POST')post++;});await p.getByRole('button',{name:'○ Stemple inn',exact:true}).click();await p.getByRole('button',{name:'Avbryt',exact:true}).click();assert.equal(post,0,'Avbryt stempling gjør ingen endring');
 for(const [sti,navn] of [['kurs','Kurs'],['folk','Folk'],['penger','Penger'],['mer','Mer'],['i-dag','I dag']]){
  await p.locator((width<560?'nav.bunn':'nav.meny')+` a[href="#${sti}"]`).click();await p.getByRole('heading',{name:navn,exact:true}).waitFor();assert.equal(await p.locator('iframe').count(),0);assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);console.log(`${width} px: egen modul ${navn}`);
 }
 assert.deepEqual(errors,[]);await c.close();
}const c=await b.newContext(),p=await c.newPage();await p.goto('http://lokal.lissom.no:8140/admin2');await p.getByRole('heading',{name:'Logg inn først'}).waitFor();await c.close();console.log('Innlogging kreves, og avbryt er uten skriving.');
}finally{await b.close();fixture('cleanup',s);}
