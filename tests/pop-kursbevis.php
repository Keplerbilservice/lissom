<?php
/**
 * Paint on Pots gir ikke kursbevis (eieren, 13. oktober 2026: «man skal ikke
 * faa kursbevis for paint on pots», valgte «Bare Paint on Pots»).
 *
 * Kjor:  php tests/pop-kursbevis.php
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

echo "\n── Paint on Pots uten kursbevis ─────────────────────────────\n";

$tag = 'popbevis-' . bin2hex(random_bytes(4));
$kurs = [];
$lag = static function (string $tittel, string $slug) use (&$kurs): int {
    $kid = DB::settInn('courses', ['slug' => $slug, 'tittel' => $tittel, 'type' => 'kurs', 'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
    $kurs[] = $kid;
    $okt = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => gmdate('Y-m-d H:i:s', time() - 2 * 86400), 'status' => 'planlagt']);
    return DB::settInn('bookings', ['course_id' => $kid, 'course_session_id' => $okt, 'gjest_navn' => 'Test ' . $kid,
        'antall' => 1, 'belop_ore' => 50000, 'status' => 'betalt']);
};
$pop = $lag('Paint on Pots', $tag . '-pop');
$dreie = $lag('Dreiekurs ' . $tag, $tag . '-dreie');

sjekk('Paint on Pots kjennes igjen paa navnet', Booking::utenKursbevis((int) DB::verdi('SELECT course_id FROM bookings WHERE id = :i', ['i' => $pop])));
sjekk('Paint on Pots: ingen kursbevislenke', Booking::bevisLenke($pop) === null);
$lenke = Booking::bevisLenke($dreie);
sjekk('vanlig kurs: kursbeviset finnes som foer', is_string($lenke) && str_contains($lenke, '/api/kursbevis.php?booking=' . $dreie), (string) $lenke);
sjekk('Min side og kursbevissida spoer det samme', str_contains((string) file_get_contents(dirname(__DIR__) . '/api/mine-plasser.php'), 'Booking::utenKursbevis(')
    && str_contains((string) file_get_contents(dirname(__DIR__) . '/api/kursbevis.php'), 'Booking::utenKursbevis('));

foreach ($kurs as $kid) {
    DB::kjor('DELETE FROM bookings WHERE course_id = :k', ['k' => $kid]);
    DB::kjor('DELETE FROM course_sessions WHERE course_id = :k', ['k' => $kid]);
    DB::kjor('DELETE FROM courses WHERE id = :k', ['k' => $kid]);
}
$ferdig = true;
echo "\n  {$ok} ok, {$feil} feil\n";
exit($feil > 0 ? 1 : 0);
