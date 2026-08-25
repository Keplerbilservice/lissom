<?php
/**
 * Kursbevis.
 *
 * Beviset bygges av malen fra trykkeriet: bakgrunnen er selve arket, og navn,
 * kurs, dato, instruktor og signatur legges oppa. Alt hentes fra pameldingen —
 * ingenting skrives inn for hand, og ingenting kommer fra nettleseren.
 *
 * Bare den som gikk kurset far se sitt eget bevis. Admin kan se alle, slik at
 * verkstedet kan skrive ut for noen som ikke far det til selv.
 *
 * Siden er A5 og laget for utskrift: «Last ned» apner nettleserens
 * utskriftsdialog, der «Lagre som PDF» gir en fil kunden kan ta vare paa.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');
$medlem = krev_medlem();

$bookingId = Foresporsel::heltall('booking');

// Overstyringene kom med migrasjon 045. Uten sjekken faller beviset for alle
// som ikke har kjort den.
$bevisFelt = DB::harKolonne('bookings', 'bevis_navn')
    ? 'b.bevis_navn, b.bevis_kurs, b.bevis_sperret,'
    : '';

$b = DB::en(
    "SELECT b.id, b.member_id, b.gjest_navn, b.status,
            {$bevisFelt}
            c.tittel, c.type, c.instruktor, c.instruktor_signatur,
            cs.start_tid, cs.slutt_tid,
            m.navn AS medlem_navn
       FROM bookings b
       JOIN courses c ON c.id = b.course_id
  LEFT JOIN course_sessions cs ON cs.id = b.course_session_id
  LEFT JOIN members m ON m.id = b.member_id
      WHERE b.id = :id",
    ['id' => $bookingId]
);

if (!$b) {
    Svar::feil('Fant ikke påmeldingen.', 404);
}
if ((int) ($b['member_id'] ?? 0) !== (int) $medlem['id'] && !Sesjon::erAdmin()) {
    // 404 og ikke 403: vi bekrefter ikke at en fremmed pamelding finnes.
    Svar::feil('Fant ikke påmeldingen.', 404);
}
if (!empty($b['bevis_sperret'])) {
    // Verkstedet har trukket beviset — noen gikk fra kurset for tidlig, eller
    // det ble utstedt ved en feil. Samme beskjed til alle: at det ikke finnes.
    Svar::feil('Det er ikke utstedt kursbevis for denne påmeldingen.', 404);
}
if ($b['status'] !== 'betalt') {
    Svar::feil('Kursbeviset blir tilgjengelig når påmeldingen er betalt.', 409);
}

$slutt = $b['slutt_tid'] ?: $b['start_tid'];
if ($slutt === null || strtotime((string) $slutt) > time()) {
    Svar::feil('Kursbeviset blir tilgjengelig når kurset er gjennomført.', 409);
}

// Rettet navn eller kursnavn gaar foran. Er de tomme, staar det som for.
$navn = trim((string) ($b['bevis_navn'] ?? '')) ?: trim((string) ($b['medlem_navn'] ?: $b['gjest_navn']));
$kurs = trim((string) ($b['bevis_kurs'] ?? '')) ?: (string) $b['tittel'];
$dato = Booking::norskDatoKort((string) $slutt);

$instruktor = trim((string) ($b['instruktor'] ?? '')) ?: 'Monica Væthe-Larsen';
$signatur   = trim((string) ($b['instruktor_signatur'] ?? '')) ?: 'signatur-monica.png';

// Bare filnavn — ingen skraastreker, ingen mappebytting.
if (!preg_match('/^[A-Za-z0-9._-]+\.(png|jpg|jpeg|svg)$/', $signatur)) {
    $signatur = 'signatur-monica.png';
}

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="no">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kursbevis — <?= $e($navn) ?></title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="stylesheet" href="/ds-fonts.css">
<style>
  /* Arket er 148 x 210 mm. Alt inni maales i prosent av arket, slik at det
     staar likt paa skjerm og paa papir uansett hvor stort vinduet er. */
  :root { --brun: #4D1D12; }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: #E9E2D8;
    font-family: 'Alegreya Sans', system-ui, sans-serif;
    color: var(--brun);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    padding: 24px 16px 48px;
  }
  .verktoy {
    display: flex; gap: 12px; flex-wrap: wrap; justify-content: center;
    width: min(100%, 148mm);
  }
  .verktoy button, .verktoy a {
    appearance: none; cursor: pointer; text-decoration: none;
    font: inherit; font-weight: 700; font-size: 15px;
    padding: 12px 22px; border-radius: 999px;
    border: 2px solid var(--brun); background: var(--brun); color: #FBF6EE;
  }
  .verktoy a.andre { background: transparent; color: var(--brun); }

  .ark {
    position: relative;
    width: min(100%, 148mm);
    aspect-ratio: 148 / 210;
    background: url('/assets_kursbevis-bunn.jpg') center/cover no-repeat #FFC033;
    box-shadow: 0 10px 40px rgba(43,20,12,.22);
    overflow: hidden;
  }
  /* Feltene. Prosentene folger malen: linjene ligger der de ligger. */
  .felt {
    position: absolute; left: 8%; width: 84%;
    text-align: center; line-height: 1.1;
    white-space: nowrap;
  }
  .navn  { top: 26.4%; font-size: 5.2cqw; font-weight: 500; }
  .kurs  { top: 39.3%; font-size: 4.4cqw; font-weight: 500; }
  .dato  { top: 58.2%; font-size: 3.8cqw; font-weight: 500; }
  .ark { container-type: inline-size; }

  /* Lange navn og kurstitler skal krympe framfor aa renne ut av arket. */
  .felt span { display: inline-block; max-width: 100%; }

  .signatur {
    position: absolute;
    left: 32%; top: 63.5%; width: 35.2%;
  }
  .signatur img { width: 100%; display: block; }
  .instruktor {
    position: absolute; left: 8%; width: 84%; top: 68.9%;
    text-align: center; font-size: 2.9cqw; font-weight: 500;
  }
  .rolle {
    position: absolute; left: 8%; width: 84%; top: 71.8%;
    text-align: center; font-size: 1.9cqw; font-weight: 500;
    letter-spacing: .22em;
  }

  @media print {
    @page { size: 148mm 210mm; margin: 0; }
    body { background: #fff; padding: 0; gap: 0; display: block; }
    .verktoy { display: none; }
    .ark {
      width: 148mm; height: 210mm; box-shadow: none;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
  }
</style>
</head>
<body>

<div class="verktoy">
  <button type="button" onclick="window.print()">Last ned som PDF</button>
  <a class="andre" href="/min-side">Tilbake til Min side</a>
</div>

<div class="ark">
  <div class="felt navn"><span><?= $e($navn) ?></span></div>
  <div class="felt kurs"><span><?= $e($kurs) ?></span></div>
  <div class="felt dato"><span><?= $e($dato) ?></span></div>
  <div class="signatur"><img src="/<?= $e($signatur) ?>" alt=""></div>
  <div class="instruktor"><?= $e($instruktor) ?></div>
  <div class="rolle">INSTRUKTØR</div>
</div>

<script>
  // Krymp teksten hvis navnet eller kurstittelen er for langt for linja.
  // Bedre enn aa la den renne ut av arket, og bedre enn aa kutte den.
  for (const f of document.querySelectorAll('.felt')) {
    const s = f.firstElementChild;
    let n = 0;
    while (s.scrollWidth > f.clientWidth && n < 30) {
      f.style.fontSize = (parseFloat(getComputedStyle(f).fontSize) * 0.94) + 'px';
      n++;
    }
  }
</script>
</body>
</html>
