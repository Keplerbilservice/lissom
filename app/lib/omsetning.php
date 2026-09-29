<?php
declare(strict_types=1);

/**
 * Salgene i en periode, fra én kilde.
 *
 * Eieren, 29. september 2026: et kurs betalt i verkstedet (kr 2 800) sto i
 * dagsoppgjoret, men ikke i dagens omsetning paa Oversikt. De to regnet hver
 * sin vei. Naa leser begge herfra, og en test holder dem like.
 *
 * rader() gir én rad per betaling, med det dagsoppgjoret trenger for aa finne
 * konto og maate. perFormal() summerer inntekten per formaal: pengene inn
 * minus refusjon, pluss den delen et gavekort dekket.
 */
final class Omsetning
{
    /** @return list<array<string,mixed>> */
    public static function rader(string $fraUtc, string $tilUtc): array
    {
        $gavekortFelt = DB::harKolonne('payments', 'gavekort_ore')
            ? 'p.gavekort_ore' : '0 AS gavekort_ore';

        // Maaten paa selve betalingsraden. Kom med migrasjon 084 og settes paa alt
        // som slaas inn i kassa. Et delt oppgjor har én rad per del, og hver av dem
        // baerer sin egen maate — kontantdelen sin og Vipps-delen sin. Uten denne
        // ville begge blitt lest av «orders.betalt_maate», som staar én gang for hele
        // salget og da ikke kan si annet enn det samme om begge.
        $maateFelt = DB::harKolonne('payments', 'maate')
            ? 'p.maate AS radmaate' : 'NULL AS radmaate';

        // Var kortet kjopt eller gitt bort? Kom med migrasjon 134. De to har hver sin
        // motkonto, og uten kolonnen er alt kjopt, slik det var for.
        $opprinnelseFelt = DB::harKolonne('gift_cards', 'opprinnelse')
            ? 'g.opprinnelse' : "'kjopt' AS opprinnelse";
        $gavekortJoin = DB::harKolonne('payments', 'gavekort_id')
            ? 'LEFT JOIN gift_cards g ON g.id = p.gavekort_id' : '';
        if ($gavekortJoin === '') {
            $opprinnelseFelt = "'kjopt' AS opprinnelse";
        }

        // «orders.payment_id» peker bare paa hovedraden. Delene av et delt oppgjor
        // ville derfor mistet ordren sin i join-en under, og «betalt_maate» blitt
        // NULL paa dem. «payments.order_id» fra migrasjon 134 kobler alle radene, og
        // brukes naar den finnes.
        $ordreJoin = DB::harKolonne('payments', 'order_id')
            ? 'LEFT JOIN orders o ON o.id = p.order_id OR o.payment_id = p.id'
            : 'LEFT JOIN orders o ON o.payment_id = p.id';

        $rader = DB::alle(
            "SELECT p.id, p.formal, p.type, p.belop_ore, p.refundert_ore, p.created_at,
                    {$gavekortFelt}, {$maateFelt}, {$opprinnelseFelt}, o.betalt_maate
               FROM payments p
               {$ordreJoin}
               {$gavekortJoin}
              WHERE p.status IN ('betalt', 'delvis_refundert')
                AND p.created_at >= :fra AND p.created_at < :til
           ORDER BY p.created_at",
            ['fra' => $fraUtc, 'til' => $tilUtc]
        );

        // ── Paameldinger lagt inn for haand ────────────────────────────────────
        //
        // De lager ingen betalingsrad: de er gjort opp i verkstedet, med kontanter,
        // Vipps eller faktura, og pengene gikk aldri gjennom oss. Uten disse ville
        // bilaget vaert ufullstendig — en kursdeltaker som betalte kontant i doera
        // hadde ikke staatt noe sted i regnskapet.
        //
        // Bare betalte. «Betaler ved oppmoete» staar som reservert til den er gjort
        // opp.
        //
        // ── «Gratis» falt ikke ut av seg selv ────────────────────────────────
        //
        // Her sto det at «Gratis» er null kroner og faller ut paa «belop_ore > 0».
        // Det stemte ikke: naar en plass settes til Gratis, skrives maaten paa
        // bookingen — beloepet staar urort. Maalt 23. september 2026: booking 16
        // sto som Gratis paa kr 1 490, og dagsoppgjoret foerte de 1 490 som
        // inntekt. Okonomi gjorde det ikke, for det finnes ingen betalingsrad, og
        // de to rapportene sprikte med akkurat det beloepet.
        //
        // Derfor spoer vi paa maaten, ikke paa beloepet. Samme liste som
        // Booking::manuellBetaling() bruker, saa de to kan ikke komme i utakt.
        //
        // Fra 23. september 2026 lager begge veiene til «betalt» en betalingsrad,
        // saa dette er stien for det som ble foert for den datoen.
        $utenPenger = Booking::MAATER_UTEN_PENGER;
        $plass      = implode(',', array_fill(0, count($utenPenger), '?'));
        $manuelle = DB::alle(
            "SELECT b.id, b.belop_ore, b.betalt_maate, b.created_at
               FROM bookings b
              WHERE b.payment_id IS NULL
                AND b.status = 'betalt'
                AND b.belop_ore > 0
                AND (b.betalt_maate IS NULL OR b.betalt_maate NOT IN ({$plass}))
                AND b.created_at >= ? AND b.created_at < ?",
            array_merge($utenPenger, [$fraUtc, $tilUtc])
        );
        foreach ($manuelle as $m) {
            $rader[] = [
                'id'            => 'b' . $m['id'],
                'formal'        => 'booking',
                'type'          => 'manuell',
                'belop_ore'     => $m['belop_ore'],
                'refundert_ore' => 0,
                'created_at'    => $m['created_at'],
                'betalt_maate'  => $m['betalt_maate'],
            ];
        }

        return $rader;
    }

    /**
     * Inntekt per formaal i perioden, i oere. Tomme formaal er ikke med.
     *
     * @return array<string,int>
     */
    public static function perFormal(string $fraUtc, string $tilUtc): array
    {
        $ut = [];
        foreach (self::rader($fraUtc, $tilUtc) as $r) {
            $ore = (int) $r['belop_ore'] - (int) ($r['refundert_ore'] ?? 0) + (int) ($r['gavekort_ore'] ?? 0);
            if ($ore === 0) {
                continue;
            }
            $f = (string) $r['formal'];
            $ut[$f] = ($ut[$f] ?? 0) + $ore;
        }
        return $ut;
    }
}
