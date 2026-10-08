<?php
/**
 * Endret pris og rabatt i kassa på iPad (eieren, «ja» 8. oktober 2026,
 * oppsett A): trykk på prisen på en linje for å endre den, og «Rabatt på
 * kjøpet» i prosent eller kroner, med valgfritt «hvorfor».
 *
 * Regnestykket står i KasseKurv::deler() (fra basen, aldri fra nettleseren).
 * Her lagres det, i migrasjon 269:
 *
 *   kasse_justeringer   én rad per del (påmeldingen eller ordren): beløpet
 *                       før og etter, prisendringen, rabatten, hvem som sto
 *                       i kassa og hvorfor. Nøkkelen er delens
 *                       idempotensnøkkel, så et nytt forsøk lager ikke to.
 *   bookings.kasse_rabatt_ore  det kassa har trukket fra på påmeldingen.
 *
 * En påmelding: beløpet (bookings.belop_ore) settes ned med justeringen, så
 * «skyldig» og Vipps-QR er riktige, og det står som betalt i admin. En
 * justering som ble laget for en QR-kode som aldri ble betalt, «venter»: den
 * erstattes når kurven tas betalt på nytt, så rabatten aldri trekkes to
 * ganger. Den er gjort når det er betalt noe på påmeldingen etter at den ble
 * laget.
 *
 * En ordre: varelinjene har den nye prisen, og ordresummen er etter rabatt.
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
        return DB::harTabell('kasse_justeringer') && DB::harKolonne('bookings', 'kasse_rabatt_ore');
    }

    /**
     * Det kassa har trukket fra på påmeldingen: gjort (betalt etterpå) og
     * ventende (en QR-kode som ikke ble betalt).
     *
     * @return array{gjort:int, venter:int, ventende:list<int>}
     */
    public static function paaBooking(int $bookingId, bool $laas = false): array
    {
        $ut = ['gjort' => 0, 'venter' => 0, 'ventende' => []];
        if (!self::klar()) {
            return $ut;
        }
        $rader = DB::alle(
            'SELECT id, pris_ore, rabatt_ore, ny_ore, siste_betaling_id FROM kasse_justeringer
              WHERE booking_id = :b AND erstattet_at IS NULL ORDER BY id' . ($laas ? ' FOR UPDATE' : ''),
            ['b' => $bookingId]
        );
        if ($rader === []) {
            return $ut;
        }
        $betalinger = Booking::betalingerFor($bookingId)['rader'];
        foreach ($rader as $r) {
            $belop = (int) $r['pris_ore'] + (int) $r['rabatt_ore'];
            // Gjort: betalt etterpå, eller ingenting igjen å betale.
            if ((int) $r['ny_ore'] === 0 || self::betaltEtter($betalinger, (int) $r['siste_betaling_id'])) {
                $ut['gjort'] += $belop;
            } else {
                $ut['venter'] += $belop;
                $ut['ventende'][] = (int) $r['id'];
            }
        }
        return $ut;
    }

    /** @param list<array<string,mixed>> $betalinger Booking::betalingerFor()['rader'] */
    private static function betaltEtter(array $betalinger, int $etter): bool
    {
        foreach ($betalinger as $p) {
            if ((int) $p['id'] > $etter && $p['annullert_at'] === null
                && in_array((string) $p['status'], ['betalt', 'autorisert', 'delvis_refundert'], true)) {
                return true;
            }
        }
        return false;
    }

    /** Vil settBooking() endre beløpet på påmeldingen? (Da stoppes en QR-kode som venter, først.) */
    public static function endrerBooking(array $d, string $nokkel): bool
    {
        if (!self::klar()) {
            return ($d['justering'] ?? null) !== null;
        }
        if (DB::verdi('SELECT id FROM kasse_justeringer WHERE nokkel = :k', ['k' => strtolower($nokkel)]) !== null) {
            return false;
        }
        $j = $d['justering'] ?? null;
        $ny = $j === null ? 0 : (int) $j['pris'] + (int) $j['rabatt'];
        return $ny !== 0 || self::paaBooking((int) $d['bookingId'])['venter'] !== 0;
    }

    /**
     * Påmeldingen: justeringen fra kurven settes på (eller en ventende tas
     * bort når kurven ikke har noen). Kalles med KursstartKrav::laas() holdt,
     * etter stoppVentende(), og før PopPris::kassa().
     *
     * @param array<string,mixed> $d delen fra KasseKurv::deler()
     * @param array{id:int,navn:string} $person
     */
    public static function settBooking(array $d, string $nokkel, array $person): void
    {
        $j = $d['justering'] ?? null;
        if (!self::klar()) {
            if ($j !== null) {
                throw new RuntimeException(self::MANGLER, 503);
            }
            return;
        }
        $bid = (int) $d['bookingId'];
        $nokkel = strtolower($nokkel);
        if (DB::verdi('SELECT id FROM kasse_justeringer WHERE nokkel = :k', ['k' => $nokkel]) !== null) {
            return;   // samme kurv, nytt forsøk: alt satt
        }
        $ny = $j === null ? 0 : (int) $j['pris'] + (int) $j['rabatt'];
        $endret = DB::iTransaksjon(static function () use ($bid, $nokkel, $ny, $j, $d, $person): ?array {
            $b = DB::en('SELECT belop_ore, kasse_rabatt_ore FROM bookings WHERE id = :i FOR UPDATE', ['i' => $bid]);
            if ($b === null) {
                throw new RuntimeException('Fant ikke påmeldingen.', 404);
            }
            $naa = self::paaBooking($bid, true);
            if ($naa['venter'] === 0 && $ny === 0) {
                return null;
            }
            if ($naa['ventende'] !== []) {
                DB::kjor('UPDATE kasse_justeringer SET erstattet_at = UTC_TIMESTAMP() WHERE id IN ('
                    . implode(',', $naa['ventende']) . ')');
            }
            if ($ny !== 0) {
                DB::settInn('kasse_justeringer', self::rad($j, $d, $nokkel, $person) + ['booking_id' => $bid]);
            }
            $belop = max(0, (int) $b['belop_ore'] + $naa['venter'] - $ny);
            DB::oppdater('bookings', [
                'belop_ore'        => $belop,
                'kasse_rabatt_ore' => (int) $b['kasse_rabatt_ore'] - $naa['venter'] + $ny,
            ], ['id' => $bid]);
            return ['fra' => (int) $b['belop_ore'], 'til' => $belop];
        });
        if ($endret !== null) {
            Booking::settBetaltStatus($bid);
            revider('kasse_justering', 'booking', $bid, $endret + ['trukket_ore' => $ny, 'kasse' => $person['id'],
                'rabatt' => $j['rabattTekst'] ?? null, 'hvorfor' => $j['hvorfor'] ?? null]);
        }
    }

    /**
     * Ordren: raden lagres i samme transaksjon som ordren. En QR-kode som ble
     * stoppet og så tatt kontant, har samme nøkkel: raden flyttes til den nye
     * ordren.
     *
     * @param array<string,mixed> $d delen fra KasseKurv::deler()
     * @param array{id:int,navn:string} $person
     */
    public static function settOrdre(array $d, string $nokkel, int $ordreId, array $person): void
    {
        $j = $d['justering'] ?? null;
        if ($j === null) {
            return;
        }
        if (!self::klar()) {
            throw new RuntimeException(self::MANGLER, 503);
        }
        $felt = self::rad($j, $d, strtolower($nokkel), $person) + ['order_id' => $ordreId];
        $kol = array_keys($felt);
        DB::kjor(
            'INSERT INTO kasse_justeringer (' . implode(', ', $kol) . ') VALUES (:' . implode(', :', $kol) . ')
             ON DUPLICATE KEY UPDATE order_id = VALUES(order_id), erstattet_at = NULL',
            $felt
        );
    }

    /** QR-koden ble avvist av Vipps: raden for ordren fjernes sammen med den. */
    public static function fjern(string $nokkel): void
    {
        if (self::klar()) {
            DB::kjor('DELETE FROM kasse_justeringer WHERE nokkel = :k AND booking_id IS NULL', ['k' => strtolower($nokkel)]);
        }
    }

    /** @return array<string,mixed> */
    private static function rad(array $j, array $d, string $nokkel, array $person): array
    {
        return [
            'nokkel'            => $nokkel,
            'del_type'          => $d['type'] === 'booking' ? 'booking' : 'ordre',
            'opprinnelig_ore'   => (int) $j['fra'],
            'ny_ore'            => (int) $d['sumOre'],
            'pris_ore'          => (int) $j['pris'],
            'rabatt_ore'        => (int) $j['rabatt'],
            'rabatt_tekst'      => $j['rabattTekst'] !== '' ? $j['rabattTekst'] : null,
            'hvorfor'           => $j['hvorfor'] !== '' ? $j['hvorfor'] : null,
            'linjer'            => $j['linjer'] !== [] ? json_encode($j['linjer'], JSON_UNESCAPED_UNICODE) : null,
            'registrert_av'     => (int) $person['id'] ?: null,
            'siste_betaling_id' => (int) DB::verdi('SELECT COALESCE(MAX(id), 0) FROM payments'),
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
        foreach (DB::alle('SELECT linjer, rabatt_ore, rabatt_tekst FROM kasse_justeringer WHERE order_id = :o AND erstattet_at IS NULL',
            ['o' => $ordreId]) as $r) {
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
     * Dagens endringer til Dagens oppgjør: bare det som er betalt (en QR-kode
     * som aldri ble betalt, teller ikke).
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
            "SELECT j.*, m.navn AS hvem, o.status AS ordrestatus, o.kunde_navn, o.ordrenr,
                    COALESCE(bm.navn, b.gjest_navn) AS deltaker
               FROM kasse_justeringer j
          LEFT JOIN members m ON m.id = j.registrert_av
          LEFT JOIN orders o ON o.id = j.order_id
          LEFT JOIN bookings b ON b.id = j.booking_id
          LEFT JOIN members bm ON bm.id = b.member_id
              WHERE j.created_at >= :fra AND j.created_at < :til AND j.erstattet_at IS NULL
           ORDER BY j.id",
            ['fra' => $fra, 'til' => $til]
        ) as $r) {
            if ($r['booking_id'] !== null) {
                if (!self::betaltEtter(Booking::betalingerFor((int) $r['booking_id'])['rader'], (int) $r['siste_betaling_id'])
                    && (int) $r['ny_ore'] > 0) {
                    continue;
                }
                $kunde = (string) $r['deltaker'];
            } else {
                if (!in_array((string) $r['ordrestatus'], ['betalt', 'klar', 'hentet'], true)) {
                    continue;
                }
                $kunde = trim((string) $r['kunde_navn']);
            }
            $trukket = (int) $r['pris_ore'] + (int) $r['rabatt_ore'];
            $sum += $trukket;
            $ut[] = [
                'kl'          => (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->setTimezone($oslo)->format('H:i'),
                'hvem'        => (string) ($r['hvem'] ?? ''),
                'kunde'       => $kunde,
                'fraOre'      => (int) $r['opprinnelig_ore'],
                'fra'         => KasseKurv::kr((int) $r['opprinnelig_ore']),
                'tilOre'      => (int) $r['ny_ore'],
                'til'         => KasseKurv::kr((int) $r['ny_ore']),
                'trukketOre'  => $trukket,
                'endringer'   => self::tekster($r),
                'hvorfor'     => (string) ($r['hvorfor'] ?? ''),
            ];
        }
        return ['rader' => $ut, 'trukketOre' => $sum];
    }
}
