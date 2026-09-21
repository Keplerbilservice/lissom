<?php
/**
 * Nøklene til kjøpsmålingen fra serveren (app/lib/maaling.php).
 *
 *   GET                   om nøklene er lagt inn, og hvor mange kjøp som er
 *                         målt siste 30 dager
 *   POST handling=lagre   { maal_ga_api_secret?, maal_meta_token? }
 *
 * Nøklene forlater aldri serveren: GET sier bare om de finnes. Et tomt felt
 * i POST betyr «ikke rør», «slett» betyr fjern — som passordene under
 * Varsler. De ligger i innstillinger-tabellen (Config::FRA_BASEN), aldri i
 * content_blocks, som alle kan lese via api/innhold.php.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

const MAAL_FELTER = ['maal_ga_api_secret', 'maal_meta_token'];

if (Foresporsel::metode() === 'POST') {
    Foresporsel::krevSammeOpphav();
    if (Foresporsel::tekst('handling') !== 'lagre') {
        Svar::feil('Ukjent handling.');
    }
    if (!DB::harTabell('innstillinger')) {
        Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
    }
    $kropp = Foresporsel::kropp();
    $lagret = 0;
    foreach (MAAL_FELTER as $f) {
        if (!array_key_exists($f, $kropp)) {
            continue;
        }
        $v = trim((string) Foresporsel::tekst($f));
        if ($v === '') {
            continue; // tomt felt: ikke rør
        }
        if ($v === 'slett') {
            DB::kjor('DELETE FROM innstillinger WHERE nokkel = ?', [$f]);
            $lagret++;
            continue;
        }
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            [$f, mb_substr($v, 0, 500), (int) (Sesjon::medlem()['id'] ?? 0) ?: null]
        );
        $lagret++;
    }
    Config::glemBasen();
    revider('maaling_lagret', null, null, ['felter' => $lagret]);
    Svar::json(['ok' => true, 'beskjed' => 'Lagret.'] + status());
}

/** @return array<string,mixed> */
function status(): array
{
    $harSporing = DB::harKolonne('payments', 'sporing');
    return [
        'gaId'         => trim((string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Marked/GA-id'")),
        'metaId'       => trim((string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Marked/Meta-piksel'")),
        'harGaSecret'  => trim((string) Config::hent('maal_ga_api_secret', '')) !== '',
        'harMetaToken' => trim((string) Config::hent('maal_meta_token', '')) !== '',
        'harSporing'   => $harSporing,
        // Hvor mange betalinger siste 30 dager som hadde samtykke (sporing)
        // — de som kunne maales fra serveren — mot alle betalte.
        'betalte30'    => (int) DB::verdi(
            "SELECT COUNT(*) FROM payments WHERE status = 'betalt' AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY"
        ),
        'medSporing30' => $harSporing ? (int) DB::verdi(
            "SELECT COUNT(*) FROM payments WHERE status = 'betalt' AND sporing IS NOT NULL AND sporing <> ''
              AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY"
        ) : 0,
    ];
}

Svar::json(status());
