<?php
/**
 * Meldingene, eierens valg 3. oktober 2026 (skjema YbLC6SsCPPVP99toBZGLhh).
 *
 *   1. Google-anmeldelsen gaar neste dag kl. 10 norsk tid etter kurset —
 *      fast klokke, kurs etter midnatt, sommer- og vintertid, tre doegn.
 *   2. Migrasjon 251 slaar av tolv maler og kan kjoeres to ganger.
 *   3. Malene som er av sendes ikke, og gir ingen «Varsel må sendes for hånd».
 *   4. Ventelistebekreftelsen: «kontakter vi deg».
 *
 * Kjor:  php tests/meldinger-0310.php
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

$utc  = new DateTimeZone('UTC');
$oslo = new DateTimeZone('Europe/Oslo');
// Norsk tid → UTC-streng, slik den staar i course_sessions.
$somUtc = static fn(string $norsk): string
    => (new DateTimeImmutable($norsk, $oslo))->setTimezone($utc)->format('Y-m-d H:i:s');
$klokke = static fn(string $norsk): DateTimeImmutable
    => (new DateTimeImmutable($norsk, $oslo))->setTimezone($utc);
$iOslo = static fn(DateTimeImmutable $d): string => $d->setTimezone($oslo)->format('Y-m-d H:i');

echo "\n── Google-anmeldelse: neste dag kl. 10 ──────────────────────\n";

// Kurs som slutter 21:00 lørdag 3. oktober (sommertid).
$slutt = $somUtc('2026-10-03 21:00');
sjekk('slutt 21:00 → sendes 10:00 neste dag', $iOslo(Booking::anmeldelseSendetid($slutt)) === '2026-10-04 10:00',
    $iOslo(Booking::anmeldelseSendetid($slutt)));
sjekk('ikke 3 timer etter (00:00)', !Booking::anmeldelseKlar($slutt, $klokke('2026-10-04 00:00')));
sjekk('ikke 09:59 neste dag', !Booking::anmeldelseKlar($slutt, $klokke('2026-10-04 09:59')));
sjekk('klar 10:00 neste dag', Booking::anmeldelseKlar($slutt, $klokke('2026-10-04 10:00')));
sjekk('klar 11:00 neste dag (cron hver time)', Booking::anmeldelseKlar($slutt, $klokke('2026-10-04 11:00')));
sjekk('fortsatt klar to dager etter', Booking::anmeldelseKlar($slutt, $klokke('2026-10-06 20:59')));
sjekk('aldri eldre enn 3 døgn', !Booking::anmeldelseKlar($slutt, $klokke('2026-10-06 21:00')));

// Formiddagskurs: neste dag, ikke samme ettermiddag.
$form = $somUtc('2026-10-03 12:00');
sjekk('slutt 12:00 → 10:00 neste dag', $iOslo(Booking::anmeldelseSendetid($form)) === '2026-10-04 10:00');
sjekk('ikke samme dag kl. 15', !Booking::anmeldelseKlar($form, $klokke('2026-10-03 15:00')));

// Kurs som slutter etter midnatt: regnes til kursdagen (kvelden foer).
$natt = $somUtc('2026-10-04 00:30');
sjekk('slutt 00:30 → 10:00 samme formiddag', $iOslo(Booking::anmeldelseSendetid($natt)) === '2026-10-04 10:00',
    $iOslo(Booking::anmeldelseSendetid($natt)));
sjekk('slutt 00:30: ikke 09:59', !Booking::anmeldelseKlar($natt, $klokke('2026-10-04 09:59')));
sjekk('slutt 00:30: klar 10:00', Booking::anmeldelseKlar($natt, $klokke('2026-10-04 10:00')));

// Vintertid begynner søndag 25. oktober 2026 kl. 03:00 → 02:00.
$hoest = $somUtc('2026-10-24 21:00');
$s = Booking::anmeldelseSendetid($hoest);
sjekk('overgang til vintertid: 10:00 norsk tid', $iOslo($s) === '2026-10-25 10:00', $iOslo($s));
sjekk('… som er 09:00 UTC', $s->format('H:i') === '09:00', $s->format('H:i'));
$natt2 = $somUtc('2026-10-25 01:30');
sjekk('etter midnatt natt til vintertid: 10:00 samme formiddag', $iOslo(Booking::anmeldelseSendetid($natt2)) === '2026-10-25 10:00');

// Sommertid begynner søndag 28. mars 2027 kl. 02:00 → 03:00.
$vaar = $somUtc('2027-03-27 21:00');
$s = Booking::anmeldelseSendetid($vaar);
sjekk('overgang til sommertid: 10:00 norsk tid', $iOslo($s) === '2027-03-28 10:00', $iOslo($s));
sjekk('… som er 08:00 UTC', $s->format('H:i') === '08:00', $s->format('H:i'));

// Vintertid hele veien.
$vinter = $somUtc('2026-12-10 21:00');
sjekk('vinter: 10:00 norsk tid = 09:00 UTC', Booking::anmeldelseSendetid($vinter)->format('Y-m-d H:i') === '2026-12-11 09:00');

// Selve jobben: kurs som sluttet for en halvtime siden faar ingenting naa;
// kurs som sluttet for 40 timer siden faar den (alltid etter kl. 10 dagen etter).
echo "\n── Selve jobben (cron anmeldelser) ──────────────────────────\n";
if (DB::harKolonne('course_sessions', 'anmeldelse_sendt_at')) {
    $malFoer   = DB::en("SELECT aktiv FROM notification_templates WHERE navn = 'anmeldelse'");
    $lenkeFoer = DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'anmeldelse_lenke'");
    DB::kjor("UPDATE notification_templates SET aktiv = 1 WHERE navn = 'anmeldelse'");
    DB::kjor("INSERT INTO innstillinger (nokkel, verdi) VALUES ('anmeldelse_lenke', 'https://eksempel.test/anmeld-0310')
              ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)");

    $kurs = DB::settInn('courses', ['slug' => 'test-meldinger-0310-' . bin2hex(random_bytes(3)),
        'tittel' => 'Anmeldelsestid', 'type' => 'kurs', 'status' => 'publisert', 'pris_ore' => 10000, 'kapasitet' => 8]);
    $okt = static function (int $minSiden) use ($kurs): int {
        $id = DB::settInn('course_sessions', ['course_id' => $kurs,
            'start_tid' => gmdate('Y-m-d H:i:s', time() - ($minSiden + 180) * 60),
            'slutt_tid' => gmdate('Y-m-d H:i:s', time() - $minSiden * 60), 'kapasitet' => 8]);
        DB::settInn('bookings', ['course_id' => $kurs, 'course_session_id' => $id, 'antall' => 1,
            'gjest_navn' => 'Tid Test', 'gjest_epost' => 'tid-' . $id . '@example.com', 'belop_ore' => 0, 'status' => 'betalt']);
        return $id;
    };
    $fersk  = $okt(30);
    $igaar  = $okt(40 * 60);
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/cron.php') . ' anmeldelser 2>&1', $ut, $kode);
    sjekk('jobben kjørte (exit 0)', $kode === 0, implode(' | ', $ut));
    $sendt = static fn(int $id): bool
        => DB::verdi('SELECT anmeldelse_sendt_at FROM course_sessions WHERE id = :i', ['i' => $id]) !== null;
    $tilE = static fn(int $id): int
        => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE mal = 'anmeldelse' AND mottaker = :m", ['m' => 'tid-' . $id . '@example.com']);
    sjekk('kurs som sluttet for 30 min siden: ikke ennå', !$sendt($fersk) && $tilE($fersk) === 0);
    sjekk('kurs som sluttet for 40 timer siden: sendt', $sendt($igaar) && $tilE($igaar) === 1);
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/cron.php') . ' anmeldelser 2>&1');
    sjekk('én gang per kursdato (ny kjøring sender ikke igjen)', $tilE($igaar) === 1);

    // Rydder
    DB::kjor("DELETE FROM notifications WHERE mottaker LIKE 'tid-%@example.com'");
    DB::kjor('DELETE FROM bookings WHERE course_id = :c', ['c' => $kurs]);
    DB::kjor('DELETE FROM course_sessions WHERE course_id = :c', ['c' => $kurs]);
    DB::kjor('DELETE FROM courses WHERE id = :c', ['c' => $kurs]);
    DB::kjor("UPDATE notification_templates SET aktiv = :a WHERE navn = 'anmeldelse'", ['a' => (int) ($malFoer['aktiv'] ?? 0)]);
    if ($lenkeFoer === null) {
        DB::kjor("DELETE FROM innstillinger WHERE nokkel = 'anmeldelse_lenke'");
    } else {
        DB::kjor("UPDATE innstillinger SET verdi = :v WHERE nokkel = 'anmeldelse_lenke'", ['v' => $lenkeFoer]);
    }
}

echo "\n── Migrasjon 251: tolv maler av, venteliste-tekst ───────────\n";
$avNavn = ['butikkordre_pakke', 'intern_dugnad_ferdig', 'intern_dugnad_sporsmal', 'intern_gave_lost_inn',
    'intern_gave_pakkes', 'intern_nytt_medlem', 'intern_nytt_medlem_sms', 'intern_ny_foresporsel',
    'intern_ny_pamelding', 'intern_ny_vare', 'ordre_annullert', 'soknad_godkjent_sms'];
$liste = "'" . implode("','", $avNavn) . "'";
$foer = DB::alle("SELECT navn, aktiv, tekst, avsnitt FROM notification_templates WHERE navn IN ($liste, 'venteliste_satt', 'anmeldelse')");
sjekk('alle tolv malene finnes', count(array_filter($foer, static fn($r) => in_array($r['navn'], $avNavn, true))) === 12,
    (string) count($foer));

$gammel = 'Skulle det bli en ledig plass, sender vi deg en e-post eller forsøker å ringe deg.';
$ny     = 'Skulle det bli en ledig plass, kontakter vi deg.';
// Stiller basen som foer migrasjonen: malene paa, gammel tekst.
DB::kjor("UPDATE notification_templates SET aktiv = 1 WHERE navn IN ($liste)");
DB::kjor("UPDATE notification_templates SET aktiv = 1 WHERE navn = 'anmeldelse'");
DB::kjor("UPDATE notification_templates SET avsnitt = :a WHERE navn = 'venteliste_satt'",
    ['a' => json_encode(['Hei {navn}.', $gammel . ' Du betaler ingenting før en plass eventuelt er bekreftet.', 'Hilsen Lissom Keramikk.'], JSON_UNESCAPED_UNICODE)]);

$sql = (string) file_get_contents(dirname(__DIR__) . '/db/migrations/251_meldinger_av_og_venteliste.sql');
sjekk('migrasjonen er merket «godkjent av eieren»', str_starts_with($sql, '-- godkjent av eieren: 2026-10-03'));
$kjor = static function () use ($sql): ?string {
    try { DB::kobling()->exec($sql); return null; } catch (Throwable $e) { return $e->getMessage(); }
};
$f1 = $kjor();
sjekk('første kjøring uten feil', $f1 === null, (string) $f1);
$tilstand = static fn(): array => DB::alle("SELECT navn, aktiv, tekst, avsnitt FROM notification_templates WHERE navn IN ($liste, 'venteliste_satt', 'anmeldelse') ORDER BY navn");
$etter1 = $tilstand();
$f2 = $kjor();
sjekk('andre kjøring uten feil', $f2 === null, (string) $f2);
sjekk('andre kjøring endrer ingenting (idempotent)', $tilstand() === $etter1);

$aktiv = array_column($etter1, 'aktiv', 'navn');
sjekk('alle tolv står av', array_sum(array_map(static fn($n) => (int) ($aktiv[$n] ?? 1), $avNavn)) === 0);
sjekk('andre maler røres ikke (anmeldelse står på)', (int) ($aktiv['anmeldelse'] ?? 0) === 1);
$vl = DB::en("SELECT tekst, avsnitt FROM notification_templates WHERE navn = 'venteliste_satt'");
sjekk('venteliste: ny setning', str_contains((string) $vl['avsnitt'], $ny), (string) $vl['avsnitt']);
sjekk('venteliste: gammel setning borte', !str_contains((string) $vl['avsnitt'], $gammel));
sjekk('venteliste: resten likt', str_contains((string) $vl['avsnitt'], $ny . ' Du betaler ingenting før en plass eventuelt er bekreftet.')
    && str_contains((string) $vl['avsnitt'], 'Hilsen Lissom Keramikk.'));
sjekk('venteliste: avsnittene er fortsatt gyldig JSON', is_array(json_decode((string) $vl['avsnitt'], true)));

echo "\n── Maler som er av sendes ikke ───────────────────────────────\n";
$antall = static fn(): int => (int) DB::verdi('SELECT COUNT(*) FROM notifications');
$forHaand = static fn(): int => (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE emne LIKE 'Varsel må sendes for hånd%'");
$n0 = $antall(); $h0 = $forHaand();
foreach (['intern_dugnad_ferdig', 'intern_dugnad_sporsmal', 'intern_gave_lost_inn', 'intern_gave_pakkes',
          'intern_nytt_medlem', 'intern_ny_foresporsel', 'intern_ny_pamelding', 'intern_ny_vare'] as $m) {
    sjekk("$m: malTilAdmin gir 0", Varsel::malTilAdmin($m, ['navn' => 'Test Testesen'], 'test', 9100310) === 0);
}
Varsel::mal('intern_nytt_medlem_sms', ['telefon' => '+4790000000'], ['navn' => 'Test'], 'test', 9100310);
Varsel::mal('butikkordre_pakke', ['epost' => 'av-0310@example.com'], ['navn' => 'Test'], 'test', 9100310);
Varsel::mal('ordre_annullert', ['epost' => 'av-0310@example.com'], ['navn' => 'Test'], 'test', 9100310);
Varsel::mal('soknad_godkjent_sms', ['telefon' => '+4790000000'], ['navn' => 'Test'], 'test', 9100310);
sjekk('ingen meldinger lagt i kø', $antall() === $n0, ($antall() - $n0) . ' nye');
sjekk('ingen «Varsel må sendes for hånd»', $forHaand() === $h0);

// Setter basen tilbake slik den sto.
foreach ($foer as $r) {
    DB::oppdater('notification_templates', ['aktiv' => $r['aktiv'], 'tekst' => $r['tekst'], 'avsnitt' => $r['avsnitt']], ['navn' => $r['navn']]);
}

$ferdig = true;
echo "\n── $ok gikk gjennom, $feil feilet\n";
exit($feil > 0 ? 1 : 0);
