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
 // .idag lar den falske Vippsen avvise et forfall som ikke er minst én dag fram.
 $styr=__DIR__.'/';$idag=(new DateTimeImmutable('now',$oslo))->format('Y-m-d');$dagEtter=(new DateTimeImmutable($idag))->modify('+1 day')->format('Y-m-d');$toDager=(new DateTimeImmutable($idag))->modify('+2 day')->format('Y-m-d');
 $styrfil=static function(string $n,string $v)use($styr):void{if($v==='')@unlink($styr.$n);else file_put_contents($styr.$n,$v);};
 $nyAvtale=static function(string $agr)use($s,$plan,$imorgen):array{$i=DB::settInn('subscriptions',['member_id'=>$s['admin'],'plan'=>$plan,'pris_ore'=>199000,'status'=>'aktiv','vipps_agreement_id'=>$agr,'neste_trekk'=>$imorgen]);$a=DB::en('SELECT * FROM subscriptions WHERE id=:i',['i'=>$i]);$a['epost']='';return $a;};
 $rad=static fn(array $a):array=>DB::en('SELECT * FROM payments WHERE subscription_id=:s',['s'=>(int)$a['id']]);
 $forsokPaa=static fn(array $p):array=>json_decode((string)$p['trekk_foresporsel'],true)['forsok']??[];
 $poster=static function(string $agr,int $fra)use($styr):array{$ut=[];foreach(array_slice(file($styr.'.falsk-vipps.jsonl'),$fra) as $l){$k=json_decode($l,true);if(($k['metode']??'')==='POST'&&str_contains((string)($k['sti']??''),$agr.'/charges'))$ut[]=$k['kropp'];}return $ut;};
 $loggLengde=static fn():int=>is_file($styr.'.falsk-vipps.jsonl')?count(file($styr.'.falsk-vipps.jsonl')):0;
 $styrfil('.trekk-idempotens','ja');$styrfil('.idag',$idag);
 $nye=[];
 // A. Vipps laget trekket, men svaret ble borte.
 $a2=$nyAvtale('agr_TEST2_'.$s['tag']);$nye[]=(int)$a2['id'];$fra2=$loggLengde();
 $styrfil('.trekk-svar-tapt','ja');
 $kastet=false;try{Medlemskap::trekk($a2,$idag);}catch(RuntimeException){$kastet=true;}
 $styrfil('.trekk-svar-tapt','');
 $sjekk($kastet,'Tapt svar fra Vipps gir feil i første forsøk');
 $p2=$rad($a2);
 $sjekk($p2['status']==='feilet'&&$p2['vipps_psp_ref']===null&&($forsokPaa($p2)[0]['kropp']['due']??null)===$imorgen,'Raden er feilet uten trekk-id, og innholdet er lagret');
 $sjekk(Medlemskap::trekkForesporsel($a2,null,$dagEtter)['due']!==$imorgen,'Et forfall regnet ut på nytt dagen etter ville vært et annet');
 $styrfil('.idag',$dagEtter);
 $sjekk(Medlemskap::trekk($a2,$dagEtter)==='bedt om trekk til '.$imorgen,'Nytt forsøk dagen etter sender samme forfall og får det første svaret');
 $p2=$rad($a2);
 $sjekk($p2['status']==='venter'&&!empty($p2['vipps_psp_ref'])&&(int)DB::verdi('SELECT COUNT(*) FROM payments WHERE subscription_id=:s',['s'=>(int)$a2['id']])===1,'Samme rad får trekk-id-en fra Vipps');
 $sjekk(count(Vipps::trekkPaaAvtale($a2['vipps_agreement_id']))===1,'Vipps har ett trekk, ikke to');
 $pA=$poster($a2['vipps_agreement_id'],$fra2);
 $sjekk(count($pA)===2&&$pA[0]===$pA[1],'Begge forsøkene sendte nøyaktig samme innhold');
 // B. Nettbrudd FØR Vipps laget trekket. Neste natt er gårsdagens forfall ugyldig.
 $styrfil('.idag',$idag);
 $a3=$nyAvtale('agr_TEST3_'.$s['tag']);$nye[]=(int)$a3['id'];$fra3=$loggLengde();
 $styrfil('.trekk-feiler','ja');
 $kastet=false;try{Medlemskap::trekk($a3,$idag);}catch(RuntimeException){$kastet=true;}
 $styrfil('.trekk-feiler','');
 $sjekk($kastet&&$rad($a3)['status']==='feilet'&&count(Vipps::trekkPaaAvtale($a3['vipps_agreement_id']))===0,'Nettbrudd: raden er feilet, og Vipps har ikke noe trekk');
 $styrfil('.idag',$dagEtter);
 $sjekk(Medlemskap::trekk($a3,$dagEtter)==='bedt om trekk til '.$toDager,'Neste natt: avvist forfall gir nytt trekk med gyldig forfall');
 $p3=$rad($a3);$f3=$forsokPaa($p3);
 $sjekk($p3['status']==='venter'&&!empty($p3['vipps_psp_ref'])&&(int)DB::verdi('SELECT COUNT(*) FROM payments WHERE subscription_id=:s',['s'=>(int)$a3['id']])===1,'… på samme rad, med trekk-id');
 $sjekk(count($f3)===2&&$f3[0]['nokkel']!==$f3[1]['nokkel']&&$f3[1]['kropp']['due']===$toDager&&$f3[1]['kropp']['amount']===$f3[0]['kropp']['amount'],'… med ny nøkkel, samme beløp, og begge forsøkene lagret');
 $sjekk(count(Vipps::trekkPaaAvtale($a3['vipps_agreement_id']))===1,'… og Vipps har ett trekk');
 $pB=$poster($a3['vipps_agreement_id'],$fra3);
 $sjekk(count($pB)===3&&$pB[0]===$pB[1]&&$pB[2]['due']===$toDager,'… etter nettbrudd, avvist gjentakelse og nytt trekk');
 $sjekk(Medlemskap::trekk($a3,$dagEtter)==='alt fort','… og en runde til gir ikke et trekk til');
 // C. Vipps har glemt nøkkelen og avviser, men trekket finnes. Det brukes.
 $styrfil('.idag',$idag);
 $a4=$nyAvtale('agr_TEST4_'.$s['tag']);$nye[]=(int)$a4['id'];
 $styrfil('.trekk-svar-tapt','ja');
 try{Medlemskap::trekk($a4,$idag);}catch(RuntimeException){}
 $styrfil('.trekk-svar-tapt','');
 $hos=Vipps::trekkPaaAvtale($a4['vipps_agreement_id']);
 $styrfil('.glem-nokler','ja');$styrfil('.idag',$dagEtter);
 $sjekk(Medlemskap::trekk($a4,$dagEtter)==='bedt om trekk til '.$imorgen,'Avvist gjentakelse: trekket som finnes hos Vipps brukes');
 $styrfil('.glem-nokler','');
 $p4=$rad($a4);
 $sjekk(count($hos)===1&&$p4['status']==='venter'&&$p4['vipps_psp_ref']===(string)$hos[0]['id']&&count(Vipps::trekkPaaAvtale($a4['vipps_agreement_id']))===1&&count($forsokPaa($p4))===1,'… med samme trekk-id, uten nytt trekk eller ny nøkkel');
}finally{foreach(['.trekk-idempotens','.trekk-svar-tapt','.trekk-feiler','.idag','.glem-nokler'] as $f)@unlink(__DIR__.'/'.$f);foreach($nye??[] as $sid){DB::kjor('DELETE FROM notifications WHERE ref_type=\'medlemskap\' AND ref_id IN (SELECT id FROM payments WHERE subscription_id=:s)',['s'=>$sid]);DB::kjor('DELETE FROM payments WHERE subscription_id=:s',['s'=>$sid]);DB::kjor('DELETE FROM subscriptions WHERE id=:i',['i'=>$sid]);}DB::kjor('DELETE FROM notifications WHERE ref_type=\'medlemskap\' AND ref_id IN (SELECT id FROM payments WHERE subscription_id=:s)',['s'=>$id]);DB::kjor('DELETE FROM payments WHERE subscription_id=:s',['s'=>$id]);DB::kjor('DELETE FROM subscriptions WHERE id=:i',['i'=>$id]);}
echo "$n trekkplankontroller bestått\n";
