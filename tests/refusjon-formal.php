<?php
/**
 * L-1 og L-3 fra pengeflyt-revisjonen (eieren, 2. oktober 2026), mot databasen.
 *
 *   L-1  Et forlatt oppgraderingsforsoek (Vipps EXPIRED/STOPPED paa en avtale
 *        som aldri ble godkjent) gjor ikke medlemmet «oppsagt». avslutt()
 *        gjor det ikke heller naar en annen avtale loeper.
 *   L-3  Full refusjon gjor opp kjoepet: gavekort annulleres (nektes naar det
 *        er brukt), timepakken refunderes, nettordren legger varene tilbake,
 *        en engangsbetaling for medlemskap stopper det. Delrefusjon roerer
 *        ikke kjoepet, men logges.
 *
 * Vipps er en falsk klasse her — ingen kall gaar ut. Alle beloep i oere.
 *
 * Kjor:  php tests/refusjon-formal.php
 */
declare(strict_types=1);

require __DIR__ . '/nettleser/testdatabase.php';
krev_testdatabase(dirname(__DIR__));

/** Falsk Vipps: husker kallene, svarer det testen ber om. */
final class Vipps
{
    public const MINSTE_BELOP_ORE = 100;
    public static array $kall = [];
    /** @var array<string,string> avtale-id => status Vipps svarer */
    public static array $avtaler = [];
    public static bool $feiler = false;
    public static function refunder(string $ref, int $belop, string $op): array
    {
        self::$kall[] = ['refunder', $ref, $belop, $op];
        if (self::$feiler) { throw new RuntimeException('Falsk Vipps: refusjonen avvist'); }
        return ['ok' => true];
    }
    public static function refunderTrekk(string $a, string $t, int $belop, string $ref, string $op): array
    { self::$kall[] = ['refunderTrekk', $a, $t, $belop, $op]; return ['ok' => true]; }
    public static function hentAvtale(string $id): array
    { self::$kall[] = ['hentAvtale', $id]; return ['id' => $id, 'status' => self::$avtaler[$id] ?? 'PENDING']; }
    public static function stoppAvtale(string $id): void { self::$kall[] = ['stoppAvtale', $id]; }
    public static function trekkPaaAvtale(string $id, bool $kast = false): array { return []; }
    public static function avbryt(string $ref): void { self::$kall[] = ['avbryt', $ref]; }
    public static function nyReferanse(string $p = 'LIS'): string { return $p . '-T' . bin2hex(random_bytes(6)); }
    public static function uuid(): string { return bin2hex(random_bytes(16)); }
}

require dirname(__DIR__) . '/app/bootstrap.php';

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

$tag = 'l13-' . bin2hex(random_bytes(3));
$rydd = ['members' => [], 'payments' => [], 'gift_cards' => [], 'orders' => [], 'products' => [], 'subscriptions' => [], 'timepakker' => []];
$medlem = static function (string $navn, string $status, ?string $plan) use ($tag, &$rydd): int {
    $id = DB::settInn('members', ['navn' => $navn, 'epost' => $tag . '-' . bin2hex(random_bytes(3)) . '@lissom.test',
        'rolle' => 'medlem', 'status' => $status, 'medlemskap_type' => $plan, 'start_dato' => gmdate('Y-m-d')]);
    $rydd['members'][] = $id; return $id;
};
$betaling = static function (array $f) use (&$rydd): int {
    $id = DB::settInn('payments', $f + ['vipps_reference' => Vipps::nyReferanse('T'), 'type' => 'epayment',
        'status' => 'betalt', 'idempotency_key' => Vipps::uuid()]);
    $rydd['payments'][] = $id; return $id;
};
$rad = static fn(string $t, int $id): ?array => DB::en("SELECT * FROM {$t} WHERE id = :i", ['i' => $id]);
$logget = static fn(string $h, string $type, int $id): bool => DB::verdi(
    'SELECT id FROM audit_log WHERE handling = :h AND objekt_type = :t AND objekt_id = :i LIMIT 1',
    ['h' => $h, 't' => $type, 'i' => $id]) !== null;

$prove = (string) DB::verdi('SELECT navn FROM membership_plans WHERE engangs = 1 AND aktiv = 1 ORDER BY sortering LIMIT 1');
$liten = (string) DB::verdi("SELECT navn FROM membership_plans WHERE engangs = 0 AND aktiv = 1 AND timer IS NOT NULL ORDER BY timer LIMIT 1");
$stor  = (string) DB::verdi("SELECT navn FROM membership_plans WHERE engangs = 0 AND aktiv = 1 AND timer IS NOT NULL ORDER BY timer DESC LIMIT 1");

try {
    // ── L-1: oppgradering_forlatt ──────────────────────────────────────
    echo "\n── L-1: oppgradering_forlatt ─────────────────────────────────\n";
    foreach (['EXPIRED' => 'utlopt', 'STOPPED' => 'stoppet'] as $vipps => $vaar) {
        $m = $medlem("Oppgraderer $vipps", 'aktiv', $liten);
        $gammel = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $liten, 'pris_ore' => 179000,
            'status' => 'aktiv', 'vipps_agreement_id' => "agr-$tag-g-$vipps", 'neste_trekk' => gmdate('Y-m-d', time() + 20 * 86400)]);
        $forsok = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $stor, 'pris_ore' => 259000,
            'status' => 'venter', 'vipps_agreement_id' => "agr-$tag-n-$vipps"]);
        $rydd['subscriptions'][] = $gammel; $rydd['subscriptions'][] = $forsok;
        Vipps::$avtaler["agr-$tag-n-$vipps"] = $vipps;
        $svar = Medlemskap::oppdaterFraVipps($rad('subscriptions', $forsok));
        sjekk("Vipps $vipps paa forsoeket: forsoeket blir «{$vaar}»", $svar === $vaar && $rad('subscriptions', $forsok)['status'] === $vaar);
        sjekk("… medlemmet staar fortsatt aktiv paa $liten (kr 1 790/mnd loeper)",
            $rad('members', $m)['status'] === 'aktiv' && $rad('members', $m)['medlemskap_type'] === $liten,
            (string) $rad('members', $m)['status']);
        sjekk('… den gamle avtalen er urort (aktiv, trekkdato staar)',
            $rad('subscriptions', $gammel)['status'] === 'aktiv' && $rad('subscriptions', $gammel)['neste_trekk'] !== null);
    }
    // Kontroll: den loepende avtalen selv stoppes i Vipps, ingen annen loeper.
    $m = $medlem('Stopper selv', 'aktiv', $liten);
    $a = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $liten, 'pris_ore' => 179000,
        'status' => 'aktiv', 'vipps_agreement_id' => "agr-$tag-selv"]);
    $rydd['subscriptions'][] = $a;
    Vipps::$avtaler["agr-$tag-selv"] = 'STOPPED';
    Medlemskap::oppdaterFraVipps($rad('subscriptions', $a));
    sjekk('kontroll: loepende avtale STOPPED i Vipps, ingen annen → «oppsagt»', $rad('members', $m)['status'] === 'oppsagt');

    // ── L-1: avslutt_med_annen_aktiv ───────────────────────────────────
    echo "\n── L-1: avslutt_med_annen_aktiv ──────────────────────────────\n";
    $m = $medlem('Byttet plan', 'aktiv', $stor);
    $gammel = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $liten, 'pris_ore' => 179000, 'status' => 'aktiv',
        'vipps_agreement_id' => "agr-$tag-av-g", 'slutter' => gmdate('Y-m-d', time() - 86400)]);
    $ny = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $stor, 'pris_ore' => 259000, 'status' => 'aktiv',
        'vipps_agreement_id' => "agr-$tag-av-n"]);
    $rydd['subscriptions'][] = $gammel; $rydd['subscriptions'][] = $ny;
    Medlemskap::avslutt($rad('subscriptions', $gammel));
    sjekk('avslutt(): den gamle stoppes', $rad('subscriptions', $gammel)['status'] === 'stoppet');
    sjekk('… medlemmet staar aktiv paa den nye (kr 2 590/mnd)', $rad('members', $m)['status'] === 'aktiv'
        && $rad('subscriptions', $ny)['status'] === 'aktiv');
    $m = $medlem('Slutter', 'aktiv', $liten);
    $a = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $liten, 'pris_ore' => 179000, 'status' => 'aktiv',
        'vipps_agreement_id' => "agr-$tag-av-e", 'slutter' => gmdate('Y-m-d', time() - 86400)]);
    $rydd['subscriptions'][] = $a;
    Medlemskap::avslutt($rad('subscriptions', $a));
    sjekk('kontroll: avslutt() uten annen avtale → «oppsagt»', $rad('members', $m)['status'] === 'oppsagt');

    // ── L-3: gavekortkjop_refundert ────────────────────────────────────
    echo "\n── L-3: gavekortkjop_refundert ───────────────────────────────\n";
    $kort = static function (int $pid, int $saldo, int $verdi = 50000) use ($tag, &$rydd): int {
        $id = DB::settInn('gift_cards', ['kode' => strtoupper($tag) . '-' . bin2hex(random_bytes(3)), 'opprinnelig_ore' => $verdi,
            'saldo_ore' => $saldo, 'gyldig_til' => gmdate('Y-m-d', time() + 365 * 86400), 'payment_id' => $pid > 0 ? $pid : null,
            'status' => $saldo > 0 ? 'aktivt' : 'brukt']);
        $rydd['gift_cards'][] = $id; return $id;
    };
    $p = $betaling(['formal' => 'gavekort', 'belop_ore' => 50000]);
    $k = $kort($p, 50000);
    $foer = count(Vipps::$kall);
    $r = Booking::refunderBetaling($p);
    sjekk('ubrukt kort kr 500: hele 50000 oere refundert', $r['belop'] === 50000 && $r['gjenstaar'] === 0
        && end(Vipps::$kall)[2] === 50000 && count(Vipps::$kall) === $foer + 1);
    sjekk('… kortet er annullert med saldo 0', $rad('gift_cards', $k)['status'] === 'annullert' && (int) $rad('gift_cards', $k)['saldo_ore'] === 0);
    sjekk('… betalingen staar refundert (refundert_ore 50000)', $rad('payments', $p)['status'] === 'refundert' && (int) $rad('payments', $p)['refundert_ore'] === 50000);
    sjekk('… annulleringen er logget', $logget('gavekort_annullert_ved_refusjon', 'payment', $p));
    sjekk('… kortet kan ikke brukes', Booking::finnGavekort((string) $rad('gift_cards', $k)['kode']) === null);

    $p = $betaling(['formal' => 'gavekort', 'belop_ore' => 50000]);
    $k = $kort($p, 20000);
    $foer = count(Vipps::$kall);
    $melding = '';
    try { Booking::refunderBetaling($p); } catch (RuntimeException $e) { $melding = $e->getMessage() . '|' . $e->getCode(); }
    sjekk('brukt kort (kr 200 igjen av kr 500): full refusjon nektes med tydelig melding',
        str_contains($melding, 'er brukt') && str_ends_with($melding, '|422'), $melding);
    sjekk('… ingen Vipps-kall, ingen journalrad, kortet urort (saldo 20000)', count(Vipps::$kall) === $foer
        && DB::verdi('SELECT id FROM payment_refunds WHERE payment_id = :p', ['p' => $p]) === null
        && $rad('gift_cards', $k)['status'] === 'aktivt' && (int) $rad('gift_cards', $k)['saldo_ore'] === 20000);

    // Kort som er paa vei i et kjoep (reservert, ikke trukket ennaa).
    $p = $betaling(['formal' => 'gavekort', 'belop_ore' => 50000]);
    $k = $kort($p, 50000);
    $betaling(['formal' => 'booking', 'belop_ore' => 10000, 'status' => 'venter', 'gavekort_id' => $k, 'gavekort_ore' => 30000]);
    $melding = '';
    try { Booking::refunderBetaling($p); } catch (RuntimeException $e) { $melding = $e->getMessage(); }
    sjekk('kort i et kjoep paa vei i Vipps: full refusjon nektes', str_contains($melding, 'på vei'), $melding);

    $p = $betaling(['formal' => 'gavekort', 'belop_ore' => 50000]);
    $k = $kort($p, 50000);
    $r = Booking::refunderBetaling($p, 10000);
    sjekk('delrefusjon 10000 av 50000: kortet urort (aktivt, saldo 50000)', $r['gjenstaar'] === 40000
        && $rad('gift_cards', $k)['status'] === 'aktivt' && (int) $rad('gift_cards', $k)['saldo_ore'] === 50000);
    sjekk('… og det er logget at kjoepet ikke er gjort opp', $logget('refusjon_delvis_formal_uendret', 'payment', $p));
    $r = Booking::refunderBetaling($p);
    sjekk('resten 40000 etterpaa (sum 50000): kortet annulleres da', $r['gjenstaar'] === 0 && $rad('gift_cards', $k)['status'] === 'annullert');

    // ── L-3: timepakke_refundert ───────────────────────────────────────
    echo "\n── L-3: timepakke_refundert ──────────────────────────────────\n";
    $m = $medlem('Timepakke', 'aktiv', $liten);
    $p = $betaling(['formal' => 'medlemskap', 'member_id' => $m, 'belop_ore' => 80000]);
    $tp = DB::settInn('timepakker', ['member_id' => $m, 'timer' => 6, 'pris_ore' => 80000, 'status' => 'betalt',
        'payment_id' => $p, 'betalt_at' => gmdate('Y-m-d H:i:s')]);
    $rydd['timepakker'][] = $tp;
    sjekk('foer: pakken gir 360 minutter til gode', Timepakke::tilgodeMin($m) === 360, (string) Timepakke::tilgodeMin($m));
    $r = Booking::refunderBetaling($p);
    sjekk('full refusjon 80000 oere: pakken staar «refundert»', $r['belop'] === 80000 && $rad('timepakker', $tp)['status'] === 'refundert');
    sjekk('… timene er borte (0 minutter til gode)', Timepakke::tilgodeMin($m) === 0);
    sjekk('… medlemskapet roeres ikke (aktiv)', $rad('members', $m)['status'] === 'aktiv');

    // ── L-3: nettordre, varer tilbake ──────────────────────────────────
    echo "\n── L-3: nettordre_refundert ──────────────────────────────────\n";
    $vare = DB::settInn('products', ['tittel' => "Vare $tag", 'pris_ore' => 15000, 'lager' => 3]);
    $rydd['products'][] = $vare;
    $p = $betaling(['formal' => 'ordre', 'belop_ore' => 30000]);
    $o = DB::settInn('orders', ['ordrenr' => 'B-' . strtoupper(bin2hex(random_bytes(4))), 'kunde_navn' => 'Kunde', 'sum_ore' => 30000,
        'status' => 'betalt', 'payment_id' => $p]);
    $rydd['orders'][] = $o;
    DB::settInn('order_lines', ['order_id' => $o, 'product_id' => $vare, 'tittel' => 'Vare', 'antall' => 2, 'pris_ore' => 15000]);
    $r = Booking::refunderBetaling($p);
    sjekk('full refusjon 30000 oere: ordren refundert', $r['gjenstaar'] === 0 && $rad('orders', $o)['status'] === 'refundert');
    sjekk('… 2 varer tilbake paa lager (3 → 5)', (int) $rad('products', $vare)['lager'] === 5, (string) $rad('products', $vare)['lager']);
    Booking::refunderBetaling($p);
    sjekk('… nytt kall legger ikke tilbake to ganger (5)', (int) $rad('products', $vare)['lager'] === 5);

    // ── L-3: medlemskap ────────────────────────────────────────────────
    echo "\n── L-3: medlemskap_engangs_refundert ─────────────────────────\n";
    $m = $medlem('Prøver', 'aktiv', $prove);
    $s = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $prove, 'pris_ore' => 99000, 'status' => 'aktiv']);
    $rydd['subscriptions'][] = $s;
    $p = $betaling(['formal' => 'medlemskap', 'member_id' => $m, 'subscription_id' => $s, 'belop_ore' => 99000]);
    Booking::refunderBetaling($p);
    sjekk("full refusjon 99000 oere paa $prove: avtalen stoppet", $rad('subscriptions', $s)['status'] === 'stoppet');
    sjekk('… medlemmet «oppsagt»', $rad('members', $m)['status'] === 'oppsagt');

    $m = $medlem('Fornyer', 'aktiv', $liten);
    $s = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $liten, 'pris_ore' => 179000, 'status' => 'aktiv']);
    $rydd['subscriptions'][] = $s;
    $betaling(['formal' => 'medlemskap', 'member_id' => $m, 'subscription_id' => $s, 'belop_ore' => 179000]);
    $p = $betaling(['formal' => 'medlemskap', 'member_id' => $m, 'subscription_id' => $s, 'belop_ore' => 179000]);
    Booking::refunderBetaling($p);
    sjekk('fornyelse 179000 oere refundert: avtalen stoppes ikke av seg selv', $rad('subscriptions', $s)['status'] === 'aktiv');
    sjekk('… men den flagges i loggen', $logget('medlemskap_refundert_flagg', 'member', $m));
    // Codex runde 5: den andre (foerste) perioden refunderes ogsaa. Den er ikke
    // et engangskjoep bare fordi den andre alt er refundert.
    $p1 = (int) DB::verdi("SELECT MIN(id) FROM payments WHERE subscription_id = :s", ['s' => $s]);
    Booking::refunderBetaling($p1);
    sjekk('begge periodene refundert (2 x 179000): avtalen stoppes fortsatt ikke av seg selv',
        $rad('subscriptions', $s)['status'] === 'aktiv' && $rad('members', $m)['status'] === 'aktiv');

    // ── Codex runde 3 ──────────────────────────────────────────────────
    echo "\n── Codex runde 3: barnetillegg og eldre uttak ────────────────\n";
    // «Ta med barn»-ordre 20000 oere, tillegget aktivt. Full refusjon.
    $m = $medlem('Barnetillegg', 'aktiv', $liten);
    $p = $betaling(['formal' => 'ordre', 'member_id' => $m, 'belop_ore' => 20000]);
    $o = DB::settInn('orders', ['ordrenr' => 'T-' . strtoupper(bin2hex(random_bytes(4))), 'member_id' => $m, 'kunde_navn' => 'Kunde',
        'sum_ore' => 20000, 'status' => 'betalt', 'payment_id' => $p]);
    $rydd['orders'][] = $o;
    $t = DB::settInn('medlem_tillegg', ['member_id' => $m, 'maaned' => gmdate('Y-m'), 'pris_ore' => 20000, 'order_id' => $o,
        'status' => 'aktiv', 'vilkaar_akseptert_at' => gmdate('Y-m-d H:i:s'), 'betalt_at' => gmdate('Y-m-d H:i:s')]);
    Booking::refunderBetaling($p);
    sjekk('barnetillegg 20000 refundert fullt: tillegget er avbrutt',
        DB::verdi('SELECT status FROM medlem_tillegg WHERE id = :i', ['i' => $t]) === 'avbrutt');
    DB::kjor('DELETE FROM medlem_tillegg WHERE id = :i', ['i' => $t]);

    // To perioder paa samme avtale, begge 30000 fra samme kort, uttakene fra
    // foer migrasjon 245 (uten betaling). Den andre refunderes fullt: ingen
    // av de gamle uttakene kan knyttes til den, saa ingenting gis tilbake.
    $m = $medlem('To perioder', 'aktiv', $liten);
    $s = DB::settInn('subscriptions', ['member_id' => $m, 'plan' => $liten, 'pris_ore' => 179000, 'status' => 'aktiv']);
    $rydd['subscriptions'][] = $s;
    $k = $kort(0, 40000, 100000);
    $p1 = $betaling(['formal' => 'medlemskap', 'member_id' => $m, 'subscription_id' => $s, 'belop_ore' => 149000,
        'gavekort_id' => $k, 'gavekort_ore' => 30000]);
    $p2 = $betaling(['formal' => 'medlemskap', 'member_id' => $m, 'subscription_id' => $s, 'belop_ore' => 149000,
        'gavekort_id' => $k, 'gavekort_ore' => 30000]);
    foreach ([1, 2] as $_) {
        DB::settInn('gift_card_uses', ['gift_card_id' => $k, 'belop_ore' => 30000, 'ref_type' => 'medlemskap', 'ref_id' => $s]);
    }
    Booking::refunderBetaling($p2);
    sjekk('eldre uttak paa samme avtale gis ikke tilbake for feil periode (saldo 40000)',
        (int) $rad('gift_cards', $k)['saldo_ore'] === 40000, (string) $rad('gift_cards', $k)['saldo_ore']);
    sjekk('… begge de gamle uttakene staar paa 30000', (int) DB::verdi(
        'SELECT COUNT(*) FROM gift_card_uses WHERE gift_card_id = :k AND belop_ore = 30000', ['k' => $k]) === 2);

    // ── Codex runde 4 ──────────────────────────────────────────────────
    echo "\n── Codex runde 4: Vipps avviser, og refusjon fra portalen ────\n";
    $p = $betaling(['formal' => 'gavekort', 'belop_ore' => 50000]);
    $k = $kort($p, 50000);
    Vipps::$feiler = true;
    $melding = '';
    try { Booking::refunderBetaling($p); } catch (RuntimeException $e) { $melding = $e->getMessage(); }
    Vipps::$feiler = false;
    sjekk('Vipps avviser refusjonen av kortet: feilen kommer fram', str_contains($melding, 'avvist'), $melding);
    sjekk('… kortet er sperret, men verdien 50000 staar (kan aapnes igjen)',
        $rad('gift_cards', $k)['status'] === 'annullert' && (int) $rad('gift_cards', $k)['saldo_ore'] === 50000
        && Booking::finnGavekort((string) $rad('gift_cards', $k)['kode']) === null);
    $r = Booking::refunderBetaling($p);
    sjekk('nytt forsoek gaar gjennom: 50000 refundert, saldo 0', $r['gjenstaar'] === 0
        && (int) $rad('gift_cards', $k)['saldo_ore'] === 0);

    // Refundert i Vipps-portalen (REFUNDED-hendelse): betalingen er alt
    // «refundert» naar vi faar vite det. Kortet var brukt — det sperres likevel.
    $p = $betaling(['formal' => 'gavekort', 'belop_ore' => 50000, 'status' => 'refundert', 'refundert_ore' => 50000]);
    $k = $kort($p, 20000);
    Booking::gjorOppFullRefusjon($p);
    sjekk('portalrefusjon av brukt kort (20000 igjen): sperret og saldo 0',
        $rad('gift_cards', $k)['status'] === 'annullert' && (int) $rad('gift_cards', $k)['saldo_ore'] === 0);
    $m = $medlem('Portal timepakke', 'aktiv', $liten);
    $p = $betaling(['formal' => 'medlemskap', 'member_id' => $m, 'belop_ore' => 80000, 'status' => 'refundert', 'refundert_ore' => 80000]);
    $tp = DB::settInn('timepakker', ['member_id' => $m, 'timer' => 6, 'pris_ore' => 80000, 'status' => 'betalt',
        'payment_id' => $p, 'betalt_at' => gmdate('Y-m-d H:i:s')]);
    $rydd['timepakker'][] = $tp;
    Booking::gjorOppFullRefusjon($p);
    sjekk('portalrefusjon av timepakke 80000: pakken refundert, 0 minutter', $rad('timepakker', $tp)['status'] === 'refundert'
        && Timepakke::tilgodeMin($m) === 0);
    $p = $betaling(['formal' => 'gavekort', 'belop_ore' => 50000, 'status' => 'delvis_refundert', 'refundert_ore' => 10000]);
    $k = $kort($p, 50000);
    Booking::gjorOppFullRefusjon($p);
    sjekk('delvis portalrefusjon (10000 av 50000): kortet urort', $rad('gift_cards', $k)['status'] === 'aktivt'
        && (int) $rad('gift_cards', $k)['saldo_ore'] === 50000);
} catch (Throwable $e) {
    sjekk('uventet feil', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($rydd['payments'] as $p) {
        DB::kjor('DELETE FROM payment_refunds WHERE payment_id = :p', ['p' => $p]);
        DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'payment' AND objekt_id = :p", ['p' => $p]);
    }
    foreach ($rydd['orders'] as $o) {
        DB::kjor('DELETE FROM order_lines WHERE order_id = :o', ['o' => $o]);
        DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'ordre' AND objekt_id = :o", ['o' => $o]);
        DB::kjor('DELETE FROM orders WHERE id = :o', ['o' => $o]);
    }
    foreach ($rydd['timepakker'] as $t) { DB::kjor('DELETE FROM timepakker WHERE id = :i', ['i' => $t]); }
    foreach ($rydd['gift_cards'] as $k) {
        DB::kjor('DELETE FROM gift_card_uses WHERE gift_card_id = :k', ['k' => $k]);
        DB::kjor('UPDATE payments SET gavekort_id = NULL WHERE gavekort_id = :k', ['k' => $k]);
        DB::kjor('DELETE FROM gift_cards WHERE id = :k', ['k' => $k]);
    }
    foreach ($rydd['payments'] as $p) { DB::kjor('DELETE FROM payments WHERE id = :p', ['p' => $p]); }
    foreach ($rydd['subscriptions'] as $s) { DB::kjor('DELETE FROM subscriptions WHERE id = :s', ['s' => $s]); }
    foreach ($rydd['products'] as $v) { DB::kjor('DELETE FROM products WHERE id = :v', ['v' => $v]); }
    foreach ($rydd['members'] as $m) {
        DB::kjor("DELETE FROM audit_log WHERE objekt_type = 'member' AND objekt_id = :m", ['m' => $m]);
        DB::kjor('DELETE FROM members WHERE id = :m', ['m' => $m]);
    }
}

echo "\n  $ok ok, $feil feil\n";
$ferdig = true;
exit($feil === 0 ? 0 : 1);
