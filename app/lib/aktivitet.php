<?php
/**
 * Lav aktivitet — medlemmer som ikke bruker timene sine.
 *
 * Eieren, 28. september 2026: «medlemmer som ikke bruker timene sine, vil jeg
 * gjerne få beskjed om, slik at jeg kan kontakte de å se om jeg kan gjøre noe
 * for de, la oss si lav aktivitet etter 14 dager».
 *
 * Et medlem med aktivt medlemskap (prove, aktiv) staar paa lista naar siste
 * innstempling er minst N dager gammel — eller naar hen aldri har stemplet
 * inn og ble medlem for minst N dager siden. N staar i innstillingen
 * «lav_aktivitet_dager», standard 14, og settes i admin.
 *
 * Ingenting sendes til medlemmene. Lista er eierens: flisen paa Oversikt,
 * pillen «Lav aktivitet» i Medlemmer, og en daglig e-post naar noen nye
 * kommer paa (malen «intern_lav_aktivitet», bryter under Varsler).
 */

declare(strict_types=1);

final class Aktivitet
{
    public const STANDARD_DAGER = 14;

    public static function dager(): int
    {
        $raa = DB::harTabell('innstillinger')
            ? DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'lav_aktivitet_dager'")
            : null;
        $n = is_numeric($raa) ? (int) $raa : self::STANDARD_DAGER;
        return max(1, min(365, $n));
    }

    public static function settDager(int $dager, ?int $av = null): int
    {
        $dager = max(1, min(365, $dager));
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            ['lav_aktivitet_dager', (string) $dager, $av]
        );
        return $dager;
    }

    /**
     * Medlemmene paa lista, lengst siden sist forst.
     *
     * @return list<array{id:int,navn:string,telefon:string,epost:string,dager:int,sist:string}>
     */
    public static function lave(?int $dager = null): array
    {
        $dager ??= self::dager();
        $rader = DB::alle(
            "SELECT m.id, m.navn, m.telefon, m.epost, m.start_dato,
                    (SELECT MAX(c.inn_tid) FROM check_ins c WHERE c.member_id = m.id) AS sist
               FROM members m
              WHERE m.status IN ('prove', 'aktiv')
                AND m.rolle <> 'admin'"
        );
        $idag = new DateTimeImmutable('today', new DateTimeZone('Europe/Oslo'));
        $ut = [];
        foreach ($rader as $r) {
            $fra = $r['sist'] !== null ? substr((string) $r['sist'], 0, 10) : (string) ($r['start_dato'] ?? '');
            if ($fra === '') {
                continue;
            }
            $d = (int) (new DateTimeImmutable($fra, new DateTimeZone('Europe/Oslo')))->diff($idag)->format('%r%a');
            if ($d < $dager) {
                continue;
            }
            $ut[] = [
                'id'      => (int) $r['id'],
                'navn'    => (string) $r['navn'],
                'telefon' => (string) ($r['telefon'] ?? ''),
                'epost'   => (string) ($r['epost'] ?? ''),
                'dager'   => $d,
                'sist'    => $r['sist'] !== null ? substr((string) $r['sist'], 0, 10) : '',
            ];
        }
        usort($ut, static fn($a, $b) => $b['dager'] <=> $a['dager'] ?: strcmp($a['navn'], $b['navn']));
        return $ut;
    }

    /**
     * Den daglige e-posten til eieren. Bare de som er NYE paa lista siden
     * forrige sending — ellers kommer de samme navnene hver dag. Hvem som
     * er meldt, staar i innstillingen «lav_aktivitet_meldt» (id-er).
     *
     * @return int antall nye i e-posten
     */
    public static function meldNye(): int
    {
        // Er malen av (eller migrasjon 233 ikke kjoert), meldes ingen — og
        // ingen merkes som meldt, saa de kommer med naar den skrus paa.
        if (DB::verdi("SELECT aktiv FROM notification_templates WHERE navn = 'intern_lav_aktivitet'") != 1) {
            return 0;
        }
        $liste = self::lave();
        $ids = array_map(static fn($m) => $m['id'], $liste);
        $raa = DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'lav_aktivitet_meldt'");
        $meldt = is_string($raa) ? (json_decode($raa, true) ?: []) : [];
        $nye = array_values(array_filter($liste, static fn($m) => !in_array($m['id'], $meldt, true)));
        // Den som har vaert innom igjen, faller av lista — og kan meldes paa
        // nytt senere. Derfor lagres lista slik den er naa, ikke summen.
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
            ['lav_aktivitet_meldt', json_encode($ids)]
        );
        if ($nye === []) {
            return 0;
        }
        $linjer = implode("\n", array_map(
            static fn($m) => $m['navn'] . ' — ' . $m['dager'] . ' dager'
                . ($m['telefon'] !== '' ? ' — ' . $m['telefon'] : ''),
            $nye
        ));
        Varsel::malTilAdmin('intern_lav_aktivitet', [
            'antall'   => (string) count($nye),
            'dager'    => (string) self::dager(),
            'medlemmer' => $linjer,
        ]);
        return count($nye);
    }
}
