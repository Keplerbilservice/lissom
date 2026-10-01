import {tmpdir} from 'node:os';import {join} from 'node:path';const report=process.env.LISSOM_TEST_RAPPORT||(process.platform==='win32'?'C:/Users/info/kepler-lissom-analyse-20261001':join(tmpdir(),'lissom-nyadmin-kontroll'));
﻿import assert from 'node:assert/strict';import {createRequire} from 'node:module';import {execFileSync} from 'node:child_process';
const req=createRequire(import.meta.url),{chromium}=req('playwright');const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try{for(const width of [390,1280]){
 const s=fixture('seed');fixture('member',s);fixture('paid',s);const c=await browser.newContext({viewport:{width,height:900}});const errors=[];
 try{await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);await c.addInitScript(()=>localStorage.setItem('lissom-samtykke','nei'));
 const p=await c.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto('http://lokal.lissom.no:8140/min-side');await p.getByRole('switch',{name:'Stemple inn og timene dine: vis/skjul',exact:true}).waitFor();await p.waitForTimeout(1500);
 assert.equal(await p.locator('[data-tp-vindu]').count(),0,'Timepakke skal ikke sperre inneværende økt');
 assert.ok(await p.locator('[data-ms-modul]').count()>=10,'Fullstendige moduler på forsiden');
 for(const id of ['stempel','abonnement','handleliste','butikk','chat','hms']){assert.equal(await p.locator('[data-ms-modul="'+id+'"]').isVisible(),true,id+' vises');}
 const chat=p.locator('[data-ms-modul="chat"]');await chat.getByRole('switch').click();await assert.equal(await chat.getByRole('switch').getAttribute('aria-checked'),'false');assert.equal(await p.locator('#minside-chat textarea').count(),0);
 await p.reload();await p.getByRole('switch',{name:'Medlemschat: vis/skjul',exact:true}).waitFor();assert.equal(await p.getByRole('switch',{name:'Medlemschat: vis/skjul',exact:true}).getAttribute('aria-checked'),'false','Valget beholdes ved reload');
 await p.getByRole('switch',{name:'Medlemschat: vis/skjul',exact:true}).click();assert.equal(await p.getByRole('switch',{name:'Medlemschat: vis/skjul',exact:true}).getAttribute('aria-checked'),'true');
 assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Ingen vannrett rulling');
 await p.screenshot({path:'C:/Users/info/kepler-lissom-analyse-20261001/minside-oversikt-'+width+'.png',fullPage:true});
 await p.getByRole('button',{name:'Stemple ut',exact:true}).click();await p.getByRole('button',{name:'Bekreft',exact:true}).click();await p.waitForTimeout(1800);await p.getByText('Du er stemplet ut',{exact:true}).waitFor({timeout:5000});
 const d=await p.evaluate(async()=>await (await fetch('/api/stempling.php')).json());assert.equal(d.innstemplet,false,'Økten faktisk avsluttet');assert.equal(await p.locator('[data-tp-vindu]').count(),0,'Kvittering blir ikke skjult av timepakke');
 await p.screenshot({path:'C:/Users/info/kepler-lissom-analyse-20261001/minside-moduler-'+width+'.png',fullPage:true});assert.deepEqual(errors,[]);console.log(width+' px: moduler, lagret bryter, full utstempling og ingen skriptfeil bestått.');
 }finally{await c.close();fixture('cleanup',s);}
}}finally{await browser.close();}
