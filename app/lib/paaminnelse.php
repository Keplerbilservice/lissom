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
 * Utsendingen er atomisk (Codex runde 2): oekta laases, paameldingene merkes
 * (varsel_utsendinger, «paaminnelse:<booking>:<oekt>») og meldingene legges i koen i
 * én transaksjon. Feiler noe midt i, rulles alt tilbake — ingen halv
 * utsending, ingen merket. To faner eller cron samtidig: den andre venter paa
 * laasen og finner noeklene. Uten migrasjon 270 tas hele oekta med
 * paaminnelse_sendt_at, som foer.
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
    public static function mottakere(int $oktId, bool $utenSendte = false): array
    {
        // $utenSendte: bare dem som ikke har faatt paaminnelsen for denne
        // paameldingen paa denne oekta (noekkel «paaminnelse:<booking>:<oekt>»,
        // migrasjon 270). En paamelding som flyttes til en ny oekt, faar
        // paaminnelsen for den nye datoen.
        //
        // Sikkerhetsnett for eldre sending (foer noeklene): har oekta
        // paaminnelse_sendt_at, men ingen paaminnelsesnoekler i det hele tatt,
        // er den sendt som foer — ingen ny til dem som var paameldt da. Bare
        // paameldinger opprettet etterpaa kan faa den.
        $uten = $utenSendte && self::harNokler()
            ? " AND NOT EXISTS (SELECT 1 FROM varsel_utsendinger v
                                 WHERE v.nokkel = CONCAT('paaminnelse:', b.id, ':', cs.id))
                AND NOT (cs.paaminnelse_sendt_at IS NOT NULL
                         AND b.created_at <= cs.paaminnelse_sendt_at
                         AND NOT EXISTS (SELECT 1 FROM varsel_utsendinger v2
                                          WHERE v2.nokkel LIKE CONCAT('paaminnelse:%:', cs.id)))"
            : '';
        return DB::alle(
            "SELECT b.id, b.gjest_navn, b.gjest_epost, b.gjest_telefon,
                    m.navn AS m_navn, m.epost AS m_epost, m.telefon AS m_telefon
               FROM bookings b
               JOIN course_sessions cs ON cs.id = b.course_session_id
          LEFT JOIN members m ON m.id = b.member_id
              WHERE b.course_session_id = :s AND b.status = 'betalt'
                AND b.created_at <= DATE_SUB(NOW(), INTERVAL 14 DAY){$uten}
           ORDER BY b.id",
            ['s' => $oktId]
        );
    }

    /**
     * Finnes utsendingsnoeklene (migrasjon 270)? Da merkes paaminnelsen per
     * paamelding, ikke per oekt: en paaminnelse sendt for haand tidlig stopper
     * ikke cron for dem som meldte seg paa etterpaa (kontrolloeren runde 3,
     * 9. oktober 2026). Uten tabellen: hele oekta, som foer.
     */
    public static function harNokler(): bool
    {
        return DB::harTabell('varsel_utsendinger');
    }

    /**
     * Gjoer en eldre sending (foer noeklene) om til noekler for én oekt: har
     * oekta paaminnelse_sendt_at og ingen paaminnelsesnoekler, faar hver
     * paamelding opprettet foer sendingen sin noekkel. Samme utfylling som
     * migrasjon 270. Kalles under laasen i send(), saa en ny paamelding som
     * faar sin noekkel ikke aapner for de gamle igjen.
     */
    public static function fyllEldre(int $oktId): int
    {
        return DB::kjor(
            "INSERT IGNORE INTO varsel_utsendinger (nokkel, created_at)
             SELECT CONCAT('paaminnelse:', b.id, ':', cs.id), cs.paaminnelse_sendt_at
               FROM bookings b
               JOIN course_sessions cs ON cs.id = b.course_session_id
              WHERE cs.id = :i
                AND cs.paaminnelse_sendt_at IS NOT NULL
                AND b.created_at <= cs.paaminnelse_sendt_at
                AND NOT EXISTS (SELECT 1 FROM varsel_utsendinger v
                                 WHERE v.nokkel LIKE CONCAT('paaminnelse:%:', cs.id))",
            ['i' => $oktId]
        )->rowCount();
    }

    /** «paaminnelse:<booking>:<oekt>» — oekta med, saa en flyttet paamelding faar den for den nye datoen. */
    public static function nokkel(int $bookingId, int $oktId): string
    {
        return 'paaminnelse:' . $bookingId . ':' . $oktId;
    }

    /**
     * Oekta slik den staar i basen naa. Kalles under radlaasen i send(), saa
     * noekkel og meldingstekst bygges fra samme rad.
     *
     * @return array<string,mixed>|null
     */
    public static function oktRad(int $oktId): ?array
    {
        $smsKol = DB::harKolonne('courses', 'sms_paaminnelse') ? 'c.sms_paaminnelse' : '0 AS sms_paaminnelse';
        return DB::en(
            "SELECT cs.id, cs.start_tid, cs.slutt_tid, cs.status, c.tittel, {$smsKol}
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :i",
            ['i' => $oktId]
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
     * Oekta laases (SELECT … FOR UPDATE) og leses paa nytt under laasen:
     * status, start_tid og kurs. Er den avlyst, hoppes den over (grunn
     * «avlyst»). Er datoen en annen enn den som ble forventet
     * ($okt['forventet_start'] fra skjermen, ellers $okt['start_tid'] slik
     * cron valgte den), hoppes den over (grunn «dato»). Teksten bygges fra
     * raden under laasen.
     *
     * Med noeklene (migrasjon 270): hver paamelding merkes for seg
     * («paaminnelse:<booking>:<oekt>») i samme transaksjon som koeleggingen, og
     * bare paameldte uten noekkel faar den. En oekt sendt foer noeklene
     * (paaminnelse_sendt_at satt, ingen noekler) regnes som sendt til dem som
     * var paameldt da (se mottakere()). paaminnelse_sendt_at settes som «sist
     * sendt» naar noe gikk ut, men stopper ikke cron for nye. Uten tabellen:
     * oekta tas med paaminnelse_sendt_at, som foer (grunn «sendt» naar den
     * alt er tatt).
     *
     * $forHaand (ny admin, «Send påminnelse»): mottakere ingen vei naar,
     * hoppes over og listes i ikkeNaadd, og naar ingen ble naadd rulles det
     * tilbake. Uten (cron): Varsel::mal() for alle.
     *
     * @param array<string,mixed> $okt id, start_tid (og ev. forventet_start)
     * @return array{tatt: bool, grunn: string, sendt: int, alt: int, ikkeNaadd: list<string>, sendtAt: string}
     */
    public static function send(array $okt, bool $forHaand): array
    {
        $oktId = (int) $okt['id'];
        $forventet = substr((string) ($okt['forventet_start'] ?? $okt['start_tid'] ?? ''), 0, 16);
        $mal = $forHaand ? DB::en("SELECT * FROM notification_templates WHERE navn = 'kurspaaminnelse'") : null;
        $nokler = self::harNokler();
        $tom = static fn(string $grunn): array
            => ['tatt' => false, 'grunn' => $grunn, 'sendt' => 0, 'alt' => 0, 'ikkeNaadd' => [], 'sendtAt' => ''];
        $pdo = DB::kobling();
        $pdo->beginTransaction();
        try {
            DB::verdi('SELECT id FROM course_sessions WHERE id = :i FOR UPDATE', ['i' => $oktId]);
            $rad = self::oktRad($oktId);
            if ($rad === null || (string) $rad['status'] === 'avlyst') {
                $pdo->rollBack();
                return $tom('avlyst');
            }
            if (substr((string) $rad['start_tid'], 0, 16) !== $forventet) {
                $pdo->rollBack();
                return $tom('dato');
            }
            $okt = $rad;
            $tatt = gmdate('Y-m-d H:i:s');
            if (!$nokler && DB::kjor(
                'UPDATE course_sessions SET paaminnelse_sendt_at = :t WHERE id = :i AND paaminnelse_sendt_at IS NULL',
                ['t' => $tatt, 'i' => $oktId]
            )->rowCount() !== 1) {
                $pdo->rollBack();
                return $tom('sendt');
            }
            if ($nokler) {
                // Eldre sending uten noekler: gjoer den om til noekler foerst.
                self::fyllEldre($oktId);
            }
            $naar = self::naar($oktId, (string) $okt['start_tid'], (string) ($okt['slutt_tid'] ?? ''));
            $sted = self::sted();
            $sendt = 0;
            $ikkeNaadd = [];
            $nye = self::mottakere($oktId, true);
            $alt = count(self::mottakere($oktId)) - count($nye);
            foreach ($nye as $d) {
                $mottaker = self::mottaker($okt, $d);
                if ($forHaand) {
                    $v = self::veier($mal, $mottaker);
                    if (!$v['epost'] && !$v['sms']) {
                        $ikkeNaadd[] = self::navn($d);
                        continue;
                    }
                }
                $n = self::nokkel((int) $d['id'], $oktId);
                if ($nokler && DB::kjor('INSERT IGNORE INTO varsel_utsendinger (nokkel) VALUES (:n)', ['n' => $n])->rowCount() !== 1) {
                    $alt++;
                    continue;
                }
                $lagt = Varsel::mal('kurspaaminnelse', $mottaker,
                    self::felter($okt, self::navn($d), $naar, $sted), 'course_session', $oktId);
                if ($lagt > 0) {
                    $sendt++;
                } else {
                    $ikkeNaadd[] = self::navn($d);
                    if ($nokler) {
                        DB::kjor('DELETE FROM varsel_utsendinger WHERE nokkel = :n', ['n' => $n]);
                    }
                }
            }
            if ($forHaand && $sendt === 0) {
                $pdo->rollBack();
                return ['tatt' => true, 'grunn' => '', 'sendt' => 0, 'alt' => $alt, 'ikkeNaadd' => $ikkeNaadd, 'sendtAt' => ''];
            }
            if ($nokler && $sendt > 0) {
                // «Sist sendt», for visning. Stopper ikke cron (noeklene gjoer det).
                DB::kjor('UPDATE course_sessions SET paaminnelse_sendt_at = :t WHERE id = :i', ['t' => $tatt, 'i' => $oktId]);
            }
            $pdo->commit();
            return ['tatt' => true, 'grunn' => '', 'sendt' => $sendt, 'alt' => $alt, 'ikkeNaadd' => $ikkeNaadd,
                    'sendtAt' => $sendt > 0 || !$nokler ? $tatt : ''];
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
