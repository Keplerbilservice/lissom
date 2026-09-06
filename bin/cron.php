<?php
// Planlagte jobber. Settes opp i cPanel -> Cron Jobs.
//
//   */5 * * * *   php ~/lissom-app/bin/cron.php varsler
//   */5 * * * *   php ~/lissom-app/bin/cron.php betalinger
//   0 * * * *     php ~/lissom-app/bin/cron.php anmeldelser
//   0 1 * * *     php ~/lissom-app/bin/cron.php vedlikehold
//   0 4 * * *     php ~/lissom-app/bin/cron.php medlemstrekk
//   0 7 * * *     php ~/lissom-app/bin/cron.php paaminnelser
//
// Alle seks staar her, og alle seks staar i docs/OPPSETT.md. Fram til
// 5. september sto det fem hvert sted — men ikke de samme fem: her manglet
// «anmeldelser», og i OPPSETT.md manglet «medlemstrekk». Det siste er jobben
// som henter inn pengene, og den ble derfor aldri satt opp i cPanel.
// tests/backend.php leser naa begge listene og krever at de stemmer.
//
// Klokkeslettene er UTC. 07:00 UTC er 09:00 norsk sommertid, 08:00 om vinteren.

declare(strict_types=1);

// Kjorer dette fra et terminalvindu eller fra cron — eller fra nettet?
//
// Sto som «PHP_SAPI !== 'cli'», og da svarte jobbene 404 og gjorde ingenting.
// Hele historien, og hvorfor SAPI-navnet er feil sted aa spoerre, staar i
// app/lib/foresporsel.php. Den lastes her, foer alt annet, slik at vakta staar
// foer foerste linje arbeid.
//
// Vakta staar fortsatt — bin/ ligger over public_html og kan ikke naas fra
// nettet i dag, men den dagen noen flytter en mappe skal den fange det.
require_once dirname(__DIR__) . '/app/lib/foresporsel.php';

if (er_nettforesporsel(PHP_SAPI, $_SERVER)) {
    http_response_code(404);
    exit;
}

// CGI-utgaven skriver «Content-type: text/html» av seg selv, og cPanel sender
// e-post for hver linje en jobb skriver. Uten dette ville rettelsen over gitt
// én e-post hvert femte minutt fra jobber som gjor akkurat det de skal.
//
// Dette er den ene delen jeg ikke har kunnet maale her: containeren har bare
// CLI-utgaven av PHP. Under CLI gjor de to linjene ingenting.
if (PHP_SAPI !== 'cli') {
    ini_set('default_mimetype', '');
    header_remove();
}

// ---------------------------------------------------------------------------
// Utstroemmene. STDOUT og STDERR finnes BARE i CLI-utgaven av PHP.
//
// Eieren, 6. september 2026, videresendt e-post fra Cron Daemon klokka 22:10
// — etter at 404-en over var rettet, samme kveld:
//
//     Cron <rbvapxvz@gungnir> php ~/lissom-app/bin/cron.php varsler
//     Status: 500 Internal Server Error
//     Content-Type: application/json; charset=utf-8
//     {"feil":"Noe gikk galt. Proev igjen, eller ta kontakt med oss."}
//
// Jobben kom altsaa forbi vakta og inn i koden — og stoppet paa neste linje.
// Der sto det «stream_isatty(STDOUT)». CGI-utgaven definerer ikke den
// konstanten, og i PHP 8 er et ukjent konstantnavn en Error som velter alt.
// Feilhandtereren i app/bootstrap.php tok imot den og svarte slik den svarer
// nettet: 500 og en JSON-linje. Ingen varsler ble sendt.
//
// php://stdout og php://stderr finnes i alle utgaver av PHP.
$ut  = fopen('php://stdout', 'wb');
$err = fopen('php://stderr', 'wb');

require dirname(__DIR__) . '/app/bootstrap.php';

// ---------------------------------------------------------------------------
// En jobb som feiler skal si det som en jobb, ikke som en nettside.
//
// Handtereren i app/bootstrap.php svarer med HTTP-status og JSON. Det er
// riktig for /api — men for en cron-jobb ble det bare «Status: 500» i en
// e-post, uten et ord om hva som var galt. Denne overtar for jobbene og
// skriver grunnen til stderr, som er nettopp det cPanel sender videre.
set_exception_handler(static function (Throwable $e) use ($err): void {
    logg_feil('Cron-jobben stoppet', $e);
    if ($err !== false) {
        fwrite($err, 'Cron-jobben stoppet: ' . $e::class . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    }
    exit(1);
});

// Navnet paa jobben staar bak kommandoen: «php bin/cron.php varsler».
//
// $argv fylles bare naar register_argc_argv staar paa. CLI-utgaven har den
// paa uansett; CGI-utgaven foelger php.ini, og der er den slaatt av i PHPs
// egen produksjonsmal. $_SERVER['argv'] er samme liste, og den ene finnes
// noen ganger naar den andre ikke gjoer det.
$jobb = (string) ($argv[1] ?? $_SERVER['argv'][1] ?? '');
$start = microtime(true);

/**
 * Skriver til skjerm naar du kjorer for haand — og tier naar cron kjorer.
 *
 * cPanel sender e-post hver gang en cron-jobb skriver noe som helst. Skrev
 * disse jobbene en linje hver gang, ble det rundt tre hundre e-poster i
 * dognet fra fem jobber som alle gjorde nettopp det de skulle. Da slutter
 * man aa lese dem, og den ene som betyr noe drukner.
 *
 * Naa staar det ingenting naar alt gaar bra. Feil gaar fortsatt til
 * feilloggen og til stderr, og da sender cPanel e-post — som er akkurat den
 * beskjeden man vil ha.
 *
 * Kjorer du kommandoen selv i et terminalvindu, skriver den som for.
 */
$tilSkjerm = $ut !== false && stream_isatty($ut);
$si = static function (string $t) use ($tilSkjerm, $ut): void {
    if ($tilSkjerm && $ut !== false) {
        fwrite($ut, $t . "\n");
    }
};

switch ($jobb) {

    // -----------------------------------------------------------------------
    case 'varsler':
        [$sendt, $feilet] = Utsending::tomKo(50);
        if ($sendt > 0 || $feilet > 0) {
            logg('Varselkø tømt', ['sendt' => $sendt, 'feilet' => $feilet]);
        }
        $si("Varsler: {$sendt} sendt, {$feilet} feilet.");
        break;

    // -----------------------------------------------------------------------
    // Manedstrekk for medlemskap.
    //
    // Kjores én gang i dognet. Hver avtale har sin egen dato — trekket folger
    // dagen medlemmet meldte seg inn, ikke den 1. i maaneden. Da slipper vi aa
    // forklare hvorfor noen betalte full pris for tre dager.
    //
    // Trygg aa kjore flere ganger: idempotensnokkelen bygges av avtalen og
    // maaneden, saa to kjoringer samme natt gir ett trekk.
    case 'medlemstrekk':
        // Selve runden ligger i Medlemskap::kjorTrekkrunde(). Den stod her,
        // og bin/cron.php var det eneste som kjorte den — men jobben stod
        // aldri i docs/OPPSETT.md, saa den ble aldri satt opp i cPanel.
        // Ingen ble trukket. Naa kjorer trafikken paa sida den ogsaa, én gang
        // i dognet (Tikk::kjor), og begge kaller det samme.
        $t = Medlemskap::kjorTrekkrunde($si);
        $si("Ugodkjente avtaler: {$t['paaminnet']} paaminnet");
        $si("Medlemstrekk: {$t['trukket']} trekk, {$t['feilet']} feilet, "
            . $t['sjekket'] . ' avtaler sjekket, ' . $t['avsluttet']
            . ' avsluttet, ' . $t['gjort_opp'] . ' gjort opp.');
        break;

    // -----------------------------------------------------------------------
    // Sikkerhetsnett for webhooks som ikke kom fram. Vi spør Vipps direkte om
    // status på betalinger som har hengt i «venter» en stund.
    case 'betalinger':
        // Maanedstrekkene staar utenfor. De er ikke ePayment og finnes ikke
        // paa den adressen — hvert oppslag ga 404 og en linje i feilloggen,
        // hvert femte minutt. De hentes fra avtalen sin, i «medlemstrekk».
        $venter = DB::alle(
            "SELECT id, vipps_reference, belop_ore
               FROM payments
              WHERE status IN ('opprettet','venter','autorisert')
                AND type <> 'recurring_charge'
                -- Tretti dager, ikke sju.
                --
                -- Grensa var der for aa slippe aa sporre om gamle rader. Men
                -- en betaling som hang i aatte dager ble aldri sett paa igjen:
                -- den sto «venter» for alltid, pengene kunne staa reservert
                -- hos Vipps, og ingen visste noe. Tretti dager er den samme
                -- grensa som maanedstrekkene bruker.
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE)
              ORDER BY id
              LIMIT 30"
        );

        // Her sto det bare et oppslag: statusen ble hentet fra Vipps, lagt i
        // «siste_payload», og saa var det slutt. Ingen trekk, ingen «betalt»,
        // ingen «avbrutt» — og «siste_payload» leses ingen steder. Samtidig
        // sier api/vipps-webhook.php «Cron rydder opp» naar den selv feiler.
        // Det gjorde den altsaa ikke: sviktet webhooken, og kunden aldri kom
        // tilbake til retur-adressen, kunne betalingen bli staaende for
        // alltid.
        //
        // Behandlingen ligger naa i Vipps::synkroniser(), som Tikk ogsaa
        // bruker. Ett sted, én oppforsel.
        $sjekket = 0;
        $gjortOpp = 0;
        foreach ($venter as $p) {
            $tilstand = Vipps::synkroniser((string) $p['vipps_reference']);
            if ($tilstand !== '') { $sjekket++; }
            if ($tilstand === 'AUTHORIZED' || $tilstand === 'CAPTURED') { $gjortOpp++; }
            usleep(300_000);
        }
        $si("Betalinger: {$sjekket} statusoppslag, {$gjortOpp} gjort opp.");
        break;

    // -----------------------------------------------------------------------
    // Kurspåminnelse dagen før, og varsling til venteliste.
    case 'paaminnelser':
        $okter = DB::alle(
            "SELECT cs.id, cs.start_tid, c.tittel, c.sms_paaminnelse
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt'
                AND cs.paaminnelse_sendt_at IS NULL
                AND cs.start_tid BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 HOUR)"
        );

        $antall = 0;
        foreach ($okter as $okt) {
            $deltakere = DB::alle(
                "SELECT b.gjest_navn, b.gjest_epost, b.gjest_telefon,
                        m.navn AS m_navn, m.epost AS m_epost, m.telefon AS m_telefon
                   FROM bookings b
              LEFT JOIN members m ON m.id = b.member_id
                  WHERE b.course_session_id = :s AND b.status = 'betalt'",
                ['s' => $okt['id']]
            );

            foreach ($deltakere as $d) {
                Varsel::mal('kurspaaminnelse', [
                    'epost'   => $d['m_epost'] ?? $d['gjest_epost'],
                    'telefon' => $okt['sms_paaminnelse'] ? ($d['m_telefon'] ?? $d['gjest_telefon']) : null,
                ], [
                    'navn' => (string) ($d['m_navn'] ?: $d['gjest_navn']),
                    'kurs' => (string) $okt['tittel'],
                    'tid'  => norsk_klokkeslett((string) $okt['start_tid']),
                ], 'course_session', (int) $okt['id']);
                $antall++;
            }

            DB::oppdater('course_sessions', ['paaminnelse_sendt_at' => gmdate('Y-m-d H:i:s')], ['id' => $okt['id']]);
        }
        $si("Påminnelser: {$antall} lagt i kø for " . count($okter) . " økt(er).");
        break;

    // -----------------------------------------------------------------------
    //
    // Oppfoelgingen etter kurset: «takk for sist, legg gjerne igjen noen ord».
    //
    // Tre sperrer, og alle tre maa vaere aapne for det gaar en melding:
    //
    //   1. anmeldelse_paa staar paa. Skrus paa under Markedsforing → E-post
    //      og SMS, av eieren, naar hun vil.
    //   2. anmeldelse_lenke er fylt ut. Uten en lenke har meldingen ingenting
    //      aa peke paa, og «legg igjen noen ord» uten sted er bare stoy.
    //   3. Malen «anmeldelse» er aktiv.
    //
    // Og uansett: aldri lenger tilbake enn tre dogn. Skrur du den paa i
    // november, skal ingen faa «takk for sist» for et kurs i august.
    case 'anmeldelser':
        $paa    = (string) Config::hent('anmeldelse_paa', '0') === '1';
        $lenke  = trim((string) Config::hent('anmeldelse_lenke', ''));
        $malPaa = (int) (DB::verdi(
            "SELECT aktiv FROM notification_templates WHERE navn = 'anmeldelse'"
        ) ?? 0) === 1;

        if (!$paa || $lenke === '' || !$malPaa) {
            $si('Oppfølging etter kurs: står av'
                . (!$paa ? ' (bryteren)' : '')
                . ($lenke === '' ? ' (mangler lenke)' : '')
                . (!$malPaa ? ' (malen er slått av)' : '')
                . '. Ingenting sendt.');
            break;
        }

        // Hvor lenge etter kurset. Timer, ikke dager: SMS-en skal komme mens
        // de fortsatt husker det, ikke uken etter.
        $timer = max(1, min(72, (int) Config::hent('anmeldelse_timer', '3')));

        $okter = DB::alle(
            "SELECT cs.id, cs.start_tid, c.tittel, c.sms_paaminnelse
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt'
                AND cs.anmeldelse_sendt_at IS NULL
                AND COALESCE(cs.slutt_tid, cs.start_tid)
                    <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :t HOUR)
                AND COALESCE(cs.slutt_tid, cs.start_tid)
                    > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)
                AND COALESCE(c.tema, '') <> 'Kun for medlemmer'",
            ['t' => $timer]
        );

        $antall = 0;
        foreach ($okter as $okt) {
            $deltakere = DB::alle(
                "SELECT b.gjest_navn, b.gjest_epost, b.gjest_telefon,
                        m.navn AS m_navn, m.epost AS m_epost, m.telefon AS m_telefon
                   FROM bookings b
              LEFT JOIN members m ON m.id = b.member_id
                  WHERE b.course_session_id = :s AND b.status = 'betalt'",
                ['s' => $okt['id']]
            );

            foreach ($deltakere as $d) {
                Varsel::mal('anmeldelse', [
                    'epost'   => $d['m_epost'] ?? $d['gjest_epost'],
                    // Samme regel som paaminnelsen: SMS bare der kurset har
                    // sagt ja til det. Har ikke kurset det, gaar den som
                    // e-post — Varsel::mal() ordner det selv.
                    'telefon' => $okt['sms_paaminnelse'] ? ($d['m_telefon'] ?? $d['gjest_telefon']) : null,
                ], [
                    'navn'  => (string) ($d['m_navn'] ?: $d['gjest_navn']),
                    'kurs'  => (string) $okt['tittel'],
                    'lenke' => $lenke,
                ], 'course_session', (int) $okt['id']);
                $antall++;
            }

            // Merkes ogsaa naar okta ikke hadde deltakere. Ellers ville den
            // blitt sett paa igjen ved hver kjoring i tre dogn.
            DB::oppdater('course_sessions',
                ['anmeldelse_sendt_at' => gmdate('Y-m-d H:i:s')], ['id' => $okt['id']]);
        }
        $si("Oppfølging etter kurs: {$antall} lagt i kø for " . count($okter) . ' økt(er).');
        break;

    // -----------------------------------------------------------------------
    case 'vedlikehold':
        $sesjoner = Sesjon::ryddUtlopte();
        $rater = Rate::rydd();

        // Glemt utstempling. Jobben gaar 01:00 UTC — etter stengetid, som er
        // klokka 23 norsk tid — saa nattas oekter er lukket for medlemmet
        // vaakner og ser paa timene sine.
        $stemplinger = Stempling::lukkGlemte();
        if ($stemplinger > 0) {
            logg('Glemte innstemplinger lukket', ['antall' => $stemplinger]);
        }

        // Kurs med fast ukedag: legg ut oktene som mangler framover.
        //
        // Uten dette ville en serie gaatt tom etter aatte uker, og kurset
        // forsvunnet fra nettsida uten at noen sa fra.
        $nyeOkter = Serier::fyllPaa();
        if ($nyeOkter > 0) {
            logg('Faste kursdatoer lagt ut', ['okter' => $nyeOkter]);
        }

        // Medlemskap som har gaatt ut.
        //
        // Et medlemskap tok bare slutt naar Vipps-avtalen stoppet. En som var
        // meldt inn for haand — eller en proveperiode — sto som aktiv i all
        // evighet, og medlemslista blandet dem som betaler med dem som
        // sluttet i fjor.
        //
        // Sluttdatoen er fasiten. Den settes ved innmelding for proveperioder
        // og ved avslutning for haand; er den ikke satt, roerer vi ingenting.
        $utlopte = DB::kjor(
            "UPDATE members
                SET status = 'oppsagt'
              WHERE status IN ('aktiv', 'prove')
                AND slutt_dato IS NOT NULL
                AND slutt_dato < CURDATE()
                AND anonymisert_at IS NULL"
        )->rowCount();
        if ($utlopte > 0) {
            logg('Medlemskap gaatt ut', ['antall' => $utlopte]);
        }

        // Ubetalte reservasjoner som har stått for lenge frigis, slik at
        // plassen blir ledig for andre.
        $frigitt = DB::kjor(
            "UPDATE bookings
                SET status = 'avbestilt', avbestilt_at = UTC_TIMESTAMP()
              WHERE status = 'reservert'
                AND reservert_til IS NOT NULL
                AND reservert_til < UTC_TIMESTAMP()"
        )->rowCount();

        DB::kjor('DELETE FROM login_states WHERE expires_at < UTC_TIMESTAMP()');

        // Gavekort går ut på dato etter tre år.
        DB::kjor("UPDATE gift_cards SET status = 'utlopt'
                   WHERE status = 'aktivt' AND gyldig_til < CURDATE()");

        $si("Vedlikehold: {$sesjoner} sesjoner, {$rater} ratelinjer, {$frigitt} reservasjoner frigitt, "
            . "{$nyeOkter} faste kursdatoer lagt ut, {$stemplinger} glemte innstemplinger lukket.");
        break;

    // -----------------------------------------------------------------------
    default:
        $skriv = $err === false ? fopen('php://output', 'wb') : $err;
        // Staar det ingenting bak kommandoen, er det sjelden fordi noen glemte
        // det: da har PHP-utgaven latt vaere aa fylle $argv. Det skal e-posten
        // si rett ut, ikke bare vise bruksanvisningen paa nytt.
        if ($jobb === '') {
            fwrite($skriv, "Fikk ikke med navnet paa jobben.\n"
                . 'PHP-utgave: ' . PHP_SAPI
                . '. register_argc_argv: ' . (ini_get('register_argc_argv') ? 'paa' : 'av')
                . ".\n\n");
        }
        fwrite($skriv,
            "Bruk: php bin/cron.php <jobb>\n\n"
            . "  varsler        Sender det som ligger i varselkøen\n"
            . "  betalinger     Henter status fra Vipps for betalinger som henger\n"
            . "  paaminnelser   Kurspåminnelser og ventelistevarsler\n"
            . "  anmeldelser    «Takk for sist» etter kurs, med lenke til anmeldelse\n"
            . "  medlemstrekk   Månedstrekk for medlemskapene\n"
            . "  vedlikehold    Rydder utløpte sesjoner, reservasjoner og gavekort\n");
        exit(1);
}

$si(sprintf('(%.2f sekunder)', microtime(true) - $start));

/** 2026-08-22 17:00:00 (UTC) → «17:00» norsk tid */
function norsk_klokkeslett(string $utc): string
{
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i');
}
