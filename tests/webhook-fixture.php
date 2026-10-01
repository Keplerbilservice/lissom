<?php
/** Kun den isolerte webhook-harnessen; ingen app-bootstrap eller ekstern HTTP. */
declare(strict_types=1);
$rot = (string) getenv('LISSOM_WEBHOOK_ROT');
require $rot . '/tests/nettleser/testdatabase.php';
$test = krev_testdatabase($rot);
$styrFil = (string) getenv('LISSOM_WEBHOOK_STYR');
$styr = json_decode((string) file_get_contents($styrFil), true, 512, JSON_THROW_ON_ERROR);
final class Config
{
    public static array $s;
    public static function hent(string $n, mixed $d = null): mixed { return self::$s[$n] ?? $d; }
    public static function krev(string $n): string { return (string) (self::$s[$n] ?? 'syntetisk'); }
    public static function vippsBase(): string { return 'http://stub.invalid'; }
}
Config::$s = $test + ['vipps_webhook_secret' => $styr['secret']];
Config::$s['vipps_webhook_secret'] = $styr['secret'];
require $rot . '/app/lib/db.php';
require $rot . '/app/lib/http.php';
require $rot . '/app/lib/vipps.php';
final class Rate { public static function sjekk(string $n, int $maks, int $vindu): void {} }
function logg_feil(string $m, ?Throwable $e = null): void {}
function http_post_form(string $u, array $d, array $h): array
{ return ['status' => 200, 'kropp' => '{"access_token":"syntetisk","expires_in":3600}']; }
function http_get_json(string $u, array $h): array
{
    global $styr;
    return ['status' => 200, 'json' => str_ends_with($u, '/events') ? $styr['events'] : $styr['payment'], 'kropp' => '{}'];
}
function http_post_json(string $u, array $d, array $h): array
{ throw new RuntimeException('Uventet ekstern operasjon i webhook-test'); }
final class Booking
{
    public static function markerBetalt(string $ref): bool
    {
        global $styr, $styrFil;
        if ($styr['fail'] ?? false) { throw new RuntimeException('Syntetisk DB-feil'); }
        if ($styr['delay'] ?? false) { usleep(500000); }
        file_put_contents($styrFil . '.calls', $ref . "\n", FILE_APPEND | LOCK_EX);
        return DB::kjor("UPDATE payments SET status='betalt' WHERE vipps_reference=:r AND status='venter'", ['r' => $ref])->rowCount() > 0;
    }
}
