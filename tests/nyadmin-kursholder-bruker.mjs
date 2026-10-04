// Ny kursholder fra Folk › Brukere › Ny bruker (eieren 04.10.2026, GO): «Kursholder» under Tilgang
// gir medlemsinnlogging og legger personen i kursholderregisteret, koblet på e-post.
import assert from 'node:assert/strict';import{createRequire}from'node:module';import{execFileSync}from'node:child_process';
const{chromium}=createRequire(import.meta.url)('playwright');
const fixture=(m,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',m,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed'),browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const URL='http://lokal.lissom.no:8140/admin-ny.html';
const epost=s.tag.toLowerCase()+'-kh@e2e.lissom.test',brukernavn=s.tag.toLowerCase().replace(/[^a-z0-9]/g,'')+'kh';
const hent=async(p,f)=>p.evaluate(async f=>await(await fetch('/api/admin/'+f)).json(),f);
try{
 const c=await browser.newContext({viewport:{width:1280,height:950}});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
 const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
 // Folk › Brukere, slik eieren går.
 await p.goto(URL+'#folk');await p.getByRole('heading',{name:'Folk',exact:true}).waitFor();await p.getByRole('link',{name:'Brukere',exact:true}).click();await p.getByRole('heading',{name:'Brukere',exact:true}).waitFor();
 await p.getByRole('button',{name:'Ny bruker',exact:true}).click();
 const tilgang=p.getByRole('combobox',{name:'Tilgang',exact:true});
 assert.deepEqual(await tilgang.locator('option').allTextContents(),['Medlem','Kursholder','Regnskap','Administrator']);
 await p.getByRole('textbox',{name:'Navn',exact:true}).fill(s.tag+' kursholder');await p.getByRole('textbox',{name:'Brukernavn',exact:true}).fill(brukernavn);
 await p.getByRole('textbox',{name:'Telefon',exact:true}).fill('90000001');await p.getByLabel('Passord',{exact:true}).fill('kursholder-passord-1');
 await tilgang.selectOption('kursholder');
 // Uten e-post: stopper før noe lagres.
 let posts=0;p.on('request',r=>{if(r.method()==='POST'&&r.url().includes('brukere.php'))posts++;});
 await p.getByRole('button',{name:'Lagre',exact:true}).click();await p.getByText('Skriv e-posten til kursholderen.',{exact:true}).waitFor();assert.equal(posts,0,'uten e-post sendes ingenting');
 await p.getByRole('textbox',{name:'E-post',exact:true}).fill(epost);await p.getByRole('button',{name:'Lagre',exact:true}).click();
 await p.getByText('Denne personen får medlemstilgang og blir lagt til under Kursholdere.',{exact:true}).waitFor();await p.getByRole('button',{name:'Lagre bruker',exact:true}).click();await p.locator('dialog').first().waitFor({state:'detached'});
 const u=(await hent(p,'brukere.php')).brukere.find(b=>b.brukernavn===brukernavn);assert.ok(u,'brukeren er laget');assert.equal(u.rolle,'medlem');assert.equal(u.kursholder,true);
 const h=(await hent(p,'kursholdere.php')).kursholdere.filter(k=>String(k.epost||'').toLowerCase()===epost);assert.equal(h.length,1,'én kursholder i registeret');assert.equal(h[0].navn,s.tag+' kursholder');
 console.log('Ny bruker med Tilgang «Kursholder»: innlogging laget og kursholderen står i registeret med samme e-post.');
 // Lista og Endre: merket «Kursholder», og Tilgang står på Kursholder.
 await p.getByRole('searchbox',{name:'Søk navn, brukernavn eller e-post'}).fill(brukernavn);await p.locator('main .badge',{hasText:/^Kursholder$/}).waitFor();assert.equal(await p.locator('main .badge',{hasText:/^medlem$/}).count(),0,'merket Kursholder i stedet for medlem');await p.getByRole('button',{name:'Endre',exact:true}).click();assert.equal(await tilgang.inputValue(),'kursholder');
 // Lagre på nytt uten endring: ingen dublett i registeret.
 await p.getByRole('button',{name:'Lagre',exact:true}).click();await p.getByRole('button',{name:'Lagre bruker',exact:true}).click();await p.locator('dialog').first().waitFor({state:'detached'});
 assert.equal((await hent(p,'kursholdere.php')).kursholdere.filter(k=>String(k.epost||'').toLowerCase()===epost).length,1,'ingen dublett');
 // Mobil, nettbrett og PC: lista og skjemaet uten sideveis rulling.
 for(const width of[390,820,1280]){await p.setViewportSize({width,height:950});await p.goto(URL+'#brukere');await p.getByRole('searchbox',{name:'Søk navn, brukernavn eller e-post'}).fill(brukernavn);await p.getByRole('button',{name:'Endre',exact:true}).click();await tilgang.waitFor();assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,width+' px');await p.locator('dialog .close').first().click();console.log(width+' px: Brukere og skjemaet med Kursholder uten sideveis rulling.');}
 // Tilbake til Medlem: kursholderen settes som sluttet, innloggingen står.
 await p.getByRole('button',{name:'Endre',exact:true}).click();await tilgang.selectOption('medlem');await p.getByRole('button',{name:'Lagre',exact:true}).click();await p.getByRole('button',{name:'Lagre bruker',exact:true}).click();await p.locator('dialog').first().waitFor({state:'detached'});
 const etter=(await hent(p,'brukere.php')).brukere.find(b=>b.brukernavn===brukernavn);assert.equal(etter.kursholder,false);assert.equal((await hent(p,'kursholdere.php')).kursholdere.filter(k=>String(k.epost||'').toLowerCase()===epost&&k.aktiv!==false&&k.aktiv!==0).length,0);
 console.log('Tilgang tilbake til Medlem: kursholderen er satt som sluttet, brukeren står.');
 assert.deepEqual(errors,[]);await c.close();
}finally{await browser.close();fixture('cleanup',s);}
