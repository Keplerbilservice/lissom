<?php
/**
 * Kurskatalogen med ledige plasser. Aapent endepunkt — dette er offentlig
 * informasjon, det samme som staar paa kurssiden.
 *
 * Med ett unntak: samlinger merket «Kun for medlemmer» sendes bare til den
 * som er innlogget som medlem. De sto tidligere i den offentlige lista, saa
 * en medlemsfrokost var synlig for alle — bookbar var den riktignok ikke.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$erMedlem = ($m = Sesjon::medlem()) !== null && er_aktivt_medlem($m);

// ── Stoppeklokke, for admin ──────────────────────────────────────────
//
// «?tid=1» svarer med hvor millisekundene gaar i stedet for katalogen.
// Bare for den som er logget inn som admin: tallene sier noe om hvordan
// basen staar, og det er ikke publikums sak. Kunden faar akkurat det
// samme svaret som for.
if (Foresporsel::tekst('tid') === '1' && Sesjon::erAdmin()) {
    $ms = static fn(float $fra): float => round((microtime(true) - $fra) * 1000, 1);

    $t0 = microtime(true);
    $kurs = Katalog::offentlig($erMedlem);
    $katalogMs = $ms($t0);

    $t0 = microtime(true);
    $rabatter = Katalog::rabatter();
    $rabatterMs = $ms($t0);

    $t0 = microtime(true);
    $fokus = Bilder::fokus();
    $fokusMs = $ms($t0);

    $t0 = microtime(true);
    $json = (string) json_encode(['kurs' => $kurs, 'rabatter' => $rabatter, 'fokus' => $fokus],
                                 JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $jsonMs = $ms($t0);

    $datoer = 0;
    foreach ($kurs as $k) {
        $datoer += count($k['datoer'] ?? []);
    }
    Svar::json([
        'ms' => [
            'katalog'  => $katalogMs,
            'rabatter' => $rabatterMs,
            'fokus'    => $fokusMs,
            'json'     => $jsonMs,
        ],
        'faser'   => Katalog::faser(),
        'kurs'    => count($kurs),
        'datoer'  => $datoer,
        'bytes'   => strlen($json),
        'minne'   => round(memory_get_peak_usage(true) / 1048576, 1) . ' MB',
    ]);
}

// Katalogen bygges i app/lib/katalog.php — serversidene tegner av den samme.
// Fokuspunktene: hvilken del av hvert bilde ramma skal sentreres paa.
Svar::json(['kurs' => Katalog::offentlig($erMedlem), 'rabatter' => Katalog::rabatter(), 'fokus' => Bilder::fokus()]);
