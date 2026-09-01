<?php
/**
 * Medlemssoknader — les og behandle.
 *
 * GET  ?status=venter   Lister soknader.
 * POST {id, vedtak: godkjent|avslatt, type?, begrunnelse?}
 *
 * Godkjenning setter medlemmets status. Uten godkjenning her kommer ingen inn
 * i medlemsdelen, uansett hvor mange ganger de logger inn med Vipps.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$admin = krev_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = Foresporsel::tekst('status') ?: 'venter';
    if (!in_array($status, ['venter', 'godkjent', 'avslatt', 'alle'], true)) {
        $status = 'venter';
    }

    $rader = $status === 'alle'
        ? DB::alle('SELECT s.*, m.status AS medlem_status FROM membership_applications s
                      JOIN members m ON m.id = s.member_id
                  ORDER BY s.status = "venter" DESC, s.id DESC LIMIT 200')
        : DB::alle('SELECT s.*, m.status AS medlem_status FROM membership_applications s
                      JOIN members m ON m.id = s.member_id
                     WHERE s.status = :s ORDER BY s.id DESC LIMIT 200', ['s' => $status]);

    Svar::json([
        'soknader' => array_map(static fn(array $r): array => [
            'id'        => (int) $r['id'],
            'medlemId'  => (int) $r['member_id'],
            'navn'      => (string) $r['navn'],
            'epost'     => (string) ($r['epost'] ?? ''),
            'telefon'   => (string) ($r['telefon'] ?? ''),
            'type'      => (string) ($r['onsket_type'] ?? ''),
            'erfaring'  => (string) ($r['erfaring'] ?? ''),
            'melding'   => (string) ($r['melding'] ?? ''),
            'status'    => (string) $r['status'],
            'sendt'     => Booking::norskDatoKort($r['created_at']),
        ], $rader),
        'venter' => (int) DB::verdi('SELECT COUNT(*) FROM membership_applications WHERE status = "venter"'),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$id     = Foresporsel::heltall('id');
$vedtak = Foresporsel::tekst('vedtak');
if (!in_array($vedtak, ['godkjent', 'avslatt'], true)) {
    Svar::feil('Ukjent vedtak.');
}

$soknad = DB::en('SELECT * FROM membership_applications WHERE id = :id', ['id' => $id]);
if (!$soknad) {
    Svar::feil('Fant ikke søknaden.', 404);
}
if ($soknad['status'] !== 'venter') {
    Svar::feil('Søknaden er allerede behandlet.');
}

$type        = mb_substr(Foresporsel::tekst('type') ?: (string) ($soknad['onsket_type'] ?? ''), 0, 64);
$begrunnelse = mb_substr(Foresporsel::tekst('begrunnelse'), 0, 500);

// ── Betalingen skal vaere paa plass for noen slippes inn ────────────────
//
// Soknaden oppretter avtalen i Vipps med det samme, men trekket holdes igjen
// til her (se Medlemskap::oppdaterFraVipps). Godkjenner vi uten at avtalen er
// godkjent i appen, faar medlemmet tilgang uten at det finnes noe aa trekke
// fra — og cron henter bare avtaler som er aktive. Da kommer det ingen penger,
// ikke den maaneden og ikke noen gang.
//
// Eldre soknader, sendt inn for avtalen ble en del av innmeldingen, har ingen
// avtale i det hele tatt. De kan fortsatt godkjennes — men det staar i svaret
// at det maa kreves inn paa annen maate, saa det ikke gaar stille forbi.
// Hva sokeren valgte. Kolonna kom med migrasjon 081; staar den ukjort, er
// alle soknader «trekk», som var det eneste som fantes for.
$betaling = DB::harKolonne('membership_applications', 'betaling')
    ? (string) ($soknad['betaling'] ?? 'trekk') : 'trekk';

$avtaleStatus = 'ingen';

// «Ordner selv» skal sjekkes like noye som fast trekk.
//
// Eieren, 1. september: «Hun fikk medlemskap selv om betalingen ikke gikk inn
// hva faen». Her sto ingen sjekk i det hele tatt for denne maaten: ett trykk
// paa Godkjenn ga full tilgang, og svaret sa «gjor opp selv for hver periode»
// — som om alt var i orden. At foerste betaling aldri kom, sto ingen steder.
//
// Forskjellen paa de to maatene er hvem som krever inn de SENERE periodene.
// Den foerste betalingen skal vaere i havn uansett.
if ($vedtak === 'godkjent' && $betaling === 'selv') {
    $ut = Medlemskap::engangsBetalt((int) $soknad['member_id']);
    $avtaleStatus = $ut['status'];

    if ($avtaleStatus === 'ukjent') {
        Svar::feil('Fikk ikke sjekket betalingen hos Vipps akkurat nå. '
            . 'Prøv igjen om litt — da slipper vi å slippe noen inn på en antakelse.');
    }
    if (!in_array($avtaleStatus, ['aktiv', 'ingen'], true)) {
        Svar::feil('Betalingen fra ' . $soknad['navn'] . ' har ikke kommet inn. '
            . 'Den står som «' . $avtaleStatus . '» hos Vipps. Be hen fullføre '
            . 'betalingen fra medlemskapssiden, så kan du si ja her etterpå.');
    }
}

if ($vedtak === 'godkjent' && $betaling === 'trekk') {
    $ut = Medlemskap::slippForsteTrekk((int) $soknad['member_id']);
    $avtaleStatus = $ut['status'];

    if ($avtaleStatus === 'venter') {
        Svar::feil('Betalingsavtalen er ikke godkjent i Vipps ennå. '
            . $soknad['navn'] . ' har fått lenken på e-post — be hen godkjenne den, '
            . 'så kan du si ja her etterpå.');
    }
    if (!in_array($avtaleStatus, ['aktiv', 'ingen'], true)) {
        Svar::feil('Betalingsavtalen i Vipps er ' . $avtaleStatus . '. '
            . 'Da kan ikke medlemskapet starte. ' . $soknad['navn']
            . ' må opprette avtalen på nytt fra medlemskapssiden.');
    }
}

DB::iTransaksjon(static function () use ($soknad, $vedtak, $type, $begrunnelse, $admin): void {
    DB::oppdater('membership_applications', [
        'status'       => $vedtak,
        'behandlet_av' => $admin['id'],
        'behandlet_at' => gmdate('Y-m-d H:i:s'),
        'begrunnelse'  => $begrunnelse !== '' ? $begrunnelse : null,
    ], ['id' => $soknad['id']]);

    if ($vedtak === 'godkjent') {
        // «prove» og ikke «aktiv»: medlemskapet begynner å løpe først når
        // betalingsavtalen er på plass. Tilgangen er den samme.
        DB::oppdater('members', [
            'status'          => 'prove',
            'medlemskap_type' => $type !== '' ? $type : null,
            'start_dato'      => gmdate('Y-m-d'),
        ], ['id' => $soknad['member_id']]);
    }
});

$navn = (string) $soknad['navn'];
if ($vedtak === 'godkjent') {
    Varsel::mal('soknad_godkjent', ['epost' => (string) $soknad['epost']], [
        'navn' => $navn,
    ], 'membership_application', (int) $soknad['id']);
    if ($soknad['telefon']) {
        Varsel::mal('soknad_godkjent_sms', ['telefon' => (string) $soknad['telefon']], [
            'navn' => $navn,
        ], 'membership_application', (int) $soknad['id']);
    }
} else {
    Varsel::mal('soknad_avslatt', ['epost' => (string) $soknad['epost']], [
        'navn'        => $navn,
        'begrunnelse' => $begrunnelse !== '' ? "\n\n" . $begrunnelse : '',
    ], 'membership_application', (int) $soknad['id']);
}

// Avslag: avtalen stoppes, saa den ikke blir liggende som en fullmakt hos
// noen vi har sagt nei til. Ingen har betalt noe — trekket er aldri sluppet.
if ($vedtak !== 'godkjent' && $betaling === 'trekk') {
    $a = Medlemskap::avtale((int) $soknad['member_id'])
        ?? DB::en("SELECT * FROM subscriptions WHERE member_id = :m AND status = 'venter'
                   ORDER BY id DESC LIMIT 1", ['m' => (int) $soknad['member_id']]);
    if ($a !== null) {
        try { Medlemskap::siOpp($a); } catch (Throwable $e) { logg_feil('Fikk ikke stoppet avtalen etter avslag', $e); }
    }
}

revider('medlemssoknad_' . $vedtak, 'membership_application', (int) $soknad['id'], ['medlem' => (int) $soknad['member_id']]);

Svar::ok([
    'status'  => $vedtak,
    'beskjed' => $vedtak !== 'godkjent'
        ? 'Søknaden er avslått, og betalingsavtalen er stoppet.'
        // «selv» foerst: staar «aktiv» over, faar en som gjor opp selv
        // beskjed om et trekk som aldri kommer.
        : ($betaling !== 'selv' && $avtaleStatus === 'aktiv'
            ? 'Godkjent. Første trekk går ut i natt.'
            : ($betaling === 'selv'
                // «ingen» betyr at det ikke finnes noen betaling aa se paa —
                // en gammel soknad. Da skal det staa at ingenting er betalt,
                // ikke bare at det ikke kommer trekk.
                ? ($avtaleStatus === 'ingen'
                    ? 'Godkjent. Det finnes ingen betaling på ' . $soknad['navn']
                      . ' — søknaden er fra før innmeldingen krevde det, så beløpet '
                      . 'må kreves inn selv.'
                    : 'Godkjent. Første betaling er i havn. ' . $soknad['navn']
                      . ' gjør opp selv for de neste periodene — det kommer ingen '
                      . 'automatiske trekk.')
                : 'Godkjent. Denne søknaden har ingen betalingsavtale — '
                  . 'den er fra før innmeldingen krevde det, så beløpet må kreves inn selv.')),
]);
