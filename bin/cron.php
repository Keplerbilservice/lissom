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

/** Skriver til skjerm når du kjører for hånd, og til loggen ellers. */
$si = static function (string $t): void {
    echo $t . "\n";
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

        // Avtaler som venter paa godkjenning: kunden kan ha godkjent i appen
        // uten aa komme tilbake til nettsiden. Vi sporr Vipps.
        $venter = DB::alle(
            "SELECT * FROM subscriptions
              WHERE status = 'venter'
                AND vipps_agreement_id IS NOT NULL
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );
        foreach ($venter as $a) {
            Medlemskap::oppdaterFraVipps($a);
            usleep(200_000);
        }

        if ($gjort > 0 || $feilet > 0) {
            logg('Medlemstrekk kjort', ['trukket' => $gjort, 'feilet' => $feilet]);
        }
        $si("Medlemstrekk: {$gjort} trekk, {$feilet} feilet, " . count($venter) . ' avtaler sjekket.');
        break;

    // -----------------------------------------------------------------------
    // Sikkerhetsnett for webhooks som ikke kom fram. Vi spør Vipps direkte om
    // status på betalinger som har hengt i «venter» en stund.
    case 'betalinger':
        $venter = DB::alle(
            "SELECT id, vipps_reference, belop_ore
               FROM payments
              WHERE status IN ('opprettet','venter','autorisert')
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
                AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE)
              ORDER BY id
              LIMIT 30"
        );

        $sjekket = 0;
        foreach ($venter as $p) {
            try {
                $status = Vipps::hentBetaling((string) $p['vipps_reference']);
                DB::oppdater('payments', [
                    'siste_payload' => json_encode($status, JSON_UNESCAPED_UNICODE),
                    'updated_at'    => gmdate('Y-m-d H:i:s'),
                ], ['id' => $p['id']]);
                $sjekket++;
            } catch (Throwable $e) {
                logg_feil('Statusoppslag feilet for ' . $p['vipps_reference'], $e);
            }
            usleep(300_000);
        }
        $si("Betalinger: {$sjekket} statusoppslag.");
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
    case 'vedlikehold':
        $sesjoner = Sesjon::ryddUtlopte();
        $rater = Rate::rydd();

        // Kurs med fast ukedag: legg ut oktene som mangler framover.
        //
        // Uten dette ville en serie gaatt tom etter aatte uker, og kurset
        // forsvunnet fra nettsida uten at noen sa fra.
        $nyeOkter = Serier::fyllPaa();
        if ($nyeOkter > 0) {
            logg('Faste kursdatoer lagt ut', ['okter' => $nyeOkter]);
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

        $si("Vedlikehold: {$sesjoner} sesjoner, {$rater} ratelinjer, {$frigitt} reservasjoner frigitt, {$nyeOkter} faste kursdatoer lagt ut.");
        break;

    // -----------------------------------------------------------------------
    default:
        fwrite(STDERR, "Bruk: php bin/cron.php <jobb>\n\n"
            . "  varsler        Sender det som ligger i varselkøen\n"
            . "  betalinger     Henter status fra Vipps for betalinger som henger\n"
            . "  paaminnelser   Kurspåminnelser og ventelistevarsler\n"
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
