<?php
/**
 * Brukere med innlogging til verkstedet.
 *
 *   GET                             lister dem
 *   POST {handling: 'opprett'|'endre'|'slett', ...}
 *
 * Kontoene ligger paa members, ikke i en egen tabell. Da virker sesjoner,
 * portvakter og revisjonslogg som for, og den samme personen kan logge inn
 * med Vipps eller med passord uten aa bli to personer i systemet.
 *
 * «Kursholder» under Tilgang (eieren 04.10.2026, GO): personen faar vanlig
 * medlemsinnlogging og legges samtidig i kursholderregisteret med samme navn,
 * e-post og telefon. E-posten er koblingen (Kursholder::forMedlem), saa den
 * maa fylles ut. Velges «Medlem» paa en kursholder, settes hen som sluttet i
 * registeret — som «Sett som sluttet» paa Kursholdere, historikken beholdes.
 *
 * To sperrer mot aa laase seg selv ute: den siste admin-en kan verken slettes
 * eller settes ned til vanlig medlem, og du kan ikke slette din egen konto.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$jeg = krev_admin();

/** Minstekrav til passord. Lengde betyr mer enn tegnsammensetning. */
const PASSORD_MINST = 12;

$sjekkPassord = static function (string $passord, string $brukernavn): void {
    if (mb_strlen($passord) < PASSORD_MINST) {
        Svar::feil('Passordet må være minst ' . PASSORD_MINST . ' tegn.');
    }
    if (mb_strtolower($passord) === mb_strtolower($brukernavn)) {
        Svar::feil('Passordet kan ikke være det samme som brukernavnet.');
    }
};

$rensBrukernavn = static function (string $raa): string {
    $b = mb_strtolower(trim($raa));
    if (!preg_match('/^[a-z0-9._-]{3,64}$/', $b)) {
        Svar::feil('Brukernavnet kan bare inneholde små bokstaver, tall, punktum, bindestrek og understrek — minst tre tegn.');
    }
    return $b;
};

/**
 * Hvor mange kan faktisk administrere?
 *
 * Bade de som staar som admin i databasen og de som er det via nodluke-numrene
 * i secrets.php. Teller vi bare den forste gruppa, ville den siste konto-baserte
 * admin-en vaert umulig aa slette selv om eieren fortsatt kom inn med Vipps.
 */
$antallAdmin = static fn(): int => antall_admin();

/** Den aktive kursholderen med denne e-posten, eller null. Samme kobling som Min side. */
$kursholderFor = static function (?string $epost): ?array {
    $e = mb_strtolower(trim((string) $epost));
    if ($e === '' || !DB::harTabell('kursholdere')) {
        return null;
    }
    return DB::en(
        'SELECT id FROM kursholdere WHERE aktiv = 1 AND LOWER(TRIM(epost)) = :e ORDER BY id LIMIT 1',
        ['e' => $e]
    );
};

/** Legger personen i kursholderregisteret, om hen ikke alt staar der. */
$blirKursholder = static function (string $navn, string $epost, ?string $telefon) use ($kursholderFor): void {
    if ($kursholderFor($epost)) {
        return;
    }
    $id = DB::settInn('kursholdere', [
        'navn'    => mb_substr($navn, 0, 191),
        'epost'   => mb_substr($epost, 0, 191),
        'telefon' => $telefon !== null && $telefon !== '' ? mb_substr($telefon, 0, 32) : null,
    ]);
    revider('kursholder_lagt_til', 'kursholder', $id, ['fra' => 'brukere']);
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Numrene i secrets.php er admin ved kjoring uten aa staa som det i
    // databasen. Uten dem ville eieren ikke sett seg selv i lista, og trodd at
    // ingen hadde tilgang.
    $numre = Config::adminNumre();
    $plass = $numre ? implode(',', array_fill(0, count($numre), '?')) : "''";

    $rader = DB::alle(
        "SELECT id, brukernavn, navn, epost, telefon, rolle, siste_innlogging,
                passord_hash IS NOT NULL AS har_passord, vipps_sub IS NOT NULL AS har_vipps
           FROM members
          WHERE brukernavn IS NOT NULL
             OR rolle IN ('admin', 'regnskap')
             OR telefon IN ({$plass})
       ORDER BY rolle = 'admin' DESC, rolle = 'regnskap' DESC,
                brukernavn IS NULL, brukernavn, navn",
        $numre
    );

    // Hvem som staar i kursholderregisteret, paa e-post.
    $holderEposter = DB::harTabell('kursholdere')
        ? array_flip(array_map(
            static fn($e): string => mb_strtolower(trim((string) $e)),
            array_column(DB::alle("SELECT epost FROM kursholdere WHERE aktiv = 1 AND epost IS NOT NULL AND epost <> ''"), 'epost')
        ))
        : [];

    Svar::json([
        'brukere' => array_map(static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'brukernavn' => (string) ($r['brukernavn'] ?? ''),
            'navn'       => (string) $r['navn'],
            'epost'      => (string) ($r['epost'] ?? ''),
            // Nodluke-numrene er admin selv om kolonnen sier medlem.
            // «regnskap» er regnskapsfoereren: hun ser OEkonomi og
            // betalingene, og ingenting annet.
            'rolle'      => (string) $r['rolle'] === 'admin'
                                || in_array(normaliser_telefon((string) ($r['telefon'] ?? '')), $numre, true)
                                ? 'admin'
                                : ((string) $r['rolle'] === 'regnskap' ? 'regnskap' : 'medlem'),
            'fraNodluke' => (string) $r['rolle'] !== 'admin'
                                && in_array(normaliser_telefon((string) ($r['telefon'] ?? '')), $numre, true),
            'kursholder' => isset($holderEposter[mb_strtolower(trim((string) ($r['epost'] ?? '')))]),
            'harPassord' => (bool) $r['har_passord'],
            'harVipps'   => (bool) $r['har_vipps'],
            'sist'       => $r['siste_innlogging']
                                ? Booking::norskDato((string) $r['siste_innlogging'])
                                : 'Aldri logget inn',
            'erDeg'      => (int) $r['id'] === (int) $jeg['id'],
        ], $rader),
        'minstLengde' => PASSORD_MINST,
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');
$id       = Foresporsel::heltall('id');
// Tre roller naa. «regnskap» kom 1. september: eieren ville gi
// regnskapsfoereren en egen innlogging framfor aa gi henne hele verkstedet.
$rolle    = in_array(Foresporsel::tekst('rolle'), ['admin', 'regnskap'], true)
                ? Foresporsel::tekst('rolle') : 'medlem';
// «kursholder» er ingen egen rolle i basen: en medlemsinnlogging som i tillegg
// staar i kursholderregisteret.
$somKursholder = Foresporsel::tekst('rolle') === 'kursholder';

/**
 * Taaler kolonnen rollen?
 *
 * «regnskap» kommer med migrasjon 117. Koden ligger ute noen minutter for
 * vedlikeholdet kjores, og uten denne sa basen bare «Data truncated for
 * column 'rolle'» — som ble til «Noe gikk galt» paa skjermen. Eieren,
 * 1. september: «faar ikke opprettet paa regnskap».
 */
$rollenFinnes = static function (string $r): bool {
    if ($r !== 'regnskap') {
        return true;   // medlem og admin har vaert der siden 001
    }
    $type = (string) DB::verdi(
        "SELECT COLUMN_TYPE FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'rolle'"
    );
    return str_contains($type, "'regnskap'");
};
if (!$rollenFinnes($rolle)) {
    Svar::feil('Rollen «Regnskap» krever en oppdatering av databasen. '
        . 'Kjør vedlikeholdet fra menyen nederst til venstre, så kan du opprette henne.', 503);
}
$passord  = (string) (Foresporsel::kropp()['passord'] ?? '');

if ($handling === 'opprett') {
    $brukernavn = $rensBrukernavn(Foresporsel::tekst('brukernavn'));
    $navn       = mb_substr(Foresporsel::tekst('navn'), 0, 191);
    $epost      = mb_substr(Foresporsel::tekst('epost'), 0, 191);

    if ($navn === '') {
        Svar::feil('Vi trenger navnet på brukeren.');
    }
    if ($epost !== '' && !filter_var($epost, FILTER_VALIDATE_EMAIL)) {
        Svar::feil('E-postadressen ser ikke riktig ut.');
    }
    if ($somKursholder && $epost === '') {
        Svar::feil('Skriv e-posten til kursholderen.');
    }
    $sjekkPassord($passord, $brukernavn);

    if (DB::verdi('SELECT COUNT(*) FROM members WHERE brukernavn = :b', ['b' => $brukernavn])) {
        Svar::feil('Brukernavnet er opptatt.');
    }

    $telefon = normaliser_telefon(Foresporsel::tekst('telefon')) ?: null;
    // Innloggingen og kursholderen lages sammen, eller ingen av dem.
    $nyId = DB::iTransaksjon(static function () use ($brukernavn, $passord, $navn, $epost, $telefon, $rolle, $somKursholder, $blirKursholder): int {
        $id = DB::settInn('members', [
            'brukernavn'   => $brukernavn,
            'passord_hash' => password_hash($passord, PASSWORD_DEFAULT),
            'navn'         => $navn,
            'epost'        => $epost !== '' ? $epost : null,
            'telefon'      => $telefon,
            'rolle'        => $rolle,
            'status'       => 'ingen',
        ]);
        if ($somKursholder) {
            $blirKursholder($navn, $epost, $telefon);
        }
        return $id;
    });

    revider('bruker_opprettet', 'member', $nyId, ['brukernavn' => $brukernavn, 'rolle' => $rolle]);
    Svar::ok(['id' => $nyId]);
}

$bruker = DB::en('SELECT * FROM members WHERE id = :id', ['id' => $id]);
if (!$bruker) {
    Svar::feil('Fant ikke brukeren.', 404);
}

if ($handling === 'endre') {
    $data = [];

    // Brukernavnet kunne ikke endres. Ble det feil ved opprettelsen — og et
    // navn skrevet inn i farten blir fort det — var eneste utvei aa slette
    // brukeren og lage den paa nytt. Det gaar ikke paa din egen konto, og det
    // ville dessuten mistet historikken.
    $nyttBrukernavn = Foresporsel::tekst('brukernavn');
    if ($nyttBrukernavn !== '') {
        $b = $rensBrukernavn($nyttBrukernavn);
        if ($b !== (string) ($bruker['brukernavn'] ?? '')) {
            $opptatt = DB::verdi(
                'SELECT COUNT(*) FROM members WHERE brukernavn = :b AND id <> :i',
                ['b' => $b, 'i' => $id]
            );
            if ($opptatt) {
                Svar::feil('Brukernavnet er opptatt.');
            }
            $data['brukernavn'] = $b;
        }
    }

    $nyttNavn = mb_substr(Foresporsel::tekst('navn'), 0, 191);
    if ($nyttNavn !== '') {
        $data['navn'] = $nyttNavn;
    }

    $nyEpost = mb_substr(Foresporsel::tekst('epost'), 0, 191);
    if ($nyEpost !== '') {
        if (!filter_var($nyEpost, FILTER_VALIDATE_EMAIL)) {
            Svar::feil('E-postadressen ser ikke riktig ut.');
        }
        $data['epost'] = $nyEpost;
    }

    if (Foresporsel::tekst('rolle') !== '') {
        // Den siste admin-en kan ikke settes ned. Da staar ingen igjen som
        // kan sette den opp igjen.
        if ($bruker['rolle'] === 'admin' && $rolle !== 'admin' && $antallAdmin() <= 1) {
            Svar::feil('Dette er den siste admin-brukeren. Opprett en ny før du tar bort tilgangen.');
        }
        $data['rolle'] = $rolle;
    }

    if ($passord !== '') {
        // Mot det nye brukernavnet om det byttes i samme slengen.
        $sjekkPassord($passord, (string) ($data['brukernavn'] ?? $bruker['brukernavn'] ?? ''));
        $data['passord_hash'] = password_hash($passord, PASSWORD_DEFAULT);
    }

    $epostEtter = (string) ($data['epost'] ?? $bruker['epost'] ?? '');
    if ($somKursholder && trim($epostEtter) === '') {
        Svar::feil('Skriv e-posten til kursholderen.');
    }

    if ($data === []) {
        Svar::feil('Ingenting å endre.');
    }

    // Kursholderen foelger innloggingen paa e-post. Byttes e-posten, flyttes
    // koblingen med, ellers ville hen mistet kursene sine paa Min side.
    $holderFoer = $kursholderFor($bruker['epost'] ?? null);
    DB::iTransaksjon(static function () use ($data, $id, $bruker, $somKursholder, $holderFoer, $epostEtter, $blirKursholder): void {
        DB::oppdater('members', $data, ['id' => $id]);
        if ($somKursholder) {
            if ($holderFoer) {
                DB::oppdater('kursholdere', ['epost' => mb_substr($epostEtter, 0, 191)], ['id' => $holderFoer['id']]);
            } else {
                $blirKursholder((string) ($data['navn'] ?? $bruker['navn']), $epostEtter, $bruker['telefon'] ?? null);
            }
        } elseif ($holderFoer && Foresporsel::tekst('rolle') === 'medlem') {
            DB::oppdater('kursholdere', ['aktiv' => 0], ['id' => $holderFoer['id']]);
            revider('kursholder_sluttet', 'kursholder', (int) $holderFoer['id'], ['fra' => 'brukere']);
        }
    });
    // Nytt passord: alle andre innlogginger paa kontoen avsluttes. Den som
    // bytter, blir sittende i sin egen fane.
    if (isset($data['passord_hash'])) {
        Sesjon::avsluttAndreFor((int) $id);
    }
    revider('bruker_endret', 'member', $id, ['felter' => array_keys($data)]);
    Svar::ok([
        'id'      => $id,
        'beskjed' => isset($data['brukernavn'])
            ? 'Brukernavnet er nå «' . $data['brukernavn'] . '».'
            : 'Endringene er lagret.',
    ]);
}

if ($handling === 'slett') {
    if ((int) $id === (int) $jeg['id']) {
        Svar::feil('Du kan ikke slette din egen bruker.');
    }
    if ($bruker['rolle'] === 'admin' && $antallAdmin() <= 1) {
        Svar::feil('Dette er den siste admin-brukeren.');
    }

    // Har personen historikk, slettes ikke raden — bookinger og betalinger er
    // bokforingspliktige, og fremmednoklene maa fortsatt peke et sted. Da
    // fjernes tilgangen i stedet, som er det sletting egentlig handler om her.
    $harHistorikk = (int) DB::verdi(
        'SELECT (SELECT COUNT(*) FROM bookings WHERE member_id = :a)
              + (SELECT COUNT(*) FROM payments WHERE member_id = :b)',
        ['a' => $id, 'b' => $id]
    ) > 0;

    if ($harHistorikk) {
        DB::oppdater('members', [
            'brukernavn'   => null,
            'passord_hash' => null,
            'rolle'        => 'medlem',
        ], ['id' => $id]);
        DB::kjor('DELETE FROM sessions WHERE member_id = :id', ['id' => $id]);
        revider('bruker_tilgang_fjernet', 'member', $id);
        Svar::ok(['slettet' => false, 'beskjed' => 'Tilgangen er fjernet. Kjøpshistorikken er beholdt, som bokføringsloven krever.']);
    }

    DB::kjor('DELETE FROM sessions WHERE member_id = :id', ['id' => $id]);
    DB::kjor('DELETE FROM members WHERE id = :id', ['id' => $id]);
    revider('bruker_slettet', 'member', $id, ['brukernavn' => $bruker['brukernavn']]);
    Svar::ok(['slettet' => true]);
}

Svar::feil('Ukjent handling.');
