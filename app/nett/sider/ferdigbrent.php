<?php
/** Klar til henting — /ferdigbrent. Skjermen fra lissom-2108.html; lista som /api/ferdigbrent.php. */

declare(strict_types=1);

$uker = 3;
$liste = [];
if (DB::harKolonne('course_sessions', 'hentemelding_at')) {
    $naa = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $rader = DB::alle(
        "SELECT cs.start_tid, cs.hentemelding_at, c.tittel
           FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
          WHERE cs.hentemelding_at IS NOT NULL AND cs.hentemelding_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL :u WEEK)
       ORDER BY cs.hentemelding_at DESC",
        ['u' => $uker]
    );
    foreach ($rader as $r) {
        $frist = (new DateTimeImmutable((string) $r['hentemelding_at'], new DateTimeZone('UTC')))->modify('+' . $uker . ' weeks');
        $dager = (int) $naa->diff($frist)->format('%r%a');
        $liste[] = [
            'tittel' => $r['tittel'] . ', ' . Booking::norskDato((string) $r['start_tid']),
            'tekst'  => 'Gjenstandene fra dette kurset er klare til henting. Vi oppbevarer dem hos oss i ' . $uker . ' uker.',
            'igjen'  => $dager <= 0 ? 'Siste frist er ute' : ($dager === 1 ? 'Én dag igjen' : $dager . ' dager igjen'),
            'fargen' => $dager <= 5 ? 'var(--terracotta-600)' : 'var(--text-muted)',
        ];
    }
}
return [
    'kropp' => Mal::tegn('Klar til henting', [
        'fbIngress' => 'Her legger vi ut når arbeidene fra et kurs er ferdig brent. Vi oppbevarer dem i ' . $uker . ' uker.',
        'fbHarNoe' => $liste !== [], 'fbTomt' => $liste === [], 'fbListe' => $liste, 'sant' => true,
    ], []) . "\n" . Deler::bunn(true),
    'aktiv' => '',
];
