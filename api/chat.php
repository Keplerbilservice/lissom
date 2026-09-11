<?php
/**
 * Medlemschatten.
 *
 *   GET                        de siste meldingene
 *   GET ?etter=<id>            bare det som har kommet siden sist
 *   POST                       { tekst }     send en melding
 *   POST handling=slett        { id }        angre sin egen — admin kan alle
 *   POST handling=angre-slett  { id }        hent en slettet melding tilbake
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

// Den som driver verkstedet maa kunne rydde i rommet. Eieren, 10. september
// 2026, om en melding fra Monica som sto klippet: «Jeg vil slette meldingen
// til monica, den maa kunne angres.»
//
// Sesjon::erAdmin() og ikke rollen i raden: den samme sperra som resten av
// admin bruker, saa en sesjon uten passord ikke gir mer her enn der.
$erAdmin = Sesjon::erAdmin();

// Hvem som slettet meldingen. Eieren, 11. september 2026: «min side kan
// angre meldingen som admin har slettet!»
//
// «slettet_at» sier naar, ikke av hvem — og uten det kunne medlemmet hente
// tilbake det verkstedet nettopp hadde ryddet vekk. Migrasjon 156 legger til
// kolonnen.
//
// Er den ikke kjort ennaa, faar bare admin hente noe tilbake i det hele
// tatt. Heller én knapp for lite hos et medlem enn én for mye.
$vetHvemSomSlettet = DB::harKolonne('chat_meldinger', 'slettet_av');

/**
 * Meldingene, nyeste sist.
 *
 * Navnet leses fra medlemmet naa, ikke fra da meldingen ble skrevet: bytter
 * noen navn, skal det staa riktig ogsaa paa det gamle.
 */
$les = static function (int $etter) use ($megId, $erAdmin, $vetHvemSomSlettet): array {
    $rader = DB::alle(
        'SELECT c.id, c.member_id, c.tekst, c.created_at, c.slettet_at,
                ' . ($vetHvemSomSlettet ? 'c.slettet_av,' : 'NULL AS slettet_av,') . '
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
    return array_map(static function (array $r) use ($megId, $erAdmin, $vetHvemSomSlettet, $oslo): array {
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
            // Serveren sier hvem som kan roere hva; skjermen tegner bare
            // etter det. Da kan de to ikke komme i utakt.
            'kanSlette' => $egen || $erAdmin,
            // Slettet du den selv? Eieren, 11. september 2026: «jeg vil
            // ikke kunne slette den naar jeg er inne paa min side, kun naar
            // jeg er i admin.» Paa Min side er ogsaa admin bare et medlem,
            // og et medlem kan hente tilbake det det selv slettet.
            'slettetAvMeg' => $slettet && $vetHvemSomSlettet
                           && $r['slettet_av'] !== null
                           && (int) $r['slettet_av'] === $megId,
            // Aa hente tilbake er noe annet enn aa slette: slettet du den
            // selv, kan du angre. Slettet verkstedet den, er det bare
            // verkstedet som kan det.
            'kanHente'  => $slettet && ($erAdmin || (
                $egen && $vetHvemSomSlettet
                     && $r['slettet_av'] !== null
                     && (int) $r['slettet_av'] === $megId
            )),
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

$handling = Foresporsel::tekst('handling');
if ($handling === 'slett' || $handling === 'angre-slett') {
    $id = Foresporsel::heltall('id');
    $rad = DB::en('SELECT id, member_id FROM chat_meldinger WHERE id = :i', ['i' => $id]);
    if ($rad === null) {
        Svar::feil('Fant ikke meldingen.', 404);
    }
    // Sin egen, eller admin. Et medlem skal fortsatt ikke kunne roere det
    // et annet medlem har skrevet.
    $egen = (int) $rad['member_id'] === $megId;
    if (!$egen && !$erAdmin) {
        Svar::feil('Du kan bare slette dine egne meldinger.', 403);
    }

    // Aa hente tilbake krever mer enn aa slette: har verkstedet ryddet vekk
    // en melding, skal ikke den som skrev den kunne sette den opp igjen.
    if ($handling === 'angre-slett' && !$erAdmin) {
        $slettetAv = $vetHvemSomSlettet
            ? DB::verdi('SELECT slettet_av FROM chat_meldinger WHERE id = :i', ['i' => $id])
            : null;
        if ($slettetAv === null || (int) $slettetAv !== $megId) {
            Svar::feil('Denne meldingen er slettet av verkstedet.', 403);
        }
    }

    // Sletting er myk: teksten staar i basen, raden faar bare et tidspunkt,
    // og den som leser ser «Meldingen er slettet». Derfor kan den hentes
    // tilbake — eieren ba om nettopp det.
    if ($handling === 'slett') {
        DB::kjor(
            'UPDATE chat_meldinger SET slettet_at = UTC_TIMESTAMP()'
            . ($vetHvemSomSlettet ? ', slettet_av = :a' : '')
            . ' WHERE id = :i AND slettet_at IS NULL',
            $vetHvemSomSlettet ? ['i' => $id, 'a' => $megId] : ['i' => $id]
        );
    } else {
        DB::kjor(
            'UPDATE chat_meldinger SET slettet_at = NULL'
            . ($vetHvemSomSlettet ? ', slettet_av = NULL' : '')
            . ' WHERE id = :i',
            ['i' => $id]
        );
    }

    // Rydder admin i andres meldinger, skal det staa i loggen hvem som
    // gjorde hva. Sin egen melding er sin egen sak.
    if (!$egen && function_exists('revider')) {
        revider($handling === 'slett' ? 'chat_slettet' : 'chat_hentet_tilbake', 'chat', $id, [
            'skrevet_av' => (int) $rad['member_id'],
        ]);
    }

    Svar::ok(['id' => $id, 'slettet' => $handling === 'slett']);
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
