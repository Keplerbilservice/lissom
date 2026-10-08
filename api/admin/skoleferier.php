<?php
/**
 * Skoleferiene i Vestfold, til «Ny serie» i den nye adminen (/ny-admin).
 *
 *   GET   { perioder: [{ navn, fra, til, skoleaar, anslaattSlutt? }], kilde, hentet }
 *
 * Skoleruta hentes allerede av seg selv (app/lib/skolerute.php, cron). Den
 * gaar foran. Datafila ny-admin/skoleferier-vestfold.json er reserven: den
 * staar for de feriene den hentede ruta ikke har (ikke hentet ennaa, eller
 * fylkets side var nede), med datoene lest av fylkets skolerute 8. oktober 2026.
 * Ingen ny sannhet: samme navn + skoleaar fra den hentede ruta erstatter fila.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('GET');
krev_admin();

$fil = dirname(__DIR__, 2) . '/ny-admin/skoleferier-vestfold.json';
$data = is_file($fil) ? json_decode((string) file_get_contents($fil), true) : null;
$reserve = is_array($data['perioder'] ?? null) ? $data['perioder'] : [];

$hentet = [];
try {
    $hentet = class_exists('Skolerute') ? Skolerute::perioder() : [];
} catch (Throwable $e) {
    logg('Skoleferier: fikk ikke lest skoleruta', ['feil' => $e->getMessage()]);
}

$nokkel = static fn(array $p): string => mb_strtolower(trim((string) ($p['navn'] ?? ''))) . '|' . (string) ($p['skoleaar'] ?? '');
$har = [];
foreach ($hentet as $p) {
    $har[$nokkel($p)] = true;
}
$ut = [];
foreach ($hentet as $p) {
    $ut[] = ['navn' => (string) $p['navn'], 'fra' => (string) $p['fra'], 'til' => (string) $p['til'],
             'skoleaar' => (string) ($p['skoleaar'] ?? ''), 'kilde' => 'hentet'];
}
foreach ($reserve as $p) {
    if (!is_array($p) || isset($har[$nokkel($p)])) {
        continue;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['fra'] ?? '')) !== 1
        || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['til'] ?? '')) !== 1) {
        continue;
    }
    $ut[] = ['navn' => (string) $p['navn'], 'fra' => (string) $p['fra'], 'til' => (string) $p['til'],
             'skoleaar' => (string) ($p['skoleaar'] ?? ''), 'kilde' => 'fil',
             'anslaattSlutt' => !empty($p['anslaattSlutt'])];
}
usort($ut, static fn(array $a, array $b): int => $a['fra'] <=> $b['fra']);

Svar::json([
    'perioder' => $ut,
    'kilde'    => (string) ($data['kilde'] ?? ''),
    'lest'     => (string) ($data['lest'] ?? ''),
]);
