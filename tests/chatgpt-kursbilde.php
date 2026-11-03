<?php
declare(strict_types=1);
// Statisk sikkerhetskontroll av integrasjonsendepunktet, uten database/produksjon.
$source = file_get_contents(__DIR__ . '/../api/admin/kursbilde-kontrollert.php');
if ($source === false) { fwrite(STDERR, "Mangler endepunkt\n"); exit(1); }
$checks = [
    'krever admin' => 'krev_admin();',
    'sjekker opphav ved skriving' => 'Foresporsel::krevSammeOpphav();',
    'eksplisitt bekreftelse' => "$body['bekreftet'] !== true",
    'radlaas' => 'FOR UPDATE',
    'transaksjon' => 'beginTransaction()',
    'tilbakerulling' => 'rollBack()',
    'bildearkiv-validering' => "Bilder::sti($name, 'artikler')",
    'revisjonslogg' => "revider('kursbilde_endret'",
    'begrenset databaseoppdatering' => 'UPDATE courses SET bilde = :b WHERE id = :id',
    'forrige bilde kontroll' => "$old['bilde']",
];
foreach ($checks as $name => $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Mangler kontroll: $name\n"); exit(1);
    }
}
echo "10 statiske sikkerhetskontroller OK (ikke integrasjonstest).\n";
