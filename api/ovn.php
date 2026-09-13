<?php
/**
 * Ovnkortet: «Ovn er tømt», «Råbrann satt», «Glasurbrann satt».
 *
 * Eieren, 12. september 2026: medlemmene vil ha en knapp paa Min side som
 * sier at ovnen er toemt, og de andre skal se det — med en pulserende
 * markering — til de selv har trykket «Sett». Etter 24 timer er det borte
 * uansett. Admin har samme knapp paa kalenderoversikten.
 * 13. september: to piller til, raabrann satt og glasurbrann satt, i samme
 * kort. Det siste trykket er statusen — én om gangen.
 *
 *   GET                        siste status det siste doegnet, og om jeg har sett den
 *   POST handling=tomt         ovnen er toemt naa (av meg)
 *   POST handling=raabrann     raabrann er satt (av meg)
 *   POST handling=glasurbrann  glasurbrann er satt (av meg)
 *   POST handling=sett         jeg har sett den siste statusen
 *
 * Medlemmer og admin. Alt svarer med det samme bildet som GET, saa skjermen
 * alltid viser det som staar i basen.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_aktivt_medlem();
$medlemId = (int) $medlem['id'];

// Foer migrasjon 171 er kjoert finnes ikke tabellene. Da er det ingen
// toemming aa vise, og knappen sier fra naar den trykkes.
$klar = DB::harTabell('ovn_tomt') && DB::harTabell('ovn_tomt_sett');
// Slaget kom med migrasjon 179. Foer den er kjoert er alt «tomt».
$harSlag = $klar && DB::harKolonne('ovn_tomt', 'slag');
const SLAG = ['tomt', 'raabrann', 'glasurbrann'];

/**
 * Siste toemming det siste doegnet — eller null.
 *
 * Doegnet regnes fra basens klokke, som gaar i UTC (app/bootstrap.php), saa
 * «24 timer» er 24 timer uansett sommertid.
 */
$siste = static function () use ($klar, $harSlag, $medlemId): ?array {
    if (!$klar) {
        return null;
    }
    $r = DB::en(
        'SELECT id, navn, created_at' . ($harSlag ? ', slag' : '') . ' FROM ovn_tomt
          WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
          ORDER BY id DESC LIMIT 1'
    );
    if ($r === null) {
        return null;
    }
    $sett = DB::verdi(
        'SELECT 1 FROM ovn_tomt_sett WHERE tomt_id = :t AND member_id = :m',
        ['t' => (int) $r['id'], 'm' => $medlemId]
    );
    return [
        'id'   => (int) $r['id'],
        'slag' => in_array((string) ($r['slag'] ?? ''), SLAG, true) ? (string) $r['slag'] : 'tomt',
        'av'   => (string) $r['navn'],
        // Sekunder siden epoken. Nettleseren skriver «i dag 14:20» selv, i
        // sin egen tidssone — basen lagrer UTC.
        'naar' => (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->getTimestamp(),
        'sett' => $sett !== null && $sett !== false,
    ];
};

if (Foresporsel::metode() === 'GET') {
    Svar::json(['klar' => $klar, 'tomt' => $siste()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (!$klar) {
    Svar::feil('«Ovn er tømt» er ikke satt opp ennå. Kjør oppdateringen av databasen først.');
}

$handling = Foresporsel::tekst('handling');

if (in_array($handling, SLAG, true)) {
    if ($handling !== 'tomt' && !$harSlag) {
        Svar::feil('«' . ($handling === 'raabrann' ? 'Råbrann' : 'Glasurbrann') . ' satt» er ikke satt opp ennå. Kjør oppdateringen av databasen først.');
    }
    $rad = [
        'member_id' => $medlemId,
        'navn'      => mb_substr(trim((string) ($medlem['navn'] ?? '')), 0, 191) ?: 'et medlem',
    ];
    if ($harSlag) {
        $rad['slag'] = $handling;
    }
    $id = DB::settInn('ovn_tomt', $rad);
    // Den som trykket har sett det.
    DB::kjor(
        'INSERT IGNORE INTO ovn_tomt_sett (tomt_id, member_id) VALUES (:t, :m)',
        ['t' => $id, 'm' => $medlemId]
    );
    revider('ovn_' . $handling, 'member', $medlemId, ['tomt' => $id]);
    Svar::ok(['klar' => true, 'tomt' => $siste()]);
}

if ($handling === 'sett') {
    $n = $siste();
    if ($n !== null) {
        DB::kjor(
            'INSERT IGNORE INTO ovn_tomt_sett (tomt_id, member_id) VALUES (:t, :m)',
            ['t' => $n['id'], 'm' => $medlemId]
        );
    }
    Svar::ok(['klar' => true, 'tomt' => $siste()]);
}

Svar::feil('Ukjent handling.');
