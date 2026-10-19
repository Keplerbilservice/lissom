// Ny Min side: «Kjøp timepakke · {timer} t for kr {pris}» som pille under timeflisen
// i «Verkstedet nå» (eieren 19.10.2026, visningen DTmtwYnXHWiybMRcQeS7HM). Pillen står
// bare når månedens timer er brukt opp og pakken kan kjøpes (samme regel som vindu 2),
// også etter «Ikke nå», og ikke mens medlemmet er innstemplet. Trykk åpner vindu 2,
// og «Kjøp timepakke» der starter kjøpet som før.
//   bash tests/nettleser/kjor.sh admin-ny   (kjøres i gruppa «nyadmin»)
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {tmpdir} from 'node:os';import {join} from 'node:path';
const BILDER=process.env.LISSOM_TEST_RAPPORT||tmpdir();
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'})||'{}');
const db=(sql,p={})=>JSON.parse(execFileSync('php',['tests/nettleser/db.php',sql,JSON.stringify(p)],{encoding:'utf8'})||'[]');
const ADR=process.env.E2E_ADRESSE||'http://lokal.lissom.no:8140';
const bryter=(k,v)=>v===null?db('DELETE FROM content_blocks WHERE nokkel=:k',{k}):db('INSERT INTO content_blocks (nokkel, verdi) VALUES (:k, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',{k,v});
const NOKLER=['Vis/minsideny','Vis/minsidestempel'];
const foer=Object.fromEntries(db("SELECT nokkel, verdi FROM content_blocks WHERE nokkel IN ('Vis/minsideny','Vis/minsidestempel')").map(r=>[r.nokkel,r.verdi]));
const s=fixture('seed');fixture('member',s);fixture('paid',s);
const plan=db('SELECT medlemskap_type AS navn FROM members WHERE id=:m',{m:s.admin})[0].navn;
const fullt=Number(db('SELECT timer FROM membership_plans WHERE navn=:n',{n:plan})[0].timer)*60;
// «Ikke nå» er trykket i dag: vindu 2 dukker ikke opp av seg selv, bare pillen står.
const oslo=new Date(new Date().toLocaleString('en-US',{timeZone:'Europe/Oslo'}));
const DAG=oslo.getFullYear()+'-'+String(oslo.getMonth()+1).padStart(2,'0')+'-'+String(oslo.getDate()).padStart(2,'0');
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const side=async(width)=>{const mob=width<600;const c=await browser.newContext({viewport:{width,height:900},isMobile:mob,hasTouch:mob});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);await c.addInitScript(d=>{localStorage.setItem('lissom-samtykke','nei');localStorage.setItem('lissom_tp_ikke_na',d);},DAG);const p=await c.newPage();p.errors=[];p.on('pageerror',e=>p.errors.push(e.message));p.trykk=l=>mob?l.tap():l.click();return {c,p};};
const hjem=async p=>{await p.goto(ADR+'/min-side');await p.locator('.msny-hjem .msny-fliser').waitFor();await p.getByText('Verkstedet nå',{exact:true}).waitFor();await p.locator('.msny-verksted .msny-timeflis').waitFor();await p.waitForTimeout(1500);};
const pille=p=>p.locator('.msny-verksted .msny-tp-pille');
const stempleUt=()=>db('UPDATE check_ins SET ut_tid=inn_tid, minutter=0 WHERE member_id=:m AND ut_tid IS NULL',{m:s.admin});
const innstemplet=()=>db('SELECT COUNT(*) AS n FROM check_ins WHERE member_id=:m AND ut_tid IS NULL',{m:s.admin})[0].n>0;
const brukt=min=>db('UPDATE check_ins SET minutter=:n, ut_tid=DATE_ADD(inn_tid, INTERVAL :n2 MINUTE) WHERE member_id=:m AND ut_tid IS NOT NULL AND minutter>0',{m:s.admin,n:min,n2:min});
try{
 // Fixturen stempler inn og har én avsluttet økt. Den åpne lukkes med 0 minutter,
 // så timene telles bare av den avsluttede.
 const varInne=innstemplet();
 const apen=db('SELECT id FROM check_ins WHERE member_id=:m AND ut_tid IS NULL',{m:s.admin}).map(r=>r.id);
 for(const width of [390,1280]){
  bryter('Vis/minsideny','ja');bryter('Vis/minsidestempel',null);
  stempleUt();brukt(fullt);
  // 1) Timene brukt opp, utstemplet, etter «Ikke nå»: pillen står og åpner vindu 2.
  let {c,p}=await side(width);
  try{await hjem(p);
   assert.equal(await p.getByText('Månedens timer er brukt opp',{exact:true}).count(),0,'vindu 2 er ikke oppe etter «Ikke nå»');
   const pl=pille(p);await pl.waitFor();
   assert.equal(await pl.innerText(),'Kjøp timepakke · 6 t for kr 800','pilleteksten');
   // Samme pille som resten av Min side (.msny-pille), under linja med timene.
   const [pS,stempelS]=await Promise.all([pl.evaluate(e=>{const c=getComputedStyle(e);return [c.borderTopWidth,c.borderTopStyle,c.borderRadius,c.fontWeight,c.fontFamily].join('|');}),
     p.locator('.msny-verksted .msny-pille:not(.msny-tp-pille)').first().evaluate(e=>{const c=getComputedStyle(e);return [c.borderTopWidth,c.borderTopStyle,c.borderRadius,c.fontWeight,c.fontFamily].join('|');})]);
   assert.equal(pS,stempelS,'samme drakt som de andre pillene');
   const linje=await p.locator('.msny-verksted .msny-timelinje').boundingBox(),pb=await pl.boundingBox();
   assert.ok(pb.y>=linje.y+linje.height-1,'pillen står under linja med timene');
   assert.ok(pb.x>=0&&pb.x+pb.width<=width,'pillen er innenfor skjermen');
   assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen vannrett rulling');
   await p.locator('.msny-verksted').scrollIntoViewIfNeeded();
   await p.screenshot({path:join(BILDER,'minside-timepakke-pille-'+width+'.png'),fullPage:width<600}).catch(()=>{});
   if(width>=900){await pl.focus();await p.keyboard.press('Enter');}else await p.trykk(pl);
   const vindu=p.locator('[data-tp-vindu="2"]');await vindu.waitFor();
   await vindu.getByText('Månedens timer er brukt opp',{exact:true}).waitFor();
   assert.match(await vindu.innerText(),/Timepakke · 6 timer\s+kr 800/,'vindu 2: pakken og prisen');
   await p.screenshot({path:join(BILDER,'minside-timepakke-vindu-'+width+'.png')}).catch(()=>{});
   // «Kjøp timepakke» i vinduet starter kjøpet som før (POST handling=kjop).
   let kropp=null;
   await p.route('**/api/timepakke.php',r=>{kropp=r.request().postDataJSON();return r.fulfill({json:{url:ADR+'/min-side'}});});
   await p.trykk(vindu.getByRole('button',{name:'Kjøp timepakke',exact:true}));
   await p.waitForURL(ADR+'/min-side');
   assert.deepEqual(kropp,{handling:'kjop'},'kjøpet startes med handling=kjop');
   assert.deepEqual(p.errors,[]);
  }finally{await c.close();}
  // 2) Innstemplet med timene brukt opp: ingen pille (vinduet åpnes ikke da).
  if(apen.length){
   db('UPDATE check_ins SET ut_tid=NULL WHERE id IN ('+apen.map(Number).join(',')+')');
   ({c,p}=await side(width));
   try{await hjem(p);
    assert.equal(await pille(p).count(),0,'innstemplet: ingen pille');
    assert.deepEqual(p.errors,[]);
   }finally{await c.close();}
   stempleUt();
  }
  // 3) Timer igjen (7 timer brukt): ingen pille.
  brukt(420);
  ({c,p}=await side(width));
  try{await hjem(p);
   assert.notEqual(await p.locator('.msny-timeflis small').innerText(),'0 t igjen','timer igjen');
   assert.equal(await pille(p).count(),0,'timer igjen: ingen pille');
   assert.deepEqual(p.errors,[]);
  }finally{await c.close();}
 }
 if(varInne&&apen.length)db('UPDATE check_ins SET ut_tid=NULL WHERE id IN ('+apen.map(Number).join(',')+')');
 console.log('OK  Min side: kjøp timepakke-pillen under timeflisen (390 + 1280, brukt opp, innstemplet, timer igjen)');
}finally{
 await browser.close();
 for(const k of NOKLER)bryter(k,foer[k]??null);
 try{fixture('cleanup',s);}catch(e){}
}
