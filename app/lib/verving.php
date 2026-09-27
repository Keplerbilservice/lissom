<?php
/**
 * Vervepremie: verv en venn til aarsmedlemskap og faa timer i verkstedet.
 *
 * Eieren, 27. september 2026: «verv en venn til aarsmedlemskap og faa 5
 * timer til bruk i verkstedet, jeg maa kunne endre antall timer de faar».
 *
 * Slik henger det sammen:
 *   1. Medlemmet ser banneret paa Min side og kopierer sin personlige lenke
 *      (lissom.no/medlemskap?verv=KODE). Koden lages foerste gang.
 *   2. Vennen melder seg inn fra lenka. Koden lagres paa medlemsordren
 *      (api/medlemsordre.php), saa den overlever turen innom Vipps.
 *   3. Naar Vipps sier at vennens avtale er aktiv — foerste trekk er da tatt
 *      — kaller Medlemskap::oppdaterFraVipps() premier(). Premien er en
 *      vanlig timegave (medlemsgaver, type timer) til ververen, og en rad i
 *      «vervinger».
 *
 * Reglene: bryteren maa staa paa, planen maa vaere aarsmedlemskapet (bundet i
 * tolv maaneder), vennen maa vaere ny, ingen kan verve seg selv, ververen maa
 * vaere aktivt medlem, og hver venn gir én premie. UNIQUE paa vennen i
 * «vervinger» er sperren naar webhook og retur kommer samtidig.
 */

declare(strict_types=1);

final class Verving
{
    public const STANDARD_TIMER = 5;

    public static function klar(): bool
    {
        return DB::harTabell('vervinger') && DB::harKolonne('members', 'verve_kode');
    }

    /** Markedsfoering › Vervepremie. Mangler raden, er den av. */
    public static function paa(): bool
    {
        return (string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/verving'") === 'ja';
    }

    /** Timene den som verver faar. Eieren setter tallet i admin. */
    public static function timer(): int
    {
        if (!DB::harTabell('innstillinger')) {
            return self::STANDARD_TIMER;
        }
        $v = DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'verving_timer'");
        return $v === null || $v === false || (int) $v < 1 ? self::STANDARD_TIMER : (int) $v;
    }

    public static function settTimer(int $timer, int $av): void
    {
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (:n, :v, :a)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            ['n' => 'verving_timer', 'v' => (string) max(1, min(100, $timer)), 'a' => $av]
        );
    }

    /** Bare bokstavene og tallene en kode kan ha. */
    public static function renKode(string $kode): string
    {
        return substr(preg_replace('/[^a-z0-9]/', '', strtolower($kode)) ?? '', 0, 16);
    }

    /** Medlemmets kode — den samme hver gang. Lages foerste gang. */
    public static function kodeFor(int $medlemId): string
    {
        $har = (string) (DB::verdi('SELECT verve_kode FROM members WHERE id = :i', ['i' => $medlemId]) ?? '');
        if ($har !== '') {
            return $har;
        }
        // Uten tegn som ligner hverandre (0/o, 1/l), saa den kan leses opp.
        $tegn = 'abcdefghjkmnpqrstuvwxyz23456789';
        for ($forsok = 0; $forsok < 10; $forsok++) {
            $kode = '';
            for ($i = 0; $i < 8; $i++) {
                $kode .= $tegn[random_int(0, strlen($tegn) - 1)];
            }
            try {
                $endret = DB::kjor(
                    'UPDATE members SET verve_kode = :k WHERE id = :i AND verve_kode IS NULL',
                    ['k' => $kode, 'i' => $medlemId]
                )->rowCount();
            } catch (PDOException $e) {
                continue; // en annen har den koden — proev en ny
            }
            if ($endret > 0) {
                return $kode;
            }
            // Noen lagde den i samme oeyeblikk: les den som staar.
            $har = (string) (DB::verdi('SELECT verve_kode FROM members WHERE id = :i', ['i' => $medlemId]) ?? '');
            if ($har !== '') {
                return $har;
            }
        }
        throw new RuntimeException('Fikk ikke laget en vervekode.');
    }

    public static function lenkeFor(int $medlemId): string
    {
        return rtrim(Config::nettsted(), '/') . '/medlemskap?verv=' . self::kodeFor($medlemId);
    }

    /** Aarsmedlemskapet er det som er bundet i tolv maaneder. */
    public static function gjelderPlan(string $planNavn): bool
    {
        $plan = Medlemskap::planUansett($planNavn);
        return $plan !== null && (int) ($plan['binding_mnd'] ?? 0) >= 12;
    }

    /**
     * Gir premien for denne vennen, hvis alt stemmer. Trygg aa kalle flere
     * ganger: premien gis bare én gang per venn.
     *
     * @return int|null id-en paa gaven, eller null naar ingen premie ble gitt
     */
    public static function premier(int $vennId, int $avtaleId, string $planNavn): ?int
    {
        if (!self::klar() || !self::paa() || !self::gjelderPlan($planNavn)) {
            return null;
        }

        // Koden sto paa innmeldingen som ble til denne avtalen.
        $ordre = DB::en(
            'SELECT id, verve_kode FROM medlemsordrer
              WHERE subscription_id = :a AND verve_kode IS NOT NULL
           ORDER BY id DESC LIMIT 1',
            ['a' => $avtaleId]
        );
        $kode = $ordre === null ? '' : self::renKode((string) $ordre['verve_kode']);
        if ($kode === '') {
            return null;
        }

        $verver = DB::en('SELECT * FROM members WHERE verve_kode = :k', ['k' => $kode]);
        if ($verver === null || (int) $verver['id'] === $vennId || !er_aktivt_medlem($verver)) {
            return null;
        }

        // Ny: ingen annen avtale som har loept, og ingen innmelding i
        // verkstedet foer denne.
        $tidligere = (int) DB::verdi(
            "SELECT COUNT(*) FROM subscriptions
              WHERE member_id = :m AND id <> :a AND status IN ('aktiv','stoppet','utlopt')",
            ['m' => $vennId, 'a' => $avtaleId]
        ) + (int) DB::verdi(
            "SELECT COUNT(*) FROM medlemsordrer
              WHERE medlem_id = :m AND id <> :o AND status = 'fullfort'",
            ['m' => $vennId, 'o' => (int) $ordre['id']]
        );
        if ($tidligere > 0) {
            return null;
        }

        $timer = self::timer();
        $oslo  = new DateTimeZone('Europe/Oslo');

        try {
            $gaveId = DB::iTransaksjon(static function () use ($verver, $vennId, $avtaleId, $planNavn, $timer, $oslo): int {
                $radId = DB::settInn('vervinger', [
                    'verver_id'       => (int) $verver['id'],
                    'venn_id'         => $vennId,
                    'subscription_id' => $avtaleId,
                    'plan'            => mb_substr($planNavn, 0, 64),
                    'timer'           => $timer,
                ]);
                // Samme gyldighet som timegavene admin gir: ut inneværende
                // maaned (api/admin/gaver.php).
                $gaveId = DB::settInn('medlemsgaver', [
                    'member_id'  => (int) $verver['id'],
                    'type'       => 'timer',
                    'timer'      => $timer,
                    'gyldig_til' => (new DateTimeImmutable('now', $oslo))->format('Y-m-t'),
                ]);
                DB::oppdater('vervinger', ['gave_id' => $gaveId], ['id' => $radId]);
                return $gaveId;
            });
        } catch (PDOException $e) {
            // Duplikat paa vennen: premien er alt gitt.
            if ((string) $e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }

        revider('verving_premie', 'member', (int) $verver['id'],
            ['venn' => $vennId, 'avtale' => $avtaleId, 'timer' => $timer, 'gave' => $gaveId]);

        return $gaveId;
    }

    /** «Vervet saa langt» i admin. */
    public static function liste(int $maks = 200): array
    {
        if (!self::klar()) {
            return [];
        }
        return array_map(static fn(array $r): array => [
            'id'     => (int) $r['id'],
            'verver' => (string) $r['verver'],
            'venn'   => (string) $r['venn'],
            'dato'   => Booking::norskDatoKort((string) $r['created_at']),
            'timer'  => (int) $r['timer'],
        ], DB::alle(
            'SELECT v.id, v.timer, v.created_at, a.navn AS verver, b.navn AS venn
               FROM vervinger v
               JOIN members a ON a.id = v.verver_id
               JOIN members b ON b.id = v.venn_id
           ORDER BY v.id DESC LIMIT ' . max(1, $maks)
        ));
    }
}
