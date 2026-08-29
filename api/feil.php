<?php
/**
 * Meld inn feil — og feilene som melder seg selv.
 *
 *   GET   ->  { apen, til }         Skal knappen vises, og hvor lenge til?
 *   POST  ->  { ok }               En rapport.
 *
 * ── Hvorfor dette er aapent ──────────────────────────────────────────
 *
 * Feilene vi trenger aa vite om, skjer for den som *ikke* er logget inn:
 * en besokende paa en iPhone som ikke fikk valgt betalingsmaate. Krevde
 * dette innlogging, ville vi bare hort om feil hos dem som allerede er
 * inne — og de er ikke der feilene gjor mest skade.
 *
 * Aapent betyr rate-grenser, ikke tillit: hardt tak per IP, harde
 * lengdegrenser paa hvert felt, og ingen felt som gaar videre til noen
 * andre systemer. Ingenting herfra vises noen andre enn admin.
 *
 * ── Hva som lagres, og hva som ikke gjor det ─────────────────────────
 *
 * Lagres: feilmeldingen, hvilken side, nettleser og skjermbredde. Det er
 * det som skal til for aa gjenskape feilen — «Safari 17 paa iPhone, 390
 * piksler bred, paa /kurs» er en oppskrift, «det virket ikke» er det ikke.
 *
 * Lagres ikke: IP-adressen. Den brukes til aa telle mot grensen og blir
 * ikke skrevet ned. Er du logget inn, lagres member_id — for da vil eieren
 * kunne svare deg. Er du ikke det, kan du selv velge aa skrive en kontakt.
 * Ingenting hentes ut av deg uten at du har skrevet det.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

/** Datoen knappen staar paa til. Tom streng = knappen er av. */
$apenTil = static function (): string {
    if (!DB::harTabell('innstillinger')) {
        return '';
    }
    // Én navngitt noekkel, ikke tabellen. Her ligger ogsaa passordet til
    // e-postkontoen, og dette er et aapent endepunkt.
    $v = trim((string) DB::verdi(
        'SELECT verdi FROM innstillinger WHERE nokkel = :n',
        ['n' => 'feilmelding_til']
    ));
    if ($v === '' || preg_match('~^\d{4}-\d{2}-\d{2}$~', $v) !== 1) {
        return '';
    }
    return $v >= date('Y-m-d') ? $v : '';
};

if (Foresporsel::metode() === 'GET') {
    $til = $apenTil();
    Svar::ok(['apen' => $til !== '', 'til' => $til]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (!DB::harTabell('feilrapporter')) {
    // Migrasjonen er ikke kjort enda. Da er det bedre aa tie enn aa gi den
    // besokende en feilmelding om feilmeldingssystemet.
    Svar::ok(['ok' => true]);
}

$kropp = Foresporsel::kropp();
$slag  = ((string) ($kropp['slag'] ?? 'automatisk')) === 'melding' ? 'melding' : 'automatisk';

// Menneskene faar romslig plass og et lavt tak; maskinen faar motsatt.
// En sloyfe som kaster den samme feilen i det uendelige, skal ikke kunne
// fylle tabellen — den forste rapporten er den som teller.
if ($slag === 'melding') {
    Rate::sjekk('feilmelding', maks: 5, vindu: 3600);
} else {
    Rate::sjekk('feilfangst', maks: 30, vindu: 3600);
}

$kort = static fn(mixed $v, int $n): ?string => (
    ($t = mb_substr(trim((string) $v), 0, $n)) === '' ? null : $t
);

$melding = $slag === 'melding' ? $kort($kropp['melding'] ?? '', 2000) : null;
if ($slag === 'melding') {
    if ($melding === null) {
        Svar::feil('Skriv gjerne litt om hva som gikk galt.');
    }
    if ($apenTil() === '') {
        Svar::feil('Takk, men innmelding av feil er stengt akkurat nå.');
    }
}

$feiltekst = $kort($kropp['feiltekst'] ?? '', 500);
if ($slag === 'automatisk' && $feiltekst === null) {
    Svar::ok(['ok' => true]);
}

$medlem = Sesjon::medlem();

$rad = [
    'slag'      => $slag,
    'melding'   => $melding,
    'kontakt'   => $kort($kropp['kontakt'] ?? '', 191),
    'feiltekst' => $feiltekst,
    'kilde'     => $kort($kropp['kilde'] ?? '', 300),
    'side'      => $kort($kropp['side'] ?? '', 300),
    // Nettleseren sier den selv. Vi tar den fra hodet og ikke fra kroppen:
    // det er den samme opplysningen, men den kan ikke dikteres av avsenderen.
    'nettleser' => $kort(Foresporsel::userAgent(), 300),
    'skjerm'    => $kort($kropp['skjerm'] ?? '', 32),
    'member_id' => $medlem ? (int) $medlem['id'] : null,
    'rolle'     => $medlem ? $kort($medlem['rolle'] ?? 'medlem', 32) : null,
];

// Samme feil, samme sted, samme nettleserfamilie = én rad som teller opp.
$familie = preg_match('~(Firefox|Edg|Chrome|Safari)/[\d.]+~', (string) $rad['nettleser'], $m) === 1
    ? $m[1] : 'annen';
$rad['fingeravtrykk'] = $slag === 'melding'
    ? sha1('melding:' . bin2hex(random_bytes(16)))
    : sha1(implode('|', [$rad['feiltekst'], $rad['kilde'], $rad['side'], $familie]));

DB::kjor(
    'INSERT INTO feilrapporter
            (slag, melding, kontakt, feiltekst, kilde, side, nettleser, skjerm,
             member_id, rolle, fingeravtrykk)
     VALUES (:slag, :melding, :kontakt, :feiltekst, :kilde, :side, :nettleser, :skjerm,
             :member_id, :rolle, :fingeravtrykk)
     ON DUPLICATE KEY UPDATE
            antall    = antall + 1,
            sist_sett = CURRENT_TIMESTAMP,
            status    = IF(status = :lukket, :ny, status)',
    $rad + ['lukket' => 'lukket', 'ny' => 'ny']
);

// Holder tabellen liten uten at noen maa rydde. Nyeste 500 blir staaende;
// resten er uansett historie ingen kommer til aa lese.
if (random_int(1, 25) === 1) {
    DB::kjor(
        'DELETE FROM feilrapporter
          WHERE status = :s
            AND sist_sett < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)',
        ['s' => 'lukket']
    );
}

Svar::ok(['ok' => true]);
