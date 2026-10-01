<?php
/** Ekte isolert DB, syntetisk leverandoer: retry etter gjennomfoert operasjon. */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
$oppsett = krev_testdatabase(dirname(__DIR__));
final class Config
{
    public static array $s;
    public static function hent(string $n, mixed $d = null): mixed { return self::$s[$n] ?? $d; }
    public static function krev(string $n): string { return (string) (self::$s[$n] ?? 'syntetisk'); }
    public static function vippsBase(): string { return 'http://stub.invalid'; }
}
Config::$s = $oppsett;
require dirname(__DIR__) . '/app/lib/db.php';
require dirname(__DIR__) . '/app/lib/vipps.php';
function logg_feil(string $m, ?Throwable $e = null): void {}
final class Booking
{
    public static bool $feil = false;
    public static function markerBetalt(string $ref): bool
    { if (self::$feil) { throw new RuntimeException('DB-feil under behandling'); } return true; }
}
function http_post_form(string $u, array $d, array $h): array
{ return ['status' => 200, 'kropp' => '{"access_token":"syntetisk","expires_in":3600}']; }
$ledger = []; $forsok = []; $mistSvar = false;
$hendelser = [];
function http_get_json(string $u, array $h): array
{ global $hendelser; return ['status' => 200, 'json' => $hendelser, 'kropp' => '[]']; }
function http_post_json(string $u, array $d, array $h): array
{
    global $ledger, $forsok, $mistSvar;
    $nokkel = '';
    foreach ($h as $v) { if (str_starts_with($v, 'Idempotency-Key: ')) { $nokkel = substr($v, 17); } }
    if ($nokkel === '') { throw new RuntimeException('Nokkel mangler'); }
    $forsok[] = $nokkel;
    $ledger[$u . ':' . $nokkel] ??= $d;
    if ($mistSvar) { $mistSvar = false; throw new RuntimeException('Gjennomfoert, men svar tapt'); }
    return ['status' => 200, 'json' => ['ok' => true], 'kropp' => '{}'];
}
function sjekk(string $n, bool $ok): void
{ if (!$ok) { throw new RuntimeException('FEIL: ' . $n); } echo 'OK: ' . $n . "\n"; }
$ref = 'IDEMP-' . bin2hex(random_bytes(6));
$id = DB::settInn('payments', ['vipps_reference' => $ref, 'belop_ore' => 100,
    'formal' => 'ordre', 'status' => 'venter', 'idempotency_key' => Vipps::uuid()]);
try {
    $mistSvar = true;
    try { Vipps::trekk($ref, 100, 0); throw new LogicException('Feilen ble skjult'); }
    catch (RuntimeException $e) { sjekk('capture med tapt svar kaster feil', $e->getMessage() === 'Gjennomfoert, men svar tapt'); }
    Vipps::trekk($ref, 100, 0);
    sjekk('capture retry er samme operasjon', count($ledger) === 1 && $forsok[0] === $forsok[1]);
    $mistSvar = true;
    try { Vipps::refunder($ref, 40, 'journal:1'); throw new LogicException('Feilen ble skjult'); }
    catch (RuntimeException $e) { sjekk('refund med tapt svar kaster feil', $e->getMessage() === 'Gjennomfoert, men svar tapt'); }
    Vipps::refunder($ref, 40, 'journal:1');
    sjekk('refund retry er samme operasjon', count($ledger) === 2 && $forsok[2] === $forsok[3]);
    Vipps::refunder($ref, 40, 'journal:2');
    sjekk('ny tilsiktet delrefusjon har egen identitet', count($ledger) === 3 && $forsok[3] !== $forsok[4]);
    Vipps::trekk($ref, 40, 60);
    sjekk('ny capture-offset har egen identitet', count($ledger) === 4 && $forsok[0] !== $forsok[5]);
    sjekk('UUID-format', preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/', $forsok[0]) === 1);
    Booking::$feil = true;
    try { Vipps::anvendTilstand($ref, ['state' => 'CAPTURED'], true); throw new LogicException('Feilen ble skjult'); }
    catch (RuntimeException $e) { sjekk('webhook-behandlingsfeil propagert', $e->getMessage() === 'DB-feil under behandling'); }
    Booking::$feil = false;
    sjekk('replay kan fullfoere etter feil', Vipps::anvendTilstand($ref, ['state' => 'CAPTURED'], true) === 'CAPTURED');
    $nyHendelse = static fn(string $psp, string $type, int $ore, bool $ok = true): array => [
        'reference' => $ref, 'pspReference' => $psp, 'name' => $type,
        'amount' => ['currency' => 'NOK', 'value' => $ore], 'success' => $ok];
    $hendelser = [$nyHendelse('c1', 'CAPTURED', 60), $nyHendelse('x', 'CAPTURED', 40, false)];
    try { Vipps::anvendTilstand($ref, Vipps::avstemHendelse($ref, 'c1', 'CAPTURED'), true); throw new LogicException('Partial ble betalt'); }
    catch (RuntimeException $e) { sjekk('delvis capture er ikke fullt oppgjort', str_contains($e->getMessage(), 'delvis')); }
    $hendelser[] = $nyHendelse('c2', 'CAPTURED', 40);
    sjekk('capture summeres uten feiloperasjon', Vipps::avstemHendelse($ref, 'c2', 'CAPTURED')['aggregate']['capturedAmount']['value'] === 100);
    $hendelser[] = $nyHendelse('r1', 'REFUNDED', 40);
    Vipps::anvendTilstand($ref, Vipps::avstemHendelse($ref, 'r1', 'REFUNDED'), true);
    $p = DB::en('SELECT status, refundert_ore FROM payments WHERE id = :i', ['i' => $id]);
    sjekk('delrefusjon beholder delvis status og korrekt saldo', $p['status'] === 'delvis_refundert' && (int) $p['refundert_ore'] === 40);
    try { Vipps::avstemHendelse($ref, 'r2', 'REFUNDED'); throw new LogicException('Usynlig event ble godkjent'); }
    catch (RuntimeException $e) { sjekk('forsinket eventlog krever replay', str_contains($e->getMessage(), 'ikke avstemt')); }
    $hendelser[] = $nyHendelse('r2', 'REFUNDED', 60);
    Vipps::anvendTilstand($ref, Vipps::avstemHendelse($ref, 'r2', 'REFUNDED'), true);
    $p = DB::en('SELECT status, refundert_ore FROM payments WHERE id = :i', ['i' => $id]);
    sjekk('flere refusjoner summeres til full', $p['status'] === 'refundert' && (int) $p['refundert_ore'] === 100);
    Vipps::anvendTilstand($ref, ['state' => 'REFUNDED', 'aggregate' => ['refundedAmount' => ['value' => 40]]], true);
    sjekk('gammel refundsnapshot kan ikke senke saldo', (int) DB::verdi('SELECT refundert_ore FROM payments WHERE id = :i', ['i' => $id]) === 100);
    Vipps::anvendTilstand($ref, ['state' => 'ABORTED'], true);
    sjekk('gammel ABORTED bevarer full refusjon', DB::verdi('SELECT status FROM payments WHERE id = :i', ['i' => $id]) === 'refundert');
    $for = count($ledger); $start = count($forsok); $mistSvar = true;
    try { Vipps::refunderTrekk('avtale', 'charge', 20, $ref, 'journal:3'); throw new LogicException('Feilen ble skjult'); }
    catch (RuntimeException $e) { sjekk('recurring tapt svar kaster feil', $e->getMessage() === 'Gjennomfoert, men svar tapt'); }
    Vipps::refunderTrekk('avtale', 'charge', 20, $ref, 'journal:3');
    sjekk('recurring retry samme varige operasjon', count($ledger) === $for + 1 && $forsok[$start] === $forsok[$start + 1]);
    Vipps::refunderTrekk('avtale', 'charge', 20, $ref, 'journal:4');
    sjekk('ny recurring delrefusjon separat', count($ledger) === $for + 2 && $forsok[$start] !== $forsok[$start + 2]);
} finally {
    DB::kjor('DELETE FROM payments WHERE id = :i', ['i' => $id]);
}
