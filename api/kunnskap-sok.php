<?php
/**
 * Søk i kunnskapen — håndbøkene, teknikkarkene og malene i Verkstedet.
 *
 *   GET ?q=ord     inntil seks treff: { id, navn, kort, utdrag }
 *
 * Eieren, 11. september 2026 (GO): kunnskapstreff i søkefeltet på nettsida
 * og i søkefeltet i kalender admin.
 *
 * Krever innlogging, med vilje: dokumentene ligger bak innlogging, og et
 * søk som fant «Medlemskontrakt» for hvem som helst ville sagt mer enn det
 * skulle. Et medlem får bare det som ligger i kort som er slått på
 * (samme regel som api/mine-dokumenter.php); admin får alt. Treffet åpnes
 * gjennom api/dokument.php, som gjør den samme sjekken en gang til.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$medlem  = krev_medlem();
$erAdmin = Sesjon::erAdmin();

if (!$erAdmin && !er_aktivt_medlem($medlem)) {
    Svar::json(['treff' => []]);
}
if (!Dokumenter::klar()) {
    Svar::json(['treff' => []]);
}

$q = Foresporsel::tekst('q');
if (mb_strlen($q) > 80) {
    $q = mb_substr($q, 0, 80);
}

// Hvert tastetrykk er et kall. Hundre i minuttet per person er rikelig til
// aa skrive, og lite nok til at et skript ikke leser ut alt ord for ord.
Rate::sjekk('kunnskap-sok', 100, 60, 'medlem:' . (int) $medlem['id']);

Svar::json(['treff' => Dokumenter::sok($q, !$erAdmin)]);
