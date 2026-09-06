<?php
/**
 * Lenka verkstedet sender — vaar egen, ikke Vipps sin.
 *
 *   https://lissom.no/godkjenn/<noekkel>
 *
 * Eieren, 6. september: «men det fungerer ikke, linken virker ikke, saa noe
 * er feil, du maa gjore dette paa en annen maate».
 *
 * Vipps sin egen dokumentasjon: «By default, a user has a total of 10 minutes
 * to accept a payment. If the user doesn't complete the payment within this
 * time window, the payment request will expire. The EXPIRED state is a final
 * state.» En avtale staar PENDING til hun godkjenner — ellers EXPIRED.
 *
 * Ti minutter er ingenting naar lenka skal kopieres ut av admin, sendes paa
 * Messenger, og aapnes av en som er paa jobb. Derfor sender vi ikke Vipps sin
 * adresse lenger. Vi sender denne, og lager Vipps-avtalen foerst i det hun
 * trykker. Da er adressen hun sendes videre til alltid sekunder gammel,
 * uansett hvor lenge meldingen har ligget.
 *
 * Noekkelen gir ingen tilgang til noe: alt den kan, er aa starte en avtale
 * paa det medlemskapet verkstedet alt har satt opp for henne. Den lever i
 * fjorten dager, og settes som brukt naar avtalen er godkjent.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

/**
 * Sida hun lander paa naar noe ikke stemmer.
 *
 * Den staar for seg selv, uten nettsida rundt: dette er en adresse hun aapner
 * fra en melding, og da skal svaret komme med det samme — ikke etter at et
 * skript har lastet.
 */
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

// Noekkelen kommer fra adressen. .htaccess skriver /godkjenn/<noekkel> om til
// denne fila; «t» virker ogsaa direkte, saa lenka kan testes uten omskriving.
$token = preg_replace('/[^a-f0-9]/', '', strtolower(Foresporsel::tekst('t')));

// En fremmed kan skrive hva som helst. Hvert forsoek koster oss et oppslag —
// og et treff koster et kall til Vipps.
Rate::sjekk('godkjenn-lenke', maks: 30, vindu: 600);

if ($token === '' || strlen($token) !== 32 || !DB::harTabell('avtale_lenker')) {
    $side(
        'Vi fant ikke denne lenka',
        'Sjekk at hele adressen kom med i meldingen. Er du usikker, si fra til oss på '
        . $kontakt . ', så sender vi en ny.'
    );
}

$rad = DB::en(
    'SELECT l.*, m.id AS medlem_id, m.navn, m.epost, m.telefon, m.medlemskap_type
       FROM avtale_lenker l
       JOIN members m ON m.id = l.member_id
      WHERE l.token = :t
      LIMIT 1',
    ['t' => $token]
);

if ($rad === null) {
    $side(
        'Vi fant ikke denne lenka',
        'Sjekk at hele adressen kom med i meldingen. Er du usikker, si fra til oss på '
        . $kontakt . ', så sender vi en ny.'
    );
}

// ── Er medlemskapet alt i orden? ────────────────────────────────────────
//
// Dette sjekkes FOER utloepet. Trykker hun paa den gamle lenka i etterkant,
// skal hun faa vite at alt er som det skal — ikke at lenka er ugyldig.
$fra = Medlemskap::avtale((int) $rad['medlem_id']);
if ($fra !== null && (string) $fra['status'] === 'aktiv') {
    Medlemskap::godkjennLenkeBrukt((int) $rad['medlem_id']);
    $side(
        'Alt er i orden',
        'Medlemskapet ditt er godkjent i Vipps og løper som det skal. Du finner det på Min side.',
        'Gå til Min side',
        Config::nettsted() . '/min-side'
    );
}

if ($rad['brukt_at'] !== null) {
    $side(
        'Denne lenka er brukt',
        'Avtalen er alt satt opp. Er noe likevel galt, si fra til oss på ' . $kontakt . '.',
        'Gå til Min side',
        Config::nettsted() . '/min-side'
    );
}

if (strtotime((string) $rad['utloper']) < time()) {
    $side(
        'Lenka har gått ut',
        'Denne lenka var gyldig i fjorten dager. Si fra til oss på ' . $kontakt
        . ', så sender vi en ny med én gang.'
    );
}

// Planen kan ha blitt tatt bort eller byttet siden lenka ble laget. Da er det
// den som staar paa medlemmet naa som gjelder — ellers ville hun godkjent et
// medlemskap verkstedet ikke lenger tilbyr.
$planNavn = trim((string) ($rad['medlemskap_type'] ?? '')) !== ''
    ? (string) $rad['medlemskap_type']
    : (string) $rad['plan'];
$plan = Medlemskap::plan($planNavn);
if ($plan === null) {
    $side(
        'Medlemskapet må settes opp først',
        'Vi finner ikke medlemskapet som hører til denne lenka. Si fra til oss på '
        . $kontakt . ', så ordner vi det.'
    );
}

// ── Her lages avtalen, i dette sekundet ─────────────────────────────────
//
// Det er hele poenget: adressen Vipps gir oss lever i ti minutter, og hun er
// paa vei inn i den naa.
try {
    $medlem = DB::en('SELECT * FROM members WHERE id = :i', ['i' => (int) $rad['medlem_id']]);
    $ut = Medlemskap::kreverFastTrekk($plan)
        ? Medlemskap::startAvtale($medlem, $planNavn)
        : Medlemskap::startEngangs($medlem, $planNavn);
} catch (Throwable $e) {
    logg_feil('Fikk ikke startet avtalen fra godkjenningslenka', $e);
    $side(
        'Vipps svarte ikke akkurat nå',
        'Prøv igjen om et par minutter — lenka virker fortsatt. Ingenting er trukket.'
    );
}

$adresse = trim((string) ($ut['url'] ?? ''));
if ($adresse === '') {
    $side(
        'Vipps svarte ikke akkurat nå',
        'Prøv igjen om et par minutter — lenka virker fortsatt. Ingenting er trukket.'
    );
}

revider('avtale_lenke_apnet', 'member', (int) $rad['medlem_id'], ['plan' => $planNavn]);

header('Location: ' . $adresse, true, 302);
exit;
