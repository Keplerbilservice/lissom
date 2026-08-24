<?php
/**
 * Medlemschatten.
 *
 *   GET                     de siste meldingene
 *   GET ?etter=<id>         bare det som har kommet siden sist
 *   POST                    { tekst }        send en melding
 *   POST handling=slett     { id }           angre sin egen
 *
 * Meldingene laa i localStorage. De var altsaa synlige bare for den som
 * skrev dem — chatten gikk én vei, og det kom aldri et varsel, fordi det
 * aldri kom noe inn. Naa ligger de i basen, og alle med aktivt medlemskap
 * leser det samme rommet.
 *
 * Krever aktivt medlemskap, ikke bare innlogging. Vipps Login forteller hvem
 * noen er; det er medlemskapet som gir tilgang til verkstedet, og chatten
 * hoerer til verkstedet.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

/** Saa mange rader hentes ved forste lasting. Nok til aa se traaden, lite nok til aa vaere raskt. */
const CHAT_ANTALL = 60;
const CHAT_MAKS_TEGN = 500;

$medlem = krev_aktivt_medlem();
$megId = (int) $medlem['id'];

/**
 * Meldingene, nyeste sist.
 *
 * Navnet leses fra medlemmet naa, ikke fra da meldingen ble skrevet: bytter
 * noen navn, skal det staa riktig ogsaa paa det gamle.
 */
$les = static function (int $etter) use ($megId): array {
    $rader = DB::alle(
        'SELECT c.id, c.member_id, c.tekst, c.created_at, c.slettet_at,
                m.navn
           FROM chat_meldinger c
           JOIN members m ON m.id = c.member_id
          WHERE c.id > :etter
       ORDER BY c.id DESC
          LIMIT ' . CHAT_ANTALL,
        ['etter' => $etter]
    );
    $rader = array_reverse($rader);

    $oslo = new DateTimeZone('Europe/Oslo');
    return array_map(static function (array $r) use ($megId, $oslo): array {
        $egen = (int) $r['member_id'] === $megId;
        $slettet = $r['slettet_at'] !== null;
        return [
            'id'    => (int) $r['id'],
            'navn'  => $egen ? 'Deg' : (string) $r['navn'],
            'tekst' => $slettet ? 'Meldingen er slettet' : (string) $r['tekst'],
            'tid'   => (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))
                        ->setTimezone($oslo)->format('H:i'),
            'egen'  => $egen,
            'slettet' => $slettet,
        ];
    }, $rader);
};

if (Foresporsel::metode() === 'GET') {
    $etter = max(0, Foresporsel::heltall('etter'));
    $meldinger = $les($etter);
    Svar::json([
        'meldinger' => $meldinger,
        // Hoyeste id, ogsaa naar svaret er tomt: neste sporring skal ikke
        // begynne forfra og vise alt paa nytt som «nytt».
        'siste' => (int) (DB::verdi('SELECT COALESCE(MAX(id), 0) FROM chat_meldinger') ?? 0),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (Foresporsel::tekst('handling') === 'slett') {
    $id = Foresporsel::heltall('id');
    $rad = DB::en('SELECT id, member_id FROM chat_meldinger WHERE id = :i', ['i' => $id]);
    if ($rad === null) {
        Svar::feil('Fant ikke meldingen.', 404);
    }
    // Bare sin egen. Ingen skal kunne fjerne det andre har skrevet.
    if ((int) $rad['member_id'] !== $megId) {
        Svar::feil('Du kan bare slette dine egne meldinger.', 403);
    }
    DB::kjor('UPDATE chat_meldinger SET slettet_at = UTC_TIMESTAMP() WHERE id = :i AND slettet_at IS NULL', ['i' => $id]);
    Svar::ok(['id' => $id]);
}

// Et rom flere deler taaler ikke at én fyller det. Tjue meldinger paa fem
// minutter er rikelig for en samtale, og stopper en loepsk fane.
Rate::sjekk('chat', maks: 20, vindu: 300);

$tekst = trim(Foresporsel::tekst('tekst'));
if ($tekst === '') {
    Svar::feil('Skriv noe først.');
}
if (mb_strlen($tekst) > CHAT_MAKS_TEGN) {
    Svar::feil('Meldingen kan være opptil ' . CHAT_MAKS_TEGN . ' tegn.');
}

$id = DB::settInn('chat_meldinger', [
    'member_id' => $megId,
    'tekst'     => $tekst,
]);

Svar::ok([
    'id'        => $id,
    'meldinger' => $les(max(0, $id - 1)),
    'siste'     => $id,
]);
