<?php
/** Paint on Pots — /paint-on-pots. Skjermen fra lissom-2108.html, tegnet av Mal; datoene som popDatoer i nettsida. */

declare(strict_types=1);

$innh = [Nett::class, 'innh'];
$kort = null;
foreach (Kort::kurs() as $k) {
    if ($k['title'] === 'Paint on Pots') { $kort = $k; break; }
}
$okter = $kort['okter'] ?? [];
$antall = count($okter);
$pop = [];
if ($kort !== null && $antall > 0) {
    $o = $okter[0];
    $pop[] = array_merge($kort, [
        'level' => 'Event', 'date' => (string) ($o['dato'] ?? ''), 'duration' => '', 'price' => '',
        'status' => $antall === 1 ? ((int) ($o['ledige'] ?? 0) > 0 ? $o['ledige'] . ' plasser igjen' : 'Fullt') : $antall . ' ledige tider',
        'text' => $kort['text'] ?? '',
    ]);
}
$steg = [];
for ($i = 1; $i <= 4; $i++) {
    $t = $innh('Paint on Pots/7/Steg ' . $i); $x = $innh('Paint on Pots/7/Steg ' . $i . ' tekst');
    if (trim($t) !== '' || trim($x) !== '') { $steg[] = ['nr' => (string) (count($steg) + 1), 'tittel' => $t, 'tekst' => $x]; }
}
$faq = [];
for ($i = 1; $i <= 4; $i++) {
    $q = $innh('Paint on Pots/8/Spørsmål ' . $i);
    if (trim($q) !== '') { $faq[] = ['q' => $q, 'a' => $innh('Paint on Pots/8/Svar ' . $i)]; }
}
$popHref = $kort !== null ? $kort['href'] : '/events';
return [
    'kropp' => Mal::tegn('Paint on Pots', [
        'popDatoer' => $pop, 'popHarDatoer' => $antall > 0, 'popManglerDatoer' => $antall === 0, 'popHarFlere' => $antall > 1,
        'popFlereDatoer' => $antall > 1 ? 'Du velger dag og tid i bestillingen — ' . $antall . ' ledige tider de neste to ukene.' : '',
        'popSteg' => $steg, 'popFaq' => $faq, 'sant' => true,
    ], ['goEvents' => '/events', 'goForesporsel' => '/kontakt', 'popTilDatoer' => $popHref, 'book' => $popHref]) . "\n" . Deler::bunn(true),
    'aktiv' => 'Events',
    // Bildet oeverst er LCP paa sida — samme srcset som i malen.
    'hode'  => '<link rel="preload" as="image" href="uploads_shutterstock_2830576853.jpg" imagesrcset="uploads_shutterstock_2830576853-400.jpg 400w, uploads_shutterstock_2830576853-800.jpg 800w, uploads_shutterstock_2830576853.jpg 1024w" imagesizes="(max-width: 760px) 100vw, 700px">' . "\n",
];
