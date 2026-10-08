// Butikk i admin-ny (eieren 04.10.2026, GO): Butikk i menyen, én vare i både Nettbutikken og Internt med
// felles lager, Lite på lager (Butikk og I dag), Fyll på, filterpiller, og e-postvarsel når antallet settes
// under min for hånd. Kunden: gjest ser varen i nettbutikken, medlem ser den også internt.
import assert from 'node:assert/strict';import{createRequire}from'node:module';import{execFileSync}from'node:child_process';
const{chromium}=createRequire(import.meta.url)('playwright');
const fixture=(m,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',m,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed'),browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const URL='http://lokal.lissom.no:8140/admin-ny.html',navn=s.tag+' Glasur';
const varer=async p=>(await p.evaluate(async()=>await(await fetch('/api/admin/produkter.php')).json()));
try{
 const c=await browser.newContext({viewport:{width:1280,height:950}});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
 const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
 // Butikk i hovedmenyen.
 await p.goto(URL+'#idag');await p.getByRole('heading',{name:'Kjøpt i dag',exact:true}).waitFor();
 await p.locator('#meny').getByRole('link',{name:/Butikk/}).click();await p.getByRole('heading',{name:'Butikk',exact:true}).waitFor();
 assert.equal(await p.locator('#meny a[aria-current="page"]').innerText().then(t=>t.includes('Butikk')),true,'Butikk lyser i menyen');
 // 08.10.2026: Nettbutikk og Internt som faner (pluss Alle), «Lite på lager» filtrerer fanen med antall, og «Terskel» for varer uten egen grense.
 assert.deepEqual((await p.locator('.butikk-piller button').allTextContents()).map(t=>t.replace(/ \d+$/,'')),['Nettbutikk','Internt','Alle','Lite på lager','Terskel']);
 // Ny vare: begge bryterne, 2 på lager, min 5, maks 20.
 await p.getByRole('button',{name:'Ny vare',exact:true}).click();await p.getByText('Hvor skal varen vises?',{exact:true}).waitFor();
 assert.equal(await p.getByLabel('Nettbutikken',{exact:true}).isChecked(),true,'ny vare: Nettbutikken er krysset av');
 await p.getByLabel('Varenavn',{exact:true}).fill(navn);await p.getByLabel('Pris i kroner',{exact:true}).fill('149');await p.getByLabel('Antall på lager',{exact:true}).fill('2');
 await p.getByLabel('Synlighet',{exact:true}).selectOption('publisert');await p.getByLabel('Internt',{exact:true}).check();
 await p.getByLabel('Bestill når lageret er under',{exact:true}).fill('5');await p.getByLabel('Fyll opp lageret til',{exact:true}).fill('20');
 // Leire fra Scan-Form: kan tas ut, og legges i handlelista når den når min.
 await p.getByLabel('Leire',{exact:true}).check();await p.getByLabel('Leverandør',{exact:true}).selectOption({label:'Scan-Form'});
 await p.getByRole('button',{name:'Lagre',exact:true}).click();await p.getByRole('dialog',{name:'Publiser varen?',exact:true}).getByRole('button',{name:'Publiser',exact:true}).click();
 await p.getByRole('dialog',{name:'Ny vare',exact:true}).waitFor({state:'detached'});
 let d=await varer(p);const v=d.varer.find(r=>r.tittel===navn);assert.ok(v,'varen er lagret');
 assert.equal(v.iNettbutikk,true);assert.equal(v.kunMedlemmer,true);assert.equal(v.lager,2);assert.equal(v.lagerMin,5);assert.equal(v.lagerMaks,20);
 assert.ok(d.litePaaLager.some(r=>r.id===v.id&&r.min===5&&r.maks===20),'står under Lite på lager');
 assert.ok(fixture('lagervarsel',{...s,vare:v.id}).antall>=1,'e-postvarsel sendt når varen ble lagt inn under min');
 console.log('Ny vare i Nettbutikken og Internt, 2 på lager (min 5 / maks 20); e-postvarselet er sendt.');
 // Butikk: Lite på lager-kortet og varelinja.
 await p.goto(URL+'#butikk');const kort=p.locator('section.card').filter({has:p.getByRole('heading',{name:'Lite på lager',exact:true})});
 await kort.getByText('2 igjen · min 5 · fyll til 20',{exact:true}).waitFor();
 await p.getByRole('searchbox',{name:'Søk vare eller kategori'}).fill(navn);const rad=p.locator('main').getByText(/149.*2 på lager \(min 5 \/ maks 20\)/).first();await rad.waitFor();
 for(const [k,med] of [['nett',true],['intern',true],['lite',true]]){await p.locator(`.butikk-piller [data-vis="${k}"]`).click();await p.getByRole('searchbox',{name:'Søk vare eller kategori'}).fill(navn);assert.equal(await p.locator('main').getByText(navn,{exact:true}).count()>0,med,'filter '+k);}
 assert.ok(Number((await p.locator('.butikk-piller [data-vis="lite"]').innerText()).replace(/\D+/g,''))>=1,'Lite på lager viser antallet');assert.equal(await p.locator('.butikk-piller [data-vis="lite"]').getAttribute('aria-pressed'),'true');
 // I dag: Lite på lager med Åpne butikken.
 await p.goto(URL+'#idag');const idag=p.locator('section.card').filter({has:p.getByRole('heading',{name:'Lite på lager',exact:true})});await idag.getByText(navn,{exact:true}).waitFor();
 await idag.getByRole('link',{name:'Åpne butikken',exact:true}).waitFor();
 // Fyll på 18 fra I dag: lageret blir 20, og varen er ute av Lite på lager.
 await idag.locator('.row',{hasText:navn}).getByRole('button',{name:'Fyll på',exact:true}).click();await p.getByRole('dialog',{name:'Fyll på · '+navn,exact:true}).waitFor();
 await p.getByLabel('Antall inn',{exact:true}).fill('18');await p.getByRole('button',{name:'Lagre',exact:true}).click();await p.getByText('Lageret er nå 20.',{exact:true}).waitFor();
 d=await varer(p);assert.equal(d.varer.find(r=>r.id===v.id).lager,20);assert.ok(!d.litePaaLager.some(r=>r.id===v.id),'ute av Lite på lager');
 console.log('Fyll på 18 fra I dag: lageret er 20, og varen er ute av Lite på lager.');
 // For hånd under min igjen: nytt varsel.
 const foer=fixture('lagervarsel',{...s,vare:v.id}).antall;
 await p.goto(URL+'#butikk');await p.getByRole('searchbox',{name:'Søk vare eller kategori'}).fill(navn);await p.getByRole('button',{name:'Rediger',exact:true}).first().click();
 await p.getByLabel('Antall på lager',{exact:true}).fill('3');await p.getByRole('button',{name:'Lagre',exact:true}).click();await p.getByRole('dialog',{name:'Publiser varen?',exact:true}).getByRole('button',{name:'Publiser',exact:true}).click();
 await p.getByRole('dialog',{name:'Rediger vare',exact:true}).waitFor({state:'detached'});
 assert.ok(fixture('lagervarsel',{...s,vare:v.id}).antall>foer,'varsel når antallet settes under min for hånd');
 console.log('Antall satt til 3 for hånd: nytt e-postvarsel.');
 // Handlelista: én linje «Verkstedets lager» under Scan-Form med det som fyller opp til maks (20 − 3 = 17), uten Vipps-krav.
 const hl=await p.evaluate(async()=>await(await fetch('/api/admin/handlelister.php')).json());
 const hv=hl.varer.find(r=>r.produktId===v.id);assert.ok(hv,'varen ligger i handlelista');assert.equal(hv.antall,17);assert.match(hv.hvem,/Verkstedets lager 17/);assert.equal(hv.leverandor,'Scan-Form');
 const verk=hl.medlemmer.find(m=>m.intern);assert.ok(verk&&verk.navn==='Verkstedets lager'&&verk.gebyrOre===0,'verkstedet i oppgjøret, uten gebyr');
 await p.goto(URL+'#handlelister');await p.getByText('Verkstedets lager',{exact:true}).first().waitFor();
 assert.equal(await p.locator('main .row,main li').filter({hasText:'Verkstedets lager'}).getByText('Ikke krevd inn').count(),0,'ingen krav-merke på verkstedet');
 console.log('Handlelista: «Verkstedets lager» 17 stk under Scan-Form, uten gebyr og uten krav.');
 // Ta ut leire: én pose fra lageret, uten betaling.
 await p.goto(URL+'#butikk');const tu=p.locator('section.card').filter({has:p.getByRole('heading',{name:'Ta ut leire',exact:true})});
 await tu.getByText(navn,{exact:true}).waitFor();await tu.locator(`[data-taut="${v.id}"]`).click();await p.getByText('Tatt ut 1. Lageret er nå 2.',{exact:true}).waitFor();
 d=await varer(p);assert.equal(d.varer.find(r=>r.id===v.id).lager,2);
 const hl2=await p.evaluate(async()=>await(await fetch('/api/admin/handlelister.php')).json());assert.equal(hl2.varer.find(r=>r.produktId===v.id).antall,17,'samme linje, ikke en ny');
 await p.goto(URL+'#idag');await p.locator('section.card').filter({has:p.getByRole('heading',{name:'Ta ut leire',exact:true})}).getByText(navn,{exact:true}).waitFor();
 console.log('Ta ut leire: lageret 3 → 2, ingen ny linje i handlelista; kortet står også på I dag.');
 // Bare i admin: ingen av bryterne — ikke for gjester eller medlemmer, men i admin.
 const admin=await p.evaluate(async t=>await(await fetch('/api/admin/produkter.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({handling:'lagre',id:0,tittel:t,pris:250,lager:9,status:'publisert',iNettbutikk:'nei',kunMedlemmer:'nei',leire:'ja'})})).json(),s.tag+' Toffee');
 assert.ok(admin.id,'bare i admin lagres');
 // Kunden: gjest ser varen i nettbutikken (ikke «kun medlemmer»), innlogget ser den også internt.
 const gjest=await browser.newContext();const g=await gjest.newPage();await g.goto('http://lokal.lissom.no:8140/');
 const gv=(await g.evaluate(async()=>await(await fetch('/api/butikk.php')).json())).varer.find(r=>r.id===v.id);
 assert.ok(gv,'gjest ser varen');assert.equal(gv.kunMedlemmer,false);assert.equal(gv.internt,true);assert.ok(gv.sti,'varen har egen side');
 assert.equal((await g.evaluate(async()=>await(await fetch('/api/butikk.php')).json())).varer.some(r=>r.id===admin.id),false,'gjest ser ikke Bare i admin');await gjest.close();
 const mliste=(await p.evaluate(async()=>await(await fetch('/api/butikk.php')).json())).varer;const mv=mliste.find(r=>r.id===v.id);assert.ok(mv&&mv.internt,'innlogget ser varen internt');
 assert.equal(mliste.some(r=>r.id===admin.id),false,'innlogget ser ikke Bare i admin');
 await p.goto(URL+'#butikk');await p.locator('section.card').filter({has:p.getByRole('heading',{name:'Ta ut leire',exact:true})}).getByText(s.tag+' Toffee',{exact:true}).waitFor();
 console.log('Kunden: gjest ser varen i nettbutikken; innlogget ser den også i internbutikken.');
 // Mobil og nettbrett: Butikk uten sideveis rulling.
 for(const width of[390,820]){await p.setViewportSize({width,height:900});await p.goto(URL+'#butikk');await p.locator('.butikk-piller').waitFor();assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,width+' px');console.log(width+' px: Butikk uten sideveis rulling.');}
 assert.deepEqual(errors,[]);await c.close();
}finally{await browser.close();fixture('cleanup',s);}
