<?php
/**
 * Falsk Graph API for tests/autosvar.php. Svarer paa lesekallene
 * Meta::autosvar() gjoer, og skriver hvert svar (POST) til en fil i temp-mappa.
 */
declare(strict_types=1);

$sti = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$logg = sys_get_temp_dir() . '/lissom-autosvar-logg.txt';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    parse_str((string) file_get_contents('php://input'), $f);
    file_put_contents($logg, $sti . ' ' . ($f['message'] ?? '') . "\n", FILE_APPEND);
    echo json_encode(['id' => 'svar-' . md5($sti)]);
    return;
}

$naa = gmdate('Y-m-d\TH:i:sO');
$gammel = gmdate('Y-m-d\TH:i:sO', time() - 30 * 86400);

echo json_encode(match (true) {
    str_ends_with($sti, '/ig1') => ['username' => 'lissom_keramikk'],
    str_ends_with($sti, '/ig1/media') => ['data' => [[
        'id' => 'm1',
        'comments' => ['data' => [
            ['id' => 'ig-ny', 'username' => 'flowhelse', 'timestamp' => $naa],
            ['id' => 'ig-svart', 'username' => 'kunde2', 'timestamp' => $naa, 'replies' => ['data' => [['id' => 'r1', 'username' => 'lissom_keramikk', 'text' => 'Takk! 😊']]]],
            ['id' => 'ig-skjult', 'username' => 'kunde3', 'timestamp' => $naa, 'hidden' => true],
            ['id' => 'ig-egen', 'username' => 'lissom_keramikk', 'timestamp' => $naa],
            ['id' => 'ig-gammel', 'username' => 'kunde4', 'timestamp' => $gammel],
        ]],
    ]]],
    str_ends_with($sti, '/side1') => ['access_token' => 'side-token'],
    str_ends_with($sti, '/side1/feed') => ['data' => [[
        'id' => 'p1',
        'comments' => ['data' => [
            ['id' => 'fb-ny', 'from' => ['id' => '99'], 'created_time' => $naa],
            ['id' => 'fb-egen', 'from' => ['id' => 'side1'], 'created_time' => $naa],
            ['id' => 'fb-haand', 'from' => ['id' => '96', 'name' => 'Kari'], 'created_time' => $naa, 'comments' => ['data' => [['id' => 'c9', 'from' => ['id' => 'side1'], 'message' => 'Vi ses torsdag!']]]],
        ]],
    ]]],
    str_ends_with($sti, '/side1/ads_posts') => ['data' => [[
        'id' => 'a1',
        'comments' => ['data' => [
            ['id' => 'ann-ny', 'from' => ['id' => '98'], 'created_time' => $naa],
            ['id' => 'ann-skjult', 'from' => ['id' => '97'], 'created_time' => $naa, 'is_hidden' => true],
        ]],
    ]]],
    default => ['error' => ['message' => 'ukjent sti ' . $sti, 'code' => 100]],
});
