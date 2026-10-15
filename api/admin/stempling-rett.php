<?php
/** Se og behandle forslag samt korrigere en valgt økt i dagens admin. */
declare(strict_types=1);
require __DIR__ . '/../_boot.php';

$admin = krev_admin();
function varsle_stempling_medlem(int $medlemId, int $sakId, string $tekst): void
{
    if ($sakId <= 0) { return; }
    $m = DB::en('SELECT navn, epost, telefon FROM members WHERE id = :m', ['m' => $medlemId]);
    if ($m === null) { return; }
    $mottaker = ['epost' => (string) ($m['epost'] ?? ''), 'telefon' => (string) ($m['telefon'] ?? '')];
    $fornavn = (string) (preg_split('/\s+/u', trim((string) ($m['navn'] ?? '')))[0] ?? '');
    $svar = 'Hei' . ($fornavn !== '' ? ' ' . $fornavn : '') . ",\n\n" . $tekst
        . "\n\nDu kan se status på https://lissom.no/verkstedtid.html";
    try {
        if ($mottaker['epost'] !== '') {
            Varsel::mal('foresporsel_svar', $mottaker, ['svar' => $svar]);
        } elseif ($mottaker['telefon'] !== '' && Varsel::smsMulig()) {
            Varsel::mal('foresporsel_svar_sms', $mottaker, ['svar' => $tekst]);
        }
    } catch (Throwable $e) {
        logg_feil('Kunne ikke varsle medlem om verkstedtid', $e);
    }
}
if (!DB::harTabell('stempling_rettinger')) {
    Svar::feil('Oppdater databasen før verkstedtid kan behandles.');
}
if (Foresporsel::metode() === 'GET') {
    $id = Foresporsel::heltall('medlemId');
    if ($id > 0) {
        $medlem = DB::en('SELECT id, navn FROM members WHERE id = :i', ['i' => $id]);
        if (!$medlem) {
            Svar::feil('Fant ikke medlemmet.', 404);
        }
        Svar::json(['medlem' => ['id' => $id, 'navn' => (string) $medlem['navn']]]
            + StemplingRett::oversikt($id, Foresporsel::heltall('sakId'), Foresporsel::heltall('oktId')));
    }
    Svar::json(['saker' => DB::alle("SELECT r.id, r.member_id AS medlemId, r.check_in_id AS oktId,
                                            r.status, r.created_at AS opprettet, m.navn
                                       FROM stempling_rettinger r JOIN members m ON m.id = r.member_id
                                      WHERE r.status = 'apen'
                                   ORDER BY r.id DESC LIMIT 100")]);
}
Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$handling = Foresporsel::tekst('handling');
try {
    if ($handling === 'beregn') {
        $tid = StemplingRett::beregn(Foresporsel::tekst('dato'), Foresporsel::tekst('inn'), Foresporsel::tekst('ut'));
        Svar::ok(['minutter' => $tid['minutter']]);
    }
    if ($handling === 'rett') {
        $svar = StemplingRett::rett(Foresporsel::heltall('medlemId'), Foresporsel::heltall('oktId'),
            Foresporsel::tekst('dato'), Foresporsel::tekst('inn'), Foresporsel::tekst('ut'),
            Foresporsel::tekst('grunn'), (int) $admin['id'], Foresporsel::heltall('sakId'));
        varsle_stempling_medlem(Foresporsel::heltall('medlemId'), Foresporsel::heltall('sakId'),
            'Verkstedtiden er rettet. Økten teller nå ' . Stempling::varighet($svar['minutter']) . '.');
        Svar::ok(['oktId' => $svar['id'], 'minutter' => $svar['minutter'], 'beskjed' => 'Verkstedtiden er rettet.']);
    }
    if (in_array($handling, ['trenger_info', 'avslaatt'], true)) {
        $sakId = Foresporsel::heltall('sakId');
        StemplingRett::avgjor($sakId, $handling,
            Foresporsel::tekst('notat'), (int) $admin['id']);
        $medlemId = (int) (DB::verdi('SELECT member_id FROM stempling_rettinger WHERE id = :s', ['s' => $sakId]) ?? 0);
        varsle_stempling_medlem($medlemId, $sakId,
            ($handling === 'avslaatt' ? 'Forslaget om verkstedtid er avslått: ' : 'Vi trenger flere opplysninger om verkstedtiden: ')
            . Foresporsel::tekst('notat'));
        Svar::ok(['beskjed' => $handling === 'avslaatt' ? 'Forslaget er avslått.' : 'Saken venter på flere opplysninger.']);
    }
} catch (InvalidArgumentException $e) {
    Svar::feil($e->getMessage());
}
Svar::feil('Ukjent handling.');
