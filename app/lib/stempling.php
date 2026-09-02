<?php
/**
 * Innstempling i verkstedet, og timene den trekker.
 *
 * Medlemskapet gir et antall timer i maaneden. En okt starter ved
 * innstempling og slutter ved utstempling; minuttene legges sammen per
 * kalendermaaned i norsk tid.
 *
 * To ting maa loses for at tallet skal vaere til aa stole paa:
 *
 *   1. Glemt utstempling. Noen gaar hjem uten aa stemple ut. Da ville okta
 *      staatt aapen i det uendelige og spist hele maaneden. Okter som har
 *      staatt lenger enn MAKS_OKT lukkes automatisk paa den lengden, og
 *      merkes, saa verkstedet ser at det skjedde.
 *
 *   2. Dobbel innstempling. Ett medlem kan bare ha én aapen okt. Den sperren
 *      ligger i transaksjonen, ikke i skjemaet.
 */

declare(strict_types=1);

final class Stempling
{
    /** Lengste okt som telles naar noen glemmer aa stemple ut. */
    private const MAKS_OKT_MIN = 6 * 60;

    /**
     * Klokkeslettet verkstedet stenger, i norsk tid.
     *
     * Eieren, 2. september: «Automatisk utstemplibg kl 23».
     *
     * Foer sto det en varighet her — en oekt som hadde staatt aapen i ti
     * timer ble lukket ved neste oppslag. Den regnet fra innstemplinga og
     * ikke fra klokka, saa en som stemplet inn klokka ni om morgenen sto inne
     * til sju om kvelden foer noe skjedde, og en som stemplet inn klokka aatte
     * om kvelden sto inne til seks neste morgen.
     *
     * Naa er det doegnet som avgjor: ingen staar innstemplet over natta.
     */
    private const STENGER_KL = 23;

    /**
     * Hvor lenge en oekt kan rettes etter at verkstedet stengte den kvelden.
     *
     * Den som ble stemplet ut av systemet klokka 23, oppdager det neste
     * morgen. Uten et vindu her kunne feltet bare brukes én gang: rettinga
     * fjerner merket om automatisk lukking, og da forsvant knappen — saa en
     * som skrev 15:00 i stedet for 16:00 satt igjen med feilen.
     *
     * Vinduet regnes fra stengetida og ikke fra utstemplinga, nettopp derfor:
     * en retting flytter utstemplinga bakover, og et vindu maalt paa den ville
     * lukket seg i samme oeyeblikk som man brukte det.
     *
     * Ett doegn. Lenger tilbake er det verkstedet som maa inn i basen; da er
     * det ikke lenger «jeg glemte aa stemple ut», men et regnskap.
     */
    private const RETTEVINDU_TIMER = 24;

    private static function oslo(): DateTimeZone
    {
        return new DateTimeZone('Europe/Oslo');
    }

    private static function naa(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Forste sekund av inneverende maaned, i UTC.
     *
     * Grensa maa folge norsk kalender: klokka 00.30 den forste er fortsatt
     * forrige maaned i UTC, og timene ville havnet feil.
     */
    public static function manedStart(): string
    {
        return (new DateTimeImmutable('now', self::oslo()))
            ->modify('first day of this month')->setTime(0, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Foerste stengetid etter et gitt tidspunkt, i UTC.
     *
     * Regnet i norsk tid og gjort om til UTC, ikke motsatt: klokka 23 i Oslo
     * er 21 eller 22 i UTC alt etter sommertid, og et fast klokkeslett i UTC
     * ville flyttet stengetida en time to ganger i aaret.
     */
    private static function stengetidEtter(string $utcTid): string
    {
        $inn = (new DateTimeImmutable($utcTid, new DateTimeZone('UTC')))
            ->setTimezone(self::oslo());
        $stenger = $inn->setTime(self::STENGER_KL, 0);
        // Stemplet noen inn etter stengetid, er det neste kveld som gjelder.
        if ($stenger <= $inn) {
            $stenger = $stenger->modify('+1 day');
        }
        return $stenger->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Lukker okter som staar igjen etter stengetid.
     *
     * Kalles fra hvert oppslag av innstemplinga, fra bakgrunnsarbeidet som
     * folger trafikken paa sida (Tikk), og fra nattjobben «vedlikehold».
     * Uten dette ville «i verkstedet naa» vist folk som gikk hjem i forrige
     * uke — og aapningstida paa nettsida, som leser om verkstedet er
     * bemannet, ville sagt «aapent» hele natta etter en som glemte seg.
     *
     * Tida som telles har fortsatt taket paa MAKS_OKT_MIN. Eieren, 2.
     * september, spurt om hva som skal trekkes: «Behold taket paa 6 timer».
     * Uten det ville en som stemplet inn klokka ti og glemte seg mistet
     * tretten timer av et medlemskap paa tretti — en glemsel skal ikke koste
     * en tredel av maaneden.
     *
     * Raden merkes «auto_lukket», saa bade verkstedet og medlemmet ser at det
     * var systemet som lukket den, og kan sette det riktige klokkeslettet.
     */
    public static function lukkGlemte(): int
    {
        // Bare de som faktisk har passert en stengetid. Lista er kort — det
        // er dem som glemte seg — saa hver rad faar sitt eget klokkeslett.
        $apne = DB::alle(
            'SELECT id, inn_tid FROM check_ins WHERE ut_tid IS NULL'
        );
        $naa = self::naa();
        $lukket = 0;
        foreach ($apne as $o) {
            $stenger = self::stengetidEtter((string) $o['inn_tid']);
            if ($stenger > $naa) {
                continue;   // kvelden er ikke omme
            }
            $min = (int) round((strtotime($stenger) - strtotime((string) $o['inn_tid'])) / 60);
            DB::oppdater('check_ins', [
                'ut_tid'      => $stenger,
                'minutter'    => max(0, min($min, self::MAKS_OKT_MIN)),
                'auto_lukket' => 1,
            ], ['id' => (int) $o['id']]);
            $lukket++;
        }
        return $lukket;
    }

    /** Den aapne okta til et medlem, eller null. */
    public static function apenOkt(int $medlemId): ?array
    {
        return DB::en(
            'SELECT id, inn_tid FROM check_ins WHERE member_id = :m AND ut_tid IS NULL ORDER BY id DESC LIMIT 1',
            ['m' => $medlemId]
        );
    }

    /** Brukte minutter denne maaneden, inkludert okta som paagaar naa. */
    public static function minutterDenneManeden(int $medlemId): int
    {
        $fra = self::manedStart();

        $ferdige = (int) DB::verdi(
            'SELECT COALESCE(SUM(minutter), 0) FROM check_ins
              WHERE member_id = :m AND ut_tid IS NOT NULL AND inn_tid >= :fra',
            ['m' => $medlemId, 'fra' => $fra]
        );

        $paagaar = (int) DB::verdi(
            'SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, inn_tid, UTC_TIMESTAMP())), 0) FROM check_ins
              WHERE member_id = :m AND ut_tid IS NULL AND inn_tid >= :fra',
            ['m' => $medlemId, 'fra' => $fra]
        );

        return max(0, $ferdige + $paagaar);
    }

    /**
     * Stempler inn. Returnerer okt-id.
     *
     * Hele sjekken ligger inne i transaksjonen: to raske trykk etter hverandre
     * skal ikke gi to aapne okter.
     */
    /**
     * @param int $ressursId Hva medlemmet skal bruke — dreieskive eller
     *                       verkstedplass. 0 naar det ikke er oppgitt.
     *
     * Eieren, 30. august: «kunne det voere lost om de booker inn og velger
     * dreieskive, eller verkstedplass». For dette gjettet regnestykket at
     * enhver innstemplet sto ved en skive; naa sier medlemmet det selv.
     *
     * Staar man alt inne, endres valget. Det er billigere enn aa stemple ut
     * og inn igjen for aa flytte seg fra bordet til skiva.
     */
    public static function inn(int $medlemId, int $ressursId = 0): int
    {
        return DB::iTransaksjon(static function () use ($medlemId, $ressursId): int {
            $apen = DB::en(
                'SELECT id FROM check_ins WHERE member_id = :m AND ut_tid IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',
                ['m' => $medlemId]
            );
            $har = DB::harKolonne('check_ins', 'ressurs_id');
            if ($apen !== null) {
                if ($har && $ressursId > 0) {
                    DB::oppdater('check_ins', ['ressurs_id' => $ressursId], ['id' => (int) $apen['id']]);
                }
                return (int) $apen['id'];
            }
            $rad = ['member_id' => $medlemId, 'inn_tid' => self::naa()];
            if ($har) {
                $rad['ressurs_id'] = $ressursId > 0 ? $ressursId : null;
            }
            return DB::settInn('check_ins', $rad);
        });
    }

    /** Stempler ut. Returnerer minutter, eller null om ingenting var aapent. */
    public static function ut(int $medlemId): ?int
    {
        return DB::iTransaksjon(static function () use ($medlemId): ?int {
            $apen = DB::en(
                'SELECT id, inn_tid FROM check_ins WHERE member_id = :m AND ut_tid IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',
                ['m' => $medlemId]
            );
            if ($apen === null) {
                return null;
            }

            $naa = self::naa();
            $min = (int) round((strtotime($naa) - strtotime((string) $apen['inn_tid'])) / 60);
            $min = max(0, min($min, self::MAKS_OKT_MIN));

            DB::oppdater('check_ins', ['ut_tid' => $naa, 'minutter' => $min], ['id' => (int) $apen['id']]);
            return $min;
        });
    }

    /**
     * Setter klokkeslettet medlemmet faktisk gikk.
     *
     * Eieren, 2. september: «Naar et medlem glemmer aa stemple ut, kan vi
     * legge til knappen glemt aa stemple ut. Og mulighet aa legge til
     * klokkeslett naar de faktisk gikk.»
     *
     * Gjelder den siste oekta, aapen eller lukket. Den maa kunne rettes ogsaa
     * ETTER at systemet har lukket den klokka 23 — det er nettopp da man
     * oppdager at man glemte seg, og uten dette sto medlemmet med seks timer
     * det ikke fikk gjort noe med.
     *
     * Taket gjelder som ellers: ingen oekt teller mer enn MAKS_OKT_MIN.
     *
     * @param  string $utTid Tidspunktet, i UTC.
     * @return array{ok: bool, feil?: string, minutter?: int, id?: int}
     */
    public static function rettUt(int $medlemId, string $utTid): array
    {
        return DB::iTransaksjon(static function () use ($medlemId, $utTid): array {
            $okt = DB::en(
                'SELECT id, inn_tid, ut_tid FROM check_ins
                  WHERE member_id = :m ORDER BY id DESC LIMIT 1 FOR UPDATE',
                ['m' => $medlemId]
            );
            if ($okt === null) {
                return ['ok' => false, 'feil' => 'Fant ingen økt å rette.'];
            }

            $inn = strtotime((string) $okt['inn_tid']);
            $ut  = strtotime($utTid);

            // Man kan ikke ha gaatt for man kom.
            if ($ut <= $inn) {
                return ['ok' => false, 'feil' => 'Tidspunktet må være etter at du stemplet inn.'];
            }
            // Og ikke i framtida. Et klokkeslett som ikke har vaert enda er
            // ikke et tidspunkt man gikk.
            if ($ut > strtotime(self::naa()) + 60) {
                return ['ok' => false, 'feil' => 'Tidspunktet kan ikke være fram i tid.'];
            }

            $min = (int) round(($ut - $inn) / 60);
            $felt = [
                'ut_tid'   => gmdate('Y-m-d H:i:s', $ut),
                'minutter' => max(0, min($min, self::MAKS_OKT_MIN)),
                // Den er ikke lenger lukket av systemet — et menneske har
                // sagt naar de gikk. Uten dette ville merket blitt staaende
                // og sagt at tida er gjettet.
                'auto_lukket' => 0,
            ];
            DB::oppdater('check_ins', $felt, ['id' => (int) $okt['id']]);

            return ['ok' => true, 'minutter' => $felt['minutter'], 'id' => (int) $okt['id']];
        });
    }

    /**
     * Retter utstemplinga fra et klokkeslett i norsk tid: «14:30».
     *
     * Klokkeslettet gjelder doegnet oekta begynte. Uten den dagen ville en
     * som retter dagen etter satt tidspunktet paa feil doegn — og timene
     * havnet i feil maaned om det skjedde den foerste.
     *
     * Ligger her og ikke i endepunktene fordi bade medlemmet og verkstedet
     * skal kunne gjore det. Eieren, 2. september, spurt om hvem: «Begge —
     * medlemmet og du». To kopier av den samme utregningen ville skilt lag.
     *
     * @return array{ok: bool, feil?: string, minutter?: int, id?: int}
     */
    public static function rettUtKlokke(int $medlemId, string $klokke): array
    {
        $klokke = trim($klokke);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $klokke)) {
            return ['ok' => false, 'feil' => 'Skriv klokkeslettet som for eksempel 15:30.'];
        }
        $okt = self::sisteOkt($medlemId);
        if ($okt === null) {
            return ['ok' => false, 'feil' => 'Fant ingen økt å rette.'];
        }
        // Endepunktet skal ikke kunne mer enn knappen. Er oekta eldre enn
        // vinduet, staar feltet ikke der — og da skal et kall heller ikke gaa
        // gjennom.
        if (!$okt['kanRettes']) {
            return ['ok' => false, 'feil' => 'Økta er for gammel til å rettes her. '
                . 'Si fra i verkstedet, så ordner de det.'];
        }

        $inn = (new DateTimeImmutable($okt['inn_tid'], new DateTimeZone('UTC')))
            ->setTimezone(self::oslo());
        [$t, $m] = array_map('intval', explode(':', $klokke));
        $ut = $inn->setTime($t, $m);
        // Gikk noen etter midnatt, er klokkeslettet mindre enn det de kom paa.
        // Da er det neste doegn, ikke samme morgen.
        if ($ut <= $inn) {
            $ut = $ut->modify('+1 day');
        }

        // ... men ikke lenger enn til stengetid.
        //
        // Doegnskiftet over er til for den som kom klokka ti om kvelden og
        // gikk halv ett. Uten denne grensa slo den ogsaa inn paa en
        // skrivefeil: kom man klokka ti om morgenen og skrev 09:00, ble det
        // lest som ni dagen etter — treogtyve timer, kappet til seks — og
        // medlemmet mistet en dag av maaneden paa et feiltrykk.
        //
        // Verkstedet stenger klokka 23. Lenger enn dit kan ingen ha vaert.
        $stenger = new DateTimeImmutable(
            self::stengetidEtter($okt['inn_tid']), new DateTimeZone('UTC')
        );
        if ($ut > $stenger) {
            return ['ok' => false, 'feil' => 'Verkstedet stenger kl. '
                . self::STENGER_KL . '. Skriv klokkeslettet du gikk.'];
        }

        return self::rettUt($medlemId, $ut->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    /**
     * Den siste oekta, med om den ble lukket av systemet.
     *
     * Skjermen trenger den for aa vite om «Glemt aa stemple ut» skal staa der
     * — og hva som staar der fra for, saa feltet kan aapne paa det.
     *
     * @return array{id:int,inn_tid:string,ut_tid:?string,auto:bool,kanRettes:bool}|null
     */
    public static function sisteOkt(int $medlemId): ?array
    {
        $o = DB::en(
            'SELECT id, inn_tid, ut_tid, auto_lukket FROM check_ins
              WHERE member_id = :m ORDER BY id DESC LIMIT 1',
            ['m' => $medlemId]
        );
        if ($o === null) {
            return null;
        }
        $ut = $o['ut_tid'] === null ? null : (string) $o['ut_tid'];
        return [
            'id'      => (int) $o['id'],
            'inn_tid' => (string) $o['inn_tid'],
            'ut_tid'  => $ut,
            'auto'    => (int) $o['auto_lukket'] === 1,
            // Om «Glemt aa stemple ut» skal staa der. Regelen ligger her og
            // ikke paa skjermene, saa Min side, medlemsruta i admin og
            // rettUtKlokke() ikke kan svare hver sitt om den samme oekta.
            'kanRettes' => $ut === null
                || strtotime(self::stengetidEtter((string) $o['inn_tid']))
                   >= strtotime(self::naa()) - self::RETTEVINDU_TIMER * 3600,
        ];
    }

    /**
     * Hvem som er i verkstedet naa.
     *
     * Medlemmer som har slaatt av synlighet telles med, men navnet vises ikke
     * — de skal ikke forsvinne fra antallet, bare fra lista.
     */
    public static function inneNa(): array
    {
        $ressurs = DB::harKolonne('check_ins', 'ressurs_id')
            ? ', (SELECT r.navn FROM ressurser r WHERE r.id = c.ressurs_id) AS ressurs'
            : ", '' AS ressurs";
        $rader = DB::alle(
            "SELECT c.inn_tid, m.navn, m.vis_innstempling, m.medlemskap_type{$ressurs}
               FROM check_ins c
               JOIN members m ON m.id = c.member_id
              WHERE c.ut_tid IS NULL
              ORDER BY c.inn_tid"
        );

        $synlige = [];
        $skjulte = 0;

        foreach ($rader as $r) {
            if (!(int) $r['vis_innstempling']) {
                $skjulte++;
                continue;
            }
            $inn = (new DateTimeImmutable((string) $r['inn_tid'], new DateTimeZone('UTC')))
                ->setTimezone(self::oslo());
            $synlige[] = [
                'navn'    => (string) $r['navn'],
                'siden'   => $inn->format('H:i'),
                'type'    => (string) ($r['medlemskap_type'] ?? ''),
                'ressurs' => (string) ($r['ressurs'] ?? ''),
            ];
        }

        return ['synlige' => $synlige, 'skjulte' => $skjulte, 'antall' => count($rader)];
    }

    /**
     * Staar noen fra verkstedet innstemplet naa?
     *
     * Aapningstida paa nettsiden ble regnet av kalenderen alene — en paastand
     * om framtiden, som blir staaende som sannhet ogsaa naar den er feil.
     * Er verkstedet bemannet, er doeren aapen, og det er det som gjelder.
     *
     * Bare admin teller. Et medlem som staar og dreier alene, gjor ikke
     * verkstedet aapent for folk som kommer forbi.
     *
     * @return array{apen: bool, siden: string|null}
     */
    public static function verkstedetBemannet(): array
    {
        $rad = DB::en(
            "SELECT c.inn_tid
               FROM check_ins c
               JOIN members m ON m.id = c.member_id
              WHERE c.ut_tid IS NULL AND m.rolle = 'admin'
           ORDER BY c.inn_tid
              LIMIT 1"
        );
        if ($rad === null) {
            return ['apen' => false, 'siden' => null];
        }
        $inn = (new DateTimeImmutable((string) $rad['inn_tid'], new DateTimeZone('UTC')))
            ->setTimezone(self::oslo());

        return ['apen' => true, 'siden' => $inn->format('H:i')];
    }

    /** «1 t 20 min», «45 min», «—». */
    public static function varighet(int $minutter): string
    {
        if ($minutter <= 0) {
            return '0 min';
        }
        $t = intdiv($minutter, 60);
        $m = $minutter % 60;
        if ($t === 0) {
            return $m . ' min';
        }
        return $m === 0 ? $t . ' t' : $t . ' t ' . $m . ' min';
    }

    /** Timer med komma, slik nordmenn skriver dem: 11,5. */
    public static function timer(int $minutter): string
    {
        return str_replace('.', ',', (string) (round($minutter / 60 * 10) / 10));
    }
}
