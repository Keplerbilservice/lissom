<?php
/**
 * Skisser i admin: alle tavler, og deling med medlemmer og kursdeltakere.
 *
 * Se app/lib/skisser.php. Bryteren for hele modulen (Vis/skisser) står under
 * ⊙ Synlighet; av betyr 403 også her.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if (!Skisser::klar()) {
    Svar::feil('Migrasjon 236 er ikke kjørt. Trykk ⚙ Kjør oppdateringer.');
}
if (!Skisser::modulPaa()) {
    Svar::feil('Skisser er slått av under Synlighet.', 403);
}

SkisserApi::haandter($admin);
