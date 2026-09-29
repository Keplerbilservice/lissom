<?php
/**
 * Feilrapportene, kort, for vakta paa eierens PC.
 *
 *   GET ?nokkel=<cron_nokkel>
 *
 * Eieren, 28. september 2026: sjekkene gaar én gang i doegnet, og da skal de
 * ta med feilmeldingene som er rapportert inn. Vakta er ikke logget inn, saa
 * den slipper inn med cron_nokkel — samme noekkel som api/status.php. Uten
 * noekkel: 404, som for status.
 *
 * Svaret er bare det rapporten trenger: hvor mange som er aapne, og én linje
 * per feil. Ingen kontaktinfo og ingen skjermbilder — de staar i admin.
 * Vakta leser; den retter og lukker ingenting.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$nokkel  = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
if (!($nokkel !== '' && $oppgitt !== '' && hash_equals($nokkel, $oppgitt)) && !Sesjon::erAdmin()) {
    Svar::feil('Fant ikke siden.', 404);
}

if (!DB::harTabell('feilrapporter')) {
    Svar::ok(['apne' => 0, 'linjer' => []]);
}

$rader = DB::alle(
    "SELECT slag, melding, feiltekst, side, antall, status, sist_sett
       FROM feilrapporter
      WHERE status <> 'lukket'
   ORDER BY slag = 'melding' DESC, sist_sett DESC
      LIMIT 50"
);

$kort = static fn(string $t, int $n): string => mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t;

Svar::ok([
    'apne'   => count($rader),
    'linjer' => array_map(static function (array $r) use ($kort): string {
        $hva = trim((string) ($r['melding'] ?? '')) !== '' ? '«' . $kort(trim((string) $r['melding']), 120) . '»'
             : $kort(trim((string) ($r['feiltekst'] ?? '')), 120);
        return substr((string) $r['sist_sett'], 0, 16)
            . ' · ' . ($r['slag'] === 'melding' ? 'meldt inn' : 'fanget')
            . ((int) $r['antall'] > 1 ? ' (' . (int) $r['antall'] . '×)' : '')
            . ' · ' . ($r['side'] !== null && $r['side'] !== '' ? $r['side'] : 'ukjent side')
            . ' · ' . $hva;
    }, $rader),
]);
