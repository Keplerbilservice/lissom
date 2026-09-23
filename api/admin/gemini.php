<?php
/**
 * Bildegenerering med Gemini, rett i billedvelgeren.
 *
 *   GET                        status: er noekkelen satt, hvilken modell
 *   POST handling=lag          { ledetekst }  lag bildet, legg i biblioteket
 *   POST handling=oppsett      { nokkel?, modell?, pris? }  lagre oppsettet
 *
 * Noekkelen gaar i «innstillinger» og ikke i «content_blocks»: den siste
 * leses av nettsida og er dermed offentlig. En API-noekkel hoerer ikke
 * hjemme der. Den leveres aldri tilbake heller — skjermen faar vite OM den
 * er satt og de fire siste tegnene, ikke hva den er.
 *
 * Selve kallet gjor serveren, ikke nettleseren: noekkelen skal ikke ligge
 * i JavaScript der hvem som helst kan lese den.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

// http_kall() kaster naar tilkoblingen ikke gaar gjennom, og Gemini kaster
// med tekst skrevet for aa vises. Uten dette blir «webhotellet naar ikke ut
// paa nettet» til en 500 med filsti og linjenummer i svaret.
set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof RuntimeException) {
        logg('Gemini-kall stoppet', ['feil' => $e->getMessage()]);
        Svar::feil($e->getMessage(), 400);
    }
    logg_feil('Gemini feilet', $e);
    Svar::feil('Noe gikk galt. Prøv igjen, eller si fra.', 500);
});

if (Foresporsel::metode() === 'GET') {
    $n = Gemini::noekkel();
    Svar::json([
        'ok'      => true,
        'status'  => Gemini::status(),
        // Nok til aa kjenne igjen hvilken noekkel som staar inne, ikke nok
        // til aa bruke den.
        'hale'    => $n === '' ? '' : mb_substr($n, -4),
        'tak'     => Booking::kroner(AI::tak() * 100),
        // Referansebildene, saa skjermen kan vise dem og la eieren rydde.
        'referanser' => Gemini::referanser(),
        // Hvem som skriver teksten, og hvilken modell hvis det er Gemini.
        'leverandor'  => AI::leverandor(),
        'tekstModell' => Gemini::tekstModell(),
        'brukt'   => Booking::kroner(AI::bruktDenneMaaneden()),
    ]);
}

Foresporsel::krevMetode('POST');

// Opplastingen kommer som multipart og ikke som JSON, saa den maa tas foer
// Foresporsel::kropp() proever aa lese en tom stroem som JSON.
if (($_POST['handling'] ?? '') === 'referanse') {
    if (!isset($_FILES['bilde']) || ($_FILES['bilde']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        Svar::feil('Du må velge et bilde.');
    }
    try {
        $navn = Bilder::taImot($_FILES['bilde'], Gemini::referanseMappe());
    } catch (RuntimeException $e) {
        Svar::feil($e->getMessage());
    }
    revider('gemini_referanse_lagt_til', 'bilde', null, ['navn' => $navn]);
    Svar::ok([
        'navn'       => $navn,
        'referanser' => Gemini::referanser(),
        'beskjed'    => 'Bildet er lagt til som referanse.',
    ]);
}

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

        if (array_key_exists('nokkel', $kropp)) {
            $n = trim((string) $kropp['nokkel']);

            // «GEMINI_API_KEY=AIza…» → «AIza…».
            //
            // Noekkelen ligger gjerne i en .env-fil, og da limes hele linja
            // inn. Eieren, 23. september 2026: «den sier at noekkel ikke ble
            // lagret at den ikke saa riktig ut». Fila hans var nettopp det:
            // 14 tegn navn, likhetstegn, og verdien bak. Prefikset er
            // utvetydig, saa vi tar det bort framfor aa avvise.
            if (preg_match('/^[A-Z][A-Z0-9_]{2,40}\s*=\s*(.+)$/s', $n, $m) === 1) {
                $n = trim($m[1], " \t\n\r\"'");
            }

            // Tom kobler fra. Ellers: bare en grov formsjekk.
            //
            // Her sto det at noekkelen maatte vaere 39 tegn, begynne paa
            // «AIza» og vaere uten punktum. Det var feil. Eierens noekkel fra
            // AI Studio 23. september 2026 begynner paa «AQ.» og har punktum
            // i seg — og ble avvist. Han hadde den riktige noekkelen hele
            // tiden; det var denne regelen som sa nei.
            //
            // Laerdommen: Google bytter format, og en regel som beskriver
            // dagens format blir en sperre i morgen. Vi sjekker derfor bare
            // det som ikke kan endre seg — at det er én sammenhengende
            // streng av fornuftig lengde — og lar Google selv avvise en
            // noekkel som ikke virker. Feilmeldingen derfra er tydelig nok.
            if ($n !== '' && preg_match('/^\S{20,200}$/', $n) !== 1) {
                Svar::feil('Nøkkelen ser ikke riktig ut. Den er én sammenhengende '
                         . 'streng uten mellomrom, og står på aistudio.google.com '
                         . 'under «Get API key». Lim inn bare selve nøkkelen.');
            }
            $lagre('gemini_api_key', $n);
        }

        if (array_key_exists('modell', $kropp)) {
            $m = trim((string) $kropp['modell']);
            if ($m !== '' && preg_match('/^[a-z0-9.\-]{3,64}$/i', $m) !== 1) {
                Svar::feil('Modellnavnet ser ikke riktig ut. Det ser ut som '
                         . Gemini::MODELL_STANDARD . '.');
            }
            $lagre('gemini_modell', $m);
        }

        // Hvem som skriver teksten. Gjelder alt — kursbeskrivelser, SEO,
        // artikler, nyhetsbrev og innlegg.
        if (array_key_exists('leverandor', $kropp)) {
            $l = strtolower(trim((string) $kropp['leverandor']));
            if (!in_array($l, ['claude', 'gemini'], true)) {
                Svar::feil('Velg Claude eller Gemini.');
            }
            $lagre('ai_leverandor', $l);
        }

        if (array_key_exists('tekstModell', $kropp)) {
            $m = trim((string) $kropp['tekstModell']);
            if ($m !== '' && preg_match('/^[a-z0-9.-]{3,64}$/i', $m) !== 1) {
                Svar::feil('Modellnavnet ser ikke riktig ut. Det ser ut som '
                         . Gemini::MODELL_TEKST_STANDARD . '.');
            }
            $lagre('gemini_tekst_modell', $m);
        }

        if (array_key_exists('pris', $kropp)) {
            $p = (int) preg_replace('/\D+/', '', (string) $kropp['pris']);
            if ($p < 0 || $p > 10000) {
                Svar::feil('Prisanslaget må være mellom 0 og 100 kroner per bilde.');
            }
            $lagre('gemini_pris_ore', (string) $p);
        }

        Config::glemBasen();
        // Noekkelen selv staar aldri i loggen — bare at oppsettet ble rort.
        revider('gemini_oppsett', null, null, [
            'felt' => array_values(array_intersect(
                ['nokkel', 'modell', 'pris', 'leverandor', 'tekstModell'],
                array_keys($kropp)
            )),
        ]);
        Svar::ok(['status' => Gemini::status(), 'beskjed' => 'Oppsettet er lagret.']);

    // -------------------------------------------------------------- bildet
    case 'lag':
        $ledetekst = trim(mb_substr((string) ($kropp['ledetekst'] ?? ''), 0, 1200));
        if ($ledetekst === '') {
            Svar::feil('Skriv hva bildet skal vise.');
        }

        // Hva kallet gjaldt, saa kostnadsoversikten kan si hvor pengene gikk.
        $formal = trim(mb_substr((string) ($kropp['formal'] ?? 'Bilde'), 0, 40)) ?: 'Bilde';

        $b = Gemini::lagBilde($ledetekst, $formal);

        // Booking::kroner() runder til hele kroner, og et bilde koster under
        // én — svaret sa «kr. 0,-» mens forbruket steg med én krone. Under
        // hundre ore staar det derfor i ore, som i status().
        $ore = $b['kostnadOre'];
        Svar::ok([
            'url'     => $b['url'],
            'navn'    => $b['navn'],
            'kostnad' => $ore < 100 ? $ore . ' øre' : Booking::kroner($ore),
            'brukt'   => Booking::kroner(AI::bruktDenneMaaneden()),
            'beskjed' => 'Bildet er lagt i biblioteket.',
        ]);

    // ------------------------------------------------- referanse bort
    case 'referanseSlett':
        $navn = trim((string) ($kropp['navn'] ?? ''));
        // Bare et filnavn. Uten dette kunne «../../» pekt ut av mappa.
        if (preg_match('/^[a-f0-9]{32}.jpg$/i', $navn) !== 1) {
            Svar::feil('Ukjent bilde.');
        }
        Bilder::slett($navn, Gemini::referanseMappe());
        revider('gemini_referanse_fjernet', 'bilde', null, ['navn' => $navn]);
        Svar::ok(['referanser' => Gemini::referanser(), 'beskjed' => 'Bildet er fjernet.']);

    default:
        Svar::feil('Ukjent handling.');
}
