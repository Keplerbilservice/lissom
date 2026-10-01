import fs from 'node:fs';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {STEDER} from '../nyadmin/steder.js';
const {chromium}=createRequire(import.meta.url)('playwright');
const kilde=fs.readFileSync('lissom-2108.html','utf8');
const ruter=[...kilde.matchAll(/\{ sti: '(\/admin[^']*)',\s*side: '([^']+)'/g)].map(m=>({sti:m[1],side:m[2]}));
const mangler=ruter.filter(r=>r.sti!='/admin/logg-inn'&&!STEDER.some(([,s])=>s.split('?')[0]===r.sti));
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed'),browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const rapport={dato:new Date().toISOString(),mangler,ruter:ruter.length,resultater:[]};
try{for(const width of [1280,390]){
 const c=await browser.newContext({viewport:{width,height:900}});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);await c.addInitScript(()=>{try{localStorage.setItem('lissom-samtykke','nei');}catch{}});
 const p=await c.newPage();let feil=[],apiFeil=[];p.on('pageerror',e=>feil.push(e.message));p.on('response',r=>{if(r.url().includes('/api/')&&r.status()>=400)apiFeil.push({sti:new URL(r.url()).pathname,status:r.status()});});
 for(const [navn,sti] of STEDER){
  if(sti==='/admin/logg-inn')continue;
  feil=[];apiFeil=[];let rad={width,navn,sti};
  try{
   await p.goto('http://lokal.lissom.no:8140/admin2#mer',{waitUntil:'domcontentloaded'});
   await p.getByRole('button',{name:width<560?'Søk':'⌕ Søk i admin',exact:true}).waitFor();
   if(sti.startsWith('#')){await p.locator((width<560?'nav.bunn':'nav.meny')+' a[href="'+sti+'"]').click();await p.waitForTimeout(1400);rad.skjerm=await p.locator('main').innerText();rad.ok=rad.skjerm.length>30;}
   else{
    await p.getByRole('button',{name:width<560?'Søk':'⌕ Søk i admin',exact:true}).click();await p.getByRole('textbox',{name:'Søk i admin'}).fill(navn);
    // const a=p.locator('[role=dialog] a').filter({hasText:navn}).filter({has:p.locator('span')});
    const link=p.locator('[role=dialog] a[href="'+sti+'"]');await link.click();
    await p.locator('iframe').waitFor();const ramme=await p.locator('iframe').elementHandle();const frame=await ramme.contentFrame();await frame.waitForLoadState('domcontentloaded');await p.waitForTimeout(1000);
    rad.adresse=frame.url();rad.skjerm=await frame.locator('body').innerText();rad.skjerm=rad.skjerm.slice(0,2000);
    rad.skjermer=await frame.locator("[data-screen-label]").evaluateAll(es=>es.filter(e=>e.getBoundingClientRect().width>0&&e.getBoundingClientRect().height>0).map(e=>e.getAttribute("data-screen-label")));
    const faktisk=new URL(rad.adresse).pathname,onsket=sti.split('?')[0];
    const riktig=faktisk===onsket||ruter.some(r=>r.sti===faktisk&&ruter.some(o=>o.sti===onsket&&o.side===r.side));
    rad.ok=riktig&&rad.skjerm.length>40&&!/Logg inn først|Du må være logget inn/.test(rad.skjerm);
    rad.vannrett=await frame.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2);
   }
  }catch(e){rad.ok=false;rad.feil=e.message.split('\n')[0];}
  rad.skriptfeil=[...feil];rad.apiFeil=[...apiFeil];if(feil.length)rad.ok=false;rapport.resultater.push(rad);fs.writeFileSync('C:/Users/info/kepler-lissom-analyse-20261001/nyadmin-uavhengig.json',JSON.stringify(rapport,null,2));
  console.log(`${width} ${rad.ok?'OK':'FEIL'} ${navn}${feil.length?' SKRIPTFEIL':''}${apiFeil.length?' API-FEIL':''}${rad.vannrett?' VANNRETT':''}`);
 }
 await c.close();
}}finally{await browser.close();fixture('cleanup',s);fs.writeFileSync('C:/Users/info/kepler-lissom-analyse-20261001/nyadmin-uavhengig.json',JSON.stringify(rapport,null,2));}
if(mangler.length||rapport.resultater.some(r=>!r.ok))process.exitCode=1;
