<?php
/**
 * Falsk Gemini og falsk Meta, kun for tests/kursboost.sh.
 *
 *   KB_LOGG=/tmp/logg php -S 127.0.0.1:8152 tests/falsk-kursboost.php
 *
 * Kursboost lager bilder hos Gemini og legger ut hos Meta. Ingen av delene
 * skal skje fra en test — det koster penger, og et innlegg paa Lissoms konto
 * er ekte. Denne svarer som de to gjor, og skriver hvert kall paa én linje i
 * KB_LOGG, saa testen kan se hva som faktisk ble spurt om (og hva som ikke
 * ble det).
 */

declare(strict_types=1);

$sti = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$metode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$logg = getenv('KB_LOGG') ?: sys_get_temp_dir() . '/kb-logg.txt';
file_put_contents($logg, $metode . ' ' . $sti . "\n", FILE_APPEND);

header('Content-Type: application/json');

// Gemini: …/models/<modell>:generateContent → ett lite JPEG-bilde.
if (str_contains($sti, ':generateContent')) {
    $b = imagecreatetruecolor(40, 50);
    imagefill($b, 0, 0, imagecolorallocate($b, 160, 110, 70));
    ob_start();
    imagejpeg($b);
    $jpeg = (string) ob_get_clean();
    echo json_encode(['candidates' => [['content' => ['parts' => [
        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
    ]]]]]);
    return true;
}

// Meta (Graph API): /<versjon>/<resten>
$deler = array_values(array_filter(explode('/', $sti)));
array_shift($deler); // versjonen
$rest = implode('/', $deler);

if ($metode === 'POST' && str_ends_with($rest, '/media')) {
    echo json_encode(['id' => 'beholder1']);
} elseif ($metode === 'GET' && $rest === 'beholder1') {
    echo json_encode(['status_code' => 'FINISHED']);
} elseif ($metode === 'POST' && str_ends_with($rest, '/media_publish')) {
    echo json_encode(['id' => 'ig-innlegg1']);
} elseif ($metode === 'GET' && $rest === 'ig-innlegg1') {
    echo json_encode(['permalink' => 'https://www.instagram.com/p/test/']);
} elseif ($metode === 'GET' && isset($_GET['fields']) && $_GET['fields'] === 'access_token') {
    echo json_encode(['access_token' => 'side-token-test']);
} elseif ($metode === 'POST' && str_ends_with($rest, '/photos')) {
    echo json_encode(['id' => 'foto1', 'post_id' => 'side1_innlegg1']);
} else {
    http_response_code(404);
    echo json_encode(['error' => ['message' => 'Ukjent i falsk Meta: ' . $metode . ' ' . $rest]]);
}
return true;
