<?php
/**
 * Frys av medlemskap — medlemmets side.
 *
 *   GET                    mine soknader, og om jeg kan soke naa
 *   POST handling=sok      { fra, til, begrunnelse }
 *   POST handling=trekk    { id }   angre en soknad som ikke er svart paa
 *
 * Bare det innloggede medlemmet ser sine egne rader. Svaret fra verkstedet
 * staar med, for det er skrevet til medlemmet — men hvem som svarte, og naar
 * det ble behandlet, hoerer til i admin.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$meg = krev_aktivt_medlem();
$medlemId = (int) $meg['id'];

if (!Frys::klar()) {
    Svar::feil('Frys av medlemskap er ikke satt opp ennå. Kjør oppdateringen av databasen først.');
}

// En frys som er over aapner medlemskapet igjen. Det skjer her og i admin,
// slik at det ikke avhenger av at noen er innom en bestemt skjerm.
Frys::ajour();

// Slaatt av av verkstedet? Da finnes ikke funksjonen for medlemmet.
$paa = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/medlemfrys'");
if ((string) $paa === 'nei') {
    Svar::feil('Frys av medlemskap er ikke åpent nå. Ta kontakt med verkstedet.', 403);
}

if (Foresporsel::metode() === 'GET') {
    $gjeldende = Frys::gjeldende($medlemId);
    Svar::json([
        'kanSoke'   => $gjeldende === null,
        'gjeldende' => $gjeldende !== null ? Frys::ut($gjeldende) : null,
        'mine'      => array_map([Frys::class, 'ut'], Frys::forMedlem($medlemId)),
        'maksDager' => Frys::MAKS_DAGER,
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$handling = Foresporsel::tekst('handling', 'sok');

if ($handling === 'trekk') {
    $id = Foresporsel::heltall('id');
    // Bare mine egne, og bare de som ikke er svart paa.
    $rad = DB::en(
        "SELECT id FROM medlem_frys WHERE id = :i AND member_id = :m AND status = 'sokt'",
        ['i' => $id, 'm' => $medlemId]
    );
    if ($rad === null) {
        Svar::feil('Fant ingen søknad å trekke tilbake.');
    }
    DB::oppdater('medlem_frys', ['status' => 'trukket'], ['id' => $id]);
    revider('frys_trukket', 'member', $medlemId, ['frys' => $id]);
    Svar::ok(['beskjed' => 'Søknaden er trukket tilbake.']);
}

if ($handling !== 'sok') {
    Svar::feil('Ukjent handling.');
}

// Én om gangen. Ellers ville to soknader kunnet staa og vente paa svar, og
// verkstedet maatte gjette hvilken som gjaldt.
if (Frys::gjeldende($medlemId) !== null) {
    Svar::feil('Du har allerede en søknad som venter, eller en frys som løper.');
}

$fra = trim(Foresporsel::tekst('fra'));
$til = trim(Foresporsel::tekst('til'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fra) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $til)) {
    Svar::feil('Velg både en fra-dato og en til-dato.');
}

$idag = new DateTimeImmutable('today');
$a = new DateTimeImmutable($fra);
$b = new DateTimeImmutable($til);

if ($a < $idag) {
    Svar::feil('Frysen kan ikke starte før i dag.');
}
if ($b < $a) {
    Svar::feil('Til-datoen må være etter fra-datoen.');
}
$dager = Frys::dager($fra, $til);
if ($dager > Frys::MAKS_DAGER) {
    Svar::feil('Lengste frys er ' . Frys::MAKS_DAGER . ' dager. Skal det vare lenger, '
             . 'er det bedre å si opp og melde seg inn igjen — ta kontakt, så hjelper vi deg.');
}

$id = DB::settInn('medlem_frys', [
    'member_id'   => $medlemId,
    'fra_dato'    => $fra,
    'til_dato'    => $til,
    'begrunnelse' => mb_substr(Foresporsel::tekst('begrunnelse'), 0, 500) ?: null,
    'status_for'  => (string) ($meg['status'] ?? ''),
]);

revider('frys_sokt', 'member', $medlemId, ['frys' => $id, 'fra' => $fra, 'til' => $til]);

Svar::ok([
    'beskjed' => 'Søknaden er sendt. Verkstedet svarer så snart de rekker det.',
    'id'      => $id,
]);
