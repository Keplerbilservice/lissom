import fs from 'node:fs';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}).replace(/^\uFEFF/,''));
const s=fixture('seed');
const base=process.env.HENTING_TEST_URL||'http://127.0.0.1:8160';
const get=async p=>{const r=await fetch(base+p,{headers:{Cookie:'lissom_sesjon='+s.token}});assert.equal(r.status,200);return r.json();};
const post=async handling=>{const r=await fetch(base+'/api/admin/ferdigbrent.php',{method:'POST',headers:{Cookie:'lissom_sesjon='+s.token,'Content-Type':'application/json',Origin:process.env.HENTING_TEST_ORIGIN||'http://lokal.lissom.no:8140'},body:JSON.stringify({handling,oktId:s.session})});const d=await r.json();assert.equal(r.status,200,JSON.stringify(d));return d;};
try {
 let d=await get('/api/admin/ferdigbrent.php?oktId='+s.session);assert.equal(d.deltakere[0].kanSende,false,'Telefon alene er ikke e-postmottaker');assert.equal(d.uker,3);
 d=await post('meld-alle');assert.equal(d.sendt,0);assert.match(d.beskjed,/3 uker/);
 let inspected=fixture('inspect',s);assert.ok(inspected.stamp);assert.equal(inspected.notifications.length,0);
 d=await get('/api/ferdigbrent.php');assert.equal(d.uker,3);assert.ok(d.meldinger.some(m=>m.kurs===s.tag));
 const stamp=inspected.stamp;
 fixture('email',s);d=await post('meld-alle');assert.equal(d.sendt,1);
 inspected=fixture('inspect',s);assert.equal(inspected.notifications.length,1);assert.equal(inspected.notifications[0].kanal,'epost');assert.match(inspected.notifications[0].tekst,/tre uker/);assert.match(inspected.notifications[0].html,/Innen tre uker/);assert.equal(inspected.stamp,stamp);
 d=await post('meld-alle');assert.equal(d.sendt,0);assert.equal(fixture('inspect',s).notifications.length,1);
 await post('angre');assert.ok(!(await get('/api/ferdigbrent.php')).meldinger.some(m=>m.kurs===s.tag));assert.equal(fixture('inspect',s).notifications.length,1);
 console.log('API: uten e-post, offentlig liste, e-post med tre uker, duplikatsperre, bevart frist og angre bestått.');
} finally {fixture('cleanup',s);}
const html=fs.readFileSync('lissom-2108.html','utf8');const start=html.indexOf('  sendFerdigAlle() {');const end=html.indexOf('\n  }',start)+4;const method=eval('({'+html.slice(start,end)+'})').sendFerdigAlle;
let called=0,prompt='';globalThis.window={confirm:t=>{prompt=t;return true;}};
const app={state:{fbKurs:{okt:{oktId:1,tittel:'Testkurs',naar:'i går',meldt:false},deltakere:[{navn:'Uten e-post',kanSende:false}],melding:{tekst:'Innen tre uker'}}},ferdigbrentKall:b=>{assert.equal(b.handling,'meld-alle');called++;}};
method.call(app);assert.equal(called,1);assert.match(prompt,/Mottakere: 0/);assert.match(prompt,/Uten e-post/);assert.match(prompt,/tre uker/);
window.confirm=()=>false;method.call(app);assert.equal(called,1);
console.log('Klient: publisering uten e-post, korrekt bekreftelse og avbryt bestått.');
