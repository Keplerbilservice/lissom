<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$m = Sesjon::medlem();
if ($m !== null) {
    revider('logg_ut', 'member', (int) $m['id']);
}

Sesjon::avslutt();
Svar::ok();
