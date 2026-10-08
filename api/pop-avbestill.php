<?php
/**
 * Avbestill Paint on Pots fra lenka i bekreftelsen: /api/pop-avbestill.php?b=<id>&k=<kode>
 *
 * Eieren, «ok, bygg det» 8. oktober 2026 (fremvisningen GFD76vZ7iEiFgDiaPFf6hu,
 * steg 3): bekreftelsen har en lenke for å avbestille. «Avbestilling senest
 * 24 t før (frist i admin) = refusjon via Vipps; senere/ikke møtt = beholdes.»
 *
 * Sida viser bare plassen og regelen. Selve avbestillingen og refusjonen
 * gjøres av api/avbestill.php — det samme stedet som Min side bruker — så
 * regelen og pengene står ett sted. Koden i lenka (bookings.avbestill_kode)
 * er det eneste som gir tilgang; den sjekkes på nytt der.
 *
 * Lett side som api/avmelding.php: den skal virke uansett.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$id   = (int) ($_GET['b'] ?? 0);
$kode = (string) ($_GET['k'] ?? '');
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$ok = false;
$b = null;
try {
    $ok = PopPris::kodeStemmer($id, $kode);
    if ($ok) {
        $b = DB::en(
            'SELECT b.id, b.status, b.antall, b.course_id, b.gjenstander_ore, b.depositum_ore,
                    b.avbestilling_timer, c.tittel, cs.start_tid
               FROM bookings b
               JOIN courses c ON c.id = b.course_id
          LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
              WHERE b.id = :i',
            ['i' => $id]
        );
    }
} catch (Throwable) {
    $ok = false;
}
if (!$ok) {
    Rate::sjekk('avbestill-kode', maks: 10, vindu: 3600);
}

$knapp = false;
if (!$ok || $b === null) {
    $tittel = 'Lenken virker ikke';
    $linjer = ['Skriv til monica@lissom.no, så ordner vi det.'];
} elseif (in_array((string) $b['status'], ['avbestilt', 'refundert'], true)) {
    $tittel = 'Avbestilt';
    $linjer = ['Denne plassen er allerede avbestilt.'];
} elseif (!in_array((string) $b['status'], ['betalt', 'reservert'], true)
    || $b['start_tid'] === null || strtotime((string) $b['start_tid'] . ' UTC') <= time()
    || $b['gjenstander_ore'] !== null) {
    $tittel = 'Avbestill ' . (string) $b['tittel'];
    $linjer = ['Denne plassen kan ikke avbestilles her lenger. Ta kontakt med oss.'];
} else {
    $timerIgjen = (strtotime((string) $b['start_tid'] . ' UTC') - time()) / 3600;
    $frist = PopPris::fristFor($b);
    $regel = Booking::avbestillingsregel($timerIgjen, $frist);
    $n = (int) $b['antall'];
    // Det som faktisk gaar tilbake: Vipps-delen til Vipps, gavekortdelen til
    // kortet (samme deling som api/avbestill.php).
    $vipps = 0;
    $gave = 0;
    foreach (Booking::betalingerFor((int) $b['id'])['rader'] as $p) {
        if ($p['annullert_at'] !== null || !in_array((string) $p['status'], ['betalt', 'delvis_refundert'], true)) {
            continue;
        }
        $gave += Booking::gavekortBrukt((int) $p['id']);
        if ((string) $p['type'] !== 'manuell') {
            $vipps += max(0, (int) $p['belop_ore'] - (int) $p['refundert_ore']);
        }
    }
    $kr = static fn(int $ore): string => str_replace("\u{a0}", ' ', PopPris::kr($ore));
    $tittel = 'Avbestill ' . (string) $b['tittel'];
    $linjer = [
        Booking::norskDato((string) $b['start_tid']) . ' · ' . $n . ($n === 1 ? ' person' : ' personer'),
        $regel['andel'] < 1.0
            ? 'Det er mindre enn ' . ($frist ?? 48) . ' timer igjen, så beløpet beholdes.'
            : ($gave > 0
                ? ($vipps > 0 ? 'Du får ' . $kr($vipps) . ' tilbake på Vipps og ' : 'Du får ')
                  . $kr($gave) . ' tilbake på gavekortet.'
                : 'Du får ' . $kr($vipps) . ' tilbake på Vipps.'),
    ];
    $knapp = true;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
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
  p{font-size:18px;margin:0 0 16px;color:#6F5D4C}
  .knapper{margin-top:32px}
  .pille{display:inline-flex;align-items:center;border:2px solid #4D1D12;background:#FFCF38;border-radius:999px;padding:12px 26px;font:700 14px/1.2 "Alegreya Sans",sans-serif;letter-spacing:.06em;text-transform:uppercase;text-decoration:none;color:#4D1D12;cursor:pointer}
  .pille[disabled]{opacity:.6;cursor:default}
  footer{margin-top:64px;font-size:13px;color:#6F5D4C}
</style>
</head>
<body>
<main class="ramme">
  <h1 id="tittel">' . $e($tittel) . '</h1>
  <div id="tekst">' . implode('', array_map(static fn(string $l): string => '<p>' . $e($l) . '</p>', $linjer)) . '</div>
  <div class="knapper">'
    . ($knapp
        ? '<button class="pille" id="avbestill" type="button">Avbestill</button>'
        : '<a class="pille" href="https://lissom.no/">Til lissom.no</a>')
    . '</div>
  <footer>Lissom Keramikk &amp; Håndverk AS · Nordre Løkkevei 15, 3120 Nøtterøy</footer>
</main>'
. ($knapp ? '
<script>
(function () {
  var k = document.getElementById("avbestill");
  k.addEventListener("click", function () {
    k.disabled = true;
    fetch("/api/avbestill.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({ bookingId: ' . (int) $id . ', k: ' . json_encode($kode) . ' })
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) {
        document.getElementById("tittel").textContent = "Avbestilt";
        var p = document.createElement("p"); p.textContent = d.beskjed || "Plassen er avbestilt.";
        document.getElementById("tekst").replaceChildren(p);
        k.outerHTML = \'<a class="pille" href="https://lissom.no/">Til lissom.no</a>\';
      } else {
        var f = document.createElement("p"); f.textContent = (d && d.feil) || "Noe gikk galt. Prøv igjen, eller ta kontakt med oss.";
        document.getElementById("tekst").append(f);
        k.disabled = false;
      }
    }).catch(function () { k.disabled = false; });
  });
})();
</script>' : '') . '
</body>
</html>
';
