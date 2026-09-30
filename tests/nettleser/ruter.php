<?php
/**
 * Ruteren til nettlesertestene — samme fordeling som .htaccess paa
 * webhotellet, saa langt testene trenger den: filer som finnes serveres
 * som de er, api/*.php kjores, og alt annet gaar til side.php.
 *
 *   php -S 127.0.0.1:8140 -t . tests/nettleser/ruter.php
 */
$sti = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rot = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\');
$fil = $rot . $sti;
if ($sti !== '/' && is_file($fil)) {
    if (str_ends_with($fil, '.php')) {
        chdir(dirname($fil));
        $_SERVER['SCRIPT_FILENAME'] = $fil;
        $_SERVER['SCRIPT_NAME'] = $sti;
        require $fil;
        return true;
    }
    return false;
}
// De omskrivingene i .htaccess som gaar til et skript.
$skript = static function (string $fil, array $get = []) use ($rot): bool {
    foreach ($get as $k => $v) { $_GET[$k] = $v; }
    chdir(dirname($rot . $fil));
    require $rot . $fil;
    return true;
};
if (preg_match('~^/meld-inn/([a-fA-F0-9]{32})/?$~', $sti, $m)) { return $skript('/api/meld-inn.php', ['t' => $m[1]]); }
if (preg_match('~^/avmelding/?$~', $sti)) { return $skript('/api/avmelding.php'); }
if (preg_match('~^/nyttig-info/([a-z0-9-]+)/?$~', $sti, $m)) { return $skript('/guide.php', ['slug' => $m[1]]); }
if ($sti === '/sitemap.xml') { return $skript('/api/sitemap.php'); }
if (preg_match('~^/admin2/?$~', $sti)) { header('Content-Type: text/html; charset=UTF-8'); readfile($rot . '/admin2.html'); return true; }
require $rot . '/side.php';
