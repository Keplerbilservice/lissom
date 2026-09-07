<?php
/**
 * Steg 2 av innmeldingen: /meld-inn/<noekkel>
 *
 * Leser ordren, lager Vipps-avtalen paa DEN planen som staar i raden, og
 * sender kunden videre. Ingenting hentes fra nettleseren.
 *
 * ── Hvorfor adressen er en side og ikke et skript i sida ────────────────
 *
 * Dette er en adresse man kommer tilbake til: fra Vipps, fra en e-post, fra
 * en ny fane paa telefonen. Da skal svaret komme med det samme — ikke etter
 * at et skript har lastet og gjettet hvor man var.
 *
 * Samme form som api/godkjenn.php, med vilje: to adresser som gjor omtrent
 * det samme skal se like ut for den som staar der.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

/** Sida hun lander paa naar noe ikke stemmer. */
$side = static function (string $tittel, string $tekst, string $knapp = '', string $adresse = ''): never {
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="nb"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . $e($tittel) . ' — Lissom Keramikk</title>'
       . '<style>'
       . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'padding:24px;background:#F6F1E7;color:#2E1002;'
       . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}'
       . '.k{background:#fff;border-radius:22px;padding:40px 32px;max-width:440px;width:100%;'
       . 'box-sizing:border-box;box-shadow:0 6px 24px rgba(46,16,2,.10);text-align:center;}'
       . 'h1{margin:0 0 12px;font-size:26px;line-height:1.2;text-wrap:balance;}'
       . 'p{margin:0 0 24px;font-size:16px;line-height:1.55;color:#4A3527;text-wrap:pretty;}'
       . 'a.p{display:inline-block;background:#F2C14E;color:#2E1002;text-decoration:none;'
       . 'font-weight:700;font-size:16px;border-radius:999px;padding:14px 28px;}'
       . '.l{margin:28px 0 0;font-size:13px;color:#8A7A6B;}'
       . '</style></head><body><div class="k">'
       . '<h1>' . $e($tittel) . '</h1>'
       . '<p>' . $e($tekst) . '</p>'
       . ($knapp !== '' ? '<a class="p" href="' . $e($adresse) . '">' . $e($knapp) . '</a>' : '')
       . '<p class="l">Lissom Keramikk &amp; Håndverk</p>'
       . '</div></body></html>';
    exit;
};

$kontakt = trim((string) Config::hent('epost_svar_til', (string) Config::hent('epost_fra', 'post@lissom.no')));

// .htaccess skriver /meld-inn/<noekkel> om hit; «t» virker ogsaa direkte, saa
// adressen kan proves uten omskriving.
$token = preg_replace('/[^a-f0-9]/', '', strtolower(Foresporsel::tekst('t')));

// En fremmed kan skrive hva som helst. Hvert treff koster et kall til Vipps.
Rate::sjekk('meld-inn', maks: 30, vindu: 600);

$ordre = strlen((string) $token) === 32 && DB::harTabell('medlemsordrer')
    ? Medlemsordre::hent((string) $token)
    : null;

if ($ordre === null) {
    $side(
        'Vi fant ikke denne innmeldingen',
        'Sjekk at hele adressen kom med. Er du usikker, start på nytt fra medlemskapssiden '
        . 'eller si fra til oss på ' . $kontakt . '.',
        'Se medlemskapene', '/medlemskap'
    );
}

if ((string) $ordre['status'] === 'fullfort') {
    $side(
        'Alt er i orden',
        'Medlemskapet ditt er i gang. Du finner det på Min side.',
        'Til Min side', '/min-side'
    );
}

if (strtotime((string) $ordre['utloper']) < time()) {
    $side(
        'Innmeldingen har gått ut',
        'En påbegynt innmelding står åpen i ett døgn. Start på nytt fra medlemskapssiden, '
        . 'så tar det bare et øyeblikk.',
        'Se medlemskapene', '/medlemskap'
    );
}

// Planen leses fra ORDREN. Dette er hele poenget med denne veien: den kan
// ikke bli noe annet enn det kunden trykket paa.
$planNavn = (string) $ordre['plan'];
$plan     = Medlemskap::plan($planNavn);
if ($plan === null) {
    $side(
        'Dette medlemskapet finnes ikke lenger',
        '«' . $planNavn . '» kan ikke velges nå. Se hva som finnes, eller si fra til oss på '
        . $kontakt . '.',
        'Se medlemskapene', '/medlemskap'
    );
}

// Hvem det gjelder.
//
// Er hun logget inn, er det henne. Ellers ser vi etter telefonnummeret og
// e-posten hun oppga. Kjenner vi henne ikke, og hun ikke har skrevet et navn,
// henter vi navnet fra Vipps — og hun kommer RETT HIT igjen etterpaa.
//
// Det er hele grunnen til at noekkelen staar i adressen: turen innom Vipps
// tar ikke med seg valget hennes ut. Foer laa planen i nettleserens minne, og
// da ble den borte akkurat her.
$medlem = Medlemsordre::finnMedlem($ordre, Sesjon::medlem());
if ($medlem === null) {
    if (trim((string) $ordre['navn']) === '') {
        header('Location: /api/vipps-login.php?retur='
             . rawurlencode('/meld-inn/' . $token), true, 302);
        exit;
    }
    try {
        $medlem = Medlemsordre::lagMedlem($ordre);
    } catch (RuntimeException $e) {
        $side('Vi fikk ikke satt i gang medlemskapet', $e->getMessage()
            . ' Si fra til oss på ' . $kontakt . ', så ordner vi det.');
    }
}

if (er_aktivt_medlem($medlem) && (string) ($medlem['rolle'] ?? '') !== 'admin') {
    Medlemsordre::merkFullfort((int) $ordre['id']);
    $side(
        'Du er alt medlem',
        'Medlemskapet ditt løper. Vil du bytte til noe annet, gjør du det fra Min side.',
        'Til Min side', '/min-side'
    );
}

try {
    // Fast trekk gir en avtale aa godkjenne. «Ordner selv» gir én betaling.
    // Hvilken av dem staar paa planen, ikke paa onsket — se
    // Medlemsordre::opprett().
    $ut = Medlemskap::kreverFastTrekk($plan)
        ? Medlemskap::startAvtale($medlem, $planNavn)
        : Medlemskap::startEngangs($medlem, $planNavn);
} catch (RuntimeException $e) {
    logg_feil('Innmelding stoppet for ordre ' . $ordre['id'], $e);
    $side('Vi fikk ikke satt i gang medlemskapet', $e->getMessage()
        . ' Si fra til oss på ' . $kontakt . ', så ordner vi det.');
} catch (Throwable $e) {
    logg_feil('Innmelding feilet for ordre ' . $ordre['id'], $e);
    $side(
        'Vipps svarte ikke akkurat nå',
        'Prøv igjen om et par minutter — adressen virker fortsatt. Ingenting er trukket.'
    );
}

$adresse = (string) ($ut['url'] ?? '');
if ($adresse === '') {
    logg_feil('Innmelding uten adresse fra Vipps, ordre ' . $ordre['id']);
    $side(
        'Vipps svarte ikke akkurat nå',
        'Prøv igjen om et par minutter — adressen virker fortsatt. Ingenting er trukket.'
    );
}

Medlemsordre::merkApnet((int) $ordre['id'], isset($ut['id']) ? (int) $ut['id'] : null);

revider('medlemsordre_apnet', 'member', (int) $medlem['id'],
    ['plan' => $planNavn, 'ordre' => (int) $ordre['id']]);

header('Location: ' . $adresse, true, 302);
exit;
