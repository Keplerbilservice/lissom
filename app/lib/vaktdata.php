<?php
declare(strict_types=1);

/**
 * Datasjekkene vakta kjoerer hver morgen — se api/vakt-data.php.
 *
 * Eieren, 29. september 2026: «hvorfor fanges det ikke opp?» — Johanna sto
 * med Prøv Lissom etter at hun hadde betalt Mini 15, og et kurs betalt i
 * verkstedet manglet i dagens omsetning. Her staar reglene, saa testene og
 * vakta leser de samme.
 */
final class Vaktdata
{
    /** @return array{medlemmer:int,salg:int,avvik:list<string>} */
    public static function sjekk(): array
    {
        $avvik = [];

        // ── Medlemmene ─────────────────────────────────────────────────────────
        $aktive = [];
        foreach (DB::alle(
            "SELECT s.member_id, s.id, s.plan, m.navn, m.medlemskap_type
               FROM subscriptions s
               JOIN members m ON m.id = s.member_id
              WHERE s.status = 'aktiv' AND m.anonymisert_at IS NULL"
        ) as $r) {
            $aktive[(int) $r['member_id']][] = $r;
        }
        foreach ($aktive as $id => $rader) {
            $navn = (string) $rader[0]['navn'];
            if (count($rader) > 1) {
                $avvik[] = 'Medlem ' . $id . ' (' . $navn . ') har ' . count($rader) . ' aktive avtaler: '
                    . implode(', ', array_map(static fn($r) => '#' . $r['id'] . ' ' . $r['plan'], $rader));
            }
            $vist = trim((string) ($rader[0]['medlemskap_type'] ?? ''));
            $planer = array_map(static fn($r) => trim((string) $r['plan']), $rader);
            if (!in_array($vist, $planer, true)) {
                $avvik[] = 'Medlem ' . $id . ' (' . $navn . ') vises som «' . ($vist ?: 'ingen') . '», men den aktive avtalen er «'
                    . implode('», «', $planer) . '»';
            }
        }

        // ── Salgene i dag og i gaar ────────────────────────────────────────────
        $oslo = new DateTimeZone('Europe/Oslo');
        $utc  = new DateTimeZone('UTC');
        $idag = (new DateTimeImmutable('now', $oslo))->setTime(0, 0);
        $fra  = $idag->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $til  = $idag->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

        $rader  = Omsetning::rader($fra, $til);
        $betId  = [];
        $manuel = [];
        foreach ($rader as $r) {
            if (is_string($r['id']) && str_starts_with($r['id'], 'b')) {
                $manuel[(int) substr($r['id'], 1)] = true;
            } else {
                $betId[(int) $r['id']] = true;
            }
        }

        $uten = Booking::MAATER_UTEN_PENGER;
        $plass = implode(',', array_fill(0, count($uten), '?'));
        foreach (DB::alle(
            "SELECT b.id, b.payment_id, b.belop_ore, c.tittel
               FROM bookings b
               JOIN courses c ON c.id = b.course_id
              WHERE b.status = 'betalt' AND b.belop_ore > 0
                AND (b.betalt_maate IS NULL OR b.betalt_maate NOT IN ({$plass}))
                AND b.created_at >= ? AND b.created_at < ?",
            array_merge($uten, [$fra, $til])
        ) as $b) {
            $med = $b['payment_id'] !== null ? isset($betId[(int) $b['payment_id']]) : isset($manuel[(int) $b['id']]);
            if (!$med) {
                $avvik[] = 'Betalt påmelding ' . $b['id'] . ' (' . $b['tittel'] . ', ' . Booking::kroner((int) $b['belop_ore'])
                    . ') er ikke med i omsetningen';
            }
        }

        return [
            'medlemmer' => count($aktive),
            'salg'      => count($rader),
            'avvik'     => $avvik,
        ];
    }
}
