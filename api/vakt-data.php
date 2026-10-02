<?php
/**
 * Datasjekkene for vakta paa eierens PC.
 *
 *   GET ?nokkel=<cron_nokkel>
 *   GET med headeren X-Vakt-Nokkel: <cron_nokkel>
 *
 * Eieren, 29. september 2026: Johanna hadde betalt og blitt trukket for Mini
 * 15, men sto fortsatt med Prøv Lissom i admin — og et kurs betalt i
 * verkstedet (kr 2 800) sto ikke i dagens omsetning. «hvorfor fanges det
 * ikke opp?». Vakta gaar naa gjennom medlemmene og dagens og gaarsdagens
 * salg hver morgen:
 *
 *   medlemmer  planen som vises (members.medlemskap_type) skal vaere planen
 *              paa den aktive avtalen, og ingen skal ha mer enn én aktiv
 *   salg       hver betalte paamelding og ordre i dag og i gaar skal vaere
 *              med i omsetningen (Omsetning::rader(), som Oversikt, Okonomi
 *              og dagsoppgjoret leser) — ellers mangler den i kassen
 *
 * Eieren, 2. oktober 2026: ja til daglig datasjekk. Reglene L4–L13 i
 * Vaktdata::regler() kommer i «funn» ({regel, id, navn, tekst}); «avvik»
 * er uendret.
 *
 * Svaret er bare avvikene, med navn og id — ingen kontaktinfo. Vakta leser;
 * den retter ingenting. Samme noekkel som api/vakt-feil.php og status.php.
 *
 * Bryter: «vakt_data_paa» (Config). Av => 409, og ingenting sjekkes.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$nokkel  = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
if ($oppgitt === '') {
    $oppgitt = trim((string) ($_SERVER['HTTP_X_VAKT_NOKKEL'] ?? ''));
}
if (!($nokkel !== '' && $oppgitt !== '' && hash_equals($nokkel, $oppgitt)) && !Sesjon::erAdmin()) {
    Svar::feil('Fant ikke siden.', 404);
}

$paa = Config::hent('vakt_data_paa', true);
if ($paa === false || in_array(strtolower(trim((string) $paa)), ['0', 'av', 'false', 'nei'], true)) {
    Svar::feil('Datasjekken er slått av.', 409);
}

$svar = Vaktdata::sjekk();

Svar::ok($svar);
