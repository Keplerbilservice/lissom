<?php
/** Actual queue -> SMTP checks, against the loopback receiver in varsler-smtp.mjs. */
declare(strict_types=1);
require __DIR__ . '/nettleser/testdatabase.php';
$settings = krev_testdatabase(dirname(__DIR__));
require dirname(__DIR__) . '/app/bootstrap.php';
krev_testdatabase_identitet($settings, DB::verdi('SELECT DATABASE()'));
$port = (int) getenv('LISSOM_SMTP_TEST_PORT');
if ($port < 1024 || $port > 65535) { throw new RuntimeException('Missing local SMTP port'); }
$settings = array_replace($settings, [
    'send_i_test' => true, 'smtp_vert' => '127.0.0.1', 'smtp_port' => $port,
    'smtp_sikkerhet' => 'ingen', 'smtp_bruker' => 'smtp-test', 'smtp_passord' => 'smtp-test',
    'epost_fra' => 'sender@example.test', 'epost_svar_til' => 'reply@example.test',
    'epost_fra_navn' => 'Lissom test',
]);
Config::last($settings);
$checks = []; $finished = false;
register_shutdown_function(static function () use (&$finished): void { if (!$finished) { exit(1); } });
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok];
    echo ($ok ? 'OK ' : 'FEIL ') . $name . "\n";
};
$row = static fn(int $id): array => DB::en('SELECT * FROM notifications WHERE id = :id', ['id' => $id]) ?? [];
$ready = static function (int $id): void {
    DB::kjor('UPDATE notifications SET send_etter = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = :id', ['id' => $id]);
};
$pdo = DB::kobling(); $pdo->beginTransaction();
try {
    // Existing local fixture messages are hidden inside this transaction only.
    DB::kjor("UPDATE notifications SET send_etter = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE status = 'ko'");
    $check('ugyldig mottaker avvises', Varsel::epost('invalid', 'test', 'test') === 0);
    $id = Varsel::epost('success@example.test', 'Kursbevis og påminnelse', ".prikk\nNorsk tekst: æøå\nhttps://example.test/kursbevis?k=syntetisk", 'smtp_test', 1,
        'system', '<p>Norsk tekst: æøå</p><a href="https://example.test/kursbevis?k=syntetisk">Kursbevis</a>');
    $check('e-post opprettes i kø', $id > 0 && ($row($id)['status'] ?? '') === 'ko');
    $check('referanse beholdes', ($row($id)['ref_type'] ?? '') === 'smtp_test' && (int) $row($id)['ref_id'] === 1);
    $ready($id); $result = Utsending::tomKo(1);
    $check('SMTP godtar meldingen', $result === [1, 0]);
    $check('sendt-status lagres', ($row($id)['status'] ?? '') === 'sendt');
    $check('sendetid lagres', !empty($row($id)['sendt_at']));
    $check('ett sendeforsøk registreres', (int) $row($id)['forsok'] === 1);
    $check('sendt melding gjentas ikke', Utsending::tomKo(1) === [0, 0]);
    $future = Varsel::epost('future@example.test', 'Fremtidig', 'ikke ennå');
    DB::kjor('UPDATE notifications SET send_etter = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = :id', ['id' => $future]);
    $check('fremtidig utsending venter', Utsending::tomKo(1) === [0, 0] && (int) $row($future)['forsok'] === 0);
    $retry = Varsel::epost('retry@example.test', 'Nytt forsøk', 'Samme melding etter midlertidig feil');
    $ready($retry); $check('midlertidig SMTP-feil oppdages', Utsending::tomKo(1) === [0, 1]);
    $check('midlertidig feil beholder køstatus', ($row($retry)['status'] ?? '') === 'ko');
    $check('leverandørens feilmelding lagres', str_contains((string) $row($retry)['feilmelding'], '451'));
    $check('nytt forsøk utsettes', strtotime((string) $row($retry)['send_etter'] . ' UTC') > time());
    $check('utsatt retry sendes ikke straks', Utsending::tomKo(1) === [0, 0]);
    $ready($retry); $check('retry leveres etter ny klargjøring', Utsending::tomKo(1) === [1, 0]);
    $check('retry beholder én køoppføring', ($row($retry)['status'] ?? '') === 'sendt' && (int) $row($retry)['forsok'] === 2);
    $reject = Varsel::epost('reject@example.test', 'Avvist mottaker', 'test');
    for ($i = 1; $i <= 5; $i++) {
        $ready($reject); $check("avvist mottaker, forsøk $i", Utsending::tomKo(1) === [0, 1] && (int) $row($reject)['forsok'] === $i);
    }
    $check('fem feil gir feilet-status', ($row($reject)['status'] ?? '') === 'feilet');
    $check('mottakerfeil vises i køen', str_contains((string) $row($reject)['feilmelding'], 'Mottakeren'));
    $check('ingen sjette sending etter fem feil', Utsending::tomKo(1) === [0, 0]);
    $limited = [];
    foreach ([1, 2] as $n) { $limited[] = Varsel::epost("limit$n@example.test", 'Maks per runde', 'test'); $ready(end($limited)); }
    $check('maksantall begrenser køtømming', Utsending::tomKo(1) === [1, 0] && ($row($limited[1])['status'] ?? '') === 'ko');
    $check('resten leveres i neste runde', Utsending::tomKo(1) === [1, 0]);
    // Every saved template is exercised through the real mail builder and SMTP.
    // Activation/channel changes are confined to this rolled-back transaction.
    foreach (DB::alle('SELECT * FROM notification_templates ORDER BY navn') as $template) {
        $name = (string) $template['navn'];
        DB::oppdater('notification_templates', ['aktiv' => 1, 'kanal' => 'epost'], ['navn' => $name]);
        $fields = ['navn' => 'Kari Test', 'fornavn' => 'Kari', 'kurs' => 'Nattkontroll Dreiekurs'];
        foreach ($template as $value) {
            if (!is_string($value)) { continue; }
            preg_match_all('/\{([a-z_][a-z0-9_]*)\}/i', $value, $matches);
            foreach ($matches[1] as $key) {
                $fields[$key] ??= preg_match('/lenke|url|adresse|bevis/', $key) ? 'https://example.test/test' : 'Kontroll';
            }
        }
        $fields[Varsel::SAMLINGER] = json_encode([['nr' => '1', 'dato' => '1. oktober', 'tid' => '17:00', 'tittel' => 'Dreie', 'beskrivelse' => 'Testsamling']]);
        $recipient = 'mal-' . $name . '@example.test';
        Varsel::mal($name, ['epost' => $recipient], $fields, 'smtp_template', 1);
        $notification = DB::en('SELECT * FROM notifications WHERE mottaker=:r ORDER BY id DESC LIMIT 1', ['r' => $recipient]);
        $check("mal «{$name}»: e-post bygges og legges i kø", $notification !== null && $notification['mal'] === $name && $notification['kanal'] === 'epost');
        if ($notification === null) { continue; }
        $check("mal «{$name}»: tekst og HTML uten uerstattede felt", trim((string) $notification['tekst']) !== ''
            && trim((string) $notification['html']) !== ''
            && preg_match('/\{[a-z_][a-z0-9_]*\}/i', $notification['tekst'] . $notification['html']) !== 1);
        $ready((int) $notification['id']);
        $check("mal «{$name}»: faktisk SMTP-overføring", Utsending::tomKo(1) === [1, 0] && $row((int) $notification['id'])['status'] === 'sendt');
    }
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    Config::last(require dirname(__DIR__) . '/app/secrets.php');
}
file_put_contents((string) getenv('LISSOM_SMTP_TEST_RESULT'), json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$finished = true;
exit(count(array_filter($checks, static fn(array $c): bool => !$c['ok'])) ? 1 : 0);
