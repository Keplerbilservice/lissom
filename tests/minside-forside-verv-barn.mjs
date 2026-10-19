// Ny Min side: vervekortet og «Ta med barn» også på forsiden, rett under de seks
// flisene — først vervekortet, så Ta med barn (eieren 19.10.2026, visningen
// FS4hHqMvvb1cw9MvNgYVoP). De står fortsatt under Medlemskapet. Samme brytere:
// Vis/verving, Vis/tilleggbarn og medlemmets «Skjul». Barnekortet er kompakt på
// én rad; aktivt barn viser «Aktivt · <måned>» i stedet for pris og knapp.
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
const NOKLER=['Vis/minsideny','Vis/verving','Vis/tilleggbarn'];
const foer=Object.fromEntries(db("SELECT nokkel, verdi FROM content_blocks WHERE nokkel IN ('Vis/minsideny','Vis/verving','Vis/tilleggbarn')").map(r=>[r.nokkel,r.verdi]));
const prisFoer=(db("SELECT verdi FROM innstillinger WHERE nokkel='tillegg_barn_pris_ore'")[0]||{}).verdi??null;
const s=fixture('seed');fixture('member',s);fixture('paid',s);
db("INSERT INTO innstillinger (nokkel, verdi) VALUES ('tillegg_barn_pris_ore','59900') ON DUPLICATE KEY UPDATE verdi=VALUES(verdi)");
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const side=async(width)=>{const mob=width<600;const c=await browser.newContext({viewport:{width,height:900},isMobile:mob,hasTouch:mob});await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);await c.addInitScript(()=>localStorage.setItem('lissom-samtykke','nei'));const p=await c.newPage();p.errors=[];p.on('pageerror',e=>p.errors.push(e.message));p.trykk=l=>mob?l.tap():l.click();return {c,p};};
const flis=(p,navn)=>p.locator('.msny-fliser .msny-flis').filter({has:p.locator('b',{hasText:new RegExp('^'+navn)})});
const hjem=async p=>{await p.goto(ADR+'/min-side');await p.locator('.msny-hjem .msny-fliser').waitFor();await p.waitForTimeout(1500);};
try{for(const width of [390,1280]){
 bryter('Vis/minsideny','ja');bryter('Vis/verving','ja');bryter('Vis/tilleggbarn','ja');
 db("DELETE FROM medlem_tillegg WHERE member_id=:m",{m:s.admin});
 let {c,p}=await side(width);
 try{
  // 1) Forsiden: flisene, så vervekortet, så Ta med barn.
  await hjem(p);
  const verv=p.locator('#minside-verving'),barn=p.locator('.ms-o-barn');
  await verv.waitFor();await barn.waitFor();
  const fl=await p.locator('.msny-fliser').boundingBox(),vb=await verv.boundingBox(),bb=await barn.boundingBox();
  assert.ok(fl.y+fl.height<=vb.y+1&&vb.y+vb.height<=bb.y+1,'rekkefølge: fliser, verv, barn');
  assert.ok(vb.y-(fl.y+fl.height)<60&&bb.y-(vb.y+vb.height)<60,'kortene står rett under flisene');
  assert.match(await verv.innerText(),/Del skapergleden og få \d+ timer i verkstedet!/,'verv: tittel');
  assert.equal(await verv.getByRole('button',{name:'Kopier personlig lenke',exact:true}).isVisible(),true,'verv: knapp');
  const bt=await barn.innerText();
  assert.match(bt,/Ta med barn/,'barn: tittel');
  assert.match(bt,/Barnet ditt \(opptil \d+ år\) kan være med deg i verkstedet hele måneden\./,'barn: linja');
  assert.match(bt,/599.*per måned/s,'barn: pris');
  assert.doesNotMatch(bt,/per kalendermåned|Leire og glasur/,'barn: kompakt, uten lista');
  assert.equal(await p.locator('.ms-o-barn > .ms-modulvalg').isVisible(),false,'barn: modulvalget står ikke på forsiden');
  assert.equal(await p.locator('#minside-verving > .ms-modulvalg').isVisible(),false,'verv: modulvalget står ikke på forsiden');
  assert.equal(await p.locator('.msny-hjem [data-ms-modul="ovn"] > .ms-modulvalg').isVisible(),false,'ovnen står ikke på forsiden');
  const kjop=barn.getByRole('button',{name:/^Kjøp for \p{L}+/u}).first();
  assert.equal(await kjop.isVisible(),true,'barn: Kjøp-knapp');
  if(width>=900){const t=await barn.locator('.ms-barn-hjemlinje').boundingBox(),pr=await barn.locator('.ms-barn-pris').boundingBox(),k=await kjop.boundingBox();
   assert.ok(pr.x>t.x+t.width-1&&k.x>pr.x+pr.width-1&&Math.abs((pr.y+pr.height/2)-(k.y+k.height/2))<20,'PC: tekst, pris og knapp på én rad');}
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'ingen vannrett rulling');
  await verv.scrollIntoViewIfNeeded();
  await p.screenshot({path:join(BILDER,'minside-forside-verv-barn-'+width+'.png'),fullPage:true}).catch(()=>{});
  // Kjøp åpner det samme kjøpet som på Medlemskapet.
  await p.trykk(kjop);await p.getByText(/^Ta med barn · \p{L}+/u).waitFor();
  await p.trykk(p.getByRole('button',{name:'Avbryt',exact:true}).last());await p.getByText(/^Ta med barn · \p{L}+/u).waitFor({state:'hidden'});
  // 2) Under Medlemskapet som før.
  await p.trykk(flis(p,'Medlemskapet'));await p.locator('.msny-hode').getByRole('heading',{name:'Medlemskapet',exact:true}).waitFor();
  assert.equal(await verv.isVisible(),true,'Medlemskapet: vervekortet');
  assert.equal(await barn.isVisible(),true,'Medlemskapet: Ta med barn');
  assert.match(await barn.innerText(),/Leire og glasur er ikke inkludert/,'Medlemskapet: hele barnekortet');
  // 3) Medlemmets «Skjul» på vervekortet: borte fra forsiden.
  await p.trykk(p.getByRole('switch',{name:'Verv en venn: vis/skjul',exact:true}));
  await p.trykk(p.getByRole('button',{name:'← Min side',exact:true}));await p.locator('.msny-hjem').waitFor();
  assert.equal(await verv.isVisible(),false,'Skjul: vervekortet borte fra forsiden');
  assert.equal(await barn.isVisible(),true,'Skjul: Ta med barn står');
  await p.evaluate(()=>{for(const k of Object.keys(localStorage))if(k.startsWith('lissom_minside_moduler_'))localStorage.removeItem(k);});
  assert.deepEqual(p.errors,[]);
 }finally{await c.close();}
 // 4) Bryterne av: borte begge steder.
 bryter('Vis/verving','nei');bryter('Vis/tilleggbarn','nei');
 ({c,p}=await side(width));
 try{await hjem(p);
  assert.equal(await p.locator('#minside-verving').count(),0,'Vis/verving av: borte på forsiden');
  assert.equal(await p.locator('.ms-o-barn').count(),0,'Vis/tilleggbarn av: borte på forsiden');
  await p.trykk(flis(p,'Medlemskapet'));await p.locator('.msny-hode').getByRole('heading',{name:'Medlemskapet',exact:true}).waitFor();
  assert.equal(await p.locator('#minside-verving').count()+await p.locator('.ms-o-barn').count(),0,'av: borte under Medlemskapet');
  assert.deepEqual(p.errors,[]);
 }finally{await c.close();}
 // 5) Aktivt barn: «Aktivt · <måned>» i stedet for pris og knapp.
 bryter('Vis/tilleggbarn','ja');
 db("INSERT INTO medlem_tillegg (member_id, type, maaned, barn_navn, barn_alder, pris_ore, status, vilkaar_akseptert_at, betalt_at) VALUES (:m,'barn',DATE_FORMAT(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','+02:00'),'%Y-%m'),'E2E Barn',7,59900,'aktiv',UTC_TIMESTAMP(),UTC_TIMESTAMP())",{m:s.admin});
 ({c,p}=await side(width));
 try{await hjem(p);const barn=p.locator('.ms-o-barn');await barn.waitFor();
  assert.match(await barn.innerText(),/Aktivt · \p{L}+/iu,'aktivt: merket står');
  assert.equal(await barn.locator('.ms-barn-pris').count(),0,'aktivt: ingen pris');
  assert.equal(await barn.locator('.ms-barn-knapper').isVisible(),false,'aktivt: ingen knapp');
  await barn.screenshot({path:join(BILDER,'minside-forside-barn-aktivt-'+width+'.png')}).catch(()=>{});
  assert.deepEqual(p.errors,[]);
 }finally{await c.close();}
}
console.log('OK  Min side: vervekortet og Ta med barn på forsiden (390 + 1280)');
}finally{
 await browser.close();
 db("DELETE FROM medlem_tillegg WHERE member_id=:m",{m:s.admin});
 for(const k of NOKLER)bryter(k,foer[k]??null);
 if(prisFoer===null)db("DELETE FROM innstillinger WHERE nokkel='tillegg_barn_pris_ore'");else db("UPDATE innstillinger SET verdi=:v WHERE nokkel='tillegg_barn_pris_ore'",{v:prisFoer});
 try{fixture('cleanup',s);}catch(e){}
}
