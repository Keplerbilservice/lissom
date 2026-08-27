<?php
/**
 * Varselkoen — se den, stopp den om noe har kjort seg, og skru paa
 * utsendingen.
 *
 *   GET  /api/admin/varsler.php            status og oppsett
 *   GET  /api/admin/varsler.php?stopp=ja   avbryter alt som fortsatt ligger i ko
 *   POST handling=lagre                    lagrer oppsettet for e-post og SMS
 *
 * Innloggingen til e-postkontoen har til naa maattet skrives inn i en fil paa
 * serveren. I praksis betydde det at ingen kvitteringer gikk ut, fordi veien
 * dit gikk om FTP. Naa kan den som eier kontoen fylle den inn selv, i
 * nettleseren, uten aa sende passordet gjennom noen andre.
 *
 * Passord returneres aldri. Skjermen faar vite om et felt er utfylt, ikke hva
 * som staar der. Et tomt felt ved lagring lar det staaende vaere — ellers
 * ville en lagring av avsenderadressen tommet passordet.
 *
 * Kommer det ut meldinger som ikke skal ut — en test som gjentar seg, en
 * adresse som ikke finnes — skal det gaa an aa stanse det uten aa vente paa
 * at koen gir opp av seg selv.
 *
 * Krever noekkelen eller en innlogget admin. «stopp» endrer data, saa kallet
 * maa komme fra en adresse du selv har aapnet: Sec-Fetch-Site «none» eller
 * «same-origin», aldri «cross-site».
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$nokkel = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
$medNokkel = $nokkel !== '' && $oppgitt !== '' && hash_equals($nokkel, $oppgitt);
$fraEgenHand = fra_egen_side();

if (!$medNokkel && !(Sesjon::erAdmin() && $fraEgenHand)) {
    Svar::feil('Fant ikke siden.', 404);
}

/**
 * Oppsettet eieren kan endre. Samme liste som Config slipper gjennom fra
 * basen — staar en noekkel i secrets.php, er det den som gjelder, og da sier
 * skjermen fra at feltet er laast av fila.
 */
const FELTER = [
    'smtp_vert', 'smtp_port', 'smtp_bruker', 'smtp_passord', 'smtp_sikkerhet',
    'epost_fra', 'epost_fra_navn', 'epost_svar_til',
    'sms_leverandor', 'sveve_bruker', 'sveve_passord', 'sms_avsender',
    // Signaturen, og hvilke malgrupper den staar paa. Startverdien kom med
    // migrasjon 062 og er signaturen fra e-post-signatur.html — den som alt
    // fantes. Ingen ny signatur der det finnes en.
    'epost_signatur',
    'epost_signatur_system', 'epost_signatur_ordre',
    'epost_signatur_kurs', 'epost_signatur_nyhetsbrev',
];

/** De fire gruppene en mal kan hoere til, og hva de heter for et menneske. */
const GRUPPER = [
    'system'     => 'Systemmeldinger',
    'ordre'      => 'Ordrebekreftelser',
    'kurs'       => 'Kursmeldinger',
    'nyhetsbrev' => 'Nyhetsbrev',
];

/** Disse forlater aldri serveren. */
const HEMMELIGE = ['smtp_passord', 'sveve_passord'];

if (Foresporsel::metode() === 'POST') {
    krev_admin();
    if (Foresporsel::tekst('handling') !== 'lagre') {
        Svar::feil('Ukjent handling.');
    }
    if (!DB::harTabell('innstillinger')) {
        Svar::feil('Migrasjon 036 er ikke kjørt. Kjør vedlikehold først, så kan oppsettet lagres.');
    }

    // Bare det som faktisk staar i forespoerselen skrives.
    //
    // Skjermen sender det brukeren har endret, ikke hele skjemaet. Skrev vi
    // alle feltene, ville en lagring av avsendernavnet toemt bade server,
    // brukernavn og port — som er nettopp det som skjedde foerste gang dette
    // ble proevd. Et felt som er med og tomt, skal derimot toemmes: det er
    // slik man fjerner en svaradresse man ikke vil ha lenger.
    $kropp = Foresporsel::kropp();
    $lagret = [];
    foreach (FELTER as $f) {
        if (!array_key_exists($f, $kropp) && !isset($_GET[$f])) {
            continue;
        }
        $v = trim((string) Foresporsel::tekst($f));
        // Et tomt passordfelt betyr «ikke roer det», ikke «slett det». Feltet
        // staar tomt hver gang skjermen tegnes, siden passordet aldri sendes
        // tilbake — saa dette er det vanlige tilfellet, ikke unntaket.
        if ($v === '' && in_array($f, HEMMELIGE, true)) {
            continue;
        }
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            [$f, $v, (int) (Sesjon::medlem()['id'] ?? 0) ?: null]
        );
        $lagret[] = $f;
    }
    Config::glemBasen();
    // Verdiene revideres ikke — det er passord. Bare at det ble endret.
    revider('varseloppsett_lagret', null, null, ['felter' => count($lagret)]);

    Svar::json([
        'ok'      => true,
        'beskjed' => 'Oppsettet er lagret. Send en testmelding for å se om det virker.',
        'epost_klar' => trim((string) Config::hent('smtp_vert', '')) !== '',
        'sms_klar'   => Varsel::smsMulig(),
    ]);
}

$svar = [];

if (Foresporsel::tekst('stopp') === 'ja') {
    if (!$medNokkel && !$fraEgenHand) {
        Svar::feil('Fant ikke siden.', 404);
    }
    $antall = (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'ko'");
    DB::kjor(
        "UPDATE notifications
            SET status = 'feilet',
                feilmelding = 'Avbrutt manuelt fra admin'
          WHERE status = 'ko'"
    );
    revider('varselko_stoppet', null, null, ['antall' => $antall]);
    $svar['stoppet'] = $antall;
    $svar['beskjed'] = $antall === 0
        ? 'Køen var allerede tom.'
        : $antall . ' melding' . ($antall === 1 ? '' : 'er') . ' ble avbrutt og sendes ikke.';
}

$svar['ko'] = [
    'venter' => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'ko'"),
    'sendt'  => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'sendt'"),
    'feilet' => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE status = 'feilet'"),
];

$svar['venter_naa'] = array_map(static fn(array $r): array => [
    'kanal'    => $r['kanal'],
    'mottaker' => $r['mottaker'],
    'emne'     => (string) ($r['emne'] ?? ''),
    'forsok'   => (int) $r['forsok'],
], DB::alle("SELECT kanal, mottaker, emne, forsok FROM notifications
              WHERE status = 'ko' ORDER BY id LIMIT 20"));

// Oppsettet slik det gjelder naa. Passord vises aldri — bare om de er satt,
// og om det er fila eller admin som bestemmer. Uten det siste ville en eier
// kunne skrive inn et passord her og lure paa hvorfor ingenting endret seg,
// mens secrets.php overstyrte i stillhet.
$fraBasen = [];
if (DB::harTabell('innstillinger')) {
    foreach (DB::alle('SELECT nokkel, verdi FROM innstillinger') as $r) {
        $fraBasen[(string) $r['nokkel']] = (string) ($r['verdi'] ?? '');
    }
}

$oppsett = [];
foreach (FELTER as $f) {
    $gjeldende = (string) Config::hent($f, '');
    $hemmelig  = in_array($f, HEMMELIGE, true);
    $oppsett[$f] = [
        'verdi'     => $hemmelig ? '' : $gjeldende,
        'satt'      => $gjeldende !== '',
        'fra_fil'   => $gjeldende !== '' && ($fraBasen[$f] ?? '') !== $gjeldende,
        'hemmelig'  => $hemmelig,
    ];
}

// Hvilke meldinger hver gruppe omfatter. Uten dette er «Kursmeldinger» et
// ord: eieren skal se hvilke e-poster hun faktisk skrur signaturen paa.
$svar['malgrupper'] = [];
$harGruppe = DB::harKolonne('notification_templates', 'gruppe');
foreach (GRUPPER as $kode => $navn) {
    $maler = $harGruppe
        ? DB::alle("SELECT navn, emne, kanal FROM notification_templates
                     WHERE gruppe = :g AND kanal <> 'sms' ORDER BY navn", ['g' => $kode])
        : [];
    $svar['malgrupper'][] = [
        'kode'    => $kode,
        'navn'    => $navn,
        'paa'     => (string) Config::hent('epost_signatur_' . $kode, '1') === '1',
        'maler'   => array_map(static fn($m) => (string) ($m['emne'] ?: $m['navn']), $maler),
    ];
}
$svar['signatur_klar'] = $harGruppe;
// Signaturen skrevet ut i ren tekst, regnet av den samme koden som sender
// meldingene. Eieren skal se hva den som leser ren tekst faar.
$svar['signatur_tekst'] = Varsel::signaturSomTekst((string) Config::hent('epost_signatur', ''));

$svar['oppsett'] = $oppsett;
$svar['kan_lagre'] = DB::harTabell('innstillinger');
$svar['epost_maate'] = trim((string) Config::hent('smtp_vert', '')) !== ''
    ? 'SMTP' : 'serverens mail()';
$svar['sms_klar'] = Varsel::smsMulig();

if (!isset($svar['stoppet'])) {
    $svar['hvordan'] = 'Legg til ?stopp=ja for å avbryte alt som ligger i køen.';
}

Svar::json($svar);
