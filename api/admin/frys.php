<?php
/**
 * Frys av medlemskap — verkstedets side.
 *
 *   GET                     soknadene, nyeste forst
 *   POST handling=godkjenn  { id, svar? }
 *   POST handling=avslag    { id, svar? }
 *   POST handling=avslutt   { id }   avbryt en frys som loper
 *
 * Godkjenning setter medlemmet i pause og aapner det igjen naar perioden er
 * over. Trekket roeres ikke: Vipps kan ikke sette en avtale paa pause, bare
 * stoppe den. Svaret sier derfor fra naar det ligger en loepende avtale, slik
 * at verkstedet vet at pengene fortsetter aa gaa til noen tar tak i det.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

$jeg = krev_admin();

if (!Frys::klar()) {
    Svar::feil('Migrasjon 071 er ikke kjørt. Kjør oppdateringen først, så kommer søknadene fram her.');
}

Frys::ajour();

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle(
        'SELECT f.*, m.navn, m.epost, m.telefon, m.medlemskap_type, m.status AS medlemsstatus
           FROM medlem_frys f
           JOIN members m ON m.id = f.member_id
       ORDER BY (f.status = \'sokt\') DESC, f.id DESC
          LIMIT 200'
    );
    Svar::json([
        'soknader' => array_map(static function (array $f): array {
            $ut = Frys::ut($f);
            $ut['medlemId']    = (int) $f['member_id'];
            $ut['navn']        = (string) $f['navn'];
            $ut['kontakt']     = implode(' · ', array_filter([$f['epost'], $f['telefon']])) ?: 'Ingen kontaktinfo';
            $ut['type']        = (string) ($f['medlemskap_type'] ?? '');
            $ut['medlemsstatus'] = (string) $f['medlemsstatus'];
            $ut['venter']      = (string) $f['status'] === 'sokt';
            $ut['loper']       = (string) $f['status'] === 'godkjent';
            $ut['behandletAv'] = $f['behandlet_av']
                ? (string) (DB::verdi('SELECT navn FROM members WHERE id = :i',
                    ['i' => (int) $f['behandlet_av']]) ?: '') : '';
            $ut['behandletAt'] = $f['behandlet_at']
                ? Booking::norskDatoKort((string) $f['behandlet_at']) : '';
            return $ut;
        }, $rader),
        'venter' => (int) DB::verdi("SELECT COUNT(*) FROM medlem_frys WHERE status = 'sokt'"),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling');
$id = Foresporsel::heltall('id');
$f = DB::en('SELECT * FROM medlem_frys WHERE id = :i', ['i' => $id]);
if ($f === null) {
    Svar::feil('Fant ikke søknaden.', 404);
}
$medlemId = (int) $f['member_id'];
$svar = mb_substr(Foresporsel::tekst('svar'), 0, 500);

/** Ligger det en loepende Vipps-avtale? Da fortsetter trekket. */
$avtale = DB::harTabell('subscriptions')
    ? DB::en("SELECT id FROM subscriptions WHERE member_id = :m AND status = 'aktiv' LIMIT 1",
        ['m' => $medlemId])
    : null;

if ($handling === 'godkjenn') {
    if ((string) $f['status'] !== 'sokt') {
        Svar::feil('Søknaden er allerede behandlet.');
    }
    $m = DB::en('SELECT status, navn FROM members WHERE id = :i', ['i' => $medlemId]);
    if ($m === null) {
        Svar::feil('Fant ikke medlemmet.', 404);
    }

    DB::iTransaksjon(static function () use ($id, $medlemId, $m, $svar, $jeg, $f): void {
        DB::oppdater('medlem_frys', [
            'status'       => 'godkjent',
            'svar'         => $svar ?: null,
            // Statusen medlemmet hadde for frysen, saa det kommer tilbake til
            // den samme naar perioden er over.
            'status_for'   => (string) $m['status'] === 'pause'
                ? (string) ($f['status_for'] ?? 'aktiv') : (string) $m['status'],
            'behandlet_av' => (int) $jeg['id'],
            'behandlet_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
        // Starter frysen i dag eller tidligere, settes pausen med det samme.
        // Starter den fram i tid — «jeg er bortreist hele juli», godkjent i
        // mai — settes den naar dagen kommer, av Frys::startForfalte().
        if ((string) $f['fra_dato'] <= date('Y-m-d')) {
            DB::oppdater('members', ['status' => 'pause'], ['id' => $medlemId]);
        }
    });

    revider('frys_godkjent', 'member', $medlemId, ['frys' => $id]);
    $naar = (string) $f['fra_dato'] <= date('Y-m-d')
        ? ' står nå som fryst til '
        : ' er fryst fra ' . Booking::norskDatoKort((string) $f['fra_dato']) . ' til ';
    Svar::ok(['beskjed' => ($m['navn'] ?: 'Medlemmet') . $naar
        . Booking::norskDatoKort((string) $f['til_dato']) . '. Medlemskapet åpner seg igjen av seg selv.'
        . ($avtale !== null
            ? ' Merk: det løper en Vipps-avtale på denne personen. Vipps kan ikke sette en avtale på pause '
            . '— den må stoppes, og medlemmet setter opp en ny når det kommer tilbake. '
            . 'Gjør du ingenting, fortsetter trekket.'
            : '')]);
}

if ($handling === 'avslag') {
    if ((string) $f['status'] !== 'sokt') {
        Svar::feil('Søknaden er allerede behandlet.');
    }
    DB::oppdater('medlem_frys', [
        'status'       => 'avslatt',
        'svar'         => $svar ?: null,
        'behandlet_av' => (int) $jeg['id'],
        'behandlet_at' => gmdate('Y-m-d H:i:s'),
    ], ['id' => $id]);
    revider('frys_avslatt', 'member', $medlemId, ['frys' => $id]);
    Svar::ok(['beskjed' => 'Søknaden er ikke godkjent. Medlemmet ser svaret på Min side.']);
}

if ($handling === 'avslutt') {
    if ((string) $f['status'] !== 'godkjent') {
        Svar::feil('Denne frysen løper ikke.');
    }
    $tilbake = in_array((string) ($f['status_for'] ?? ''), ['prove', 'aktiv'], true)
        ? (string) $f['status_for'] : 'aktiv';
    DB::iTransaksjon(static function () use ($id, $medlemId, $tilbake, $svar): void {
        DB::oppdater('medlem_frys', ['status' => 'avsluttet', 'svar' => $svar ?: null], ['id' => $id]);
        DB::oppdater('members', ['status' => $tilbake], ['id' => $medlemId]);
    });
    revider('frys_avsluttet', 'member', $medlemId, ['frys' => $id]);
    Svar::ok(['beskjed' => 'Frysen er avsluttet. Medlemskapet er aktivt igjen.']);
}

Svar::feil('Ukjent handling.');
