import assert from 'node:assert/strict';import{createRequire}from'node:module';import{execFileSync}from'node:child_process';
const{chromium}=createRequire(import.meta.url)('playwright');const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed'),browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try{
 const c=await browser.newContext();await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);const p=await c.newPage();let aiCalls=0;
 await p.route('**/api/admin/ai.php',route=>{aiCalls++;assert.equal(route.request().postDataJSON().handling,'kursbeskrivelse');return route.fulfill({json:{ok:true,tekst:s.tag+' kontrollert tekst',kostnad:'kr 0,00'}});});
 await p.goto('http://lokal.lissom.no:8140/admin-ny#kurs');await p.getByRole('searchbox',{name:'Søk etter kurs',exact:true}).fill(s.tag);
 await p.getByRole('button',{name:'Kursinnstillinger',exact:true}).click();await p.getByRole('button',{name:'Tekstforslag',exact:true}).click();await p.getByRole('button',{name:'Lag forslag',exact:true}).click();
 await p.getByRole('dialog',{name:'Lag tekstforslag?',exact:true}).getByRole('button',{name:'Avbryt',exact:true}).click();assert.equal(aiCalls,0);
 await p.getByRole('button',{name:'Lag forslag',exact:true}).click();await p.getByRole('dialog',{name:'Lag tekstforslag?',exact:true}).getByRole('button',{name:'Lag forslag',exact:true}).click();
 await p.getByRole('dialog',{name:'Kontroller tekstforslaget',exact:true}).waitFor();assert.equal(aiCalls,1);
 await p.getByRole('button',{name:'Lagre',exact:true}).click();await p.getByRole('dialog',{name:'Publiser teksten?',exact:true}).getByRole('button',{name:'Publiser',exact:true}).click();
 await p.getByRole('dialog',{name:'Kontroller tekstforslaget',exact:true}).waitFor({state:'detached'});
 const course=await p.evaluate(async id=>(await(await fetch('/api/admin/kurs.php')).json()).kurs.find(r=>r.id===id),s.course);assert.equal(course.om,s.tag+' kontrollert tekst');assert.equal(course.kapasitet,8);assert.equal(course.pris,100);
 console.log('Tekstforslag: avbryt bruker ingen AI; simulert forslag kontrolleres og lagres på eget testkurs uten å endre pris eller kapasitet.');
 await p.route('**/api/admin/marked.php?utkast=999999',route=>route.fulfill({json:{utkast:{id:999999,type:'nyhetsbrev',tittel:s.tag+' brev',tekst:'Syntetisk melding for forhåndsvisning.',bilde:''}}}));
 let sends=0;await p.route('**/api/admin/beskjed.php',async route=>{if(route.request().postDataJSON().handling!=='forhandsvis'){sends++;return route.fulfill({json:{ok:true}});}return route.continue();});
 await p.goto('http://lokal.lissom.no:8140/admin-ny#beskjeder?utkast=999999');await p.getByRole('button',{name:'Skriv beskjed',exact:true}).click();assert.equal(await p.getByLabel('Emne',{exact:true}).inputValue(),s.tag+' brev');assert.equal(await p.getByLabel('Mottakere',{exact:true}).inputValue(),'medlemmer');
 await p.getByRole('button',{name:'Forhåndsvis',exact:true}).click();await p.getByRole('dialog',{name:'Kontroller før utsending',exact:true}).waitFor();await p.getByRole('button',{name:'Send beskjed',exact:true}).click();await p.getByRole('dialog',{name:'Send beskjed?',exact:true}).getByRole('button',{name:'Avbryt',exact:true}).click();assert.equal(sends,0);
 console.log('Nyhetsbrev: utkastets emne og tekst følger med til mottakervalg og faktisk HTML-forhåndsvisning. Avbrutt utsending sender ingenting.');
}finally{await browser.close();fixture('cleanup',s);}
