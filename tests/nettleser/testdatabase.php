<?php
/** Fail-closed vakt før nettlesertester får starte eller rydde testdata. */
declare(strict_types=1);

function krev_testdatabase_oppsett(array $s): void
{
    if (($s['miljo'] ?? null) !== 'test'
        || !in_array($s['db_vert'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
        || !is_string($s['db_navn'] ?? null)
        || preg_match('/\Alissom_test(?:_[a-zA-Z0-9_]+)?\z/', $s['db_navn']) !== 1
        || !is_string($s['db_bruker'] ?? null) || $s['db_bruker'] === ''
        || !is_string($s['db_passord'] ?? null)
        || !is_int($s['db_port'] ?? 3306)
        || ($s['db_port'] ?? 3306) < 1 || ($s['db_port'] ?? 3306) > 65535) {
        throw new RuntimeException('Nettlesertester krever miljo=test, lokal database og navn lissom_test eller lissom_test_*.');
    }
}

function krev_testdatabase_identitet(array $s, mixed $navn): void
{
    krev_testdatabase_oppsett($s);
    if ($navn !== $s['db_navn']) {
        throw new RuntimeException('Databasens identitet stemmer ikke med testoppsettet.');
    }
}

function krev_testdatabase(string $rot): array
{
    // Bootstrap prioriterer denne eksterne fila foran app/secrets.php.
    // Ikke les den eller start appen når den finnes: den kan tilhøre drift.
    if (is_file($rot . '/../lissom-secrets/secrets.php')) {
        throw new RuntimeException('Eksternt secrets-oppsett overstyrer lokal testkonfigurasjon. Bruk en isolert arbeidsmappe.');
    }
    $fil = $rot . '/app/secrets.php';
    if (!is_file($fil)) {
        throw new RuntimeException('Lokalt testoppsett mangler.');
    }
    $s = require $fil;
    if (!is_array($s)) {
        throw new RuntimeException('Ugyldig testoppsett.');
    }
    krev_testdatabase_oppsett($s);
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $s['db_vert'], $s['db_port'] ?? 3306, $s['db_navn']),
            $s['db_bruker'], $s['db_passord'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        krev_testdatabase_identitet($s, $pdo->query('SELECT DATABASE()')->fetchColumn());
    } catch (PDOException $e) {
        throw new RuntimeException('Kunne ikke bekrefte testdatabasens identitet.');
    }
    return $s;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        krev_testdatabase(dirname(__DIR__, 2));
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
