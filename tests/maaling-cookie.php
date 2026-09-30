<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/lib/maaling.php';
$tester = [
    'GS1' => ['GS1.1.1790780000.1.1.1790780200.0.0.0', '1790780000'],
    'GS2' => ['GS2.1.s1790780000$o1$g1$t1790780200$j60$l0$h0', '1790780000'],
    'GS2 annen rekkefolge' => ['GS2.1.o2$s1790780000$g0', '1790780000'],
    'GS2 kodet' => ['GS2.1.s1790780000%24o1%24g0', '1790780000'],
    'ugyldig' => ['GS2.1.sikke-en-oekt$o1', null],
    'ukjent format' => ['GS3.1.1790780000.1', null],
];
foreach ($tester as $navn => [$cookie, $ventet]) {
    $_COOKIE = ['lissom-maaling' => 'ja-20260930', '_ga' => 'GA1.1.123456.789012', '_ga_GMJSTL5KP2' => $cookie];
    $r = json_decode(Maaling::sporingFraNettleser(), true);
    if (($r['sid'] ?? null) !== $ventet || ($r['cid'] ?? null) !== '123456.789012') {
        fwrite(STDERR, "FEIL: $navn\n"); exit(1);
    }
    echo "OK: $navn\n";
}
$_COOKIE = [];
if (Maaling::sporingFraNettleser() !== '') { fwrite(STDERR, "FEIL: tomme cookies\n"); exit(1); }
echo "OK: ingen cookies sender ingen sporing\n";
foreach (['', 'nei', 'ja-gammel'] as $samtykke) {
    $_COOKIE = ['lissom-maaling' => $samtykke, '_ga' => 'GA1.1.123456.789012', '_fbp' => 'fb.1.1790780000.123456'];
    if (Maaling::sporingFraNettleser() !== '') { fwrite(STDERR, "FEIL: manglende/avvist/gammelt samtykke\n"); exit(1); }
}
echo "OK: gamle maalecookies uten gyldig samtykke sender ingen sporing\n";
