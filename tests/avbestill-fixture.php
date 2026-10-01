<?php
/** Ekte app; bare bakgrunnsutsending og Vipps erstattes i isolert HTTP-test. */
declare(strict_types=1);
$rot = (string) getenv('LISSOM_AVBESTILL_ROT');
require $rot . '/tests/nettleser/testdatabase.php';
krev_testdatabase($rot);
final class Tikk { public static function planlegg(): void {} }
final class Vipps
{
    public static function refunder(string $ref, int $amount, string $operation): array
    {
        $fil = (string) getenv('LISSOM_AVBESTILL_STYR');
        $s = json_decode((string) file_get_contents($fil), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($fil . '.calls', json_encode([$ref, $amount, $operation]) . "\n", FILE_APPEND | LOCK_EX);
        if ($s['delay'] ?? false) { usleep(600000); }
        if (($s['fail'] ?? '') === 'before') { throw new RuntimeException('Syntetisk leverandoerfeil'); }
        $f = fopen($fil . '.ledger', 'c+'); flock($f, LOCK_EX);
        $ledger = json_decode(stream_get_contents($f) ?: '{}', true) ?: [];
        $ledger[$ref . ':' . $operation] ??= $amount;
        rewind($f); ftruncate($f, 0); fwrite($f, json_encode($ledger)); fflush($f); flock($f, LOCK_UN); fclose($f);
        if (($s['fail'] ?? '') === 'after') { throw new RuntimeException('Syntetisk tapt svar etter gjennomfoering'); }
        return ['ok' => true];
    }
}
require $rot . '/app/bootstrap.php';
