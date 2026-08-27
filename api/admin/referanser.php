<?php
/**
 * Referansekundene — opprett, endre, flytt og slaa av.
 *
 *   GET                     alle, ogsaa de som ikke staar ute
 *   POST handling=lagre     ny eller endret kunde
 *   POST handling=flytt     { id, retning: opp|ned }
 *   POST handling=slett     { id }
 *
 * Samtykket er et eget felt, og et kort kan ikke staa ute uten. Det er
 * verkstedet som maa ha lov til aa vise navn, logo, bilde og sitat — det kan
 * ikke koden avgjore, men den kan la vaere aa publisere for noen har sagt at
 * lov finnes.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (!DB::harTabell('referansekunder')) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
}

/** Alle kundene, slik admin viser dem. */
function kundene(): array
{
    return array_map(static fn($r) => [
        'id'        => (int) $r['id'],
        'navn'      => (string) $r['navn'],
        'bilde'     => (string) ($r['bilde'] ?? ''),
        'tekst'     => (string) ($r['tekst'] ?? ''),
        'sitat'     => (string) ($r['sitat'] ?? ''),
        'sitatAv'   => (string) ($r['sitat_av'] ?? ''),
        'lenke'     => (string) ($r['lenke'] ?? ''),
        'sortering' => (int) $r['sortering'],
        'aktiv'     => (int) $r['aktiv'] === 1,
        'samtykke'  => (int) $r['samtykke'] === 1,
        // Staar den faktisk ute? Begge maa vaere paa.
        'ute'       => (int) $r['aktiv'] === 1 && (int) $r['samtykke'] === 1,
    ], DB::alle('SELECT * FROM referansekunder ORDER BY sortering, navn'));
}

if (Foresporsel::metode() === 'GET') {
    Svar::json(['kunder' => kundene()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling', 'lagre');

// ── Ny eller endret ────────────────────────────────────────────────────────
if ($handling === 'lagre') {
    $id   = Foresporsel::heltall('id');
    $navn = mb_substr(trim(Foresporsel::tekst('navn')), 0, 191);
    if ($navn === '') {
        Svar::feil('Kunden må ha et navn.');
    }

    $lenke = trim(Foresporsel::tekst('lenke'));
    // Bare http og https. En javascript:-adresse i et felt som blir til en
    // lenke paa forsida er et hull, ikke en lenke.
    if ($lenke !== '' && preg_match('#^https?://#i', $lenke) !== 1) {
        Svar::feil('Lenken må begynne med http:// eller https://');
    }

    $data = [
        'navn'     => $navn,
        'tekst'    => trim(mb_substr(Foresporsel::tekst('tekst'), 0, 2000)) ?: null,
        'sitat'    => trim(mb_substr(Foresporsel::tekst('sitat'), 0, 500)) ?: null,
        'sitat_av' => trim(mb_substr(Foresporsel::tekst('sitatAv'), 0, 191)) ?: null,
        'lenke'    => $lenke ?: null,
        'aktiv'    => Foresporsel::tekst('aktiv') === 'ja' ? 1 : 0,
        'samtykke' => Foresporsel::tekst('samtykke') === 'ja' ? 1 : 0,
    ];

    // Bare naar feltet er med: et skjema som ikke kjenner bildet skal ikke
    // toemme det. basename() klipper bort alt som ligner en sti.
    if (array_key_exists('bilde', Foresporsel::kropp())) {
        $fil = basename(trim((string) (Foresporsel::kropp()['bilde'] ?? '')));
        $data['bilde'] = $fil !== '' ? mb_substr($fil, 0, 64) : null;
    }

    if ($id > 0) {
        if (DB::en('SELECT id FROM referansekunder WHERE id = :i', ['i' => $id]) === null) {
            Svar::feil('Fant ikke kunden.', 404);
        }
        DB::oppdater('referansekunder', $data, ['id' => $id]);
        revider('referanse_endret', 'referanse', $id, ['navn' => $navn]);
    } else {
        // Nye legger seg bakerst.
        $data['sortering'] = ((int) DB::verdi('SELECT COALESCE(MAX(sortering), 0) FROM referansekunder')) + 1;
        $id = DB::settInn('referansekunder', $data);
        revider('referanse_opprettet', 'referanse', $id, ['navn' => $navn]);
    }

    Svar::ok([
        'id'      => $id,
        'kunder'  => kundene(),
        'beskjed' => $data['aktiv'] && !$data['samtykke']
            ? 'Lagret, men kortet vises ikke ute før du har krysset av for at kunden har sagt ja.'
            : ($data['aktiv'] ? 'Lagret. Kortet står på forsiden.' : 'Lagret som skjult.'),
    ]);
}

// ── Rekkefolge ─────────────────────────────────────────────────────────────
//
// Bytter plass med naboen. Enklere enn aa dra, og det er en liste paa en
// haandfull rader.
if ($handling === 'flytt') {
    $id = Foresporsel::heltall('id');
    $opp = Foresporsel::tekst('retning') !== 'ned';

    $meg = DB::en('SELECT id, sortering FROM referansekunder WHERE id = :i', ['i' => $id]);
    if ($meg === null) {
        Svar::feil('Fant ikke kunden.', 404);
    }
    $nabo = DB::en(
        $opp
            ? 'SELECT id, sortering FROM referansekunder WHERE sortering < :s ORDER BY sortering DESC LIMIT 1'
            : 'SELECT id, sortering FROM referansekunder WHERE sortering > :s ORDER BY sortering LIMIT 1',
        ['s' => (int) $meg['sortering']]
    );
    if ($nabo === null) {
        Svar::ok(['kunder' => kundene(), 'beskjed' => $opp ? 'Står allerede øverst.' : 'Står allerede nederst.']);
    }

    DB::oppdater('referansekunder', ['sortering' => (int) $nabo['sortering']], ['id' => (int) $meg['id']]);
    DB::oppdater('referansekunder', ['sortering' => (int) $meg['sortering']], ['id' => (int) $nabo['id']]);
    revider('referanse_flyttet', 'referanse', $id, ['opp' => $opp]);
    Svar::ok(['kunder' => kundene(), 'beskjed' => 'Rekkefølgen er endret.']);
}

// ── Slett ──────────────────────────────────────────────────────────────────
if ($handling === 'slett') {
    $id = Foresporsel::heltall('id');
    $k = DB::en('SELECT navn FROM referansekunder WHERE id = :i', ['i' => $id]);
    if ($k === null) {
        Svar::feil('Fant ikke kunden.', 404);
    }
    DB::kjor('DELETE FROM referansekunder WHERE id = :i', ['i' => $id]);
    revider('referanse_slettet', 'referanse', $id, ['navn' => $k['navn']]);
    Svar::ok(['kunder' => kundene(), 'beskjed' => '«' . $k['navn'] . '» er slettet.']);
}

Svar::feil('Ukjent handling.');
