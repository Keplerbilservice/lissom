<?php
/**
 * Avmelding fra tilbud på e-post: /avmelding?k=<kode>
 *
 * Lenka nederst i medlemsinvitasjonen etter kurset (malen «fortsett»).
 * Åpnes den, settes «reservert» på adressen, og jobbene som sender tilbud
 * hopper over den. Sida er en egen, lett side som vilkar.html — den skal
 * virke uansett, også for den som ikke har vært på nettsida før.
 *
 * Eieren, 11. september 2026 (GO): «Meldt av» / «Du får ikke flere e-poster
 * om medlemskap og tilbud fra oss.» / pille «Til lissom.no».
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$kode = (string) ($_GET['k'] ?? '');
$ok = false;
try {
    $ok = Avmelding::reserver($kode);
} catch (Throwable) {
    $ok = false;
}

$tittel = $ok ? 'Meldt av' : 'Lenken virker ikke';
$tekst  = $ok
    ? 'Du får ikke flere e-poster om medlemskap og tilbud fra oss.'
    : 'Skriv til monica@lissom.no, så ordner vi det.';
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo '<!DOCTYPE html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>' . $e($tittel) . ' — Lissom Keramikk</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="stylesheet" href="/ds-fonts.css">
<style>
  body{margin:0;background:#FBF6EE;color:#2E1002;font-family:"Alegreya Sans",Georgia,serif;line-height:1.6}
  .ramme{max-width:560px;margin:0 auto;padding:72px 24px 96px;text-align:center}
  h1{font-family:"Bitter",Georgia,serif;font-weight:800;font-size:clamp(30px,5vw,42px);line-height:1.15;margin:0 0 12px;color:#4D1D12}
  p{font-size:18px;margin:0 0 32px;color:#6F5D4C}
  .pille{display:inline-flex;align-items:center;border:2px solid #4D1D12;background:#FFCF38;border-radius:999px;padding:12px 26px;font:700 14px/1.2 "Alegreya Sans",sans-serif;letter-spacing:.06em;text-transform:uppercase;text-decoration:none;color:#4D1D12}
  footer{margin-top:64px;font-size:13px;color:#6F5D4C}
</style>
</head>
<body>
<main class="ramme">
  <h1>' . $e($tittel) . '</h1>
  <p>' . $e($tekst) . '</p>
  <a class="pille" href="https://lissom.no/">Til lissom.no</a>
  <footer>Lissom Keramikk &amp; Håndverk AS · Nordre Løkkevei 15, 3120 Nøtterøy</footer>
</main>
</body>
</html>
';
