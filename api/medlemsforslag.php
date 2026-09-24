<?php
/**
 * Medlemmets egne forslag til Instagram.
 *
 *   GET                     mine forslag + om funksjonen er slaatt paa
 *   POST handling=send      multipart: type (bilde|video), fil, tekst,
 *                           hashtags, instagram
 *
 * Eieren, 24. september 2026: medlemmer sender et bilde eller en video paa
 * maks 15 sekunder med litt tekst; verkstedet godkjenner, og det legges ut
 * paa @lissom_keramikk. Ett forslag om gangen.
 *
 * Styres av bryteren ⊙ Synlighet → Del paa Instagram. Er den av, svarer
 * denne som om funksjonen ikke fantes — ogsaa for den som kjenner adressen.
 *
 * Avviste forslag vises ikke: medlemmet ser bare at forslaget er borte
 * (skissen eieren sa GO til).
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

$medlem = krev_medlem();

if (!Medlemsforslag::klar()) {
    Svar::ok(['paa' => false, 'mine' => []]);
}

$tilUt = static fn(array $r): array => [
    'id'     => (int) $r['id'],
    'type'   => (string) $r['type'],
    'fil'    => '/api/bilde.php?forslag=' . rawurlencode((string) $r['fil']),
    'tekst'  => (string) $r['tekst'],
    'status' => (string) $r['status'],
    'lenke'  => (string) $r['lenke'],
];

if (Foresporsel::metode() === 'GET') {
    $rader = DB::alle(
        "SELECT * FROM medlemsforslag
          WHERE member_id = :m AND status <> 'avvist'
          ORDER BY created_at DESC LIMIT 10",
        ['m' => (int) $medlem['id']]
    );
    Svar::ok([
        'paa'      => Medlemsforslag::paa(),
        'erMedlem' => er_aktivt_medlem($medlem),
        'venter'   => (bool) array_filter($rader, static fn($r) => in_array($r['status'], ['venter', 'godkjent'], true)),
        'mine'     => array_map($tilUt, $rader),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

if (($_POST['handling'] ?? '') !== 'send') {
    Svar::feil('Ukjent handling.');
}

$medlem = krev_aktivt_medlem();

if (!Medlemsforslag::paa()) {
    Svar::feil('Denne funksjonen er ikke slått på.', 404);
}

// Ett om gangen. Sjekkes her, ikke bare paa skjermen.
$aapent = (int) DB::verdi(
    "SELECT COUNT(*) FROM medlemsforslag
      WHERE member_id = :m AND status IN ('venter','godkjent')",
    ['m' => (int) $medlem['id']]
);
if ($aapent > 0) {
    Svar::feil('Du har allerede et forslag som venter. Du kan sende et nytt når det er behandlet.');
}

$type = (string) ($_POST['type'] ?? '');
if (!in_array($type, ['bilde', 'video'], true)) {
    Svar::feil('Velg bilde eller video.');
}

$tekst = trim((string) ($_POST['tekst'] ?? ''));
if ($tekst === '') {
    Svar::feil('Skriv litt tekst til innlegget.');
}
if (mb_strlen($tekst) > 1500) {
    Svar::feil('Teksten er for lang. Maks 1500 tegn.');
}
$hashtags  = mb_substr(trim((string) ($_POST['hashtags'] ?? '')), 0, 500);
$instagram = Medlemsforslag::brukernavn((string) ($_POST['instagram'] ?? ''));

try {
    $fil = $type === 'video'
        ? Medlemsforslag::taImotVideo($_FILES['fil'] ?? [])
        : Medlemsforslag::taImotBilde($_FILES['fil'] ?? []);
} catch (RuntimeException $e) {
    Svar::feil($e->getMessage());
}

$id = DB::settInn('medlemsforslag', [
    'member_id' => (int) $medlem['id'],
    'type'      => $type,
    'fil'       => $fil,
    'tekst'     => $tekst,
    'hashtags'  => $hashtags,
    'instagram' => $instagram,
]);

$rad = DB::en('SELECT * FROM medlemsforslag WHERE id = :i', ['i' => $id]);
Svar::ok(['forslag' => $rad === null ? null : $tilUt($rad)]);
