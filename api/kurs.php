<?php
/**
 * Kurskatalogen med ledige plasser. Aapent endepunkt — dette er offentlig
 * informasjon, det samme som staar paa kurssiden.
 *
 * Med ett unntak: samlinger merket «Kun for medlemmer» sendes bare til den
 * som er innlogget som medlem. De sto tidligere i den offentlige lista, saa
 * en medlemsfrokost var synlig for alle — bookbar var den riktignok ikke.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$erMedlem = ($m = Sesjon::medlem()) !== null && er_aktivt_medlem($m);
$hvor = $erMedlem ? '1' : "COALESCE(tema, '') <> 'Kun for medlemmer'";

// Kolonna kommer med migrasjon 029. Er den ikke kjort, skal kurslista vises
// som for framfor aa gi en tom side.
$utenDatoFelt = DB::harKolonne('courses', 'vis_uten_dato') ? ', vis_uten_dato' : '';
// Kom med migrasjon 065. Uten sjekken faller katalogen naar den ikke er
// kjoert.
$oppsettFelt  = DB::harKolonne('courses', 'punkter')
    ? ', punkter, laerer, praktisk, allergener, passer_nivaa, passer_hvem, metode, varighet' : '';
// Kom med migrasjon 044. Uten sjekken faller hele katalogen naar den ikke er
// kjoert — og det er katalogen kundene ser.
$bilderFelt   = DB::harKolonne('courses', 'bilder') ? ', bilder' : '';
// Kursnivaa, tekstene og varigheten. Kom med migrasjon 072.
$tekstFelt = DB::harKolonne('courses', 'nivaa_tekst')
    ? ', nivaa_intern, nivaa_tekst, kort_beskrivelse, lager_du, med_hjem, ferdig_tid, tillegg, varighet_tekst' : '';
// «Gjenstanden betales i verkstedet». Kom med migrasjon 074.
$kassaFelt = DB::harKolonne('courses', 'gjenstand_i_kassa') ? ', gjenstand_i_kassa' : '';
// «Datoene lages av aapningstidene» — Paint on Pots og drop-in. Kom med
// migrasjon 079. Foer laa den inni gjenstand_i_kassa, som gjorde to jobber.
$apenFelt = DB::harKolonne('courses', 'folger_apningstid') ? ', folger_apningstid' : '';
// Det faste vinduet — «hver dag 08–22». Kom med migrasjon 102.
$vinduFelt = DB::harKolonne('courses', 'fast_fra') ? ', fast_fra, fast_til' : '';

// ── Billigste gjenstanden i butikken ────────────────────────────────────
//
// Paint on Pots og lignende: prisen kunden ser skal vaere det lavest mulige
// hen kan betale — plassen pluss den rimeligste tingen som faktisk staar til
// salgs. «Fra kr. 290,-» sto skrevet inn i koden foer, og var ikke et tall
// som fantes noe sted.
//
// Bare det som er aapent for alle, og bare det som er paa lager. En vare det
// er null igjen av er ikke en pris noen kan faa.
$gjenstandFra = DB::harKolonne('courses', 'gjenstand_i_kassa')
    ? (int) (DB::verdi(
        "SELECT MIN(pris_ore) FROM products
          WHERE status = 'publisert' AND kun_medlemmer = 0 AND pris_ore > 0
            AND (lager IS NULL OR lager > 0)"
      ) ?? 0)
    : 0;

$kurs = DB::alle(
    "SELECT id, slug, tittel, type, tema, pris_ore, kapasitet, beskrivelse, bilde{$bilderFelt}{$utenDatoFelt}{$oppsettFelt}{$tekstFelt}{$kassaFelt}{$apenFelt}{$vinduFelt}
       FROM courses
      WHERE status = 'publisert' AND {$hvor}
      ORDER BY type, tittel"
);

// ── Datoene, i tre sporringer i alt ─────────────────────────────────────
//
// Her sto det én sporring per kurs etter datoene, og deretter — inne i
// visningen — ett kall per dato etter ledige plasser og ett etter samlinger.
// Tre sporringer per dato: 83 datoer ble 249 sporringer paa én sidevisning.
//
// Det tallet vokser av seg selv naa. Paint on Pots og drop-in lager datoene
// sine av aapningstidene, og fjorten dager framover blir fort et par hundre.
// Katalogen er det forste nettsiden henter, saa den betaler alle.
$ekstra = DB::harKolonne('course_sessions', 'pris_ore') ? ', pris_ore, info' : '';
$kursIder = array_map(static fn(array $k): int => (int) $k['id'], $kurs);

$okterPerKurs = [];
if ($kursIder !== []) {
    $inn = implode(',', $kursIder);
    foreach (DB::alle(
        "SELECT id, course_id, start_tid, slutt_tid, kapasitet{$ekstra}
           FROM course_sessions
          WHERE course_id IN ({$inn})
            AND status = 'planlagt'
            AND start_tid > UTC_TIMESTAMP()
          ORDER BY start_tid"
    ) as $o) {
        // Ferie. Datoen blir usynlig paa nettsida saa lenge dagen staar som
        // stengt — oekta selv roeres ikke, saa alt er tilbake naar ferien
        // tas bort. Se app/lib/ferie.php for hvorfor det gjores her og ikke
        // i sporringen.
        if (Ferie::stengt((string) $o['start_tid'])) {
            continue;
        }
        $okterPerKurs[(int) $o['course_id']][] = $o;
    }
}

$alleOkter  = array_merge(...(array_values($okterPerKurs) ?: [[]]));
$oktIder    = array_map(static fn(array $o): int => (int) $o['id'], $alleOkter);
$ledigeKart = Booking::ledigePlasserFlere($oktIder);
// Maa leses etter ledigePlasserFlere: den regner det ut, denne henter svaret.
$sperretKart = Booking::sperretAvAnnet($oktIder);
$samlingKart = Samlinger::forOkter($oktIder);

$ut = [];
foreach ($kurs as $k) {
    $okter = $okterPerKurs[(int) $k['id']] ?? [];

    $ut[] = [
        'id'      => (int) $k['id'],
        'slug'    => $k['slug'],
        'tittel'  => $k['tittel'],
        'type'    => $k['type'],
        'tema'    => $k['tema'],
        // Kurs som skal staa paa nettsida ogsaa uten datoer. Date Night
        // forsvant helt da datoene tok slutt — det finnes fortsatt, det
        // settes bare opp naar noen sporr.
        'utenDatoOk' => (bool) ($k['vis_uten_dato'] ?? 0),
        'pris'    => Booking::kroner((int) $k['pris_ore']),
        'prisOre' => (int) $k['pris_ore'],
        'om'      => $k['beskrivelse'],
        // Nivaaet kunden leser, varigheten regnet av oektene, og tekstene.
        //
        // Alt sammen faller tilbake paa malen for kurstypen naar feltet er
        // tomt — malen ligger i app/lib/kursmal.php, ikke i nettsida. Da kan
        // teksten endres uten at noen roerer frontend, og et kurs som er
        // skrevet om for haand beholder sitt eget.
        ...(static function () use ($k, $okter): array {
            $mal = Kursmal::forKurs($k);
            $velg = static fn(string $felt, string $malfelt): string =>
                trim((string) ($k[$felt] ?? '')) !== ''
                    ? trim((string) $k[$felt]) : (string) ($mal[$malfelt] ?? '');
            return [
                'nivaaTekst'      => $velg('nivaa_tekst', 'nivaaTekst') ?: Kursmal::NIVAA_UTE,
                'nivaaIntern'     => (string) ($k['nivaa_intern'] ?? ''),
                'kortBeskrivelse' => $velg('kort_beskrivelse', 'kortBeskrivelse'),
                'laerer'          => $velg('laerer', 'laerer'),
                // De faa ordene som staar i faktaboksen. Hele setningen
                // staar under «Dette laerer du»; boksen er smal.
                'laererKort'      => (string) ($mal['laererKort'] ?? ''),
                'lagerDu'         => $velg('lager_du', 'lagerDu'),
                'medHjem'         => $velg('med_hjem', 'medHjem'),
                'ferdigTid'       => $velg('ferdig_tid', 'ferdigTid'),
                'tillegg'         => $velg('tillegg', 'tillegg'),
                // Varigheten er ikke en tekst noen har skrevet: den regnes av
                // start- og sluttida paa oektene, alltid. Kolonnen
                // «varighet_tekst» overstyrte den for; det ble fjernet 30.
                // august — se Kursmal::varighetFor.
                'varighetVist'    => Kursmal::varighetFor($k, array_map(static fn($o) => [
                    'start'     => (string) $o['start_tid'],
                    'slutt'     => $o['slutt_tid'] ?? null,
                    'samlinger' => 1,
                ], $okter)),
            ];
        })(),
        // Paint on Pots og andre der gjenstanden velges i verkstedet.
        //
        // Prisen paa kurset er da prisen paa plassen, ikke paa det du gaar
        // hjem med. «gjenstandFra» er den billigste tingen i butikken som
        // faktisk kan males, saa siden slipper aa love et tall som er
        // skrevet inn i koden.
        ...(static function () use ($k, $gjenstandFra): array {
            if (!($k['gjenstand_i_kassa'] ?? 0)) {
                return ['gjenstandIKassa' => false];
            }
            // Det laveste kunden kan betale: plassen pluss den rimeligste
            // gjenstanden. Er plassen gratis, er det gjenstanden alene.
            $fra = (int) $k['pris_ore'] + $gjenstandFra;
            return [
                'gjenstandIKassa' => true,
                'gjenstandFraOre' => $gjenstandFra,
                'gjenstandFra'    => $gjenstandFra > 0 ? Booking::kroner($gjenstandFra) : '',
                // Prisen kortene skal vise. «Fra» staar i teksten paa kortet.
                'prisFraOre'      => $fra,
                'prisFra'         => $fra > 0 ? Booking::kroner($fra) : 'Gratis',
            ];
        })(),
        // Kurs der datoene lages av aapningstidene: Paint on Pots og drop-in.
        //
        // Bestillingen viser dager forst og klokkeslett etterpaa, og maa kunne
        // si hvor lenge man har plassen. Lengden staar ett sted — Apent — og
        // hentes derfra, saa teksten under knappene ikke kan si noe annet enn
        // det tidene faktisk er klippet i.
        ...(static function () use ($k): array {
            if (!($k['folger_apningstid'] ?? 0)) {
                return ['folgerApningstid' => false];
            }
            $min = Apent::PLASS_MINUTTER;
            $ord = [30 => 'en halvtime', 45 => 'tre kvarter', 60 => 'én time',
                    90 => 'halvannen time', 120 => 'to timer', 150 => 'to og en halv time',
                    180 => 'tre timer', 240 => 'fire timer'];
            return [
                'folgerApningstid' => true,
                'plassMinutter'    => $min,
                'plassVarighet'    => $ord[$min] ?? ($min . ' minutter'),
                // Det faste vinduet, naar kurset har et. Nettsida skriver
                // «hver dag 08–22» av dette — den skal ikke ha to
                // klokkeslett skrevet inn for haand som kan bli uenige med
                // plassene som faktisk ligger ute.
                'fastFra' => substr((string) ($k['fast_fra'] ?? ''), 0, 5),
                'fastTil' => substr((string) ($k['fast_til'] ?? ''), 0, 5),
            ];
        })(),
        // Antall plasser kurset har. Nettsida skrev det som fast tekst —
        // «Maks aatte deltakere» — mens tallet under kom fra basen. De to
        // sto rett over hverandre og var uenige. Naa kommer begge herfra.
        'plasser' => (int) $k['kapasitet'],
        // «Alt som er inkludert».
        //
        // Lista sto fast i koden, og kunne ikke endres noe sted. Verkstedet
        // ba i juni om aa ta «verktoy» ut av ett kurs; det maatte gjores av
        // meg. Naa staar den paa kurset, og tom kolonne betyr «som for».
        //
        // «Maks N deltakere» staar ikke i lista. Den regnes av kapasiteten
        // rett under, saa de to ikke kan bli uenige — det var nettopp den
        // feilen som ble rettet i juni.
        // Punktlista. Er den ikke skrevet paa kurset, kommer den fra malen
        // — «Leire, verktoy, glasur og brenning» hoerer ikke hjemme paa et
        // kurs der man maler ferdig brent keramikk.
        'punkter' => (static function () use ($k): array {
            $egne = Medlemskap::punkter((string) ($k['punkter'] ?? ''));
            if ($egne !== []) {
                return $egne;
            }
            return Medlemskap::punkter((string) (Kursmal::forKurs($k)['punkter'] ?? ''));
        })(),
        // Seksjonene fra kursoppsettet. Tomme felt vises ikke.
        //
        // «laerer» staar ikke her: den settes over, med malen som reserve.
        // Sto den begge steder, vant den siste — og den var tom. Det er den
        // samme fella som «bPasserFor» og «refLogoStil» gikk i: to like
        // navn i det samme objektet er bare én av dem.
        // Praktisk informasjon, «naar er den ferdig» og «godt aa vite»
        // faller tilbake paa standardteksten for kategorien naar kurset ikke
        // sier noe selv — den eieren har skrevet i kursoppsettet. Samme regel
        // som punktlista over.
        'praktisk'   => (string) ($k['praktisk'] ?? '') !== ''
            ? (string) $k['praktisk']
            : (string) (Kursmal::forKurs($k)['praktisk'] ?? ''),
        'allergener' => (string) ($k['allergener'] ?? ''),
        'passerNivaa'=> (string) ($k['passer_nivaa'] ?? ''),
        'passerHvem' => (string) ($k['passer_hvem'] ?? ''),
        'metode'     => (string) ($k['metode'] ?? ''),
        'varighet'   => (string) ($k['varighet'] ?? ''),
        // Bildene verkstedet har valgt i admin. Foerste er hovedbildet;
        // resten er karusellen paa kurssida. Er lista tom, faller nettsida
        // tilbake paa bildet som hoerer til kurstypen.
        'bilde'   => (string) ($k['bilde'] ?? ''),
        'bilder'  => (static function ($raa): array {
            $l = json_decode((string) $raa, true);
            return is_array($l) ? array_values(array_filter(array_map('strval', $l))) : [];
        })($k['bilder'] ?? null),
        'datoer'  => array_map(static fn($o) => [
            'oktId'  => (int) $o['id'],
            // Paint on Pots og lignende er lagt ut paa aapningstidene: det er
            // et vindu, ikke et klokkeslett. «Tirsdag 1. september, 10:00» ser
            // ut som at kurset starter da og at du kommer for sent 10:05.
            // Staar hele spennet, ser man at doeren er aapen hele tida.
            'dato'     => ((int) ($k['folger_apningstid'] ?? $k['gjenstand_i_kassa'] ?? 0) === 1
                            && !empty($o['slutt_tid']))
                ? Booking::norskSpenn((string) $o['start_tid'], (string) $o['slutt_tid'])
                : Booking::norskPeriode((string) $o['start_tid'], $o['slutt_tid'] ?? null),
            // Dagen for seg, og klokkeslettet for seg.
            //
            // Bookingen viser tre dager, og klokkeslettene under den dagen man
            // velger. Da maa de to vaere hver sin verdi — aa klippe dem ut av
            // «tirsdag 1. september, 10:00–12:00» med et komma er en gjetning
            // som ryker paa det forste flerdagerskurset.
            'dag'      => (static function (string $utc): string {
                $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Europe/Oslo'));
                $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
                $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                        'juli', 'august', 'september', 'oktober', 'november', 'desember'];
                return $dager[(int) $d->format('N') - 1] . ' ' . (int) $d->format('j')
                     . '. ' . $mnd[(int) $d->format('n') - 1];
            })((string) $o['start_tid']),
            // Bare naar det begynner. Kunden velger et tidspunkt, og har
            // plassen i to timer fra da — da er sluttiden ikke et valg.
            'klokkeStart' => (new DateTimeImmutable((string) $o['start_tid'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Oslo'))->format('H:i'),
            'klokke'   => (static function (string $start, ?string $slutt): string {
                $sone = new DateTimeZone('Europe/Oslo');
                $a = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone($sone);
                if ($slutt === null || $slutt === '') {
                    return $a->format('H:i');
                }
                $b = (new DateTimeImmutable($slutt, new DateTimeZone('UTC')))->setTimezone($sone);
                // Gaar den over midnatt, sier vi bare naar den begynner.
                return $a->format('Y-m-d') === $b->format('Y-m-d')
                    ? $a->format('H:i') . '–' . $b->format('H:i')
                    : $a->format('H:i');
            })((string) $o['start_tid'], $o['slutt_tid'] ?? null),
            // Raa starttid slik den staar i basen. Kalenderen trenger den for
            // aa sortere okter paa ukedag; norsk datotekst kan ikke regnes paa.
            'startUtc' => $o['start_tid'],
            'ledige'   => $ledigeKart[(int) $o['id']] ?? 0,
            // Full fordi noe annet holder ressursen. En drop-in-time midt i
            // et dreiekurs er ikke «fullbooket» — det gaar et kurs, og
            // skivene staar dekket til det.
            'sperret'  => $sperretKart[(int) $o['id']] ?? false,
            // Datoen kan ha faerre plasser enn kurset ellers.
            'plasser'  => (int) ($o['kapasitet'] ?: $k['kapasitet']),
            // Prisen kan avvike paa én dato. Tomt betyr «som kurset».
            'pris'     => isset($o['pris_ore']) && $o['pris_ore'] !== null
                            ? Booking::kroner((int) $o['pris_ore']) : null,
            'prisOre'  => isset($o['pris_ore']) && $o['pris_ore'] !== null
                            ? (int) $o['pris_ore'] : null,
            'info'     => (string) ($o['info'] ?? ''),
            // Samlingene, naar kurset gaar over flere dager. Deltakeren skal
            // se at paameldingen gjelder alle sammen — ikke bare den forste.
            'samlinger' => $samlingKart[(int) $o['id']] ?? [],
        ], $okter),
    ];
}

// Rabattnivaaene folger med. Bookingsiden viste tidligere rabatter den fant
// paa selv, mens serveren trakk full pris — naa leser begge det samme.
$nivaer = array_map(static fn($r) => [
    'min'     => (int) $r['min_antall'],
    'prosent' => (float) $r['prosent'],
    'gjelder' => $r['gjelder'],
], DB::alle('SELECT min_antall, prosent, gjelder FROM discount_tiers WHERE aktiv = 1 ORDER BY min_antall'));

// Fokuspunktene: hvilken del av hvert bilde ramma skal sentreres paa.
Svar::json(['kurs' => $ut, 'rabatter' => $nivaer, 'fokus' => Bilder::fokus()]);
