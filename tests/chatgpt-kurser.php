<?php
declare(strict_types=1);
require __DIR__ . '/../app/lib/chatgptkurser.php';
// Dedicated in-memory fixture. No bootstrap, secrets, production data or payments.
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE members (id INTEGER PRIMARY KEY, rolle TEXT, anonymisert_at TEXT);
CREATE TABLE courses (id INTEGER PRIMARY KEY, tittel TEXT, slug TEXT, status TEXT, bilde TEXT, notat TEXT);
CREATE TABLE rate_limits (nokkel TEXT, vindu_start TEXT, antall INTEGER, PRIMARY KEY(nokkel,vindu_start));
CREATE TABLE audit_log (member_id INTEGER, handling TEXT, objekt_type TEXT, objekt_id INTEGER, detaljer TEXT);
INSERT INTO members VALUES (1,'admin',NULL),(2,'medlem',NULL),(3,'admin','2026-01-01');
INSERT INTO courses VALUES (1,'Testkurs','testkurs','aktiv','api/bilde.php?artikkel=test.jpg','HEMMELIG'),
(2,'Utkast','utkast','utkast',NULL,'HEMMELIG');");
$secret = str_repeat('test-only-key-', 4); $now = time(); $checks = 0;
$reader = new ChatgptKurser($db, $secret, ['owner' => 1, 'member' => 2, 'removed' => 3]);
function check(bool $ok, string $name): void {
    global $checks; if (!$ok) { throw new RuntimeException("FAIL: $name"); } $checks++;
}
function request(array $change = [], int $offset = 0): array {
    global $secret, $now;
    $raw = json_encode(array_replace(['actor' => 'owner', 'operation' => 'list', 'afterId' => 0,
        'limit' => 20, 'requestId' => '11111111-1111-4111-8111-111111111111'], $change), JSON_THROW_ON_ERROR);
    $timestamp = (string) ($now + $offset); $nonce = bin2hex(random_bytes(16));
    return [$raw, ['timestamp' => $timestamp, 'nonce' => $nonce,
        'signature' => hash_hmac('sha256', "lissom-courses-read-v1\n$timestamp\n$nonce\n$raw", $secret)]];
}
function denied(ChatgptKurser $reader, array $request, int $code, string $name): void {
    global $now;
    try { $reader->les(...[...$request, $now]); }
    catch (RuntimeException $e) { check($e->getCode() === $code, $name); return; }
    throw new RuntimeException("FAIL: accepted $name");
}
$req = request(); $result = $reader->les(...[...$req, $now]);
check(count($result['courses']) === 2 && $result['courses'][0]['id'] === 1, 'list');
check(!str_contains(json_encode($result), 'HEMMELIG') && count($result['courses'][0]) === 5, 'allowlisted projection');
check((int) $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === 1, 'audit');
denied($reader, $req, 409, 'replay');
$cutoff = gmdate('Y-m-d H:i:s', $now - 86400);
$db->exec("DELETE FROM rate_limits WHERE vindu_start < '$cutoff'");
denied($reader, $req, 409, 'normal rate cleanup preserves replay protection');
denied($reader, request([], -61), 401, 'expired signature');
denied($reader, request([], 61), 401, 'future signature');
$req = request(); $req[0] .= ' '; denied($reader, $req, 401, 'tampered body');
$req = request(); $req[1]['signature'] = str_repeat('0',64); denied($reader, $req, 401, 'wrong key');
foreach (['unknown', 'member', 'removed'] as $actor) denied($reader, request(['actor' => $actor]), 403, $actor);
denied($reader, request(['operation' => 'write']), 400, 'no writes');
denied($reader, request(['limit' => 51]), 400, 'bounded list');
denied($reader, request(['sql' => 'DROP TABLE courses']), 400, 'unknown arguments');
denied(new ChatgptKurser($db, '', []), request(), 503, 'disabled by default');
$get = ['actor' => 'owner', 'operation' => 'get', 'id' => 2, 'requestId' => '11111111-1111-4111-8111-111111111111'];
$raw = json_encode($get); $nonce = bin2hex(random_bytes(16));
$headers = ['timestamp' => (string) $now, 'nonce' => $nonce, 'signature' => hash_hmac('sha256', "lissom-courses-read-v1\n$now\n$nonce\n$raw", $secret)];
check($reader->les($raw,$headers,$now)['courses'][0]['id'] === 2, 'get exact course');
$db->exec("UPDATE members SET rolle='medlem' WHERE id=1");
denied($reader, request(), 403, 'revoked admin role checked on every request');
check((int) $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === 2, 'denials do not produce success audit');
check($db->query('SELECT notat FROM courses WHERE id=1')->fetchColumn() === 'HEMMELIG', 'course unchanged');
$db->exec("UPDATE members SET rolle='admin' WHERE id=1");
$before = (int) $db->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn();
$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT,'fixture audit failure'); END");
try { $req = request(); $reader->les(...[...$req,$now]); throw new RuntimeException('Accepted missing audit'); }
catch (PDOException) {
    check(!$db->inTransaction(), 'audit failure rolls back transaction');
    check((int) $db->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn() === $before, 'audit failure rolls back nonce too');
}
echo "$checks funksjonskontroller OK (isolert SQLite, ikke produksjon).\n";
