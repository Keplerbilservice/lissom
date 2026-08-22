<?php
/**
 * Varselkoen — se den, og stopp den om noe har kjort seg.
 *
 *   /api/admin/varsler.php            status
 *   /api/admin/varsler.php?stopp=ja   avbryter alt som fortsatt ligger i ko
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

Foresporsel::krevMetode('GET');

$nokkel = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
$medNokkel = $nokkel !== '' && $oppgitt !== '' && hash_equals($nokkel, $oppgitt);
$fraEgenHand = in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '', ['none', 'same-origin'], true);

if (!$medNokkel && !(Sesjon::erAdmin() && $fraEgenHand)) {
    Svar::feil('Fant ikke siden.', 404);
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

if (!isset($svar['stoppet'])) {
    $svar['hvordan'] = 'Legg til ?stopp=ja for å avbryte alt som ligger i køen.';
}

Svar::json($svar);
