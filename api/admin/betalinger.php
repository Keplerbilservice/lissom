<?php
/**
 * Betalingene, med mulighet for refusjon.
 *
 *   GET   viser lista
 *   POST  refunderer  { referanse, belop }  — belop i kroner, tomt = alt
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

// Regnskapsfoereren ser lista. Aa flytte penger er noe annet — refusjon og
// «send kvittering paa nytt» krever admin, og det kreves lenger nede, rett
// for POST-en behandles.
krev_regnskap();

if (Foresporsel::metode() === 'GET') {
    // Kassa viser hele lista med sok, saa den maa strekke lenger enn 200.
    // Eieren, 1. september, om kassa: «her maa betalinger ses» — og paa
    // sporsmaalet om omfang: alt, med sok. Et sok som bare naar to hundre
    // rader tilbake finner ikke betalingen fra forrige maaned.
    // Maaten pengene kom inn paa staar paa raden. Kolonnen kom med delt
    // betaling i kassa; er den ikke kjort, faller vi tilbake paa «type» slik
    // det var for.
    $maateFelt = DB::harKolonne('payments', 'maate') ? 'p.maate' : 'NULL AS maate';

    // ── Bare det som faktisk ble penger ────────────────────────────────
    //
    // Eieren, 4. september, med en annullert rad staaende under Okonomi:
    // «hva i all verden er dette?» — og fire dager for, om det samme i
    // kassa: «avbrutt skal ikke vises noen sted».
    //
    // Regelen sto paa skjermen, i kassa, og bare der. Okonomi tegner sin
    // egen liste av det samme svaret og filtrerte paa formaal, ikke paa
    // status — saa et forsok som aldri ble til penger sto ved siden av
    // dagens salg, med beloepet sitt.
    //
    // Naa staar regelen her, ett sted, og begge listene faar den samme
    // sannheten. En tredje liste kan ikke glemme den.
    //
    // Betalt, og det som er sendt helt eller delvis tilbake etterpaa: en
    // refusjon hoerer til et salg som skjedde. «opprettet», «venter»,
    // «avbrutt» og «feilet» er forsok — og en annullert rad er satt til
    // «avbrutt», saa den faller ut her ogsaa.
    //
    // Radene slettes ikke. De staar i basen som for; de vises bare ikke
    // som penger. Dagsoppgjoret og omsetningen har skilt likedan hele
    // tida — se api/admin/dagsoppgjor.php og api/admin/okonomi.php.
    $betalinger = DB::alle(
        "SELECT p.id, p.vipps_reference, p.formal, p.type, p.belop_ore, p.refundert_ore,
                p.status, p.created_at, m.navn AS medlem, {$maateFelt},
                (SELECT b.id FROM bookings b WHERE b.payment_id = p.id LIMIT 1) AS booking_id,
                (SELECT o.id FROM orders o WHERE o.payment_id = p.id LIMIT 1) AS ordre_id
           FROM payments p
      LEFT JOIN members m ON m.id = p.member_id
         WHERE p.status IN ('betalt', 'delvis_refundert', 'refundert')
          ORDER BY p.id DESC
          LIMIT 1000"
    );

    // ── Hvordan kom pengene inn? ────────────────────────────────────────
    //
    // Kolonnen «maate» staar paa raden og sier det: «Kontant», «Vipps», eller
    // et gavekort. Den er det dagsoppgjoret til regnskapet leser — se
    // api/admin/dagsoppgjor.php, «p.maate AS radmaate».
    //
    // Her ble maaten regnet ut av «type» i stedet: alt som var fort for haand
    // ble til kontant. Et medlemskap betalt med Vipps over disk havnet dermed
    // i kontantkolonnen paa kortet i Kassa, mens dagsoppgjoret la det under
    // Vipps. De to sa ikke det samme om den samme dagen.
    //
    // Eieren, 4. september, etter aa ha registrert kr 500 med Vipps og sett
    // dem staa under Kontant.
    //
    // Gamle rader har ingen «maate». Da gjelder den gamle regelen, saa
    // historikken staar som for.
    $maateAv = static function (array $p): string {
        $m = trim((string) ($p['maate'] ?? ''));
        if ($m !== '') { return $m; }
        return (string) $p['type'] === 'manuell' ? 'Kontant' : 'Vipps';
    };

    Svar::json(['betalinger' => array_map(static fn($p) => [
        'id'         => (int) $p['id'],
        'referanse'  => $p['vipps_reference'],
        'formal'     => $p['formal'],
        'belop'      => Booking::kroner((int) $p['belop_ore']),
        'belopOre'   => (int) $p['belop_ore'],
        'refundert'  => (int) $p['refundert_ore'] > 0 ? Booking::kroner((int) $p['refundert_ore']) : null,
        // Raa tall ogsaa, saa skjermen kan regne ut hva som staar igjen aa
        // refundere uten aa tolke «kr. 1 490,-» tilbake til et tall.
        'refundertOre' => (int) $p['refundert_ore'],
        'status'     => $p['status'],
        'medlem'     => $p['medlem'],
        'tidspunkt'  => Booking::norskDato((string) $p['created_at']),
        // ── Det kassa trenger i tillegg ─────────────────────────────────
        //
        // To spoersmaal som saa like ut og ble besvart med det samme feltet:
        //
        //   Kom pengene inn paa Vipps?     → deler dagen i kassa
        //   Kan de sendes tilbake dit?     → om refusjonsknappen skal staa
        //
        // De er ikke det samme. En Vipps-betaling tatt over disk og fort inn
        // for haand kom inn paa Vipps, men har ingen referanse hos oss aa
        // refundere paa — den ble aldri opprettet gjennom integrasjonen vaar.
        // Skal pengene tilbake, gjores det i Vipps.
        'maate'         => $maateAv($p),
        'medVipps'      => $maateAv($p) === 'Vipps',
        'kanRefunderes' => (string) $p['type'] !== 'manuell',
        // Datoen som tall, saa skjermen kan skille «i dag» fra resten uten
        // aa tolke «1. september 2026, 10:41» tilbake til en dato.
        'dato'       => substr((string) $p['created_at'], 0, 10),
        // Hva kvitteringen hoerer til. Er begge tomme, finnes det ingen
        // kvittering aa sende — og da skal knappen ikke staa der.
        'bookingId'  => $p['booking_id'] === null ? null : (int) $p['booking_id'],
        'ordreId'    => $p['ordre_id'] === null ? null : (int) $p['ordre_id'],
    ], $betalinger)]);
}

Foresporsel::krevMetode('POST');

// Herfra og ned flyttes det penger, eller sendes noe til en kunde. Det er
// verkstedets avgjorelse, ikke regnskapsfoererens.
krev_admin();
Foresporsel::krevSammeOpphav();

$referanse = Foresporsel::tekst('referanse');
$betaling = DB::en('SELECT * FROM payments WHERE vipps_reference = :r', ['r' => $referanse]);

if ($betaling === null) {
    Svar::feil('Fant ikke betalingen.', 404);
}

// ── Kvittering paa nytt ────────────────────────────────────────────────
//
// Eieren, 1. september, om hva mer kassa trenger: «Send kvittering paa nytt».
// Kunden mistet den, eller den kom aldri fram. Da skal den samme kunne sendes
// igjen uten aa lage et nytt salg — det ville blitt en ny linje i regnskapet
// for noe som alt er betalt.
//
// Vi lager ingen ny tekst her: det er de samme funksjonene bookingen og
// ordren sender fra seg naar de blir betalt.
if (Foresporsel::tekst('handling') === 'kvittering') {
    if ((string) $betaling['status'] !== 'betalt'
        && (string) $betaling['status'] !== 'delvis_refundert') {
        Svar::feil('Det finnes ingen kvittering — betalingen er ikke gjennomført.', 409);
    }

    $booking = DB::en('SELECT id FROM bookings WHERE payment_id = :p LIMIT 1', ['p' => $betaling['id']]);
    $ordre   = DB::en('SELECT id FROM orders   WHERE payment_id = :p LIMIT 1', ['p' => $betaling['id']]);

    try {
        if ($booking !== null) {
            Booking::sendBekreftelse((int) $booking['id']);
        } elseif ($ordre !== null) {
            Booking::sendOrdrebekreftelse((int) $ordre['id']);
        } else {
            // Medlemstrekk og gavekort har ingen kvittering av dette slaget.
            // Da sier vi det, framfor aa svare «sendt» uten aa ha sendt noe.
            Svar::feil('Denne betalingen har ingen kvittering å sende. '
                . 'Gavekort og medlemstrekk sendes hver sin vei.', 409);
        }
    } catch (Throwable $e) {
        logg_feil('Fikk ikke sendt kvittering paa nytt for ' . $referanse, $e);
        Svar::feil('Kvitteringen ble ikke sendt. Prøv igjen om litt.', 502);
    }

    revider('kvittering_sendt_paa_nytt', 'payment', (int) $betaling['id'], []);
    Svar::ok(['beskjed' => 'Kvitteringen er sendt på nytt.']);
}

if ($betaling['status'] !== 'betalt' && $betaling['status'] !== 'delvis_refundert') {
    Svar::feil('Denne betalingen kan ikke refunderes — den er ikke gjennomfort.', 409);
}

// Et kontantsalg har aldri vaert innom Vipps, saa det finnes ingenting aa
// sende tilbake der. Foer denne stod refusjonen og ventet paa en feil fra
// Vipps paa en referanse Vipps ikke kjenner. Salget annulleres i stedet, fra
// kassa — da gaar varene tilbake paa lager ogsaa.
if ((string) $betaling['type'] === 'manuell') {
    Svar::feil('Dette er et kontantsalg — det har ikke gått gjennom Vipps, '
        . 'så det finnes ingen penger å sende tilbake. Annuller salget i kassa i stedet.', 409);
}

$maks = (int) $betaling['belop_ore'] - (int) $betaling['refundert_ore'];
$onsket = Foresporsel::heltall('belop') * 100;   // kroner inn, ore ut
$belop = $onsket > 0 ? min($onsket, $maks) : $maks;

if ($belop <= 0) {
    Svar::feil('Hele beløpet er allerede refundert.', 409);
}

try {
    Vipps::refunder($referanse, $belop);
} catch (Throwable $e) {
    logg_feil('Refusjon feilet for ' . $referanse, $e);
    Svar::feil('Vipps godtok ikke refusjonen. Prøv igjen, eller sjekk i portalen.', 502);
}

$nyRefundert = (int) $betaling['refundert_ore'] + $belop;
DB::oppdater('payments', [
    'refundert_ore' => $nyRefundert,
    'status'        => $nyRefundert >= (int) $betaling['belop_ore'] ? 'refundert' : 'delvis_refundert',
], ['id' => $betaling['id']]);

// Booking foelger betalingen — men bare naar hele beloepet er sendt tilbake.
//
// Her sto oppdateringen uten betingelse. En delrefusjon etter vilkaarene (50 %
// inntil sju dager for) ville da satt plassen som refundert: deltakeren falt
// ut av lista, og stolen ble ledig igjen — selv om hen fortsatt skulle komme.
// Det er delrefusjonens hele poeng at plassen ikke gis fra seg gratis.
if ($nyRefundert >= (int) $betaling['belop_ore']) {
    DB::kjor(
        "UPDATE bookings SET status = 'refundert' WHERE payment_id = :p",
        ['p' => $betaling['id']]
    );
}

revider('refusjon', 'payment', (int) $betaling['id'], ['belop_ore' => $belop]);

Svar::ok([
    'refundert' => Booking::kroner($belop),
    'gjenstaar' => Booking::kroner($maks - $belop),
    // Raa tall ogsaa: skjermen skal ikke maatte sammenligne «kr. 0,-» som
    // tekst for aa vite om det staar noe igjen.
    'gjenstaarOre' => $maks - $belop,
]);
