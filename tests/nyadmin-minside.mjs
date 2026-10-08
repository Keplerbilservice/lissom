// admin-ny: SEO/GEO/Tekster henter kartene bak krev_admin (ikke .json rett),
// og «Min side» har bryter per modul + «Se som medlem» (eieren 3. oktober 2026).
// Fra 8. oktober 2026: #minside peker til #medlemmene (admin-ny/medlemmene.js).
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'})||'{}');
const ADR='http://lokal.lissom.no:8140';
const admin=fixture('seed'),medlem=fixture('seed');fixture('member',medlem);fixture('paid',medlem);
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try{
 for(const width of [390,1280]){
  const c=await browser.newContext({viewport:{width,height:950}});await c.addCookies([{name:'lissom_sesjon',value:admin.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const errors=[],json403=[];p.on('pageerror',e=>errors.push(e.message));p.on('response',r=>{if(/\.json(\?|$)/.test(r.url())&&r.status()>=400)json403.push(r.url()+' '+r.status());});
  // 1) Endepunktet: bare admin, bare de to filene.
  await p.goto(`${ADR}/admin-ny.html#idag`);
  const ep=await p.evaluate(async()=>{const a=await fetch('/api/admin/innhold.php?fil=seo-kart');const b=await fetch('/api/admin/innhold.php?fil=standard');const x=await fetch('/api/admin/innhold.php?fil=../app/secrets');return{a:a.status,stier:!!(await a.json()).stier,b:b.status,bAntall:Object.keys(await b.json()).length,x:x.status};});
  assert.deepEqual([ep.a,ep.stier,ep.b,ep.x],[200,true,200,404],'innhold.php?fil');assert.ok(ep.bAntall>50,'standardtekstene kom');
  // 2) Sidene som fikk 403 i produksjon.
  for(const [r,name,sok] of [['seo','SEO','Søk etter side'],['geo','GEO','Søk etter side'],['tekster','Tekster på nettsiden','Søk etter side, felt eller tekst']]){
   await p.goto(`${ADR}/admin-ny.html#${r}`);await p.getByRole('heading',{name,exact:true}).waitFor();await p.getByRole('searchbox',{name:sok}).waitFor();
   assert.ok(await p.locator('article.list-item').count()>3,r+': listen er fylt');
  }
  // 3) Medlemmene (eieren 08.10): #minside peker hit; fire fliser, forhåndsvisning og én bryter per modul (uten bekreftelse).
  await p.goto(`${ADR}/admin-ny.html#minside`);await p.getByRole('heading',{name:'Medlemmene',exact:true}).waitFor();
  assert.equal(new URL(p.url()).hash,'#medlemmene','#minside peker til #medlemmene');
  for(const t of ['Beskjed','Internt kurs','Vare','Kampanje'])await p.locator(`button.mm-flis[data-ny="${t}"]`).waitFor();
  assert.equal(await p.locator('.mm-rad').count(),25,'alle modulene står i lista');
  const sw=navn=>p.getByRole('switch',{name:new RegExp('^'+navn+': (på|av)$')});
  const sett=async(navn,paa)=>{const b=sw(navn);if((await b.getAttribute('aria-checked'))!==String(paa)){await b.click();await p.getByRole('switch',{name:`${navn}: ${paa?'på':'av'}`,exact:true}).waitFor();}};
  await sett('Stemple inn og timene dine',true);await sett('Stemple inn og timene dine',false);
  // «Dette ser medlemmene»: forhåndsvisningen i en ramme med fast tittel, og noe innhold lastes (uavhengig av hvilken adresse rammen bruker).
  await p.locator('iframe[title="Min side slik et medlem ser den"]').waitFor();
  await p.frameLocator('iframe[title="Min side slik et medlem ser den"]').locator('body').filter({hasText:/\S/}).waitFor({timeout:30000});
  // Medlemmet ser ikke modulen.
  const m=await browser.newContext({viewport:{width,height:950}});await m.addCookies([{name:'lissom_sesjon',value:medlem.token,domain:'lokal.lissom.no',path:'/'}]);await m.addInitScript(()=>localStorage.setItem('lissom-samtykke','nei'));
  const mp=await m.newPage();mp.on('pageerror',e=>errors.push('medlem: '+e.message));await mp.goto(`${ADR}/min-side`);await mp.getByRole('switch',{name:'Ovnen: vis/skjul',exact:true}).waitFor();
  assert.equal(await mp.locator('[data-ms-modul="stempel"]').isVisible(),false,'stempel skjult for medlemmet');
  // Slå på igjen — modulen er tilbake.
  await sett('Stemple inn og timene dine',true);
  await mp.reload();await mp.getByRole('switch',{name:'Stemple inn og timene dine: vis/skjul',exact:true}).waitFor();assert.equal(await mp.locator('[data-ms-modul="stempel"]').isVisible(),true,'stempel tilbake');
  // Ovnen av: bare ovn-delen forsvinner, «Ta med barn» i samme kort står (egen bryter).
  const barnVarAv=(await sw('Ta med barn').getAttribute('aria-checked'))==='false';
  if(barnVarAv){await sett('Ta med barn',true);await mp.reload();await mp.getByRole('switch',{name:'Ta med barn: vis/skjul',exact:true}).waitFor();}
  assert.equal(await mp.locator('[data-ms-modul="barn"]').isVisible(),true,'barn vises før');
  await sett('Ovnen',false);
  await mp.reload();await mp.getByRole('switch',{name:'Ta med barn: vis/skjul',exact:true}).waitFor();
  assert.equal(await mp.getByRole('button',{name:'Råbrann satt',exact:true}).count(),0,'ovn-delen borte');
  assert.equal(await mp.getByRole('switch',{name:'Ovnen: vis/skjul',exact:true}).isVisible(),false,'ovn-bryteren skjult');
  assert.equal(await mp.locator('[data-ms-modul="barn"]').isVisible(),true,'Ta med barn står når Ovnen er av');
  await sett('Ovnen',true);
  if(barnVarAv)await sett('Ta med barn',false);
  // Beskjed-arket: «Legg ut» og «Legg ut og send SMS» (ingenting sendes her).
  await p.locator('button.mm-flis[data-ny="Beskjed"]').click();const ark=p.getByRole('dialog',{name:'Beskjed til alle medlemmer'});
  await ark.getByRole('button',{name:'Legg ut',exact:true}).waitFor();await ark.getByRole('button',{name:'Legg ut og send SMS',exact:true}).waitFor();await ark.getByRole('button',{name:'Avbryt',exact:true}).click();
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,width+': ingen vannrett rulling');
  assert.deepEqual(json403,[],'ingen .json-feil');assert.deepEqual(errors,[]);
  await m.close();await c.close();console.log(width+' px: SEO/GEO/Tekster laster, Medlemmene: fliser, brytere og forhåndsvisning bestått.');
 }
}finally{await browser.close();fixture('cleanup',admin);fixture('cleanup',medlem);}
