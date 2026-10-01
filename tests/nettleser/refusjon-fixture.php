<?php
declare(strict_types=1);
require __DIR__ . '/testdatabase.php';
$LISSOM_SECRETS = krev_testdatabase(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/app/config.php';
require dirname(__DIR__, 2) . '/app/lib/db.php';
$mode = $argv[1] ?? '';
$id = (int) ($argv[2] ?? 0);
if ($mode === 'seed') {
    $tag = 'RefundBrowser-' . bin2hex(random_bytes(5));
    $member = DB::settInn('members', ['navn'=>$tag, 'epost'=>$tag.'@e2e.lissom.test', 'rolle'=>'admin']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['token_hash'=>hash('sha256',$token), 'member_id'=>$member, 'maate'=>'passord', 'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)]);
    $ref = 'refund-browser-'.bin2hex(random_bytes(8));
    $pid = DB::settInn('payments', ['member_id'=>$member, 'vipps_reference'=>$ref, 'formal'=>'booking',
        'type'=>'epayment', 'belop_ore'=>10000, 'status'=>'betalt', 'idempotency_key'=>bin2hex(random_bytes(16))]);
    echo json_encode(['member'=>$member, 'payment'=>$pid, 'name'=>$tag, 'token'=>$token, 'ref'=>$ref]); exit;
}
$p = DB::en('SELECT p.*, m.navn FROM payments p JOIN members m ON m.id=p.member_id WHERE p.id=:i', ['i'=>$id]);
if (!$p || !str_starts_with($p['navn'],'RefundBrowser-') || !str_starts_with($p['vipps_reference'],'refund-browser-')) {
    throw new RuntimeException('Fant ikke en syntetisk refusjonsfixture.');
}
if ($mode === 'inspect') {
    echo json_encode(['refunded'=>(int)$p['refundert_ore'], 'status'=>$p['status'],
        'operations'=>DB::alle('SELECT client_operation_id,amount_ore,status FROM payment_refunds WHERE payment_id=:i',['i'=>$id])]); exit;
}
if ($mode === 'cleanup') {
    DB::kjor('DELETE FROM payment_refunds WHERE payment_id=:i',['i'=>$id]);
    DB::kjor("DELETE FROM audit_log WHERE (objekt_type='payment' AND objekt_id=:i) OR member_id=:m",['i'=>$id,'m'=>$p['member_id']]);
    DB::kjor('DELETE FROM payments WHERE id=:i',['i'=>$id]);
    DB::kjor('DELETE FROM sessions WHERE member_id=:i',['i'=>$p['member_id']]);
    DB::kjor('DELETE FROM members WHERE id=:i',['i'=>$p['member_id']]);
    echo '{"ok":true}'; exit;
}
throw new RuntimeException('Ukjent testhandling.');
