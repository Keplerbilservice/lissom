// Ekte kundeside og API mot isolert testdatabase; ingen produksjonsdata.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const db=(sql,p={})=>JSON.parse(execFileSync('php',['tests/nettleser/db.php',sql,JSON.stringify(p)],{encoding:'utf8'})||'[]');
const old=db("SELECT verdi FROM content_blocks WHERE nokkel='Vis/minsideny'");
const own=fixture('seed'),foreign=fixture('seed');
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const reset=()=>{
 db("UPDATE members SET rolle='medlem',status='ingen' WHERE id=:i",{i:own.admin});
 db("UPDATE bookings SET member_id=:m,status='reservert',avbestilt_at=NULL WHERE id=:i",{m:own.admin,i:own.booking});
 db('UPDATE course_sessions SET start_tid=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 20 DAY) WHERE id=:i',{i:own.session});
};
try {
 for(const [width,ny] of [[1280,false],[390,true]]) {
  reset();
  db("INSERT INTO content_blocks(nokkel,verdi) VALUES('Vis/minsideny',:v) ON DUPLICATE KEY UPDATE verdi=VALUES(verdi)",{v:ny?'ja':'nei'});
  const c=await browser.newContext({viewport:{width,height:900}});
  await c.addCookies([{name:'lissom_sesjon',value:own.token,domain:'lokal.lissom.no',path:'/'}]);
  await c.addInitScript(()=>localStorage.setItem('lissom-samtykke','nei'));
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
  try {
   await p.goto('http://lokal.lissom.no:8140/min-side');
   const api=(path,body)=>p.evaluate(async({path,body})=>{const r=await fetch(path,body===undefined?{}:{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});return {status:r.status,data:await r.json()};},{path,body});
   let result=await api('/api/mine-plasser.php');
   let place=result.data.plasser.find(x=>Number(x.id)===own.booking);
   assert.ok(place.ubetalt&&place.kanAvmelde);assert.equal(place.kursbevis,null);
   assert.match(place.frist,/Betalingshistorikk.*registrert/);assert.match(place.frist,/gjennomført og betalt/);
   assert.equal((await api('/api/avbestill.php',{bookingId:foreign.booking})).status,404,'annen deltakers booking er beskyttet');
   assert.equal(db('SELECT status FROM bookings WHERE id=:i',{i:foreign.booking})[0].status,'betalt');
   db('UPDATE course_sessions SET start_tid=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=:i',{i:own.session});
   result=await api('/api/mine-plasser.php');place=result.data.plasser.find(x=>Number(x.id)===own.booking);
   assert.equal(place.kanAvmelde,false);assert.equal(place.kursbevis,null);
   assert.equal((await api('/api/avbestill.php',{bookingId:own.booking})).status,409,'startet kurs avvises');
   reset();await p.reload();
   if(ny)await p.locator('.msny-flis').filter({has:p.locator('b',{hasText:/^Kursene mine$/})}).click();
   const cards=p.locator('#minside-pameldinger');
   await cards.getByText(/Betalingshistorikk vises når betalinger er registrert/).waitFor();
   await cards.getByRole('button',{name:'Avbestill',exact:true}).click();
   await p.getByRole('button',{name:'Avmeld meg fra kurset',exact:true}).waitFor();
   await p.getByRole('button',{name:'Lukk',exact:true}).last().click();
   assert.equal(db('SELECT status FROM bookings WHERE id=:i',{i:own.booking})[0].status,'reservert','lukk bekreftelse beholder plass');
   await cards.getByRole('button',{name:'Avbestill',exact:true}).click();
   await p.getByRole('button',{name:'Avmeld meg fra kurset',exact:true}).click();
   await p.getByText('Plassen er avbestilt',{exact:true}).first().waitFor();
   assert.equal(db('SELECT status FROM bookings WHERE id=:i',{i:own.booking})[0].status,'avbestilt');
   assert.equal(Number(db('SELECT COUNT(*) n FROM payments WHERE booking_id=:i',{i:own.booking})[0].n),0,'ingen betalingsposter opprettet');
   assert.equal((await api('/api/avbestill.php',{bookingId:own.booking})).status,409,'dobbel avmelding avvises');
   assert.deepEqual(errors,[]);
   console.log(width+' px: beskjed, egen avmelding, avbrutt bekreftelse, tilgang og kursstart bestått.');
  } finally {await c.close();}
 }
} finally {
 await browser.close();
 if(old.length)db("UPDATE content_blocks SET verdi=:v WHERE nokkel='Vis/minsideny'",{v:old[0].verdi});
 else db("DELETE FROM content_blocks WHERE nokkel='Vis/minsideny'");
 fixture('cleanup',own);fixture('cleanup',foreign);
}
