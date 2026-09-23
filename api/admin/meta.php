<?php
/**
 * Publisering til Instagram og Facebook.
 *
 *   GET                       status: hva er koblet til
 *   POST handling=oppsett     { token?, igId?, sideId?, versjon? }
 *   POST handling=sjekk       spor Meta hvem tokenet gjelder
 *   POST handling=publiser    { utkastId, kanal, tekst? }  legg det ut
 *
 * ── Alltid et trykk ──────────────────────────────────────────────────
 *
 * «publiser» kalles bare fra en knapp. Det finnes ingen jobb, ingen
 * planlegging og ingen vei hit fra Autopilot — den lager utkast, og et
 * utkast som legger seg ut av seg selv er ikke en funksjon, det er en feil
 * som skjer i offentligheten.
 *
 * Tokenet gaar i «innstillinger» og ikke i «content_blocks»: den siste
 * leses av nettsida og er offentlig. Det leveres aldri tilbake til
 * skjermen — bare OM det staar inne.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof RuntimeException) {
        logg('Meta-kall stoppet', ['feil' => $e->getMessage()]);
        Svar::feil($e->getMessage(), 400);
    }
    logg_feil('Meta feilet', $e);
    Svar::feil('Noe gikk galt. Prøv igjen, eller si fra.', 500);
});

if (Foresporsel::metode() === 'GET') {
    Svar::json(['ok' => true, 'status' => Meta::status()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$kropp    = Foresporsel::kropp();
$handling = Foresporsel::tekst('handling');

switch ($handling) {
    // ----------------------------------------------------------- oppsettet
    case 'oppsett':
        $lagre = static function (string $nokkel, string $verdi) use ($admin): void {
            DB::kjor(
                'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
                [$nokkel, $verdi, (int) $admin['id'] ?: null]
            );
        };

        if (array_key_exists('token', $kropp)) {
            $t = trim((string) $kropp['token']);
            // Samme laerdom som med Gemini-noekkelen: vi beskriver ikke
            // formatet, for det endrer seg. Bare at det er én sammenhengende
            // streng. Meta faar selv avvise et token som ikke virker.
            if ($t !== '' && preg_match('/^\S{20,500}$/', $t) !== 1) {
                Svar::feil('Tokenet ser ikke riktig ut. Det er én lang streng uten '
                         . 'mellomrom, fra Meta Business Manager → Systembrukere.');
            }
            $lagre('meta_token', $t);
        }

        foreach (['igId' => 'meta_ig_id', 'sideId' => 'meta_side_id'] as $inn => $nokkel) {
            if (!array_key_exists($inn, $kropp)) {
                continue;
            }
            $v = trim((string) $kropp[$inn]);
            if ($v !== '' && preg_match('/^\d{5,30}$/', $v) !== 1) {
                Svar::feil('Id-en er et tall. Den står i Meta Business Suite under '
                         . 'kontoinnstillingene, eller i Graph API Explorer.');
            }
            $lagre($nokkel, $v);
        }

        if (array_key_exists('versjon', $kropp)) {
            $v = trim((string) $kropp['versjon']);
            if ($v !== '' && preg_match('/^v\d{1,3}\.\d{1,3}$/', $v) !== 1) {
                Svar::feil('Versjonen ser ut som ' . Meta::VERSJON_STANDARD . '.');
            }
            $lagre('meta_versjon', $v);
        }

        Config::glemBasen();
        // Tokenet selv staar aldri i loggen — bare at oppsettet ble rort.
        revider('meta_oppsett', null, null, [
            'felt' => array_values(array_intersect(
                ['token', 'igId', 'sideId', 'versjon'],
                array_keys($kropp)
            )),
        ]);
        Svar::ok(['status' => Meta::status(), 'beskjed' => 'Oppsettet er lagret.']);

    // -------------------------------------------------------------- sjekk
    case 'sjekk':
        $s = Meta::sjekk();
        if (!$s['ok']) {
            Svar::feil($s['feil']);
        }
        Svar::ok(['beskjed' => 'Tokenet virker. Kontoen er «' . $s['navn'] . '».']);

    // ----------------------------------------------------------- publiser
    case 'publiser':
        $utkastId = Foresporsel::heltall('utkastId');
        $kanal    = (string) ($kropp['kanal'] ?? 'Instagram');
        if (!in_array($kanal, ['Instagram', 'Facebook'], true)) {
            Svar::feil('Velg Instagram eller Facebook.');
        }

        $u = DB::en(
            "SELECT id, tittel, tekst, data, status FROM ai_utkast WHERE id = :i AND type = 'sosialt'",
            ['i' => $utkastId]
        );
        if ($u === null) {
            Svar::feil('Fant ikke utkastet.', 404);
        }
        if ((string) $u['status'] === 'publisert') {
            Svar::feil('Dette utkastet er alt lagt ut. Lag et nytt hvis det skal ut igjen.');
        }

        $data  = json_decode((string) ($u['data'] ?? '{}'), true) ?: [];
        $bilde = trim((string) ($data['bilde'] ?? ''));
        if ($bilde === '') {
            Svar::feil('Innlegget har verken bilde eller video. Velg noe først — '
                     . ($kanal === 'Instagram' ? 'Instagram tar ikke imot innlegg uten.'
                                               : 'et innlegg uten blir lite synlig.'));
        }
        // Video til Facebook ble bygget 23. september 2026 — se
        // Meta::publiserFacebook(), som velger /videos framfor /photos.
        // Sperra som sto her er derfor borte.

        // Meta henter fila selv, saa den maa staa paa en adresse de naar.
        // Det gjelder en video like mye som et bilde — se Meta::
        // publiserInstagram(), som kjenner forskjellen paa adressen.
        $url = rtrim(Config::nettsted(), '/') . '/' . ltrim($bilde, '/');

        // Teksten som faktisk gaar ut.
        //
        // Fram til 23. september 2026 kunne bare det AI-en hadde skrevet
        // legges ut: «publiser» tok en utkast-id og hentet teksten fra
        // basen, og ingen vei fantes til aa rette den foerst — verken her
        // eller i skjermen. Det gjorde hele utkastkoen halv: den som leste
        // gjennom og fant en feil kunne forkaste innlegget, men ikke rette
        // det.
        //
        // Feilen som viste det: et utkast om Paint on Pots skrev «kr. 500,-
        // per person». Prisen er «fra 500,-» — man betaler for den bitene
        // man velger. Det er et loefte verkstedet ikke holder, og det ville
        // gaatt rett ut.
        //
        // Kommer en tekst med, lagres den paa utkastet FOER den legges ut.
        // Da staar det i basen det samme som folk faktisk leser; ellers
        // ville loggen fortalt en annen historie enn Instagram.
        $tekst = trim((string) ($kropp['tekst'] ?? ''));
        if ($tekst !== '') {
            DB::oppdater('ai_utkast', ['tekst' => $tekst], ['id' => $utkastId]);
            revider('sosialt_rettet', 'ai_utkast', $utkastId, ['tegn' => mb_strlen($tekst)]);
        } else {
            $tekst = trim((string) ($u['tekst'] ?? ''));
        }
        if ($tekst === '') {
            Svar::feil('Innlegget har ingen tekst.');
        }

        $ut = $kanal === 'Instagram'
            ? Meta::publiserInstagram($url, $tekst)
            : Meta::publiserFacebook($url, $tekst);

        DB::oppdater('ai_utkast', ['status' => 'publisert'], ['id' => $utkastId]);
        revider('sosialt_publisert', 'ai_utkast', $utkastId, [
            'kanal' => $kanal, 'innlegg' => $ut['id'],
        ]);

        Svar::ok([
            'lenke'   => $ut['lenke'],
            'beskjed' => 'Innlegget er lagt ut på ' . $kanal . '.',
        ]);

    default:
        Svar::feil('Ukjent handling.');
}
