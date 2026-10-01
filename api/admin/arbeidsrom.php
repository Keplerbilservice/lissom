<?php
declare(strict_types=1);
require __DIR__ . '/../_boot.php';
Foresporsel::krevMetode('GET');
$m = krev_regnskap();
Svar::json(['navn' => (string) $m['navn'], 'erAdmin' => Sesjon::erAdmin()]);
