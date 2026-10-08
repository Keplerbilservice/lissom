// Admin-ny 08.10.2026: det gamle admin pensjoneres. Tre ting flyttet inn:
//  1) Glasurkalkulator + glasurlapp under Oppskrifter (admin-ny/glasur.js).
//  2) «Feil tid — si fra» fra stemplingen havner under Henvendelser i admin-ny.
//  3) «Se som medlem» bruker /min-side?forhandsvis=medlem — ikke /admin.
// Ingen e-post eller SMS: henvendelsen legges rett i testbasen. Kjøres via tests/nettleser/kjor.sh.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'})||'{}');
const php=kode=>execFileSync('php',['-r',`require 'tests/nettleser/testdatabase.php';krev_testdatabase(getcwd());require 'app/bootstrap.php';${kode}`],{encoding:'utf8'});
const ADR='http://lokal.lissom.no:8140';
const merke='E2E-glasur-'+Date.now();
const s=fixture('seed');const b=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const rid=Number(php(`echo DB::settInn('recipes',['navn'=>'${merke}','type'=>'Glasur','temperatur'=>'1240 °C','raavarer'=>json_encode([['Kvarts',25],['Feltspat',75]]),'notat'=>'']);`));
const eid=Number(php(`echo DB::settInn('enquiries',['navn'=>'${merke}','type'=>'Feil stemplingstid','melding'=>'${merke} sier at tida på denne økta ble feil.']);`));
try{
 for(const width of [390,1280]){
  const c=await b.newContext({viewport:{width,height:900},...(width<760?{hasTouch:true,isMobile:true}:{})});
  await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
  // Utskriftsvinduet fanges, så testen ser hva lappen inneholder uten å skrive ut.
  await p.addInitScript(()=>{window.__lapp=[];window.open=()=>({document:{write:h=>window.__lapp.push(h),close(){}}});});
  // 1) Kalkulator og lapp.
  await p.goto(`${ADR}/admin-ny.html#oppskrifter`);await p.getByRole('heading',{name:'Oppskrifter',exact:true}).waitFor();
  const rad=p.locator('article.list-item').filter({hasText:merke});await rad.getByRole('button',{name:'Utregning og lapp',exact:true}).click();
  const ark=p.getByRole('dialog',{name:'Utregning · '+merke});await ark.waitFor();
  await ark.getByRole('spinbutton',{name:'Antall liter jeg skal lage'}).fill('2');
  await ark.getByRole('textbox',{name:'Fargestoff'}).fill('Jernoksid');await ark.getByRole('textbox',{name:'Prosent'}).fill('3');
  await ark.getByRole('button',{name:'Legg til',exact:true}).click();
  const tekst=await ark.textContent();
  for(const t of ['500 g','1500 g','Jernoksid (fargestoff)','60 g','2060 g tørrstoff',merke+' + Jernoksid 3 %','Batch: B-'])assert.ok(tekst.includes(t),'kalkulator: '+t);
  await ark.getByRole('button',{name:'Skriv ut lapp',exact:true}).click();
  const lapp=await p.evaluate(()=>window.__lapp.join(''));
  assert.ok(lapp.includes(merke+' + Jernoksid 3 %')&&/Batch: B-\d{6}-\d+/.test(lapp)&&lapp.includes('Dato: '),'lappen har navn, dato og batch');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,width+': ingen vannrett rulling');
  await ark.getByRole('button',{name:'×'}).click({timeout:5000}).catch(()=>p.keyboard.press('Escape'));await ark.waitFor({state:'hidden',timeout:10000});
  // 2) Feil tid — si fra: saken står under Henvendelser.
  await p.goto(`${ADR}/admin-ny.html#foresporsler`);await p.getByRole('heading',{name:'Henvendelser',exact:true}).waitFor();
  await p.locator('article.list-item').filter({hasText:merke}).filter({hasText:'Feil stemplingstid'}).first().waitFor();
  // 3) Se som medlem: ny adresse, uten knappene til det gamle admin.
  await p.goto(`${ADR}/admin-ny.html#medlemmene`);await p.getByRole('heading',{name:'Medlemmene',exact:true}).waitFor();
  const ramme=p.locator('iframe[title="Min side slik et medlem ser den"]');
  assert.equal(await ramme.getAttribute('src'),'/min-side?forhandsvis=medlem');
  const f=p.frameLocator('iframe[title="Min side slik et medlem ser den"]');
  await f.getByText(/Slik ser Min side ut for et medlem/).first().waitFor({timeout:20000});
  assert.equal(await f.getByText('Tilbake til admin',{exact:true}).count(),0,'ingen vei til det gamle admin i rammen');
  assert.deepEqual(errors,[]);await c.close();
  console.log(`${width} px: glasurkalkulator, lapp, feil tid og Se som medlem bestått`);
 }
 // Uten admin: adressen er vanlig Min side, uten forhåndsvisning.
 const u=await b.newContext();const up=await u.newPage();await up.goto(`${ADR}/min-side?forhandsvis=medlem`);await up.waitForTimeout(1500);
 assert.equal(await up.getByText(/Slik ser Min side ut for et medlem/).count(),0,'ingen forhåndsvisning uten admin');await u.close();
}finally{await b.close();php(`DB::kjor('DELETE FROM recipes WHERE id = :i',['i'=>${rid}]);DB::kjor('DELETE FROM enquiries WHERE id = :i',['i'=>${eid}]);`);fixture('cleanup',s);}
console.log('OK');
