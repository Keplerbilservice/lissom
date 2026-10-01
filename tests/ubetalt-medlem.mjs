import {tmpdir} from 'node:os';import {join} from 'node:path';const report=process.env.LISSOM_TEST_RAPPORT||(process.platform==='win32'?'C:/Users/info/kepler-lissom-analyse-20261001':join(tmpdir(),'lissom-nyadmin-kontroll'));
import assert from 'node:assert/strict';import {createRequire} from 'node:module';import {execFileSync} from 'node:child_process';
const {chromium}=createRequire(import.meta.url)('playwright');
const fixture=(mode,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',mode,JSON.stringify(s||{})],{encoding:'utf8'}));
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try {for(const width of [390,1280]){
 const s=fixture('seed');fixture('member',s);const c=await browser.newContext({viewport:{width,height:900}});
 try {
 const kontroll=execFileSync('php',['tests/betalt-periode.php',JSON.stringify(s)],{encoding:'utf8'}).trim();assert.match(kontroll,/^27 betalingsperiodekontroller bestått$/);console.log(kontroll);
 await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.goto('http://lokal.lissom.no:8140/min-side');await p.getByRole('heading',{name:'Medlemskapet venter på betaling'}).waitFor();
 const før=await p.evaluate(async()=>{const meg=await fetch('/api/meg.php').then(r=>r.json()),chat=await fetch('/api/chat.php');const inn=await fetch('/api/stempling.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({handling:'inn'})});return {meg,chat:chat.status,inn:inn.status};});
 assert.equal(før.meg.erMedlem,false);assert.equal(før.meg.medlem.status,'venterbetaling');assert.deepEqual(før.meg.internInfo,{});assert.equal(før.meg.medlemsrabatt,0);assert.equal(før.chat,403);assert.equal(før.inn,403);
 await p.getByRole('button',{name:'Stemple ut',exact:true}).waitFor();await p.getByRole('button',{name:'Stemple ut',exact:true}).click();await p.getByRole('button',{name:'Bekreft',exact:true}).click();await p.getByText('Du er stemplet ut',{exact:true}).waitFor();
 const st=await p.evaluate(async()=>fetch('/api/stempling.php').then(r=>r.json()));assert.equal(st.innstemplet,false);assert.equal(st.timer.igjen,0);assert.deepEqual(st.inne.liste,[]);
 await p.screenshot({path:`${report}/ubetalt-medlem-${width}.png`,fullPage:true});
 fixture('paid',s);await p.reload();await p.getByRole('switch',{name:'Stemple inn og timene dine: vis/skjul',exact:true}).waitFor();const etter=await p.evaluate(async()=>fetch('/api/meg.php').then(r=>r.json()));assert.equal(etter.erMedlem,true);assert.equal(etter.betalingMangler,false);assert.deepEqual(errors,[]);console.log(`${width} px: ubetalt sperret, utstempling mulig, betaling gjenåpner tilgang`);
 }finally{await c.close();fixture('cleanup',s);}
}}finally{await browser.close();}
