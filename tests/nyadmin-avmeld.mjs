// Avmeld foer kursdatoen. Ingen ekte kunder, utsendinger eller Vipps-kall.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const {chromium} = createRequire(import.meta.url)('playwright');
const fixture = (m,s) => JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',m,JSON.stringify(s||{})],{encoding:'utf8'}));
const php = code => execFileSync('php',['-r',`require 'tests/nettleser/testdatabase.php';krev_testdatabase(getcwd());require 'app/bootstrap.php';${code}`],{encoding:'utf8'});
const s=fixture('seed'), BASE='http://lokal.lissom.no:'+(process.env.E2E_PORT||8140);
const status=()=>php(`echo DB::verdi('SELECT status FROM bookings WHERE id=:i',['i'=>${s.booking}]);`).trim();
php(`DB::oppdater('course_sessions',['start_tid'=>gmdate('Y-m-d H:i:s',time()+20*86400)],['id'=>${s.session}]);`);
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try {
  for(const [name,viewport] of [['PC',{width:1280,height:900}],['mobil',{width:390,height:844}]]) {
    php(`DB::oppdater('bookings',['status'=>'reservert','payment_id'=>null],['id'=>${s.booking}]);DB::kjor('DELETE FROM payments WHERE member_id=:i',['i'=>${s.admin}]);`);
    const c=await browser.newContext({viewport});
    await c.addCookies([{name:'lissom_sesjon',value:s.token,domain:'lokal.lissom.no',path:'/'}]);
    const p=await c.newPage(), posts=[], errors=[];
    p.on('pageerror',e=>errors.push(e.message));
    p.on('request',r=>{if(r.method()==='POST'&&r.url().includes('/api/admin/'))posts.push(r.postDataJSON());});
    const open=async()=>{
      await p.goto(`${BASE}/admin#kurs?okt=${s.session}&fra=kalender`,{waitUntil:'networkidle'});
      await p.locator(`[data-k="deltaker"][data-booking="${s.booking}"]`).click();
      await p.getByRole('dialog').getByRole('button',{name:'Avmeld deltaker',exact:true}).click();
    };
    await open();
    let dialog=p.getByRole('dialog');
    await dialog.getByRole('button',{name:'Avbryt',exact:true}).click();
    assert.equal(status(),'reservert',`${name}: Avbryt beholder paameldingen`);
    assert.equal(posts.length,0,`${name}: Avbryt sender ingen endring`);
    await dialog.getByRole('button',{name:'Avmeld deltaker',exact:true}).click();
    await dialog.getByRole('button',{name:'Ja, avmeld deltaker',exact:true}).click();
    await dialog.waitFor({state:'hidden'});
    assert.equal(status(),'avbestilt',`${name}: fremtidig paamelding avmeldes`);
    assert.deepEqual(posts,[{handling:'fjern',id:s.booking}],`${name}: eksisterende avmelding, ingen refusjon`);
    const paid=JSON.parse(php(`
      DB::oppdater('bookings',['status'=>'betalt'],['id'=>${s.booking}]);
      $p1=DB::settInn('payments',['member_id'=>${s.admin},'type'=>'epayment','formal'=>'Test legacy','belop_ore'=>10000,'status'=>'betalt','idempotency_key'=>Vipps::uuid(),'vipps_reference'=>Vipps::uuid()]);
      DB::oppdater('bookings',['payment_id'=>$p1],['id'=>${s.booking}]);
      $p2=DB::settInn('payments',['member_id'=>${s.admin},'booking_id'=>${s.booking},'type'=>'epayment','formal'=>'Test delbetaling','belop_ore'=>5000,'status'=>'betalt','idempotency_key'=>Vipps::uuid(),'vipps_reference'=>Vipps::uuid()]);
      $p3=DB::settInn('payments',['member_id'=>${s.admin},'type'=>'epayment','formal'=>'Uvedkommende betaling','belop_ore'=>9000,'status'=>'betalt','idempotency_key'=>Vipps::uuid(),'vipps_reference'=>Vipps::uuid()]);
      echo json_encode([$p1,$p2,$p3]);
    `));
    await open();
    dialog=p.getByRole('dialog');
    assert.match(await dialog.innerText(),/Å åpne betalingsoversikten avmelder ingen/);
    const count=posts.length;
    await dialog.getByRole('link',{name:'Åpne refusjonsflyten',exact:true}).click();
    await p.getByRole('button',{name:'Refunder',exact:true}).first().waitFor();
    assert.match(p.url(),new RegExp(`#betalinger\\?booking=${s.booking}$`));
    assert.equal(posts.length,count,`${name}: aapning refunderer eller avmelder ikke`);
    const r=await c.request.get(`${BASE}/api/admin/betalinger.php?bookingId=${s.booking}`);
    assert.equal(r.status(),200);
    const rows=(await r.json()).betalinger;
    assert.deepEqual(rows.map(x=>x.id).sort((a,b)=>a-b),paid.slice(0,2).sort((a,b)=>a-b),`${name}: begge betalingskoblinger, ingen uvedkommende betaling`);
    assert.equal(status(),'betalt',`${name}: betalt paamelding staar til refusjonen bekreftes`);
    assert.deepEqual(errors,[]);
    assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
    await c.close();
    console.log(`OK ${name}: avmeld foer dato, avbryt, betalt refusjonsflyt og riktig betalingsutvalg`);
  }
} finally {await browser.close();fixture('cleanup',s);}
