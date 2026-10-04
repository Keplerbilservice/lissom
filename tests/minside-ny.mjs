// Ny Min side med fliser (eieren, 4. oktober 2026, skissen TBPaVdMts5oSTmJgPsozNz).
// Bak bryteren Vis/minsideny (av som standard). Av: Min side som før. På: Hilsen,
// «Verkstedet nå», medlemskapsflisen og seks fliser; hver flis åpner en underside
// med de eksisterende kortene, og «← Min side» går tilbake. Modulbryterne virker
// fortsatt, og internbutikken viser varebildet (begge utgaver).
//   bash tests/nettleser/kjor.sh admin-ny   (kjøres i gruppa «nyadmin»)
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {tmpdir} from 'node:os';import {join} from 'node:path';
const BILDER=process.env.LISSOM_TEST_RAPPORT||tmpdir();
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'})||'{}');
const db=(sql,p={})=>JSON.parse(execFileSync('php',['tests/nettleser/db.php',sql,JSON.stringify(p)],{encoding:'utf8'})||'[]');
const php=(kode)=>JSON.parse(execFileSync('php',['tests/nettleser/db.php','--php',kode],{encoding:'utf8'})||'null');
const ADR='http://lokal.lissom.no:8140';
const bryter=(k,v)=>v===null?db('DELETE FROM content_blocks WHERE nokkel=:k',{k}):db('INSERT INTO content_blocks (nokkel, verdi) VALUES (:k, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',{k,v});
const NOKLER=['Vis/minsideny','Vis/minsidechat','Vis/minsidebeskjeder','Vis/internbutikk'];
const foer=Object.fromEntries(db("SELECT nokkel, verdi FROM content_blocks WHERE nokkel IN ('Vis/minsideny','Vis/minsidechat','Vis/minsidebeskjeder','Vis/internbutikk')").map(r=>[r.nokkel,r.verdi]));
const s=fixture('seed');fixture('member',s);fixture('paid',s);
// To internvarer: én med bilde, én uten.
const varer=php(`$a=DB::settInn('products',['tittel'=>'${s.tag} med bilde','beskrivelse'=>'10 kg','bilde'=>'uploads_vare-10.jpg','kategori'=>'Leire','pris_ore'=>29000,'status'=>'publisert','kun_medlemmer'=>1]);
 $b=DB::settInn('products',['tittel'=>'${s.tag} uten bilde','beskrivelse'=>'Per hylle','bilde'=>'','kategori'=>'Brenning','pris_ore'=>15000,'status'=>'publisert','kun_medlemmer'=>1]);return [$a,$b];`);
// Kursdeltaker: ingen medlemskap, én påmelding.
const deltaker=php(`$id=DB::settInn('members',['navn'=>'${s.tag} Deltaker','epost'=>'${s.tag}-deltaker@e2e.lissom.test','rolle'=>'medlem','status'=>'ingen']);
 $o=DB::settInn('course_sessions',['course_id'=>${s.course},'start_tid'=>gmdate('Y-m-d H:i:s',time()+86400*5),'kapasitet'=>8]);
 DB::settInn('bookings',['course_id'=>${s.course},'course_session_id'=>$o,'member_id'=>$id,'gjest_navn'=>'${s.tag} Deltaker','antall'=>1,'belop_ore'=>10000,'status'=>'betalt']);
 $t=bin2hex(random_bytes(32));DB::settInn('sessions',['member_id'=>$id,'token_hash'=>hash('sha256',$t),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)]);return ['id'=>$id,'okt'=>$o,'token'=>$t];`);
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const side=async(token,width)=>{const c=await browser.newContext({viewport:{width,height:900},isMobile:width<600,hasTouch:width<600});await c.addCookies([{name:'lissom_sesjon',value:token,domain:'lokal.lissom.no',path:'/'}]);await c.addInitScript(()=>localStorage.setItem('lissom-samtykke','nei'));const p=await c.newPage();p.errors=[];p.on('pageerror',e=>p.errors.push(e.message));return {c,p};};
const flis=(p,navn)=>p.locator('.msny-fliser .msny-flis').filter({has:p.locator('b',{hasText:new RegExp('^'+navn)})});
try{for(const width of [390,1280]){
 // 1) Bryteren av: Min side som før, og internbutikken med bilde.
 bryter('Vis/minsideny',null);bryter('Vis/internbutikk','ja');
 let {c,p}=await side(s.token,width);
 try{
  await p.goto(ADR+'/min-side');await p.getByRole('switch',{name:'Stemple inn og timene dine: vis/skjul',exact:true}).waitFor();await p.waitForTimeout(1200);
  assert.equal(await p.locator('.msny').count(),0,'av: ingen ny Min side');
  assert.equal(await p.locator('[data-ms-modul="stempel"]').isVisible(),true,'av: stemplingskortet står');
  await p.locator('#minside-internbutikk .ms-vare').first().waitFor();
  const kort=p.locator('#minside-internbutikk .ms-vare').filter({hasText:s.tag+' med bilde'});
  await kort.locator('img').waitFor({state:'attached'});await kort.scrollIntoViewIfNeeded();
  await p.waitForFunction(t=>{const k=[...document.querySelectorAll('#minside-internbutikk .ms-vare')].find(x=>x.textContent.includes(t));const i=k&&k.querySelector('img');return !!(i&&i.complete&&i.naturalWidth>0);},s.tag+' med bilde',{timeout:8000});
  assert.equal(await p.locator('#minside-internbutikk .ms-vare').filter({hasText:s.tag+' uten bilde'}).locator('img').count(),0,'vare uten bilde: ingen <img>');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'av: ingen vannrett rulling');
  assert.deepEqual(p.errors,[]);
 }finally{await c.close();}
 // 2) Bryteren på: Hilsen, Verkstedet nå, medlemskapsflisen og seks fliser.
 bryter('Vis/minsideny','ja');
 ({c,p}=await side(s.token,width));
 try{
  await p.goto(ADR+'/min-side');await p.locator('.msny-hjem').waitFor();await p.getByText('Verkstedet nå',{exact:true}).waitFor();await p.waitForTimeout(1200);
  assert.equal(await p.getByRole('button',{name:'Stemple ut',exact:true}).first().isVisible(),true,'Stemple ut i Verkstedet nå');
  assert.match(await p.locator('.msny-medl').innerText(),/Brukt [\d,]+ av [\d,]+ timer i \p{L}+/u,'medlemskapsflisen viser timene');
  assert.match(await p.locator('.msny-medl').innerText(),/Betalt til \d+\. \p{L}+/u,'medlemskapsflisen viser betalt til');
  for(const n of ['Kurs','Butikk','Medlemskapet','Chat','Fellesskap','Hjelp'])assert.equal(await flis(p,n).isVisible(),true,'flis '+n);
  for(const m of ['stempel','inne','chat','abonnement','butikk','hms'])assert.equal(await p.locator('[data-ms-modul="'+m+'"]').isVisible(),false,m+' står ikke på forsiden');
  assert.equal(await p.locator('.ms-tl-pille',{hasText:'Dørkode'}).count(),0,'dørkoden ikke i menylinja');
  assert.equal(await p.getByText('Glemt å stemple ut',{exact:true}).count()<=1,true,'glemt å stemple ut står høyst ett sted');
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'på: ingen vannrett rulling');
  await p.screenshot({path:join(BILDER,'minside-ny-hjem-'+width+'.png'),fullPage:true}).catch(()=>{});
  // Hver flis åpner sin underside, «← Min side» går tilbake.
  for(const [n,sel] of [['Butikk','#minside-internbutikk'],['Medlemskapet','#minside-abonnement'],['Chat','#minside-chat'],['Hjelp','[data-ms-modul="hms"]'],['Kurs','#minside-kursbevis'],['Fellesskap','#minside-salg']]){
   await flis(p,n).click();await p.locator('.msny-hode').getByRole('heading',{name:n,exact:true}).waitFor();
   assert.equal(await p.locator(sel).first().isVisible(),true,n+': kortet står');
   assert.equal(await p.getByText('Verkstedet nå',{exact:true}).isVisible(),false,n+': forsiden er borte');
   if(n==='Butikk'){assert.ok(await p.locator('#minside-internbutikk .ms-vare img').count()>=1,'Butikk: varebilde');await p.screenshot({path:join(BILDER,'minside-ny-butikk-'+width+'.png'),fullPage:true}).catch(()=>{});}
   assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,n+': ingen vannrett rulling');
   await p.getByRole('button',{name:'← Min side',exact:true}).click();await p.locator('.msny-hjem').waitFor();
  }
  assert.deepEqual(p.errors,[]);
 }finally{await c.close();}
 // 3) Modulbryterne virker: Chat og Beskjeder av → flisen Chat er borte.
 bryter('Vis/minsidechat','nei');bryter('Vis/minsidebeskjeder','nei');
 ({c,p}=await side(s.token,width));
 try{await p.goto(ADR+'/min-side');await p.locator('.msny-hjem .msny-fliser').waitFor();await p.waitForTimeout(800);
  assert.equal(await flis(p,'Chat').count(),0,'Chat-flisen borte når modulene er av');assert.equal(await flis(p,'Butikk').isVisible(),true,'Butikk står');
 }finally{await c.close();bryter('Vis/minsidechat',null);bryter('Vis/minsidebeskjeder',null);}
 // 4) Kursdeltaker: Neste kurs og fire fliser.
 ({c,p}=await side(deltaker.token,width));
 try{await p.goto(ADR+'/min-side');await p.locator('.msny-d-hjem').waitFor();await p.waitForTimeout(1200);
  for(const n of ['Kursene mine','Finn nytt kurs'])assert.equal(await flis(p,n).isVisible(),true,'deltaker: flis '+n);
  assert.equal(await p.getByText('Verkstedet nå',{exact:true}).count(),0,'deltaker: ingen Verkstedet nå');
  await flis(p,'Kursene mine').click();await p.locator('.msny-hode').getByRole('heading',{name:'Kursene mine',exact:true}).waitFor();
  assert.equal(await p.locator('#minside-pameldinger').isVisible(),true,'deltaker: påmeldingene');
  await p.getByRole('button',{name:'← Min side',exact:true}).click();await p.locator('.msny-d-hjem').waitFor();
  assert.deepEqual(p.errors,[]);
 }finally{await c.close();}
 console.log(width+' px: av = som før, på = fliser og undersider, modulbryter, kursdeltaker og varebilder bestått.');
}}finally{
 await browser.close();
 for(const k of NOKLER)bryter(k,foer[k]===undefined?null:foer[k]);
 php(`DB::kjor('DELETE FROM products WHERE id IN (${varer.join(',')})');DB::kjor('DELETE FROM sessions WHERE member_id=:m',['m'=>${deltaker.id}]);DB::kjor('DELETE FROM bookings WHERE member_id=:m',['m'=>${deltaker.id}]);DB::kjor('DELETE FROM course_sessions WHERE id=:o',['o'=>${deltaker.okt}]);DB::kjor('DELETE FROM members WHERE id=:m',['m'=>${deltaker.id}]);return true;`);
 fixture('cleanup',s);
}
