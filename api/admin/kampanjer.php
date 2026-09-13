<?php
/**
 * Salgskampanjene paa forsiden — lag, endre, vis og slett.
 *
 *   GET                     alle kampanjene, og hvilken som staar ute
 *   POST handling=lagre     ny eller endret kampanje
 *   POST handling=vis       { id }  — denne staar paa forsiden
 *   POST handling=slett     { id }
 *
 * Eieren, 13. september 2026: «Jeg vil ogsaa at salgsuke banneret skal vaere
 * et generelt salgs kampanje. Her vil jeg legge til og redigere bilde og
 * tekster og mulighet for aa vise pris / De kan godt lagres som maler saa har
 * vi». Hver lagret kampanje er en mal: den ligger her til han henter den fram.
 *
 * Den som staar ute speiles inn i content_blocks under «Kampanje/». Det er
 * den eneste tabellen en besoekende faar lese — se api/innhold.php — og
 * forsiden slipper et nytt endepunkt.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (!DB::harTabell('kampanjer')) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
}

/** Id-en til den som staar ute, eller 0. */
function aktivKampanje(): int
{
    return (int) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Kampanje/aktiv'");
}

/** Alle kampanjene, slik admin viser dem. */
function kampanjene(): array
{
    $aktiv = aktivKampanje();
    return array_map(static fn($r) => [
        'id'      => (int) $r['id'],
        'navn'    => (string) $r['navn'],
        'merke'   => (string) ($r['merke'] ?? ''),
        'tittel'  => (string) $r['tittel'],
        'tekst'   => (string) ($r['tekst'] ?? ''),
        // Kroner ut, oere i basen. Admin skriver kroner.
        'pris'    => $r['pris_ore'] === null ? '' : (string) ((int) $r['pris_ore'] / 100),
        'bilde'   => (string) ($r['bilde'] ?? ''),
        'knapp'   => (string) ($r['knapp'] ?? ''),
        'maal'    => (string) $r['maal'],
        'brukt'   => (string) ($r['sist_brukt'] ?? ''),
        'ute'     => (int) $r['id'] === $aktiv,
    ], DB::alle('SELECT * FROM kampanjer ORDER BY sist_brukt IS NULL, sist_brukt DESC, id'));
}

/**
 * Speiler en kampanje ut dit forsiden leser fra.
 *
 * Tomme felt skrives ogsaa — staar det en gammel pris igjen naar den nye
 * kampanjen ikke har noen, viser forsiden en pris som ikke gjelder.
 */
function speilKampanje(array $k, ?int $adminId): array
{
    $felt = [
        'Kampanje/aktiv'  => (string) (int) $k['id'],
        'Kampanje/merke'  => (string) ($k['merke'] ?? ''),
        'Kampanje/tittel' => (string) $k['tittel'],
        'Kampanje/tekst'  => (string) ($k['tekst'] ?? ''),
        'Kampanje/pris'   => $k['pris_ore'] === null ? '' : (string) (int) $k['pris_ore'],
        'Kampanje/bilde'  => (string) ($k['bilde'] ?? ''),
        'Kampanje/knapp'  => (string) ($k['knapp'] ?? ''),
        'Kampanje/maal'   => (string) $k['maal'],
    ];
    foreach ($felt as $nokkel => $verdi) {
        DB::kjor(
            'INSERT INTO content_blocks (nokkel, verdi, endret_av)
                  VALUES (:n, :v, :a)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            ['n' => $nokkel, 'v' => $verdi, 'a' => $adminId]
        );
    }
    // Sendes tilbake, saa admin kan legge dem rett inn i sitt eget bilde av
    // innholdet. hentInnhold() lar det som alt staar i nettleseren vinne, og
    // ville derfor vist forrige kampanje til siden ble lastet paa nytt.
    return $felt;
}

if (Foresporsel::metode() === 'GET') {
    Svar::json(['kampanjer' => kampanjene(), 'aktiv' => aktivKampanje()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$admin    = Sesjon::medlem();
$adminId  = $admin['id'] ?? null;
$handling = Foresporsel::tekst('handling', 'lagre');

// ── Ny eller endret ────────────────────────────────────────────────────────
if ($handling === 'lagre') {
    $id     = Foresporsel::heltall('id');
    $tittel = mb_substr(trim(Foresporsel::tekst('tittel')), 0, 191);
    if ($tittel === '') {
        Svar::feil('Kampanjen må ha en overskrift.');
    }

    // Navnet i lista. Er det ikke skrevet noe, er overskriften navnet — da
    // slipper eieren aa fylle ut det samme to ganger.
    $navn = mb_substr(trim(Foresporsel::tekst('navn')), 0, 191) ?: $tittel;

    // Prisen skrives i kroner og lagres i oere. «350», «350,50» og «kr 350»
    // skal alle gaa. Tomt felt er ingen pris — ikke null kroner.
    $prisRaa = trim(Foresporsel::tekst('pris'));
    $prisOre = null;
    if ($prisRaa !== '') {
        $tall = str_replace([' ', "\u{00a0}", 'kr', 'KR', 'Kr', ',-'], '', $prisRaa);
        $tall = str_replace(',', '.', $tall);
        if (!is_numeric($tall) || (float) $tall < 0) {
            Svar::feil('Prisen må være et tall, eller stå tom.');
        }
        $prisOre = (int) round((float) $tall * 100);
    }

    // Samme vask som referansekundene bruker: enten et filnavn fra repoet,
    // eller et bilde eieren har lastet opp selv.
    $bilde = null;
    $raaBilde = trim((string) (Foresporsel::kropp()['bilde'] ?? ''));
    if ($raaBilde !== '') {
        if (preg_match('~^api/bilde\.php\?artikkel=[A-Za-z0-9._-]{1,120}$~', $raaBilde)) {
            $bilde = $raaBilde;
        } else {
            $fil = basename($raaBilde);
            $bilde = $fil !== '' ? mb_substr($fil, 0, 255) : null;
        }
    }

    // Hvor knappen gaar. Bare steder som finnes; et fritt felt her ville
    // vaert en aapen omdirigering paa forsiden.
    $maal = Foresporsel::tekst('maal', 'butikk');
    if (!in_array($maal, ['butikk', 'medlemsbutikk', 'kurs', 'events', 'medlemskap', 'gavekort'], true)) {
        $maal = 'butikk';
    }

    $data = [
        'navn'     => $navn,
        'merke'    => mb_substr(trim(Foresporsel::tekst('merke')), 0, 191) ?: null,
        'tittel'   => $tittel,
        'tekst'    => trim(mb_substr(Foresporsel::tekst('tekst'), 0, 2000)) ?: null,
        'pris_ore' => $prisOre,
        'bilde'    => $bilde,
        'knapp'    => mb_substr(trim(Foresporsel::tekst('knapp')), 0, 191) ?: null,
        'maal'     => $maal,
    ];

    if ($id > 0) {
        if (DB::en('SELECT id FROM kampanjer WHERE id = :i', ['i' => $id]) === null) {
            Svar::feil('Fant ikke kampanjen.', 404);
        }
        DB::oppdater('kampanjer', $data, ['id' => $id]);
        revider('kampanje_endret', 'kampanje', $id, ['navn' => $navn]);
    } else {
        $id = DB::settInn('kampanjer', $data);
        revider('kampanje_opprettet', 'kampanje', $id, ['navn' => $navn]);
    }

    // Endrer du den som staar ute, skal forsiden se det med det samme.
    $ute   = aktivKampanje() === $id;
    $speil = $ute ? speilKampanje(array_merge($data, ['id' => $id]), $adminId) : [];

    Svar::ok([
        'id'        => $id,
        'kampanjer' => kampanjene(),
        'speil'     => (object) $speil,
        'beskjed'   => $ute ? 'Kampanjen er lagret, og står på forsiden.' : 'Kampanjen er lagret.',
    ]);
}

// ── Denne staar ute ────────────────────────────────────────────────────────
if ($handling === 'vis') {
    $id = Foresporsel::heltall('id');
    $k  = DB::en('SELECT * FROM kampanjer WHERE id = :i', ['i' => $id]);
    if ($k === null) {
        Svar::feil('Fant ikke kampanjen.', 404);
    }
    DB::oppdater('kampanjer', ['sist_brukt' => date('Y-m-d H:i:s')], ['id' => $id]);
    $speil = speilKampanje($k, $adminId);
    revider('kampanje_vist', 'kampanje', $id, ['navn' => $k['navn']]);

    // Bryteren er et eget valg. Staar den av, ligger kampanjen klar uten aa
    // vaere synlig — og svaret skal si det framfor aa melde «paa forsiden»
    // om noe ingen ser.
    $paa = (string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/salgsuke'") !== 'nei';

    Svar::ok([
        'kampanjer' => kampanjene(),
        'speil'     => (object) $speil,
        'beskjed'   => $paa
            ? '«' . $k['navn'] . '» står på forsiden.'
            : '«' . $k['navn'] . '» er valgt, men banneret er slått av.',
    ]);
}

// ── Slett ──────────────────────────────────────────────────────────────────
if ($handling === 'slett') {
    $id = Foresporsel::heltall('id');
    $k  = DB::en('SELECT navn FROM kampanjer WHERE id = :i', ['i' => $id]);
    if ($k === null) {
        Svar::feil('Fant ikke kampanjen.', 404);
    }
    // Den som staar ute slettes ikke ved et uhell. Velg en annen foerst, saa
    // staar forsiden aldri og peker paa noe som er borte.
    if (aktivKampanje() === $id) {
        Svar::feil('Denne står på forsiden. Vis en annen først, eller slå av banneret.');
    }
    DB::kjor('DELETE FROM kampanjer WHERE id = :i', ['i' => $id]);
    revider('kampanje_slettet', 'kampanje', $id, ['navn' => $k['navn']]);
    Svar::ok(['kampanjer' => kampanjene(), 'beskjed' => '«' . $k['navn'] . '» er slettet.']);
}

Svar::feil('Ukjent handling.');
