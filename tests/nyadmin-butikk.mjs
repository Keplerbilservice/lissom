import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed'),browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});let p;
try{
 const c=await browser.newContext({viewport:{width:390,height:900}});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);p=await c.newPage();
 /* «Ny vare» ligger i «+» (eieren 08.10.2026): #butikk?ny=1 åpner skjemaet, og ?ny=1 fjernes fra adressen. */await p.goto('http://lokal.lissom.no:8140/admin-ny#butikk?ny=1');await p.getByRole('dialog',{name:'Ny vare',exact:true}).waitFor();assert.equal(new URL(p.url()).hash,'#butikk');
 await p.getByLabel('Varenavn',{exact:true}).fill(s.tag);await p.getByLabel('Pris i kroner',{exact:true}).fill('100');await p.getByLabel('Antall på lager',{exact:true}).fill('3');await p.getByLabel('Synlighet',{exact:true}).selectOption('publisert');
 await p.getByRole('button',{name:'Lagre',exact:true}).click();await p.getByRole('dialog',{name:'Publiser varen?',exact:true}).getByRole('button',{name:'Publiser',exact:true}).click();
 await p.getByRole('dialog',{name:'Ny vare',exact:true}).waitFor({state:'detached'});
 const product=await p.evaluate(async tag=>(await(await fetch('/api/admin/produkter.php')).json()).varer.find(r=>r.tittel===tag),s.tag);assert.equal(product.pris,100);assert.equal(product.lager,3);
 await p.goto('http://lokal.lissom.no:8140/admin-ny#kasse');await p.getByRole('searchbox',{name:'Søk etter vare',exact:true}).fill(s.tag);
 await p.getByRole('button',{name:'Legg til',exact:true}).click();const basket=p.locator('section.card').filter({has:p.getByRole('heading',{name:'Denne kunden',exact:true})});assert.match(await basket.innerText(),new RegExp(s.tag));assert.equal(await basket.getByRole('button',{name:'+',exact:true}).count(),1);
 await basket.getByRole('button',{name:'+',exact:true}).click();assert.match(await basket.innerText(),/2 ×/);await basket.getByRole('button',{name:'−',exact:true}).click();assert.match(await basket.innerText(),/1 ×/);
 assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 console.log('Butikk: faktisk opprettelse og tilbake-lesing av pris/lager; handlekurv viser varer, mengde og knapper på mobil. Ingen salg eller betaling ble utført.');
}finally{
 if(p&&!p.isClosed())await p.evaluate(async tag=>{const d=await(await fetch('/api/admin/produkter.php')).json();for(const r of d.varer.filter(r=>r.tittel===tag))await fetch('/api/admin/produkter.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({handling:'slett',id:r.id})});},s.tag);
 await browser.close();fixture('cleanup',s);
}
