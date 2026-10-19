// Ny Min side: medlemskapet og timene som tredje flis i «Verkstedet nå», på raden
// med Bordplass og Dreieskive (eieren 19.10.2026, visningen KW6ceUUfcvZhWAF1dtCyHT).
// Tre like brede fliser også på mobil. Flisen: navnet, «{n} t igjen» (rødt ved 0)
// og en tynn gul måler; under raden «{brukt} av {totalt} timer brukt i <måned>
// · betalt til <dato>». Trykk/Enter åpner Medlemskapet og endrer ikke ressursvalget.
// Det gamle medlemskapskortet er borte fra forsiden, men står når timeflisen ikke
// vises (her: modulbryteren for stempling av).
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
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const side=async(width)=>{const mob=width<600;const c=await browser.newContext({viewport:{width,height:900},isMobile:mob,hasTouch:mob});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);await c.addInitScript(()=>localStorage.setItem('lissom-samtykke','nei'));const p=await c.newPage();p.errors=[];p.on('pageerror',e=>p.errors.push(e.message));p.trykk=l=>mob?l.tap():l.click();return {c,p};};
const hjem=async p=>{await p.goto(ADR+'/min-side');await p.locator('.msny-hjem .msny-fliser').waitFor();await p.getByText('Verkstedet nå',{exact:true}).waitFor();await p.waitForTimeout(1500);};
const ressursStil=p=>p.locator('.msny-verksted .msny-tre > button:not(.msny-timeflis)').evaluateAll(b=>b.map(x=>x.textContent+'|'+getComputedStyle(x).backgroundColor));
// Timeflisen i Verkstedet nå: rad, mål, tekst og at den åpner Medlemskapet.
const sjekk=async(p,width,tomt)=>{
 const tf=p.locator('.msny-verksted .msny-timeflis');await tf.waitFor();
 assert.equal(await p.locator('.msny-medl').count(),0,'det gamle medlemskapskortet er borte fra forsiden');
 assert.equal(await tf.locator('b').innerText(),plan.replace(/^Årsmedlemskap$/,'Årsmedlem'),'flisen: medlemskapets navn');
 const igjen=await tf.locator('small').innerText();
 if(tomt){assert.equal(igjen,'0 t igjen','flisen: 0 t igjen');}else{assert.match(igjen,/^[\d,]+ t igjen$/,'flisen: timer igjen');assert.notEqual(igjen,'0 t igjen','flisen: ikke tom');}
 const farge=await tf.locator('small').evaluate(e=>getComputedStyle(e).color);
 const fare=await p.evaluate(()=>{const d=document.createElement('i');d.style.color='var(--danger)';document.body.appendChild(d);const c=getComputedStyle(d).color;d.remove();return c;});
 assert.equal(farge===fare,tomt,tomt?'0 igjen: teksten er rød (--danger)':'timer igjen: ikke rød');
 const maal=await tf.locator('.msny-maaler i').evaluate(e=>e.getBoundingClientRect().width/e.parentElement.getBoundingClientRect().width);
 if(tomt)assert.ok(maal>0.99,'måleren full ved 0 igjen');else assert.ok(maal>0&&maal<1,'måleren viser brukt andel');
 assert.equal(await tf.locator('.msny-maaler').evaluate(e=>Math.round(e.getBoundingClientRect().height)),4,'tynn måler');
 // Tre like brede fliser på samme rad, samme drakt som Bordplass/Dreieskive.
 const knapper=p.locator('.msny-verksted .msny-tre > button');
 assert.equal(await knapper.count(),3,'tre fliser på raden');
 const bokser=await knapper.evaluateAll(b=>b.map(x=>{const r=x.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,r:r.right};}));
 assert.ok(bokser.every(b=>Math.abs(b.w-bokser[0].w)<1.5&&Math.abs(b.y-bokser[0].y)<1.5),'like brede, samme rad: '+JSON.stringify(bokser));
 assert.deepEqual(await knapper.locator('b').allInnerTexts().then(t=>t.slice(0,2).sort()),['Bordplass','Dreieskive'],'Bordplass og Dreieskive på raden');
 const drakt=await knapper.evaluateAll(b=>b.map(x=>{const c=getComputedStyle(x),f=getComputedStyle(x.querySelector('b'));return [c.borderTopWidth,c.borderTopStyle,c.borderRadius,f.fontFamily,f.fontSize,f.fontWeight].join('|');}));
 assert.ok(drakt.every(d=>d===drakt[0]),'samme ramme, radius og skrift: '+JSON.stringify(drakt));
 // Navnene står på én linje, uten orddeling: høyden på <b> er én linje.
 for(const n of await knapper.locator('b').all())assert.equal(await n.evaluate(e=>e.getBoundingClientRect().height<=parseFloat(getComputedStyle(e).fontSize)*1.6&&e.scrollWidth<=e.clientWidth+1),true,'navnet står på én linje: '+await n.innerText());
 const linje=await p.locator('.msny-verksted .msny-timelinje').innerText();
 assert.match(linje,/^[\d,]+ av [\d,]+ timer brukt i \p{L}+ · betalt til \d+\. \p{L}+/u,'linja under raden');
 if(tomt)assert.match(linje,/^(\d+) av \1 timer/,'0 igjen: alt brukt');
 assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen vannrett rulling');
 // Flisen er ikke et ressursvalg: trykk åpner Medlemskapet, valget står.
 const valgFoer=await ressursStil(p);
 if(width>=900){await tf.focus();await p.keyboard.press('Enter');}else await p.trykk(tf);
 await p.locator('.msny-hode').getByRole('heading',{name:'Medlemskapet',exact:true}).waitFor();
 await p.trykk(p.getByRole('button',{name:'← Min side',exact:true}));await p.locator('.msny-hjem .msny-fliser').waitFor();
 assert.deepEqual(await ressursStil(p),valgFoer,'ressursvalget er uendret');
};
try{for(const width of [390,1280]){
 bryter('Vis/minsideny','ja');bryter('Vis/minsidestempel',null);
 // 1) Timene brukt opp (fixture: hele planen brukt, innstemplet).
 const fullt=Number(db('SELECT timer FROM membership_plans WHERE navn=:n',{n:plan})[0].timer)*60;
 db("UPDATE check_ins SET minutter=:n, ut_tid=DATE_ADD(inn_tid, INTERVAL :n2 MINUTE) WHERE member_id=:m AND ut_tid IS NOT NULL",{m:s.admin,n:fullt,n2:fullt});
 let {c,p}=await side(width);
 try{await hjem(p);
  await p.locator('.msny-verksted').scrollIntoViewIfNeeded();
  await p.screenshot({path:join(BILDER,'minside-timeflis-tomt-'+width+'.png'),fullPage:width<600}).catch(()=>{});
  await sjekk(p,width,true);
  assert.deepEqual(p.errors,[]);
 }finally{await c.close();}
 // 2) Timer igjen: 7 timer brukt.
 db("UPDATE check_ins SET minutter=420, ut_tid=DATE_ADD(inn_tid, INTERVAL 420 MINUTE) WHERE member_id=:m AND ut_tid IS NOT NULL",{m:s.admin});
 ({c,p}=await side(width));
 try{await hjem(p);
  await p.locator('.msny-verksted').scrollIntoViewIfNeeded();
  await p.screenshot({path:join(BILDER,'minside-timeflis-igjen-'+width+'.png'),fullPage:width<600}).catch(()=>{});
  await sjekk(p,width,false);
  assert.deepEqual(p.errors,[]);
 }finally{await c.close();}
 // 3) Modulbryteren for stempling av: ingen timeflis, det gamle kortet står.
 bryter('Vis/minsidestempel','nei');
 ({c,p}=await side(width));
 try{await p.goto(ADR+'/min-side');await p.locator('.msny-hjem .msny-fliser').waitFor();await p.waitForTimeout(1500);
  assert.equal(await p.locator('.msny-timeflis').count(),0,'stempling av: ingen timeflis');
  assert.equal(await p.locator('.msny-medl').isVisible(),true,'stempling av: det gamle medlemskapskortet står');
  assert.deepEqual(p.errors,[]);
 }finally{await c.close();}
}
console.log('OK  Min side: timeflisen i Verkstedet nå (390 + 1280, tomt og timer igjen)');
}finally{
 await browser.close();
 for(const k of NOKLER)bryter(k,foer[k]??null);
 try{fixture('cleanup',s);}catch(e){}
}
