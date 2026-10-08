<?php
/**
 * Paaminnelsen foer kurset — ett sted for cron og «Send påminnelse» i den nye
 * adminen.
 *
 * Kontrolloeren (runde 2, 9. oktober 2026): paaminnelsen for haand
 * (api/admin/okt-varsel.php) gikk til reserverte og til dem som meldte seg
 * paa for under to uker siden, mens cron bare sender til betalte og foelger
 * 14-dagersregelen. Samme melding, to utvalg. Naa leser begge mottakerne,
 * feltene og «naar»-linja herfra.
 *
 * Utsendingen er atomisk (Codex runde 2): oekta tas (paaminnelse_sendt_at) og
 * meldingene legges i koen i én transaksjon. Feiler noe midt i, rulles alt
 * tilbake — ingen halv utsending, og datoen er ikke merket sendt. To faner
 * eller cron samtidig: den andre UPDATE-en venter paa raden og treffer ingen.
 */

declare(strict_types=1);

final class Paaminnelse
{
    /**
     * Hvem paaminnelsen gaar til: bare betalte, og bare den som meldte seg
     * paa for to uker siden eller mer. Eieren, 25. september 2026:
     * «påminnelse før kurset, sendes kun om det er 2 uker eller mer siden de
     * ble påmeldt». Den som meldte seg paa nylig, har bekreftelsen friskt i
     * minne.
     *
     * @return list<array<string,mixed>>
     */
    public static function mottakere(int $oktId): array
    {
        return DB::alle(
            "SELECT b.id, b.gjest_navn, b.gjest_epost, b.gjest_telefon,
                    m.navn AS m_navn, m.epost AS m_epost, m.telefon AS m_telefon
               FROM bookings b
          LEFT JOIN members m ON m.id = b.member_id
              WHERE b.course_session_id = :s AND b.status = 'betalt'
                AND b.created_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
           ORDER BY b.id",
            ['s' => $oktId]
        );
    }

    /** Hele navnet paa raden fra mottakere(). */
    public static function navn(array $d): string
    {
        return (string) (($d['m_navn'] ?? '') ?: ($d['gjest_navn'] ?? ''));
    }

    /**
     * Mottakeren slik Varsel::mal() vil ha den. Telefonen bare naar kurset
     * har SMS-paaminnelse paa (eieren, 31. august: av som standard).
     *
     * @param array<string,mixed> $okt med sms_paaminnelse
     * @return array{navn: string, epost: mixed, telefon: mixed}
     */
    public static function mottaker(array $okt, array $d): array
    {
        return [
            'navn'    => self::navn($d),
            'epost'   => $d['m_epost'] ?? $d['gjest_epost'],
            'telefon' => !empty($okt['sms_paaminnelse']) ? ($d['m_telefon'] ?? $d['gjest_telefon']) : null,
        ];
    }

    /**
     * Feltene i malen «kurspaaminnelse».
     *
     * @param array<string,mixed> $okt med tittel og start_tid
     * @return array<string,string>
     */
    public static function felter(array $okt, string $heleNavnet, string $naar, string $sted): array
    {
        return [
            // «navn» staar igjen for en mal eieren har skrevet om selv
            // og fortsatt bruker det feltet. Malen vaar bruker fornavn.
            'navn'    => $heleNavnet,
            'fornavn' => self::fornavn($heleNavnet),
            'kurs'    => (string) $okt['tittel'],
            'tid'     => self::klokkeslett((string) $okt['start_tid']),
            'naar'    => $naar,
            'dato'    => self::ukedagDato((string) $okt['start_tid']),
            'sted'    => $sted,
        ];
    }

    public static function sted(): string
    {
        return trim((string) Config::hent('verksted_adresse', 'Lissom Keramikk & Håndverk, Teie'));
    }

    /**
     * Hvilke veier malen naar fram paa for én mottaker, uten aa legge noe i
     * koen. Samme regler som Varsel::mal(): kanalen paa malen, SMS bare naar
     * SMS er satt opp, og en ren SMS-mal faller tilbake til e-post.
     *
     * @param array<string,mixed>|null $mal raden i notification_templates
     * @return array{epost: bool, sms: bool}
     */
    public static function veier(?array $mal, array $mottaker): array
    {
        if ($mal === null || (int) ($mal['aktiv'] ?? 0) !== 1) {
            return ['epost' => false, 'sms' => false];
        }
        $kanal = (string) $mal['kanal'];
        $epost = trim((string) ($mottaker['epost'] ?? ''));
        $tlf = trim((string) ($mottaker['telefon'] ?? ''));
        $harEpost = $epost !== '' && filter_var($epost, FILTER_VALIDATE_EMAIL) !== false;
        $harSms = $tlf !== '' && Varsel::smsMulig() && normaliser_telefon($tlf) !== '';
        $e = in_array($kanal, ['epost', 'epost_sms'], true) && $harEpost;
        $s = in_array($kanal, ['sms', 'epost_sms'], true) && $harSms;
        if (!$e && !$s && $kanal === 'sms' && $harEpost) {
            $e = true;
        }
        return ['epost' => $e, 'sms' => $s];
    }

    /**
     * Sender paaminnelsen for én oekt.
     *
     * Tar oekta (paaminnelse_sendt_at) og legger meldingene i koen i én
     * transaksjon. Treffer ikke UPDATE-en, har noen alt sendt den: da
     * returneres tatt=false og ingenting legges i koen.
     *
     * $forHaand (ny admin, «Send påminnelse»): mottakere ingen vei naar,
     * hoppes over og listes i ikkeNaadd (skjermen viser dem), og naar ingen
     * ble naadd rulles det tilbake — datoen staar umerket, saa cron kan sende
     * sin. Uten (cron): som foer — Varsel::mal() for alle, og oekta er merket
     * selv om ingen sto paa lista.
     *
     * @param array<string,mixed> $okt id, start_tid, slutt_tid, tittel, sms_paaminnelse
     * @return array{tatt: bool, sendt: int, ikkeNaadd: list<string>, sendtAt: string}
     */
    public static function send(array $okt, bool $forHaand): array
    {
        $oktId = (int) $okt['id'];
        $mal = $forHaand ? DB::en("SELECT * FROM notification_templates WHERE navn = 'kurspaaminnelse'") : null;
        $pdo = DB::kobling();
        $pdo->beginTransaction();
        try {
            $tatt = gmdate('Y-m-d H:i:s');
            if (DB::kjor(
                'UPDATE course_sessions SET paaminnelse_sendt_at = :t WHERE id = :i AND paaminnelse_sendt_at IS NULL',
                ['t' => $tatt, 'i' => $oktId]
            )->rowCount() !== 1) {
                $pdo->rollBack();
                return ['tatt' => false, 'sendt' => 0, 'ikkeNaadd' => [], 'sendtAt' => ''];
            }
            $naar = self::naar($oktId, (string) $okt['start_tid'], (string) ($okt['slutt_tid'] ?? ''));
            $sted = self::sted();
            $sendt = 0;
            $ikkeNaadd = [];
            foreach (self::mottakere($oktId) as $d) {
                $mottaker = self::mottaker($okt, $d);
                if ($forHaand) {
                    $v = self::veier($mal, $mottaker);
                    if (!$v['epost'] && !$v['sms']) {
                        $ikkeNaadd[] = self::navn($d);
                        continue;
                    }
                }
                $lagt = Varsel::mal('kurspaaminnelse', $mottaker,
                    self::felter($okt, self::navn($d), $naar, $sted), 'course_session', $oktId);
                if ($lagt > 0) {
                    $sendt++;
                } else {
                    $ikkeNaadd[] = self::navn($d);
                }
            }
            if ($forHaand && $sendt === 0) {
                $pdo->rollBack();
                return ['tatt' => true, 'sendt' => 0, 'ikkeNaadd' => $ikkeNaadd, 'sendtAt' => ''];
            }
            $pdo->commit();
            return ['tatt' => true, 'sendt' => $sendt, 'ikkeNaadd' => $ikkeNaadd, 'sendtAt' => $tatt];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 2026-08-22 17:00:00 (UTC) → «17:00» norsk tid */
    public static function klokkeslett(string $utc): string
    {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i');
    }

    /** «onsdag 9. september», norsk tid. Klokkeslettet staar i {tid}. */
    public static function ukedagDato(string $utc): string
    {
        $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Oslo'));
        $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];
        return $dager[(int) $d->format('N') - 1] . ' ' . (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n') - 1];
    }

    /**
     * Forste ord i navnet. «Mia Sørensen» → «Mia».
     *
     * Eieren, 14. september 2026: «bruk kun fornavn». Er navnet tomt, staar det
     * «Hei!» framfor «Hei ,» — et komma uten navn er verre enn ingen tiltale.
     */
    public static function fornavn(string $navn): string
    {
        $biter = preg_split('/\s+/u', trim($navn)) ?: [];
        return $biter === [] ? '' : (string) $biter[0];
    }

    /**
     * Naar kurset er, ferdig skrevet for e-posten.
     *
     * Ett moete:      «onsdag 9. september, 17:00–20:00»
     * Flere dager:    «Dag 1: onsdag 9. september, 17:00–20:00»
     *                 «Dag 2: torsdag 10. september, 17:00–20:00»
     *
     * Dagene ligger som samlinger paa kursdatoen (migrasjon 155), og de er alt
     * skrevet ferdig av Samlinger — samme setning som staar paa nettsida og i
     * kalenderen. Finnes de ikke, er det ett moete, og da regnes linja ut av
     * oektas egen start og slutt.
     */
    public static function naar(int $oktId, string $startUtc, string $sluttUtc): string
    {
        $samlinger = DB::harTabell('okt_samlinger') ? Samlinger::forOkt($oktId) : [];

        if (count($samlinger) > 1) {
            $linjer = [];
            foreach ($samlinger as $i => $s) {
                $linjer[] = 'Dag ' . ((int) ($s['nummer'] ?: $i + 1)) . ': ' . $s['naar'];
            }
            return implode("\n", $linjer);
        }
        if (count($samlinger) === 1) {
            return (string) $samlinger[0]['naar'];
        }

        // «onsdag 9. september, 17:00» fra Booking, og sluttiden bak.
        $linje = Booking::norskDato($startUtc);
        if ($sluttUtc !== '') {
            $linje .= '–' . self::klokkeslett($sluttUtc);
        }
        return $linje;
    }
}
