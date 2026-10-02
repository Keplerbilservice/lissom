<?php
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));
require dirname(__DIR__) . '/app/bootstrap.php';
if (!str_starts_with((string) Config::hent('vipps_base'), 'http://127.0.0.1:')) throw new RuntimeException('Krever lokal falsk Vipps');
$s=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$n=0;
$sjekk=static function(bool $ok,string $hva)use(&$n):void{if(!$ok)throw new RuntimeException($hva);$n++;};
$sjekk(Medlemskap::trekkForfall(['neste_trekk'=>'2026-11-01'],'2026-10-31')==='2026-11-01','Trekk 1. november bestilt 31. oktober beholder forfallet');
$sjekk(Medlemskap::trekkForfall(['neste_trekk'=>'2026-10-01'],'2026-10-01')==='2026-10-02','Forsinket bestilling respekterer Vipps minimum');
$sjekk(Medlemskap::trekkForfall(['neste_trekk'=>'2027-01-01'],'2026-12-31')==='2027-01-01','Årsskifte beholder forfallet');
$oslo=new DateTimeZone('Europe/Oslo');$imorgen=(new DateTimeImmutable('now',$oslo))->modify('+1 day')->format('Y-m-d');
$plan=Medlemskap::planer()[0]['navn'];
$id=DB::settInn('subscriptions',['member_id'=>$s['admin'],'plan'=>$plan,'pris_ore'=>199000,'status'=>'aktiv','vipps_agreement_id'=>'agr_TEST_'.$s['tag'],'neste_trekk'=>$imorgen]);
try{
 $mine=static fn():bool=>in_array($id,array_map('intval',array_column(Medlemskap::tilTrekk(),'id')),true);
 $sjekk($mine(),'Morgendagens trekk velges i dagens runde');
 DB::oppdater('subscriptions',['neste_trekk'=>(new DateTimeImmutable($imorgen))->modify('+1 day')->format('Y-m-d')],['id'=>$id]);
 $sjekk(!$mine(),'Trekk senere enn i morgen bestilles ikke ennå');
 DB::oppdater('subscriptions',['neste_trekk'=>$imorgen],['id'=>$id]);
 $a=DB::en('SELECT * FROM subscriptions WHERE id=:i',['i'=>$id]);$a['epost']='';
 $sjekk(Medlemskap::trekk($a)==='bedt om trekk til '.$imorgen,'Falsk Vipps mottar bestillingen til riktig dato');
 $p=DB::en('SELECT * FROM payments WHERE subscription_id=:s',['s'=>$id]);
 $sjekk($p['status']==='venter'&&!empty($p['vipps_psp_ref']),'Bestilling er ventende, ikke penger mottatt');
 $maaned=(new DateTimeImmutable($imorgen))->modify('first day of this month')->format('Y-m-d');
 $sjekk($p['gjelder_fra']===$maaned,'Betalingen knyttes til trekkmåneden, ikke bestillingsmåneden');
 $charge=Vipps::hentTrekk($a['vipps_agreement_id'],$p['vipps_psp_ref']);
 $sjekk(($charge['due']??null)===$imorgen,'Falsk Vipps bekrefter faktisk mottatt forfall');
 $sjekk(Medlemskap::trekk($a)==='alt fort','Samme bestilling gjentas ikke');
 $sjekk((int)DB::verdi('SELECT COUNT(*) FROM payments WHERE subscription_id=:s',['s'=>$id])===1,'Bare én betalingsrad etter gjentatt forsøk');
 // Codex 02.10.2026, P1: Vipps laget trekket, men svaret ble borte. Neste natt
 // proeves det igjen med samme Idempotency-Key. Da maa innholdet vaere det samme,
 // ellers avviser Vipps det («idempotency-conflict»). Den falske Vippsen haandhever
 // det her (.trekk-idempotens).
 $styr=__DIR__.'/';$idag=(new DateTimeImmutable('now',$oslo))->format('Y-m-d');$dagEtter=(new DateTimeImmutable($idag))->modify('+1 day')->format('Y-m-d');
 $agr2='agr_TEST2_'.$s['tag'];
 $id2=DB::settInn('subscriptions',['member_id'=>$s['admin'],'plan'=>$plan,'pris_ore'=>199000,'status'=>'aktiv','vipps_agreement_id'=>$agr2,'neste_trekk'=>$imorgen]);
 $a2=DB::en('SELECT * FROM subscriptions WHERE id=:i',['i'=>$id2]);$a2['epost']='';
 $loggFra=is_file($styr.'.falsk-vipps.jsonl')?count(file($styr.'.falsk-vipps.jsonl')):0;
 $foer=count(Vipps::trekkPaaAvtale($agr2));
 file_put_contents($styr.'.trekk-idempotens','ja');file_put_contents($styr.'.trekk-svar-tapt','ja');
 $kastet=false;try{Medlemskap::trekk($a2,$idag);}catch(RuntimeException){$kastet=true;}
 @unlink($styr.'.trekk-svar-tapt');
 $sjekk($kastet,'Tapt svar fra Vipps gir feil i første forsøk');
 $p2=DB::en('SELECT * FROM payments WHERE subscription_id=:s',['s'=>$id2]);
 $sjekk($p2['status']==='feilet'&&$p2['vipps_psp_ref']===null&&(json_decode((string)$p2['trekk_foresporsel'],true)['due']??null)===$imorgen,'Raden er feilet uten trekk-id, og innholdet er lagret');
 $sjekk(Medlemskap::trekkForesporsel($a2,null,$dagEtter)['due']!==$imorgen,'Et forfall regnet ut på nytt dagen etter ville vært et annet');
 $sjekk(Medlemskap::trekk($a2,$dagEtter)==='bedt om trekk til '.$imorgen,'Nytt forsøk dagen etter sender samme forfall og godtas');
 $p2=DB::en('SELECT * FROM payments WHERE subscription_id=:s',['s'=>$id2]);
 $sjekk($p2['status']==='venter'&&!empty($p2['vipps_psp_ref'])&&(int)DB::verdi('SELECT COUNT(*) FROM payments WHERE subscription_id=:s',['s'=>$id2])===1,'Samme rad får trekk-id-en fra Vipps');
 $sjekk(count(Vipps::trekkPaaAvtale($agr2))-$foer===1,'Vipps har ett trekk, ikke to');
 $poster=[];foreach(array_slice(file($styr.'.falsk-vipps.jsonl'),$loggFra) as $l){$k=json_decode($l,true);if(($k['metode']??'')==='POST'&&str_contains((string)($k['sti']??''),$agr2.'/charges'))$poster[]=json_encode($k['kropp']);}
 $sjekk(count($poster)===2&&$poster[0]===$poster[1],'Begge forsøkene sendte nøyaktig samme innhold');
}finally{@unlink(__DIR__.'/.trekk-idempotens');@unlink(__DIR__.'/.trekk-svar-tapt');if(isset($id2)){DB::kjor('DELETE FROM notifications WHERE ref_type=\'medlemskap\' AND ref_id IN (SELECT id FROM payments WHERE subscription_id=:s)',['s'=>$id2]);DB::kjor('DELETE FROM payments WHERE subscription_id=:s',['s'=>$id2]);DB::kjor('DELETE FROM subscriptions WHERE id=:i',['i'=>$id2]);}DB::kjor('DELETE FROM notifications WHERE ref_type=\'medlemskap\' AND ref_id IN (SELECT id FROM payments WHERE subscription_id=:s)',['s'=>$id]);DB::kjor('DELETE FROM payments WHERE subscription_id=:s',['s'=>$id]);DB::kjor('DELETE FROM subscriptions WHERE id=:i',['i'=>$id]);}
echo "$n trekkplankontroller bestått\n";
