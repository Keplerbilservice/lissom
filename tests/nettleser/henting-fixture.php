<?php
declare(strict_types=1);
require __DIR__ . '/testdatabase.php';
krev_testdatabase(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
 $tag = 'HentingTest-' . bin2hex(random_bytes(5));
 $admin = DB::settInn('members', ['navn'=>$tag,'epost'=>$tag.'@e2e.lissom.test','rolle'=>'admin','status'=>'aktiv']);
 $token=bin2hex(random_bytes(32));
 DB::settInn('sessions',['member_id'=>$admin,'token_hash'=>hash('sha256',$token),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)]);
 $course=DB::settInn('courses',['slug'=>strtolower($tag),'tittel'=>$tag,'type'=>'kurs','pris_ore'=>10000,'kapasitet'=>8,'status'=>'publisert']);
 $session=DB::settInn('course_sessions',['course_id'=>$course,'start_tid'=>gmdate('Y-m-d H:i:s',time()-86400),'kapasitet'=>8]);
 $booking=DB::settInn('bookings',['course_id'=>$course,'course_session_id'=>$session,'gjest_navn'=>$tag,'gjest_telefon'=>'+4790000000','antall'=>1,'belop_ore'=>10000,'status'=>'betalt']);
 echo json_encode(compact('tag','admin','token','course','session','booking')); exit;
}
$s=json_decode($argv[2] ?? '{}',true);
$b=DB::en('SELECT b.*, c.tittel FROM bookings b JOIN courses c ON c.id=b.course_id WHERE b.id=:i',['i'=>$s['booking']]);
if (!$b || $b['tittel'] !== $s['tag'] || !str_starts_with($s['tag'],'HentingTest-')) throw new RuntimeException('Ukjent fixture');
if ($mode==='member') {
 $plan=DB::en("SELECT navn,timer FROM membership_plans WHERE engangs=0 AND aktiv=1 AND krever_fast_trekk=0 AND timer IS NOT NULL ORDER BY timer LIMIT 1");
 DB::oppdater('members',['rolle'=>'medlem','status'=>'aktiv','medlemskap_type'=>$plan['navn'],'start_dato'=>gmdate('Y-m-d')],['id'=>$s['admin']]);
 DB::settInn('subscriptions',['member_id'=>$s['admin'],'plan'=>$plan['navn'],'pris_ore'=>50000,'status'=>'aktiv']);
 $min=(int)$plan['timer']*60;
 $start=strtotime(Stempling::manedStart().' UTC')+60;
 DB::settInn('check_ins',['member_id'=>$s['admin'],'inn_tid'=>gmdate('Y-m-d H:i:s',$start),'ut_tid'=>gmdate('Y-m-d H:i:s',$start+$min*60),'minutter'=>$min]);
 // A current open session must not cross the real 23:00 closing boundary.
 // A fixed 30-minute offset made evening tests exercise automatic closing instead.
 DB::settInn('check_ins',['member_id'=>$s['admin'],'inn_tid'=>gmdate('Y-m-d H:i:s')]);
}
// Lagervarselet (intern_bestill_mer) for en vare laget i tests/nyadmin-butikk-lager.mjs.
if ($mode==='lagervarsel') { $v=DB::en('SELECT id FROM products WHERE id=:i AND tittel LIKE :t',['i'=>(int)$s['vare'],'t'=>$s['tag'].'%']); echo json_encode(['antall'=>$v?(int)DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type='product' AND ref_id=:i",['i'=>$v['id']]):-1]); exit; }
if ($mode==='kontakt')DB::oppdater('members',['start_dato'=>'2026-09-01','slutt_dato'=>'2027-09-01','timer_per_mnd'=>11],['id'=>$s['admin']]);
if ($mode==='kontaktstatus') { echo json_encode(DB::en('SELECT start_dato,slutt_dato,timer_per_mnd,navn FROM members WHERE id=:i',['i'=>$s['admin']])); exit; }
if ($mode==='regnskap') DB::oppdater('sessions',['maate'=>'passord'],['member_id'=>$s['admin']]);
if ($mode==='regnskap' || $mode==='vanlig') DB::oppdater('members',['rolle'=>$mode==='regnskap'?'regnskap':'medlem'],['id'=>$s['admin']]);
if ($mode==='email') DB::oppdater('bookings',['gjest_epost'=>$s['tag'].'@e2e.lissom.test'],['id'=>$s['booking']]);
if ($mode==='paid') {
 $a=DB::en('SELECT * FROM subscriptions WHERE member_id=:i ORDER BY id DESC LIMIT 1',['i'=>$s['admin']]);
 DB::settInn('payments',['member_id'=>$s['admin'],'subscription_id'=>$a['id'],'formal'=>'medlemskap','type'=>'epayment','status'=>'betalt','belop_ore'=>50000,'gjelder_fra'=>gmdate('Y-m-d'),'created_at'=>gmdate('Y-m-d H:i:s'),'idempotency_key'=>Vipps::uuid(),'vipps_reference'=>'TEST-'.$s['tag']]);
}
if ($mode==='inspect') {
 echo json_encode(['stamp'=>DB::verdi('SELECT hentemelding_at FROM course_sessions WHERE id=:i',['i'=>$s['session']]),'notifications'=>DB::alle("SELECT kanal,tekst,html FROM notifications WHERE mal='ferdig_brent' AND ref_type='booking' AND ref_id=:i",['i'=>$s['booking']])]); exit;
}
if ($mode==='cleanup') {
 // Kursholdere laget fra Brukere i tests/nyadmin-kursholder-bruker.mjs.
 $kh=$s['tag'].'-kh%@e2e.lissom.test';
 DB::kjor('DELETE FROM sessions WHERE member_id IN (SELECT id FROM members WHERE epost LIKE :e)',['e'=>$kh]);
 DB::kjor('DELETE FROM members WHERE epost LIKE :e',['e'=>$kh]);
 if (DB::harTabell('kursholdere')) DB::kjor('DELETE FROM kursholdere WHERE epost LIKE :e',['e'=>$kh]);
 // Varer laget i tests/nyadmin-butikk-lager.mjs, og varslene om dem.
 foreach (DB::alle('SELECT id FROM products WHERE tittel LIKE :t',['t'=>$s['tag'].'%']) as $v) {
  DB::kjor("DELETE FROM notifications WHERE ref_type='product' AND ref_id=:i",['i'=>$v['id']]);
  if ((int)DB::verdi('SELECT COUNT(*) FROM order_lines WHERE product_id=:i',['i'=>$v['id']])===0) DB::kjor('DELETE FROM products WHERE id=:i',['i'=>$v['id']]);
 }
 if (DB::harTabell('admin_kortbruk')) DB::kjor('DELETE FROM admin_kortbruk WHERE member_id=:i',['i'=>$s['admin']]);
 DB::kjor('DELETE FROM check_ins WHERE member_id=:i',['i'=>$s['admin']]);
 DB::kjor('DELETE FROM payments WHERE booking_id=:i',['i'=>$s['booking']]);
 DB::kjor('DELETE FROM payments WHERE member_id=:i',['i'=>$s['admin']]);
 DB::kjor('DELETE FROM subscriptions WHERE member_id=:i',['i'=>$s['admin']]);
 DB::kjor("DELETE FROM notifications WHERE ref_type='booking' AND ref_id=:i",['i'=>$s['booking']]);
 DB::kjor('DELETE FROM bookings WHERE id=:i',['i'=>$s['booking']]);
 DB::kjor("DELETE FROM audit_log WHERE member_id=:i",['i'=>$s['admin']]);
 DB::kjor('DELETE FROM course_sessions WHERE id=:i',['i'=>$s['session']]);
 DB::kjor('DELETE FROM courses WHERE id=:i',['i'=>$s['course']]);
 DB::kjor('DELETE FROM sessions WHERE member_id=:i',['i'=>$s['admin']]);
 DB::kjor('DELETE FROM members WHERE id=:i',['i'=>$s['admin']]);
}
echo '{"ok":true}';
