<?php
/**
 * Kursstart — de fem kortene du går gjennom med deltakerne før dere begynner.
 *
 *   GET                      kortene, i rekkefølge, med tittel, tekst og av/på
 *   POST handling=lagre      { kort: [{ nr, tittel, tekst, paa }, …] }
 *
 * Eieren, 29. september 2026: «Jeg skulle hatt en slags onboarding til admin,
 * ved oppstart av kurs, i enkel karusellform som er mobilvennlig».
 *
 * ── Hvorfor teksten ligger i basen og ikke i koden ───────────────────
 *
 * Fordi den skal endres av den som holder kurset, ikke av den som skriver
 * koden. «Toalettet er gjennom døra til venstre» er sant helt til noen bygger
 * om. Standardteksten kommer fra migrasjon 234; her leses bare det som faktisk
 * står, med standarden som reserve hvis migrasjonen ikke er kjørt ennå.
 *
 * ── Kort 2 har ingen tekst å lagre ───────────────────────────────────
 *
 * Betalingskortet viser hvem som har gjort opp på akkurat den økta. Den lista
 * hentes av skjermen fra påmeldingene den alt har — den skal ikke skrives inn
 * her, og den skal ikke lagres. Teksten på kortet er bare innledninga over
 * lista.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

/** Antall kort. Rekkefølgen er nummeret; det finnes ingen sortering å rote med. */
const KURSSTART_ANTALL = 5;

/**
 * Teksten slik den sto da kortene ble laget.
 *
 * Reserve, ikke fasit: har eieren endret et kort, er det det som gjelder.
 * Dette er det som staar foer noen har roert noe, og det som staar hvis
 * migrasjon 234 ikke er kjoert.
 */
const KURSSTART_STANDARD = [
    1 => [
        'tittel' => 'Velkommen — det praktiske først',
        'tekst'  => "Toalettet er gjennom døra til venstre.\n"
            . "Rømningsveien er den samme veien du kom inn, og bakdøra ved ovnsrommet.\n"
            . 'Kaffe, te og litt å bite i står framme — forsyn dere når som helst.',
    ],
    2 => [
        'tittel' => 'Betaling',
        'tekst'  => 'Er det noen som ikke har gjort opp, tar vi det nå — så slipper vi å tenke på det resten av kvelden.',
    ],
    3 => [
        'tittel' => 'Dette skal vi gjøre i dag',
        'tekst'  => "Vi begynner med en kort gjennomgang, så setter dere i gang selv.\n"
            . "Det blir leire på hendene og sannsynligvis på klærne — forkle får dere låne av oss.\n"
            . 'Dere trenger ikke å få det til med én gang. Det er helt greit å begynne på nytt.',
    ],
    4 => [
        'tittel' => 'Når får dere med dere arbeidene hjem?',
        'tekst'  => "Arbeidene skal tørke, brennes, glaseres og brennes en gang til. Det tar noen uker.\n"
            . 'Dere får beskjed på Min side og på e-post når de er ferdige, og da kan dere hente dem hos oss.',
    ],
    5 => [
        'tittel' => 'Etter kurset',
        'tekst'  => 'Dere får en e-post med litt info i etterkant, og kursbeviset deres ligger på Min side.',
    ],
];

/** Kortet som viser betalingslista. Skjermen trenger å vite hvilket det er. */
const KURSSTART_BETALING = 2;

if (!DB::harTabell('innstillinger')) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.', 503);
}

/** @return array<string,string> */
$lagrede = static function (): array {
    $ut = [];
    foreach (DB::alle("SELECT nokkel, verdi FROM innstillinger WHERE nokkel LIKE 'kursstart\\_%'") as $r) {
        $ut[(string) $r['nokkel']] = (string) ($r['verdi'] ?? '');
    }
    return $ut;
};

if (Foresporsel::metode() === 'POST') {
    if (Foresporsel::tekst('handling') !== 'lagre') {
        Svar::feil('Ukjent handling.');
    }

    $kropp = Foresporsel::kropp();
    $kort  = is_array($kropp['kort'] ?? null) ? $kropp['kort'] : [];
    if ($kort === []) {
        Svar::feil('Ingenting å lagre.');
    }

    // Bare de fem kortene som finnes. Et nummer utenfor lista er enten en
    // skrivefeil eller noen som prøver seg — begge deler skal stoppe her, og
    // ikke lage en innstilling ingen leser.
    $skrevet = 0;
    foreach ($kort as $k) {
        if (!is_array($k)) {
            continue;
        }
        $nr = (int) ($k['nr'] ?? 0);
        if ($nr < 1 || $nr > KURSSTART_ANTALL) {
            Svar::feil('Ukjent kort: ' . $nr);
        }
        // Tittelen er én linje i en overskrift; teksten er det du leser opp.
        // Grensene er romslige, men de er der — feltet skal ikke kunne bli en
        // roman som sprenger karusellen på en telefon.
        $verdier = [
            'kursstart_' . $nr . '_tittel' => mb_substr(trim((string) ($k['tittel'] ?? '')), 0, 120),
            'kursstart_' . $nr . '_tekst'  => mb_substr(trim((string) ($k['tekst'] ?? '')), 0, 1200),
            'kursstart_' . $nr . '_paa'    => !empty($k['paa']) ? '1' : '0',
        ];
        foreach ($verdier as $nokkel => $verdi) {
            DB::kjor(
                'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
                [$nokkel, $verdi, (int) (Sesjon::medlem()['id'] ?? 0) ?: null]
            );
        }
        $skrevet++;
    }

    Config::glemBasen();
    revider('kursstart_lagret', null, null, ['kort' => $skrevet]);

    Svar::json(['ok' => true, 'beskjed' => 'Kursstarten er lagret.', 'kort' => $skrevet]);
}

$s = $lagrede();
$ut = [];
for ($nr = 1; $nr <= KURSSTART_ANTALL; $nr++) {
    $std = KURSSTART_STANDARD[$nr];
    // Tomt felt betyr tomt felt. Bare et felt som aldri har vaert lagret faar
    // standarden — ellers kunne ikke eieren fjerne et avsnitt hen ikke vil ha.
    $ut[] = [
        'nr'       => $nr,
        'tittel'   => array_key_exists('kursstart_' . $nr . '_tittel', $s)
            ? $s['kursstart_' . $nr . '_tittel'] : $std['tittel'],
        'tekst'    => array_key_exists('kursstart_' . $nr . '_tekst', $s)
            ? $s['kursstart_' . $nr . '_tekst'] : $std['tekst'],
        'paa'      => !array_key_exists('kursstart_' . $nr . '_paa', $s)
            || $s['kursstart_' . $nr . '_paa'] === '1',
        // Sier hva kortet er, slik at skjermen slipper å kjenne numrene.
        'betaling' => $nr === KURSSTART_BETALING,
    ];
}

Svar::json(['kort' => $ut]);
