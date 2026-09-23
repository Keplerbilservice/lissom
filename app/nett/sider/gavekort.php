<?php
/**
 * Gavekort — /gavekort. Skjermen fra lissom-2108.html, tegnet av Mal.
 *
 * Sida var appen alene: 1,2 MB, 69 paa mobil og 5,7 s til innholdet stod
 * (Lighthouse, 23. september 2026). Naa tegnes den her som de andre
 * lesesidene. Skjemaet (beloep, mottaker, hilsen) staar som vanlige felt;
 * trykker kunden Vipps-knappen, legger nett.js det som er fylt ut i
 * sessionStorage og gaar til /gavekort?kjop=1 — da har appen adressen
 * (Nett::kan) og kjoeper som foer, med innlogging og Vipps
 * (fortsettGavekort() i lissom-2108.html).
 */

declare(strict_types=1);

// Punktene og spoersmaalene staar i renderVals i nettsida (gvPunkter,
// gvFaq), ikke i Innhold. Vi leser dem derfra, saa det bare finnes én
// utgave av teksten.
$kilde = (string) @file_get_contents(Nett::rot() . '/lissom-2108.html');
$streng = static fn(string $s): string => str_replace(["\\'", '\\"'], ["'", '"'], $s);
$punkter = [];
if (preg_match('~gvPunkter:\s*\[(.*?)\],~s', $kilde, $m) === 1) {
    preg_match_all("~'((?:[^'\\\\]|\\\\.)*)'~", $m[1], $mm);
    $punkter = array_map($streng, $mm[1]);
}
$faq = [];
if (preg_match('~gvFaq:\s*\[(.*?)\],\s*//~s', $kilde, $m) === 1) {
    preg_match_all("~sp:\s*'((?:[^'\\\\]|\\\\.)*)',\s*sv:\s*'((?:[^'\\\\]|\\\\.)*)'~", $m[1], $mm, PREG_SET_ORDER);
    foreach ($mm as $x) {
        $faq[] = ['sp' => $streng($x[1]), 'sv' => $streng($x[2])];
    }
}

$kropp = Mal::tegn('Gavekort', [
    'gvPunkter' => $punkter, 'gvFaq' => $faq,
    'gvBelopFelt' => '1490', 'gvValgt' => 'kr. 1490,-',
    'gvNavn' => '', 'gvEpost' => '', 'gvHilsen' => '',
    // Begge knappene tegnes, som i appen for Vipps-skriptet er lastet:
    // nett.js viser Vipps-knappen naar den er klar, ellers var egen.
    'vippsKlar' => true, 'vippsIkkeKlar' => true, 'sant' => true,
], ['gvKjop' => 'js:gavekort', 'goKurs' => '/kurs']);

// Merkene nett.js trenger: beloepsfeltet, «Du betaler …» og de to knappene.
$kropp = str_replace('aria-label="Beløp i kroner"', 'aria-label="Beløp i kroner" data-nett-felt="gvBelop"', $kropp);
$kropp = (string) preg_replace('~<p(\s[^>]*)?>(Du betaler )~', '<p$1 data-nett-vipps hidden>$2', $kropp, 1);
// Vipps-knappen er et eget element (bindestrek i navnet), som Mal lar staa
// som det er: onClick-bindingen byttes med handlingen her.
$kropp = (string) preg_replace('~<vipps-mobilepay-button([^>]*?)\s+onClick="[^"]*"~', '<vipps-mobilepay-button data-nett-vipps hidden data-nett-handling="gavekort"$1', $kropp);
$kropp = (string) preg_replace('~<button type="button"([^>]*data-nett-handling="gavekort")~', '<button type="button" data-nett-reserve$1', $kropp, 1);

return [
    'kropp' => $kropp . "\n" . Deler::bunn(true),
    'aktiv' => '',
    // Vipps-knappens skript lastes av nett.js naar sida er ferdig: skriptet
    // henter egne skrifter, og i hodet holdt de overskriften igjen (LCP 4,5 s
    // simulert mobil, 23. september 2026). Til da staar vaar egen knapp.
    'hode'  => '',
];
