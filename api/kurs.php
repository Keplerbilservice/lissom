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

$kurs = DB::alle(
    "SELECT id, slug, tittel, type, tema, pris_ore, kapasitet, beskrivelse, bilde{$bilderFelt}{$utenDatoFelt}{$oppsettFelt}{$tekstFelt}{$kassaFelt}
       FROM courses
      WHERE status = 'publisert' AND {$hvor}
      ORDER BY type, tittel"
);

$ut = [];
foreach ($kurs as $k) {
    $ekstra = DB::harKolonne('course_sessions', 'pris_ore') ? ', pris_ore, info' : '';
    $okter = DB::alle(
        "SELECT id, start_tid, slutt_tid, kapasitet{$ekstra}
           FROM course_sessions
          WHERE course_id = :c
            AND status = 'planlagt'
            AND start_tid > UTC_TIMESTAMP()
          ORDER BY start_tid",
        ['c' => $k['id']]
    );

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
                // start- og sluttida paa oektene. «varighet_tekst» overstyrer
                // for de kursene som trenger en annen formulering.
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
        'gjenstandIKassa' => (bool) ($k['gjenstand_i_kassa'] ?? 0),
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
        'punkter' => Medlemskap::punkter((string) ($k['punkter'] ?? '')),
        // Seksjonene fra kursoppsettet. Tomme felt vises ikke.
        //
        // «laerer» staar ikke her: den settes over, med malen som reserve.
        // Sto den begge steder, vant den siste — og den var tom. Det er den
        // samme fella som «bPasserFor» og «refLogoStil» gikk i: to like
        // navn i det samme objektet er bare én av dem.
        'praktisk'   => (string) ($k['praktisk'] ?? ''),
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
            'dato'     => Booking::norskPeriode((string) $o['start_tid'], $o['slutt_tid'] ?? null),
            // Raa starttid slik den staar i basen. Kalenderen trenger den for
            // aa sortere okter paa ukedag; norsk datotekst kan ikke regnes paa.
            'startUtc' => $o['start_tid'],
            'ledige'   => Booking::ledigePlasser((int) $o['id']),
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
            'samlinger' => Samlinger::forOkt((int) $o['id']),
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
