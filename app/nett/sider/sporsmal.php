<?php
/** Spoersmaal og svar — /sporsmal-og-svar. Skjermen fra lissom-2108.html, tegnet av Mal. */

declare(strict_types=1);

// De ti spoersmaalene: standarden ligger i innhold-standard.json, og det
// eieren har endret under Innhold gaar foran — som sporsmal i nettsida.
$sp = [];
for ($i = 1; $i <= 10; $i++) {
    $q = Nett::innh('Spørsmål og svar/0/Spørsmål ' . $i);
    $a = Nett::innh('Spørsmål og svar/0/Svar ' . $i);
    if ($q !== '') {
        $sp[] = ['q' => $q, 'a' => $a];
    }
}
return [
    'kropp' => Mal::tegn('Spørsmål og svar', ['sporsmal' => $sp, 'sant' => true], []) . "\n" . Deler::bunn(true),
    'aktiv' => '',
];
