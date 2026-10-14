<?php
declare(strict_types=1);
// Synthetic HTTP fixture only, never bootstrap the application or read its secrets.
require __DIR__ . '/../../app/lib/chatgptkurser.php';
header('Content-Type: application/json');
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE members (id INTEGER PRIMARY KEY, rolle TEXT, anonymisert_at TEXT);
CREATE TABLE courses (id INTEGER PRIMARY KEY, tittel TEXT, slug TEXT, status TEXT, bilde TEXT);
CREATE TABLE rate_limits (nokkel TEXT, vindu_start TEXT, antall INTEGER, PRIMARY KEY(nokkel,vindu_start));
CREATE TABLE audit_log (member_id INTEGER, handling TEXT, objekt_type TEXT, objekt_id INTEGER, detaljer TEXT);
INSERT INTO members VALUES (1,'admin',NULL);
INSERT INTO courses VALUES (7,'Syntetisk HTTP-kurs','syntetisk-http','aktiv',NULL);");
try {
    $result = (new ChatgptKurser($db, 'synthetic-test-key-never-use-in-production', ['owner' => 1]))->les(
        file_get_contents('php://input'), [
            'timestamp' => $_SERVER['HTTP_X_LISSOM_TIMESTAMP'] ?? '',
            'nonce' => $_SERVER['HTTP_X_LISSOM_NONCE'] ?? '',
            'signature' => $_SERVER['HTTP_X_LISSOM_SIGNATURE'] ?? '',
        ], time());
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(in_array($e->getCode(), [400,401,403,409,503], true) ? $e->getCode() : 500);
    echo '{"error":"Fixture request denied"}';
}
