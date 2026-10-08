<?php
/**
 * Endret pris og rabatt i kassa på iPad (eieren, «ja» 8. oktober 2026,
 * oppsett A): trykk på prisen på en linje for å endre den, og «Rabatt på
 * kjøpet» i prosent eller kroner, med valgfritt «hvorfor».
 *
 * Regnestykket står i KasseKurv::deler() (fra basen, aldri fra nettleseren).
 * Her lagres det (migrasjon 269, kasse_justeringer), i tre steg:
 *
 *   1. låst     Første gang det tas betalt for en kurv (kontant eller QR),
 *               lages én rad per del med delens nøkkel: prisendringen og
 *               rabattandelen. Da beholder delene som står igjen sin andel
 *               når en annen del er betalt med QR (kontrolløren 8. oktober
 *               2026). Raden rører ingen påmelding eller ordre.
 *   2. koblet   Vipps-QR: raden får betalingen (payment_id) før Vipps spørres.
 *   3. gjort    Kontant / annen måte / delt: i samme transaksjon som
 *               betalingen. Vipps-QR: når betalingen er bekreftet betalt
 *               (Booking::markerBetalt). Først da settes beløpet på
 *               påmeldingen ned (bookings.belop_ore, kasse_rabatt_ore). En QR
 *               som ikke blir betalt, en feil fra Vipps eller en kasse som
 *               blir forlatt, etterlater ingen rabatt.
 *
 * En ordre: varelinjene har den nye prisen og summen er etter rabatt (ordren
 * er det som betales); raden er loggen, og «gjort» når ordren er betalt.
 *
 * Uten migrasjon 269 virker kassa som før; bare endret pris og rabatt sier
 * fra om oppdateringen.
 */

declare(strict_types=1);

final class KasseJustering
{
    public const MANGLER = 'Endret pris og rabatt i kassa krever en oppdatering av databasen. Trykk ⚙ Kjør oppdateringer.';

    public static function klar(): bool
    {
        return DB::harTabell('kasse_justeringer') && DB::harKolonne('kasse_justeringer', 'gjort_at')
            && DB::harKolonne('bookings', 'kasse_rabatt_ore');
    }

    /**
     * Rabattandelene som er låst for delene i kurven (del-id => øre), fra
     * radene med delenes nøkler. Tom når ingenting er låst ennå.
     *
     * @param array<string,mixed> $nokler del-id => nøkkel
     * @return array<string,int>
     */
    public static function laaste(array $nokler): array
    {
        $ut = [];
        if ($nokler === [] || !self::klar()) {
            return $ut;
        }
        $perNokkel = [];
        foreach ($nokler as $id => $n) {
            if (is_string($n) && Kasse::gyldigNokkel($n)) {
                $perNokkel[strtolower($n)] = (string) $id;
            }
        }
        if ($perNokkel === []) {
            return $ut;
        }
        $param = [];
        foreach (array_keys($perNokkel) as $i => $n) {
            $param['n' . $i] = $n;
        }
        foreach (DB::alle('SELECT nokkel, rabatt_ore FROM kasse_justeringer WHERE nokkel IN (:' . implode(', :', array_keys($param)) . ')', $param) as $r) {
            $ut[$perNokkel[(string) $r['nokkel']]] = (int) $r['rabatt_ore'];
        }
        return $ut;
    }

    /**
     * Steg 1: låser prisendringen og rabattandelen for hver del med en
     * endring (INSERT IGNORE: finnes raden, står den).
     *
     * @param list<array<string,mixed>> $deler fra Kasse::sjekkKurv (med «nokkel»)
     * @param array{id:int,navn:string} $person
     */
    public static function laas(array $deler, array $person): void
    {
        $med = array_values(array_filter($deler, static fn(array $d): bool => isset($d['justering'])));
        if ($med === []) {
            return;
        }
        if (!self::klar()) {
            throw new RuntimeException(self::MANGLER, 503);
        }
        foreach ($med as $d) {
            $felt = self::rad($d['justering'], $d, (string) $d['nokkel'], $person)
                + ['booking_id' => $d['type'] === 'booking' ? (int) $d['bookingId'] : null];
            $kol = array_keys($felt);
            DB::kjor('INSERT IGNORE INTO kasse_justeringer (' . implode(', ', $kol) . ') VALUES (:' . implode(', :', $kol) . ')', $felt);
        }
    }

    /** Det som trekkes fra påmeldingen for delen (pris + rabatt), når det ikke alt er gjort. */
    public static function trekkFor(string $nokkel): int
    {
        if (!self::klar()) {
            return 0;
        }
        $r = DB::en('SELECT pris_ore, rabatt_ore FROM kasse_justeringer WHERE nokkel = :k AND gjort_at IS NULL AND booking_id IS NOT NULL',
            ['k' => strtolower($nokkel)]);
        return $r === null ? 0 : (int) $r['pris_ore'] + (int) $r['rabatt_ore'];
    }

    /**
     * Steg 3 for en påmelding betalt kontant / annen måte / delt: kalles inne
     * i transaksjonen som lager betalingen. Beløpet settes ned og raden er
     * gjort. Gir tilbake det som ble trukket.
     */
    public static function brukBooking(int $bookingId, string $nokkel, ?int $betalingId): int
    {
        if (!self::klar()) {
            return 0;
        }
        $r = DB::en('SELECT id, pris_ore, rabatt_ore FROM kasse_justeringer
                      WHERE nokkel = :k AND booking_id = :b AND gjort_at IS NULL FOR UPDATE',
            ['k' => strtolower($nokkel), 'b' => $bookingId]);
        if ($r === null) {
            return 0;
        }
        return self::settPaa($bookingId, $r, $betalingId);
    }

    /** @param array<string,mixed> $r raden (id, pris_ore, rabatt_ore) */
    private static function settPaa(int $bookingId, array $r, ?int $betalingId): int
    {
        $trekk = (int) $r['pris_ore'] + (int) $r['rabatt_ore'];
        $b = DB::en('SELECT belop_ore, kasse_rabatt_ore FROM bookings WHERE id = :i FOR UPDATE', ['i' => $bookingId]);
        if ($b === null) {
            throw new RuntimeException('Fant ikke påmeldingen.', 404);
        }
        DB::oppdater('bookings', [
            'belop_ore'        => max(0, (int) $b['belop_ore'] - $trekk),
            'kasse_rabatt_ore' => (int) $b['kasse_rabatt_ore'] + $trekk,
        ], ['id' => $bookingId]);
        DB::kjor('UPDATE kasse_justeringer SET gjort_at = UTC_TIMESTAMP(), payment_id = COALESCE(:p, payment_id) WHERE id = :i',
            ['p' => $betalingId, 'i' => (int) $r['id']]);
        revider('kasse_justering', 'booking', $bookingId, ['fra' => (int) $b['belop_ore'], 'til' => max(0, (int) $b['belop_ore'] - $trekk),
            'trukket_ore' => $trekk, 'betaling' => $betalingId]);
        return $trekk;
    }

    /**
     * Steg 2 for en påmelding med Vipps-QR: raden får betalingen før Vipps
     * spørres. Andre rader for påmeldingen som venter på en QR, kobles fra
     * (den QR-en er stoppet eller gjenbrukes nå), så en rabatt aldri trekkes
     * to ganger.
     */
    public static function kobleQr(string $nokkel, int $bookingId, int $betalingId): void
    {
        DB::kjor('UPDATE kasse_justeringer SET payment_id = NULL
                   WHERE booking_id = :b AND gjort_at IS NULL AND nokkel <> :k AND payment_id IS NOT NULL',
            ['b' => $bookingId, 'k' => strtolower($nokkel)]);
        DB::kjor('UPDATE kasse_justeringer SET payment_id = :p WHERE nokkel = :k AND booking_id = :b AND gjort_at IS NULL',
            ['p' => $betalingId, 'k' => strtolower($nokkel), 'b' => $bookingId]);
    }

    /**
     * Steg 3 for Vipps-QR: betalingen er bekreftet betalt. Kalles fra
     * Booking::markerBetalt() i samme transaksjon, med påmeldingen låst.
     */
    public static function vedBetaling(int $betalingId, ?int $bookingId): void
    {
        if (!self::klar()) {
            return;
        }
        if ($bookingId !== null) {
            foreach (DB::alle('SELECT id, pris_ore, rabatt_ore FROM kasse_justeringer
                                WHERE payment_id = :p AND booking_id = :b AND gjort_at IS NULL FOR UPDATE',
                ['p' => $betalingId, 'b' => $bookingId]) as $r) {
                self::settPaa($bookingId, $r, $betalingId);
            }
            return;
        }
        DB::kjor('UPDATE kasse_justeringer SET gjort_at = UTC_TIMESTAMP() WHERE payment_id = :p AND booking_id IS NULL AND gjort_at IS NULL',
            ['p' => $betalingId]);
    }

    /**
     * Ordren: raden (låst i steg 1) får ordren, i samme transaksjon som
     * ordren. Kontant: gjort med en gang. Vipps-QR: betalingen kobles på, og
     * raden er gjort når den er betalt. En QR som ble stoppet og så tatt
     * kontant, har samme nøkkel: raden flyttes til den nye ordren.
     *
     * @param array<string,mixed> $d delen fra KasseKurv::deler()
     * @param array{id:int,navn:string} $person
     */
    public static function settOrdre(array $d, string $nokkel, int $ordreId, array $person, ?int $betalingId, bool $gjort): void
    {
        $j = $d['justering'] ?? null;
        if ($j === null) {
            return;
        }
        if (!self::klar()) {
            throw new RuntimeException(self::MANGLER, 503);
        }
        $felt = self::rad($j, $d, strtolower($nokkel), $person) + ['order_id' => $ordreId, 'payment_id' => $betalingId];
        $kol = array_keys($felt);
        DB::kjor(
            'INSERT INTO kasse_justeringer (' . implode(', ', $kol) . ', gjort_at) VALUES (:' . implode(', :', $kol) . ', '
                . ($gjort ? 'UTC_TIMESTAMP()' : 'NULL') . ')
             ON DUPLICATE KEY UPDATE order_id = VALUES(order_id), payment_id = VALUES(payment_id), gjort_at = VALUES(gjort_at)',
            $felt
        );
    }

    /** Vipps sa nei til QR-koden for ordren: raden mister ordren og betalingen (låsen står). */
    public static function fjernQr(string $nokkel): void
    {
        if (self::klar()) {
            DB::kjor('UPDATE kasse_justeringer SET order_id = NULL, payment_id = NULL WHERE nokkel = :k AND booking_id IS NULL AND gjort_at IS NULL',
                ['k' => strtolower($nokkel)]);
        }
    }

    /** @return array<string,mixed> */
    private static function rad(array $j, array $d, string $nokkel, array $person): array
    {
        return [
            'nokkel'          => strtolower($nokkel),
            'del_type'        => $d['type'] === 'booking' ? 'booking' : 'ordre',
            'opprinnelig_ore' => (int) $j['fra'],
            'ny_ore'          => (int) $d['sumOre'],
            'pris_ore'        => (int) $j['pris'],
            'rabatt_ore'      => (int) $j['rabatt'],
            'rabatt_tekst'    => $j['rabattTekst'] !== '' ? $j['rabattTekst'] : null,
            'hvorfor'         => $j['hvorfor'] !== '' ? $j['hvorfor'] : null,
            'linjer'          => $j['linjer'] !== [] ? json_encode($j['linjer'], JSON_UNESCAPED_UNICODE) : null,
            'registrert_av'   => (int) $person['id'] ?: null,
        ];
    }

    /**
     * Endringene på en ordre, til kvitteringen på e-post: «Mellom: pris
     * endret fra 700 kr» og «Rabatt 10 % — −195 kr».
     *
     * @return list<string>
     */
    public static function kvitteringslinjer(int $ordreId): array
    {
        if (!self::klar()) {
            return [];
        }
        $ut = [];
        foreach (DB::alle('SELECT linjer, rabatt_ore, rabatt_tekst FROM kasse_justeringer WHERE order_id = :o', ['o' => $ordreId]) as $r) {
            $ut = array_merge($ut, self::tekster($r));
        }
        return $ut;
    }

    /**
     * Endringene i et kjøp som nettopp er betalt, til kvitteringen på skjermen.
     *
     * @param list<array<string,mixed>> $deler fra KasseKurv::deler()
     * @return list<string>
     */
    public static function kvitteringFor(array $deler): array
    {
        $ut = [];
        foreach ($deler as $d) {
            $j = $d['justering'] ?? null;
            if ($j !== null) {
                $ut = array_merge($ut, self::tekster(['linjer' => json_encode($j['linjer']), 'rabatt_ore' => $j['rabatt'],
                    'rabatt_tekst' => $j['rabattTekst']]));
            }
        }
        return $ut;
    }

    /** @return list<string> */
    private static function tekster(array $r): array
    {
        $ut = [];
        foreach (json_decode((string) ($r['linjer'] ?? ''), true) ?: [] as $l) {
            $ut[] = $l['tekst'] . ': pris endret fra ' . KasseKurv::kr((int) $l['fraOre']);
        }
        if ((int) $r['rabatt_ore'] !== 0) {
            $ut[] = 'Rabatt ' . (string) $r['rabatt_tekst'] . ' — −' . KasseKurv::kr((int) $r['rabatt_ore']);
        }
        return $ut;
    }

    /**
     * Dagens endringer til Dagens oppgjør: bare det som er gjort (betalt i
     * dag). En QR-kode som aldri ble betalt, teller ikke.
     *
     * @return array{rader:list<array<string,mixed>>, trukketOre:int}
     */
    public static function idag(): array
    {
        if (!self::klar()) {
            return ['rader' => [], 'trukketOre' => 0];
        }
        [$fra, $til] = KasseKurv::dagen();
        $ut = [];
        $sum = 0;
        $oslo = new DateTimeZone('Europe/Oslo');
        foreach (DB::alle(
            "SELECT j.*, m.navn AS hvem, o.kunde_navn, COALESCE(bm.navn, b.gjest_navn) AS deltaker
               FROM kasse_justeringer j
          LEFT JOIN members m ON m.id = j.registrert_av
          LEFT JOIN orders o ON o.id = j.order_id
          LEFT JOIN bookings b ON b.id = j.booking_id
          LEFT JOIN members bm ON bm.id = b.member_id
              WHERE j.gjort_at >= :fra AND j.gjort_at < :til
           ORDER BY j.gjort_at, j.id",
            ['fra' => $fra, 'til' => $til]
        ) as $r) {
            $trukket = (int) $r['pris_ore'] + (int) $r['rabatt_ore'];
            $sum += $trukket;
            $ut[] = [
                'kl'         => (new DateTimeImmutable((string) $r['gjort_at'], new DateTimeZone('UTC')))->setTimezone($oslo)->format('H:i'),
                'hvem'       => (string) ($r['hvem'] ?? ''),
                'kunde'      => $r['booking_id'] !== null ? (string) $r['deltaker'] : trim((string) $r['kunde_navn']),
                'fraOre'     => (int) $r['opprinnelig_ore'],
                'fra'        => KasseKurv::kr((int) $r['opprinnelig_ore']),
                'tilOre'     => (int) $r['ny_ore'],
                'til'        => KasseKurv::kr((int) $r['ny_ore']),
                'trukketOre' => $trukket,
                'endringer'  => self::tekster($r),
                'hvorfor'    => (string) ($r['hvorfor'] ?? ''),
            ];
        }
        return ['rader' => $ut, 'trukketOre' => $sum];
    }
}
