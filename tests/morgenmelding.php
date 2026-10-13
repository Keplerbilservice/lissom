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
sjekk('ingenting i dag: kort og hyggelig',
    Morgenmelding::tekst([], []) === "God morgen, Monica! ☀️\nIngen kurs og ingenting i kalenderen i dag. Nyt dagen! 🤍");

$full = Morgenmelding::tekst(
    [['tid' => '10:00', 'tittel' => 'Dreiekurs', 'antall' => 6], ['tid' => '18:00', 'tittel' => 'Date Night', 'antall' => 8]],
    [['tid' => '09:00', 'tekst' => 'Bestille leire']]
);
sjekk('kurs og kalender: hele meldingen', $full === "God morgen, Monica! ☀️\nHer er dagen din:\n\nKurs i dag:\n• 10:00 Dreiekurs – 6 påmeldte\n• 18:00 Date Night – 8 påmeldte\n\nI kalenderen:\n• 09:00 Bestille leire\n\nHa en nydelig dag i verkstedet! 🤍", $full);

$bareKurs = Morgenmelding::tekst([['tid' => '10:00', 'tittel' => 'Dreiekurs', 'antall' => 1]], []);
sjekk('uten notater: «I kalenderen» staar ikke', !str_contains($bareKurs, 'I kalenderen'));
sjekk('én deltaker: «1 påmeldt»', str_contains($bareKurs, '– 1 påmeldt'));
$bareNotat = Morgenmelding::tekst([], [['tid' => '09:00', 'tekst' => 'Ovnen til service']]);
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

// ── Rydd ────────────────────────────────────────────────────────────
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
