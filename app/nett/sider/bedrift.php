<?php
/** Bedrift og event — /bedrift. Skjermen fra lissom-2108.html, tegnet av Mal. */

declare(strict_types=1);

$innh = [Nett::class, 'innh'];
$tilbud = [];
foreach ([[1, 'uploads_workshop4.jpg'], [2, 'uploads_datenight.jpg'], [3, 'uploads_barn2.jpg']] as [$n, $bilde]) {
    $tilbud[] = [
        'navn'     => $innh('Bedrift og event/1/Kort ' . $n . ' tittel'),
        'tekst'    => $innh('Bedrift og event/1/Kort ' . $n . ' tekst'),
        'pris'     => $innh('Bedrift og event/1/Kort ' . $n . ' pris'),
        'bildeCss' => Nett::cssUrl($bilde),
    ];
}
$kunder = [];
if (DB::harTabell('referansekunder')) {
    $logoFelt = DB::harKolonne('referansekunder', 'logo') ? 'logo,' : '';
    foreach (DB::alle("SELECT navn, bilde, {$logoFelt} tekst, sitat, sitat_av FROM referansekunder WHERE aktiv = 1 AND samtykke = 1 ORDER BY sortering, navn") as $k) {
        if ((string) $k['navn'] === '') {
            continue;
        }
        $logo = (string) ($k['logo'] ?? '');
        $bilde = (string) ($k['bilde'] ?? '');
        $tekst = (string) ($k['tekst'] ?? '');
        $sitat = (string) ($k['sitat'] ?? '');
        $av = (string) ($k['sitat_av'] ?? '');
        $kunder[] = [
            'navn' => (string) $k['navn'], 'bilde' => $bilde, 'harBilde' => $bilde !== '', 'alt' => 'Keramikk laget for ' . $k['navn'],
            'harLogo' => $logo !== '', 'logoStil' => $logo !== '' ? 'height: 36px; width: 160px; background: ' . Nett::cssUrl($logo) . ' left center / contain no-repeat;' : '',
            'tekst' => $tekst, 'harTekst' => $tekst !== '',
            'sitat' => $sitat !== '' ? '«' . $sitat . '»' : '', 'harSitat' => $sitat !== '',
            'sitatAv' => $av, 'harSitatAv' => $av !== '',
        ];
    }
}
return [
    // Foresporselsskjemaet er en rute i appen: knappen aapner den der.
    'kropp' => Mal::tegn('Bedrift og event', ['bedriftTilbud' => $tilbud, 'beReferanser' => $kunder, 'beHarReferanser' => $kunder !== [], 'sant' => true], ['bedriftTilSkjema' => '/bedrift?skjema=1']) . "\n" . Deler::bunn(true),
    'aktiv' => '',
    // Det foerste tilbudskortets bilde er LCP paa sida.
    'hode'  => '<link rel="preload" as="image" fetchpriority="high" href="uploads_workshop4.jpg">' . "\n",
];
