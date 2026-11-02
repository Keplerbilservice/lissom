<?php
/**
 * Morgenmeldingen til Monica (app/lib/morgenmelding.php).
 *
 * Eieren, 13. oktober 2026: SMS hver morgen kl. 07:30 med dagens kurs og
 * paameldte, og det som staar i kalenderen. Er det ikke noe, staar det ikke
 * noe. Teksten er godkjent ordrett.
 *
 * Kjor:  php tests/morgenmelding.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$ferdig = false;
register_shutdown_function(static function () use (&$ferdig): void {
    if (!$ferdig) { echo "\n  FEIL  testen stoppet foer den var ferdig\n"; exit(1); }
});

$ok = 0; $feil = 0;
function sjekk(string $navn, bool $v, string $mer = ''): void {
    global $ok, $feil;
    if ($v) { $ok++; echo "  OK    $navn\n"; }
    else { $feil++; echo "  FEIL  $navn" . ($mer !== '' ? "  — $mer" : '') . "\n"; }
}

echo "\n── Morgenmelding til Monica ─────────────────────────────────\n";

// ── Teksten, slik eieren godkjente den ──────────────────────────────
// Malene fra migrasjon 276, flettet slik Varsel::mal fletter dem.
$tekst = static function (array $kurs, array $notater): string {
    [$navn, $felter] = Morgenmelding::mal($kurs, $notater);
    $mal = (string) (DB::verdi('SELECT tekst FROM notification_templates WHERE navn = :n', ['n' => $navn]) ?? '');
    return Varsel::flett($mal, $felter);
};
sjekk('ingenting i dag: kort og hyggelig',
    $tekst([], []) === "God morgen, Monica! ☀️\nIngen kurs og ingenting i kalenderen i dag. Nyt dagen! 🤍", $tekst([], []));

$full = $tekst(
    [['tid' => '10:00', 'tittel' => 'Dreiekurs', 'antall' => 6], ['tid' => '18:00', 'tittel' => 'Date Night', 'antall' => 8]],
    [['tid' => '09:00', 'tekst' => 'Bestille leire']]
);
sjekk('kurs og kalender: hele meldingen', $full === "God morgen, Monica! ☀️\nHer er dagen din:\n\nKurs i dag:\n• 10:00 Dreiekurs – 6 påmeldte\n• 18:00 Date Night – 8 påmeldte\n\nI kalenderen:\n• 09:00 Bestille leire\n\nHa en nydelig dag i verkstedet! 🤍", $full);

$bareKurs = $tekst([['tid' => '10:00', 'tittel' => 'Dreiekurs', 'antall' => 1]], []);
sjekk('uten notater: «I kalenderen» staar ikke', !str_contains($bareKurs, 'I kalenderen'));
sjekk('én deltaker: «1 påmeldt»', str_contains($bareKurs, '– 1 påmeldt'));
$bareNotat = $tekst([], [['tid' => '09:00', 'tekst' => 'Ovnen til service']]);
sjekk('uten kurs: «Kurs i dag» staar ikke', !str_contains($bareNotat, 'Kurs i dag'));

// ── Dagen hentes fra basen ──────────────────────────────────────────
$tag = 'mm-' . bin2hex(random_bytes(4));
$oslo = new DateTimeZone('Europe/Oslo');
$utc = new DateTimeZone('UTC');
// En vinterdag og en sommerdag langt fram, saa ingenting annet ligger der.
$vinter = new DateTimeImmutable('2031-01-15 07:35', $oslo);
$somTid = static fn(string $lokal): string => (new DateTimeImmutable($lokal, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');

$kid = DB::settInn('courses', ['slug' => $tag, 'tittel' => 'Dreiekurs ' . $tag, 'type' => 'kurs',
    'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
$s1 = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $somTid('2031-01-15 10:00'), 'status' => 'planlagt']);
$sAvlyst = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $somTid('2031-01-15 12:00'), 'status' => 'avlyst']);
$sSent = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $somTid('2031-01-15 23:30'), 'status' => 'planlagt']);
$sMorgen = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $somTid('2031-01-16 00:30'), 'status' => 'planlagt']);
$book = static fn(int $okt, int $antall, string $status, ?string $til = null): int => DB::settInn('bookings', [
    'course_id' => $kid, 'course_session_id' => $okt, 'gjest_navn' => 'Test ' . $tag,
    'antall' => $antall, 'belop_ore' => 50000, 'status' => $status, 'reservert_til' => $til]);
$book($s1, 4, 'betalt');
$book($s1, 2, 'reservert', gmdate('Y-m-d H:i:s', time() + 3600));
$book($s1, 5, 'reservert', gmdate('Y-m-d H:i:s', time() - 3600)); // gaatt ut
$book($s1, 3, 'avbestilt');
$book($sAvlyst, 2, 'betalt');

$notat = DB::harTabell('kalender_notater')
    ? DB::settInn('kalender_notater', ['dato' => '2031-01-15', 'fra' => '09:00:00', 'tekst' => "Bestille\n leire"])
    : 0;

[$kurs, $notater] = Morgenmelding::dagen($vinter);
$mine = array_values(array_filter($kurs, static fn(array $k): bool => $k['tittel'] === 'Dreiekurs ' . $tag));
sjekk('to kurs i dag (avlyst og i morgen er ikke med)', count($mine) === 2, json_encode($mine, JSON_UNESCAPED_UNICODE));
sjekk('norsk klokkeslett om vinteren', ($mine[0]['tid'] ?? '') === '10:00' && ($mine[1]['tid'] ?? '') === '23:30');
sjekk('paameldte: betalt + gyldig reservert = 6', ($mine[0]['antall'] ?? -1) === 6);
if ($notat > 0) {
    sjekk('kalendernotatet er med, paa én linje', in_array(['tid' => '09:00', 'tekst' => 'Bestille leire'], $notater, true), json_encode($notater, JSON_UNESCAPED_UNICODE));
}
// Sommertid: 10:00 norsk er 08:00 UTC.
$s2 = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => '2031-07-10 08:00:00', 'status' => 'planlagt']);
[$sommer] = Morgenmelding::dagen(new DateTimeImmutable('2031-07-10 07:30', $oslo));
$mineS = array_values(array_filter($sommer, static fn(array $k): bool => $k['tittel'] === 'Dreiekurs ' . $tag));
sjekk('norsk klokkeslett om sommeren', ($mineS[0]['tid'] ?? '') === '10:00');

// ── Én gang i doegnet, fra 07:30 til 10:00 ──────────────────────────
$foer = DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'morgenmelding_sendt'");
$mid = DB::settInn('members', ['navn' => 'Monica ' . $tag, 'epost' => $tag . '@example.invalid', 'rolle' => 'admin', 'telefon' => '+4740000000']);
$linjer = [];
$si = static function (string $t) use (&$linjer): void { $linjer[] = $t; };
$merket = static fn(): string => (string) (DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'morgenmelding_sendt'") ?? '');

Morgenmelding::kjor($si, new DateTimeImmutable('2031-01-15 07:29', $oslo));
sjekk('07:29: ingenting', $linjer === [] && $merket() !== '2031-01-15');
Morgenmelding::kjor($si, new DateTimeImmutable('2031-01-15 07:30', $oslo));
sjekk('07:30: dagen merkes og meldingen gaar', count($linjer) === 1 && $merket() === '2031-01-15', implode(' | ', $linjer));
Morgenmelding::kjor($si, new DateTimeImmutable('2031-01-15 07:35', $oslo));
sjekk('07:35 samme dag: ikke én til', count($linjer) === 1);
Morgenmelding::kjor($si, new DateTimeImmutable('2031-01-16 10:00', $oslo));
sjekk('10:00 neste dag: for sent, ingenting', count($linjer) === 1 && $merket() === '2031-01-15');
sjekk('nummeret hentes fra en admin-bruker som heter Monica', Morgenmelding::nummer() !== '');

// ── Kursholderen (eieren 02.11.2026, «ja, sms er godkjent») ─────────
echo "\n── Kursholder i Monicas melding, og egen SMS til kursholderen ──\n";
$kurslinjer = [
    ['tid' => '10:00', 'tittel' => 'Dreiekurs', 'antall' => 4, 'holder' => ''],
    ['tid' => '18:00', 'tittel' => 'Kveldskurs keramikk', 'antall' => 6, 'holder' => 'Eirin'],
];
$medHolder = $tekst($kurslinjer, []);
sjekk('Monicas melding: «(Eirin)» bak kurset Eirin holder, ikke bak Monicas',
    $medHolder === "God morgen, Monica! ☀️\nHer er dagen din:\n\nKurs i dag:\n• 10:00 Dreiekurs – 4 påmeldte\n• 18:00 Kveldskurs keramikk – 6 påmeldte (Eirin)\n\nHa en nydelig dag i verkstedet! 🤍", $medHolder);
$khMal = (string) (DB::verdi("SELECT tekst FROM notification_templates WHERE navn = 'intern_morgenmelding_kursholder'") ?? '');
$khTekst = Varsel::flett($khMal, ['navn' => 'Eirin', 'dagen' => Morgenmelding::linjer([$kurslinjer[1]], false)]);
sjekk('kursholderens SMS: ordrett, uten navn i parentes',
    $khTekst === "God morgen, Eirin! ☀️\nDu holder kurs i dag:\n\n• 18:00 Kveldskurs keramikk – 6 påmeldte\n\nLykke til, og ha en fin dag i verkstedet! 🤍", $khTekst);
sjekk('malen er registrert under Tekst maler', str_contains((string) file_get_contents(__DIR__ . '/../app/lib/maler.php'), "'intern_morgenmelding_kursholder' => ["));

$hMonica = DB::settInn('kursholdere', ['navn' => 'Monica ' . $tag, 'telefon' => '+4740000001', 'aktiv' => 1]);
$hEirin = DB::settInn('kursholdere', ['navn' => 'Eirin ' . $tag, 'telefon' => '41506826', 'aktiv' => 1]);
$hUtenTlf = DB::settInn('kursholdere', ['navn' => 'Uten ' . $tag, 'telefon' => null, 'aktiv' => 1]);
$hSluttet = DB::settInn('kursholdere', ['navn' => 'Sluttet ' . $tag, 'telefon' => '+4740000003', 'aktiv' => 0]);
$kidE = DB::settInn('courses', ['slug' => $tag . '-e', 'tittel' => 'Kveldskurs ' . $tag, 'type' => 'kurs',
    'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert', 'kursholder_id' => $hEirin]);
$dag2 = '2031-01-22';
$okt = static fn(int $kurs, string $kl, ?int $holder): int => DB::settInn('course_sessions',
    ['course_id' => $kurs, 'start_tid' => $somTid("$dag2 $kl"), 'status' => 'planlagt', 'kursholder_id' => $holder]);
$oM = $okt($kid, '10:00', $hMonica);      // Monica
$oE = $okt($kidE, '18:00', $hEirin);      // Eirin paa datoen
$oK = $okt($kidE, '20:00', null);         // tom dato: arver Eirin fra kurset
$oU = $okt($kid, '12:00', $hUtenTlf);     // kursholder uten telefon
$oS = $okt($kid, '14:00', $hSluttet);     // sluttet: faller tilbake (ingen paa kurset)
DB::settInn('bookings', ['course_id' => $kidE, 'course_session_id' => $oE, 'gjest_navn' => 'Test ' . $tag,
    'antall' => 6, 'belop_ore' => 50000, 'status' => 'betalt']);

[$kurs2] = Morgenmelding::dagen(new DateTimeImmutable("$dag2 07:30", $oslo));
$mine2 = array_values(array_filter($kurs2, static fn(array $k): bool => str_contains($k['tittel'], $tag)));
$etter = static function (string $tid) use ($mine2): array {
    foreach ($mine2 as $k) { if ($k['tid'] === $tid) { return $k; } }
    return [];
};
sjekk('Monicas eget kurs: ingen parentes', ($etter('10:00')['holder'] ?? 'x') === '' && !empty($etter('10:00')['holder_monica']));
sjekk('Eirin paa datoen: (Eirin)', ($etter('18:00')['holder'] ?? '') === 'Eirin');
sjekk('tom dato arver kursholderen paa kurset', ($etter('20:00')['holder'] ?? '') === 'Eirin');
sjekk('kursholder som har sluttet staar ikke', ($etter('14:00')['holder'] ?? 'x') === '');
$mm = Morgenmelding::mal($mine2, [])[1]['dagen'] ?? '';
sjekk('Monicas linje: «– 6 påmeldte (Eirin)»', str_contains($mm, "18:00 Kveldskurs {$tag} – 6 påmeldte (Eirin)"), $mm);

$kh = Morgenmelding::kursholdere($mine2);
sjekk('bare Eirin faar egen SMS (ikke Monica, ikke uten telefon, ikke sluttet)',
    count($kh) === 1 && ($kh[0]['fornavn'] ?? '') === 'Eirin' && ($kh[0]['telefon'] ?? '') === normaliser_telefon('41506826'),
    json_encode($kh, JSON_UNESCAPED_UNICODE));
sjekk('Eirin faar bare sine egne kurs', array_column($kh[0]['kurs'] ?? [], 'tid') === ['18:00', '20:00']);
sjekk('dag uten kurs for Eirin: ingen SMS til henne',
    Morgenmelding::kursholdere(array_values(array_filter($mine2, static fn(array $k): bool => $k['tid'] === '10:00'))) === []);

// kjor: én gang den dagen, ogsaa for kursholderen.
$linjer2 = [];
$si2 = static function (string $t) use (&$linjer2): void { $linjer2[] = $t; };
Morgenmelding::kjor($si2, new DateTimeImmutable("$dag2 07:30", $oslo));
$tilEirin = static fn(): int => count(array_filter($linjer2, static fn(string $t): bool => str_starts_with($t, 'Morgenmelding til Eirin')));
sjekk('07:30: Monica og Eirin', $tilEirin() === 1 && count($linjer2) === 2, implode(' | ', $linjer2));
Morgenmelding::kjor($si2, new DateTimeImmutable("$dag2 08:05", $oslo));
sjekk('cron igjen samme dag: ingen dobbel-SMS', $tilEirin() === 1 && count($linjer2) === 2);

// ── Rydd ────────────────────────────────────────────────────────────
DB::kjor('DELETE FROM bookings WHERE course_id = :k', ['k' => $kidE]);
DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $kidE]);
DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kidE]);
DB::kjor('DELETE FROM course_sessions WHERE id IN (:a, :b, :c)', ['a' => $oM, 'b' => $oU, 'c' => $oS]);
DB::kjor('DELETE FROM kursholdere WHERE id IN (:a, :b, :c, :d)', ['a' => $hMonica, 'b' => $hEirin, 'c' => $hUtenTlf, 'd' => $hSluttet]);
DB::kjor('DELETE FROM bookings WHERE course_id = :k', ['k' => $kid]);
DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $kid]);
DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kid]);
DB::kjor("DELETE FROM notifications WHERE ref_type = 'morgenmelding'");
DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $mid]);
if ($notat > 0) { DB::kjor('DELETE FROM kalender_notater WHERE id = :n', ['n' => $notat]); }
if ($foer === null) {
    DB::kjor("DELETE FROM innstillinger WHERE nokkel = 'morgenmelding_sendt'");
} else {
    DB::kjor("UPDATE innstillinger SET verdi = :v WHERE nokkel = 'morgenmelding_sendt'", ['v' => $foer]);
}

$ferdig = true;
echo "\n  {$ok} ok, {$feil} feil\n";
exit($feil > 0 ? 1 : 0);
