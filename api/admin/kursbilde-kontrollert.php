<?php
/**
 * Sikker intern byggestein for bildebytte på ett kurs.
 * Dette er IKKE et offentlig ChatGPT-endepunkt. Krever eksisterende admin-sesjon.
 * Ekstern MCP/OAuth-integrasjon må implementeres og testes separat.
 */
declare(strict_types=1);
require __DIR__ . '/../_boot.php';
krev_admin();
if (Foresporsel::metode() === 'GET') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) { Svar::feil('Ugyldig kurs-ID.', 400); }
    $rad = DB::en('SELECT id, tittel, bilde FROM courses WHERE id = :id', ['id' => $id]);
    if ($rad === null) { Svar::feil('Fant ikke kurset.', 404); }
    Svar::json(['kurs' => $rad]);
}
Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$body = Foresporsel::kropp();
$keys = array_keys($body);
sort($keys);
if ($keys !== ['bekreftet', 'bilde', 'forrigeBilde', 'id']) {
    Svar::feil('Kun ID, forrige bilde, nytt bilde og bekreftelse er tillatt.', 400);
}
$id = filter_var($body['id'], FILTER_VALIDATE_INT);
$next = $body['bilde'];
$expected = $body['forrigeBilde'];
if (!$id || $id < 1 || !is_string($next) || !is_string($expected)
    || $body['bekreftet'] !== true) {
    Svar::feil('Ugyldig forespørsel eller manglende bekreftelse.', 400);
}
if (!preg_match('~^api/bilde\\.php\\?artikkel=[0-9a-f]{32}\\.jpg$~D', $next)) {
    Svar::feil('Bildet må være lastet opp via bildeadministrasjonen.', 400);
}
$name = substr($next, strlen('api/bilde.php?artikkel='));
if (Bilder::sti($name, 'artikler') === null) {
    Svar::feil('Bildet finnes ikke i bildearkivet.', 400);
}
if (strlen($expected) > 255) { Svar::feil('Ugyldig tidligere bilde.', 400); }
$pdo = DB::kobling();
$pdo->beginTransaction();
try {
    $old = DB::en('SELECT id, tittel, bilde FROM courses WHERE id = :id FOR UPDATE', ['id' => $id]);
    if ($old === null) {
        $pdo->rollBack(); Svar::feil('Fant ikke kurset.', 404);
    }
    if ((string) ($old['bilde'] ?? '') !== $expected) {
        $pdo->rollBack(); Svar::feil('Kursbildet er endret siden forhåndsvisningen. Hent kurset på nytt.', 409);
    }
    DB::kjor('UPDATE courses SET bilde = :b WHERE id = :id', ['b' => $next, 'id' => $id]);
    revider('kursbilde_endret', 'course', $id, [
        'forrige' => $expected, 'nytt' => $next,
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}
Svar::ok(['id' => $id, 'bilde' => $next, 'forrigeBilde' => $expected]);
