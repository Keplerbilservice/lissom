<?php
// Planlagte jobber. Settes opp i cPanel -> Cron Jobs.
//
//   */5 * * * *   php ~/lissom-app/bin/cron.php varsler
//   */5 * * * *   php ~/lissom-app/bin/cron.php betalinger
//   0 7 * * *     php ~/lissom-app/bin/cron.php paaminnelser
//   0 1 * * *     php ~/lissom-app/bin/cron.php vedlikehold
//   0 4 * * *     php ~/lissom-app/bin/cron.php medlemstrekk
//
// Klokkeslettene er UTC. 07:00 UTC er 09:00 norsk sommertid, 08:00 om vinteren.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$jobb = $argv[1] ?? '';
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
$tilSkjerm = stream_isatty(STDOUT);
$si = static function (string $t) use ($tilSkjerm): void {
    if ($tilSkjerm) {
        echo $t . "\n";
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
        // ── Foerst: hvem er blitt godkjent siden sist ────────────────────
        //
        // Kunden kan ha godkjent avtalen i Vipps-appen uten aa komme tilbake
        // til nettsiden. Da staar raden vaar paa «venter» til vi sporr Vipps,
        // og det er dette som gjor det.
        //
        // Dette sto ETTER trekkrunden under. Rekkefolgen var feil: en avtale
        // som ble aktivert her fikk «neste_trekk» satt til i dag — men da
        // hadde trekkrunden alt kjort, og hun var ikke med. Trekket kom
        // foerst natta etter.
        //
        // Eirin, medlem, 2. september: «Jeg betalte med vipps i gaar via siden
        // her. Saa ut til aa fungere greit. Men pengene er fremdeles paa min
        // konto». Hun hadde godkjent i appen kvelden for. Hun sto ikke i
        // betalingene, og hadde ikke gjort det for natta etter heller.
        //
        // Ingen penger gikk tapt av dette — men hvert medlem som godkjenner i
        // appen tapte et dogn, hver gang. Naa aktiveres de foer trekkrunden,
        // saa de trekkes den samme natta som alle andre.
        // Sju dager sto her. Den grensa var satt for aa slippe aa sporre
        // Vipps om gamle rader — men den gjorde ogsaa at en avtale som ble
        // liggende i aatte dager aldri ble sett paa igjen. Godkjente kunden
        // den i uke to, fikk vi det aldri med oss, og trekket startet aldri.
        //
        // Eieren, 5. september: «jeg får jo ikke inn pengene mine».
        //
        // Nitti dager i stedet. Det er ikke gratis — ett oppslag per rad per
        // natt — men en ubetalt avtale koster mer.
        $venter = DB::alle(
            "SELECT * FROM subscriptions
              WHERE status = 'venter'
                AND vipps_agreement_id IS NOT NULL
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)"
        );
        foreach ($venter as $a) {
            Medlemskap::oppdaterFraVipps($a);
            usleep(200_000);
        }

        // ── Paaminnelsen: avtalen venter paa deg ────────────────────────
        //
        // Fast trekk i Vipps er en fullmakt kunden maa gi i appen. Lukker hun
        // sida foer hun har gjort det, er avtalen ikke gyldig — og lenka laa
        // bare i basen. Ingen fikk den, og ingen purret.
        //
        // Eieren, 5. september: «kunden får ingen beskjed om å godkjenne så vi
        // får ikke penger».
        //
        // Dagen etter, og én gang til etter tre dager. Mer enn det er mas;
        // mindre er aa gi opp pengene. Kolonnene kom med migrasjon 139.
        $paaminnet = 0;
        if (DB::harKolonne('subscriptions', 'paaminnet_at')) {
            $vent = DB::alle(
                "SELECT s.*, m.navn, m.epost, m.telefon
                   FROM subscriptions s
                   JOIN members m ON m.id = s.member_id
                  WHERE s.status = 'venter'
                    AND s.vipps_url IS NOT NULL AND s.vipps_url <> ''
                    AND s.created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                    AND s.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                    AND s.paaminnet_antall < 2
                    AND (s.paaminnet_at IS NULL
                         OR s.paaminnet_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY))"
            );
            foreach ($vent as $a) {
                if (trim((string) ($a['epost'] ?? '')) === '') {
                    continue;
                }
                Varsel::mal('avtale_ikke_godkjent', [
                    'epost'   => (string) $a['epost'],
                    'telefon' => (string) ($a['telefon'] ?? ''),
                ], [
                    'navn'  => (string) ($a['navn'] ?? ''),
                    'type'  => (string) $a['plan'],
                    'belop' => Booking::kroner((int) $a['pris_ore']),
                    'lenke' => (string) $a['vipps_url'],
                ], 'subscription', (int) $a['id']);
                DB::kjor(
                    'UPDATE subscriptions
                        SET paaminnet_at = UTC_TIMESTAMP(),
                            paaminnet_antall = paaminnet_antall + 1
                      WHERE id = :i',
                    ['i' => (int) $a['id']]
                );
                $paaminnet++;
            }
        }

        // ── Saa: trekk alle som er forfalt, de nettopp aktiverte med ─────
        $avtaler = Medlemskap::tilTrekk();
        $gjort = 0;
        $feilet = 0;

        foreach ($avtaler as $a) {
            try {
                $svar = Medlemskap::trekk($a);
                $si('  ' . $a['navn'] . ' (' . $a['plan'] . '): ' . $svar);
                $gjort++;
            } catch (Throwable $e) {
                logg_feil('Medlemstrekk feilet for avtale ' . $a['id'], $e);
                $si('  ' . $a['navn'] . ': FEILET — ' . $e->getMessage());
                $feilet++;
            }
            usleep(300_000);
        }

        // Oppsigelsestida ute: her stoppes avtalen i Vipps og tilgangen tas
        // bort. Selve oppsigelsen setter bare sluttdatoen — medlemmet har
        // betalt for maaneden, og skal ha den.
        $avsluttet = 0;
        foreach (Medlemskap::tilAvslutning() as $a) {
            Medlemskap::avslutt($a);
            $avsluttet++;
            usleep(200_000);
        }

        // Hvordan gikk trekkene vi ba om?
        //
        // Trekket bes om noen dager fram i tid, saa svaret kommer ikke samme
        // natt. Her sporr vi om dem vi ikke har fatt svar paa enda. Foer dette
        // ble hver eneste rad staaende paa «venter» for alltid — og de to
        // malene «Medlemskapet ditt er fornyet» og «Vi fikk ikke trukket
        // betalingen» ble aldri sendt til noen.
        $svart = 0;
        foreach (Medlemskap::trekkUtenSvar() as $p) {
            try {
                $utfall = Medlemskap::sjekkTrekk($p);
                $si('  trekk ' . $p['id'] . ' (' . ($p['navn'] ?? '') . '): ' . $utfall);
                if ($utfall === 'betalt' || $utfall === 'failed' || $utfall === 'cancelled') {
                    $svart++;
                }
            } catch (Throwable $e) {
                logg_feil('Statusoppslag feilet for trekk ' . $p['id'], $e);
            }
            usleep(300_000);
        }

        if ($gjort > 0 || $feilet > 0 || $avsluttet > 0 || $svart > 0) {
            logg('Medlemstrekk kjort', ['trukket' => $gjort, 'feilet' => $feilet,
                                        'avsluttet' => $avsluttet, 'gjort_opp' => $svart]);
        }
        $si("Ugodkjente avtaler: {$paaminnet} paaminnet");
        $si("Medlemstrekk: {$gjort} trekk, {$feilet} feilet, " . count($venter)
            . ' avtaler sjekket, ' . $avsluttet . ' avsluttet, ' . $svart . ' gjort opp.');
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
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
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
        fwrite(STDERR, "Bruk: php bin/cron.php <jobb>\n\n"
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
