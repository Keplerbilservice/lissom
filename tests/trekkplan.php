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
}finally{DB::kjor('DELETE FROM notifications WHERE ref_type=\'medlemskap\' AND ref_id IN (SELECT id FROM payments WHERE subscription_id=:s)',['s'=>$id]);DB::kjor('DELETE FROM payments WHERE subscription_id=:s',['s'=>$id]);DB::kjor('DELETE FROM subscriptions WHERE id=:i',['i'=>$id]);}
echo "$n trekkplankontroller bestått\n";
