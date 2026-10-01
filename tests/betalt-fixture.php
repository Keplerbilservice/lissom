<?php
declare(strict_types=1);
// Bare testdata: en mottatt betaling for perioden som løper.
function test_betalt_medlem(int $id): void {
    $m = DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
    if (!$m || !empty($m['betaler_ikke']) || !in_array($m['status'], ['aktiv','prove','pause'], true)) return;
    $plan = Medlemskap::planUansett((string) ($m['medlemskap_type'] ?? ''));
    if (!$plan) {
        $plan = DB::en('SELECT * FROM membership_plans WHERE aktiv = 1 AND engangs = 0 ORDER BY sortering LIMIT 1');
        DB::oppdater('members', ['medlemskap_type' => $plan['navn']], ['id' => $id]);
    }
    DB::settInn('payments', ['member_id' => $id, 'formal' => 'medlemskap', 'type' => 'epayment',
        'status' => 'betalt', 'belop_ore' => (int) $plan['pris_ore'],
        'gjelder_fra' => gmdate('Y-m-d'), 'vipps_reference' => 'TEST-' . bin2hex(random_bytes(12)),
        'idempotency_key' => Vipps::uuid()]);
}
