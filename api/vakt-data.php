<?php
/**
 * Datasjekkene for vakta paa eierens PC.
 *
 *   GET ?nokkel=<cron_nokkel>
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
 * Svaret er bare avvikene, med navn og id — ingen kontaktinfo. Vakta leser;
 * den retter ingenting. Samme noekkel som api/vakt-feil.php og status.php.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$nokkel  = (string) Config::hent('cron_nokkel', '');
$oppgitt = Foresporsel::tekst('nokkel');
if (!($nokkel !== '' && $oppgitt !== '' && hash_equals($nokkel, $oppgitt)) && !Sesjon::erAdmin()) {
    Svar::feil('Fant ikke siden.', 404);
}

$svar = Vaktdata::sjekk();

Svar::ok($svar);
