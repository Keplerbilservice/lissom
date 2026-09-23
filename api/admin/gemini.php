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
 * Selve kallet gjor serveren, ikke nettleseren. Samme regel som
 * shutterstock.php: noekkelen skal ikke ligge i JavaScript der hvem som
 * helst kan lese den.
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
        'brukt'   => Booking::kroner(AI::bruktDenneMaaneden()),
    ]);
}

Foresporsel::krevMetode('POST');
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
            // Tom kobler fra. Ellers: Google sine noekler er lange og uten
            // mellomrom. Vi sier fra om noe aapenbart feil framfor aa lagre
            // en noekkel som stille lar vaere aa virke — for eksempel hele
            // linja fra en fil, med «API key: » foran.
            if ($n !== '' && preg_match('/^[A-Za-z0-9_\-]{20,120}$/', $n) !== 1) {
                Svar::feil('Nøkkelen ser ikke riktig ut. Den er en lang streng uten '
                         . 'mellomrom, og står i Google AI Studio under «Get API key». '
                         . 'Lim inn bare selve nøkkelen.');
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
            'felt' => array_values(array_intersect(['nokkel', 'modell', 'pris'], array_keys($kropp))),
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

        Svar::ok([
            'url'     => $b['url'],
            'navn'    => $b['navn'],
            'kostnad' => Booking::kroner($b['kostnadOre']),
            'brukt'   => Booking::kroner(AI::bruktDenneMaaneden()),
            'beskjed' => 'Bildet er lagt i biblioteket.',
        ]);

    default:
        Svar::feil('Ukjent handling.');
}
