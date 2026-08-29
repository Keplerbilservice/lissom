<?php
/**
 * Kurs og datoer.
 *
 *   GET                     alle kurs, ogsaa kladder
 *   POST handling=lagre     opprett eller endre et kurs
 *   POST handling=nydato    legg til en dato
 *   POST handling=plasser   endre antall plasser paa én dato
 *   POST handling=endredato endre tidspunktet paa én dato
 *   POST handling=avlys     avlys en dato
 *   POST handling=slettdato ta bort en dato (avlyses om noen er paameldt)
 *   POST handling=dato      pris, info og samlinger paa én dato
 *   POST handling=slett     fjern et kurs (avlyses om noen er paameldt)
 *   POST handling=bekreftelseStandard  lagre teksten nye kurs fylles ut med
 *
 * Prisen som settes her er den kunden faktisk trekkes. Nettleseren sender
 * aldri belop ved booking — den slaas opp herfra.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

// ---------------------------------------------------------------- lesing
if (Foresporsel::metode() === 'GET') {
    $kurs = DB::alle('SELECT * FROM courses ORDER BY status, tittel');

    // ── Datoene, hentet én gang ─────────────────────────────────────────
    //
    // Her sto det én sporring per kurs etter datoene, og deretter ett kall
    // per dato etter ledige plasser og ett etter samlinger. Kursoppsettet er
    // skjermen Lissom har oppe oftest, og etter at Paint on Pots og drop-in
    // begynte aa lage datoene sine av aapningstidene teller den fort et par
    // hundre datoer. Da ble det over 250 sporringer for aa tegne én skjerm.
    $ekstra   = DB::harKolonne('course_sessions', 'pris_ore') ? ', pris_ore, info' : '';
    // Kursholderen paa den enkelte datoen (migrasjon 085). Kolonnen kan
    // mangle om vedlikeholdet ikke er kjort — da staar feltet tomt i skjemaet
    // og alt annet virker som for.
    $harHolder = DB::harKolonne('course_sessions', 'kursholder_id');
    if ($harHolder) {
        $ekstra .= ', kursholder_id';
    }

    // Navnene, slaatt opp én gang. Uten dette ble det ett oppslag per dato.
    $stdKol  = DB::harKolonne('kursholdere', 'standard') ? ', standard' : ', 0 AS standard';
    $holdere = DB::harTabell('kursholdere')
        ? DB::alle('SELECT id, navn, rolle, aktiv' . $stdKol . ' FROM kursholdere ORDER BY aktiv DESC, navn')
        : [];
    $holderNavn = [];
    foreach ($holdere as $h) {
        $holderNavn[(int) $h['id']] = (string) $h['navn'];
    }
    $kursIder = array_map(static fn(array $k): int => (int) $k['id'], $kurs);

    $okterPerKurs = [];
    $datoerFramover = [];
    if ($kursIder !== []) {
        $inn = implode(',', $kursIder);
        foreach (DB::alle(
            'SELECT id, course_id, start_tid, slutt_tid, kapasitet, status' . $ekstra . '
               FROM course_sessions WHERE course_id IN (' . $inn . ') ORDER BY start_tid'
        ) as $o) {
            $okterPerKurs[(int) $o['course_id']][] = $o;
        }
        // «Hvor mange datoer ligger framover» sto som en egen COUNT per kurs.
        foreach (DB::alle(
            "SELECT course_id, COUNT(*) n FROM course_sessions
              WHERE course_id IN ({$inn}) AND status = 'planlagt'
                AND start_tid > UTC_TIMESTAMP()
           GROUP BY course_id"
        ) as $r) {
            $datoerFramover[(int) $r['course_id']] = (int) $r['n'];
        }
    }

    $alleOkter   = array_merge(...(array_values($okterPerKurs) ?: [[]]));
    $oktIder     = array_map(static fn(array $o): int => (int) $o['id'], $alleOkter);
    $ledigeKart  = Booking::ledigePlasserFlere($oktIder);
    $samlingKart = Samlinger::forOkter($oktIder);

    Svar::json(['kurs' => array_map(static function ($k) use (
        $okterPerKurs, $datoerFramover, $ledigeKart, $samlingKart, $holderNavn
    ) {
        $okter = $okterPerKurs[(int) $k['id']] ?? [];
        return [
            'id'         => (int) $k['id'],
            'slug'       => $k['slug'],
            'tittel'     => $k['tittel'],
            'type'       => $k['type'],
            'tema'       => $k['tema'],
            'bilde'      => (string) ($k['bilde'] ?? ''),
            'bilder'     => (static function ($raa): array {
                $l = json_decode((string) $raa, true);
                return is_array($l) ? array_values(array_filter(array_map('strval', $l))) : [];
            })($k['bilder'] ?? null),
            'pris'       => (int) $k['pris_ore'] / 100,
            'kapasitet'  => (int) $k['kapasitet'],
            'sms'        => (bool) $k['sms_paaminnelse'],
            'status'     => $k['status'],
            'visUtenDato'=> (bool) ($k['vis_uten_dato'] ?? 0),
            'serier'     => Serier::forKurs((int) $k['id']),
            // Hvor mange datoer som ligger framover. Kursoppsettet sier med
            // dette hva et nytt navn faktisk gjelder for.
            'datoerFramover' => $datoerFramover[(int) $k['id']] ?? 0,
            'om'         => $k['beskrivelse'],
            'instruktor' => $k['instruktor'],
            'bekreftelse'=> $k['bekreftelse_tekst'],
            // Seksjonene fra kursoppsettet (migrasjon 065). Tomme naar
            // migrasjonen ikke er kjort — da staar feltene tomme i skjemaet
            // og nettsida viser det samme som for.
            'punkter'    => (string) ($k['punkter'] ?? ''),
            'laerer'     => (string) ($k['laerer'] ?? ''),
            'praktisk'   => (string) ($k['praktisk'] ?? ''),
            'allergener' => (string) ($k['allergener'] ?? ''),
            'passerNivaa'=> (string) ($k['passer_nivaa'] ?? ''),
            'passerHvem' => (string) ($k['passer_hvem'] ?? ''),
            'metode'     => (string) ($k['metode'] ?? ''),
            'varighet'   => (string) ($k['varighet'] ?? ''),
            // Kursnivaa, tekstene og varigheten (migrasjon 072).
            //
            // «mal» er den anbefalte teksten for dette kurset. Den lagres
            // ikke — den staar her saa skjermen kan tilby «Gjenopprett
            // anbefalt tekst» uten at noe skrives over av seg selv.
            'nivaaIntern'     => (string) ($k['nivaa_intern'] ?? ''),
            'nivaaTekst'      => (string) ($k['nivaa_tekst'] ?? ''),
            'kortBeskrivelse' => (string) ($k['kort_beskrivelse'] ?? ''),
            'lagerDu'         => (string) ($k['lager_du'] ?? ''),
            'medHjem'         => (string) ($k['med_hjem'] ?? ''),
            'ferdigTid'       => (string) ($k['ferdig_tid'] ?? ''),
            'tillegg'         => (string) ($k['tillegg'] ?? ''),
            'varighetTekst'   => (string) ($k['varighet_tekst'] ?? ''),
            // «Gjenstanden betales i verkstedet» (migrasjon 074). Paint on
            // Pots: plassen bookes, gjenstanden slaas inn i kassa.
            'gjenstandIKassa' => (bool) ($k['gjenstand_i_kassa'] ?? 0),
            'mal'             => Kursmal::forKurs($k),
            // Varigheten regnet av oektene, slik kunden faktisk ser den.
            'varighetVist'    => Kursmal::varighetFor($k, array_map(static fn($o) => [
                'start'     => (string) $o['start_tid'],
                'slutt'     => $o['slutt_tid'] ?? null,
                'samlinger' => 1,
            ], $okter)),
            'datoer'     => array_map(static fn($o) => [
                'oktId'     => (int) $o['id'],
                'naar'      => Booking::norskPeriode((string) $o['start_tid'], $o['slutt_tid'] ?? null),
                'startUtc'  => $o['start_tid'],
                // Sluttiden manglet her. Veiviseren viste «--:--» for hver
                // eneste dato, og lagret du kurset, ble slutt satt lik start
                // — en okt uten varighet.
                'sluttUtc'  => $o['slutt_tid'],
                'status'    => $o['status'],
                'ledige'    => $ledigeKart[(int) $o['id']] ?? 0,
                // Pris og informasjon som gjelder bare denne datoen. NULL
                // betyr «som kurset» — da skal feltet staa tomt i skjemaet,
                // ikke fylt med kursets pris som om den var satt her.
                'pris'      => isset($o['pris_ore']) && $o['pris_ore'] !== null
                                 ? (int) $o['pris_ore'] / 100 : null,
                'info'      => (string) ($o['info'] ?? ''),
                // Kursholderen for akkurat denne gangen. Den som holder
                // dreiekurset i september er ikke noedvendigvis den samme
                // som i oktober, og det er datoen folk moeter opp paa.
                'kursholderId' => isset($o['kursholder_id']) && $o['kursholder_id'] !== null
                    ? (int) $o['kursholder_id'] : 0,
                'kursholder'   => isset($o['kursholder_id']) && $o['kursholder_id'] !== null
                    ? ($holderNavn[(int) $o['kursholder_id']] ?? '') : '',
                // Samlingene, for et kurs som gaar over flere dager.
                'samlinger' => $samlingKart[(int) $o['id']] ?? [],
            ], $okter),
        ];
    }, $kurs),
    // Standardteksten nye kurs fylles ut med. Ligger i innstillinger, saa
    // eieren kan endre den uten en ny utlegging av nettsiden.
    'bekreftelseStandard' => (string) Config::hent('kurs_bekreftelse', ''),
    // Kursholderne, saa datoene kan tildeles uten et oppslag til.
    'kursholdere' => array_map(static fn($h) => [
        'id'    => (int) $h['id'],
        'navn'  => (string) $h['navn'],
        'rolle' => (string) ($h['rolle'] ?? ''),
        'aktiv' => (bool) $h['aktiv'],
        // Den som foreslaas paa nye datoer. Bare én er det.
        'standard' => (bool) ($h['standard'] ?? 0),
    ], $holdere),
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling', 'lagre');

/**
 * Kursholderen som ble valgt, eller null.
 *
 * Tomt felt og «0» betyr ikke tildelt. En id som ikke finnes avvises framfor
 * aa lagres — da ville datoen pekt paa en kursholder som ikke er der, og
 * navnet blitt borte uten at noen skjonte hvorfor.
 */
$holderId = static function (string $felt): ?int {
    $id = Foresporsel::heltall($felt);
    if ($id <= 0) {
        return null;
    }
    if (!DB::harTabell('kursholdere')
        || DB::en('SELECT id FROM kursholdere WHERE id = :i', ['i' => $id]) === null) {
        Svar::feil('Fant ikke kursholderen.');
    }
    return $id;
};

/** «2026-09-02 17:30» i norsk tid → «2026-09-02 15:30:00» UTC for lagring. */
$tilUtc = static function (string $norsk): ?string {
    $norsk = trim($norsk);
    if ($norsk === '') {
        return null;
    }
    try {
        $d = new DateTimeImmutable($norsk, new DateTimeZone('Europe/Oslo'));
    } catch (Throwable) {
        return null;
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
};

switch ($handling) {

    // ------------------------------------------------------------ lagre kurs
    case 'lagre':
        $id     = Foresporsel::heltall('id');
        $tittel = mb_substr(Foresporsel::tekst('tittel'), 0, 191);
        $pris   = Foresporsel::heltall('pris');           // kroner

        if ($tittel === '') {
            Svar::feil('Kurset må ha en tittel.');
        }
        if ($pris < 0) {
            Svar::feil('Prisen kan ikke være negativ.');
        }

        // Bare det kallet faktisk har med.
        //
        // Alt sto her ubetinget, med en standardverdi bak. «kapasitet» falt
        // dermed til 8 hver gang noe lagret et kurs uten aa sende plasstallet
        // — et kurs med tolv plasser ble stille satt til aatte, og ingen saa
        // det for noen lurte paa hvor de fire plassene ble av. Det samme
        // gjaldt tema, pris, beskrivelse og SMS-haken.
        //
        // Tittel og status staar igjen som paakrevde: et kurs uten navn er
        // avvist lenger oppe, og status er alltid med fra skjemaene.
        $kropp = Foresporsel::kropp();
        $har = static fn(string $n): bool => array_key_exists($n, $kropp);

        $data = ['tittel' => $tittel];
        if ($har('type')) {
            $data['type'] = in_array(Foresporsel::tekst('type'), ['kurs', 'event', 'dropin', 'workshop'], true)
                ? Foresporsel::tekst('type') : 'kurs';
        }
        if ($har('tema')) {
            $data['tema'] = mb_substr(Foresporsel::tekst('tema'), 0, 64) ?: null;
        }
        if ($har('pris')) {
            $data['pris_ore'] = $pris * 100;
        }
        if ($har('kapasitet')) {
            $data['kapasitet'] = max(1, min(999, Foresporsel::heltall('kapasitet', 8)));
        }
        if ($har('sms')) {
            $data['sms_paaminnelse'] = Foresporsel::tekst('sms') === 'nei' ? 0 : 1;
        }
        if ($har('om')) {
            $data['beskrivelse'] = Foresporsel::tekst('om') ?: null;
        }
        // Navnet paa kursbeviset. Tomt betyr Monica, som staar i malen.
        if ($har('instruktor')) {
            $data['instruktor'] = mb_substr(Foresporsel::tekst('instruktor'), 0, 191) ?: null;
        }
        if ($har('bekreftelse')) {
            $data['bekreftelse_tekst'] = Foresporsel::tekst('bekreftelse') ?: null;
        }
        $data['status'] = in_array(Foresporsel::tekst('status'), ['kladd', 'publisert', 'avlyst'], true)
            ? Foresporsel::tekst('status') : 'kladd';

        // Vis kurset paa nettsida ogsaa naar det ikke har datoer — da staar
        // det med «Kontakt oss» framfor en bookingknapp.
        //
        // Bare naar feltet faktisk er med. Sto det her uansett, ville et
        // skjema som ikke kjenner feltet — kursredigeringen — slaatt det av
        // igjen hver gang kurset ble lagret, uten at noen ba om det.
        if (array_key_exists('visUtenDato', Foresporsel::kropp())
            && DB::harKolonne('courses', 'vis_uten_dato')) {
            $data['vis_uten_dato'] = Foresporsel::tekst('visUtenDato') === 'ja' ? 1 : 0;
        }

        // Seksjonene fra kursoppsettet (migrasjon 065).
        //
        // Hver av dem skrives bare naar feltet faktisk er med i kallet. Et
        // skjema som ikke kjenner dem — kursredigeringen fra en eldre skjerm
        // — skal ikke toemme dem ved neste lagring.
        //
        // De redigerbare kurstekstene staar her av samme grunn. De laa foer
        // rett i $data og ble skrevet ved hver eneste lagring: hurtigskjemaet
        // paa mobil, som ikke kjenner feltene, sendte dem ikke, og da ble
        // «Dette lager du», «Dette faar du med hjem» og resten toemt paa et
        // kurs noen hadde skrevet dem paa.
        //
        // Tomt felt betyr «bruk den anbefalte teksten»: kolonnen settes til
        // NULL, teksten kommer fra malen ved lesning, og en senere endring i
        // malen naar fram til de kursene som ikke er skrevet om for haand.
        $tekstfelter = [
            'punkter'         => 'punkter',
            'laerer'          => 'laerer',
            'praktisk'        => 'praktisk',
            'allergener'      => 'allergener',
            'passerNivaa'     => 'passer_nivaa',
            'passerHvem'      => 'passer_hvem',
            'metode'          => 'metode',
            'varighet'        => 'varighet',
            'nivaaIntern'     => 'nivaa_intern',
            'nivaaTekst'      => 'nivaa_tekst',
            'kortBeskrivelse' => 'kort_beskrivelse',
            'lagerDu'         => 'lager_du',
            'medHjem'         => 'med_hjem',
            'ferdigTid'       => 'ferdig_tid',
            'tillegg'         => 'tillegg',
            'varighetTekst'   => 'varighet_tekst',
            'gjenstandIKassa' => 'gjenstand_i_kassa',
        ];
        foreach ($tekstfelter as $inn => $kolonne) {
            if (!array_key_exists($inn, Foresporsel::kropp()) || !DB::harKolonne('courses', $kolonne)) {
                continue;
            }
            $verdi = trim(Foresporsel::tekst($inn));
            // Punktlista lagres slik verkstedet skrev den, men uten tomme
            // linjer og uten kulepunkter de har satt selv — nettsida setter
            // sin egen prikk. Samme regel som paa medlemskapene.
            if ($kolonne === 'punkter') {
                $verdi = implode("\n", Medlemskap::punkter($verdi));
            }
            // En hake er 0 eller 1, aldri NULL.
            if ($kolonne === 'gjenstand_i_kassa') {
                $data[$kolonne] = ($verdi === 'ja' || $verdi === '1') ? 1 : 0;
                continue;
            }
            // Det interne nivaaet er et valg, ikke en fritekst. Tomt betyr
            // nybegynner — kolonnen skal aldri staa uten verdi.
            if ($kolonne === 'nivaa_intern') {
                $data[$kolonne] = in_array($verdi, ['nybegynner', 'videregaende'], true)
                    ? $verdi : 'nybegynner';
                continue;
            }
            $data[$kolonne] = $verdi !== '' ? $verdi : null;
        }

        // Bildene fra steg 3. Foerste er hovedbildet; hele lista er karusellen
        // paa kurssida. Bare naar feltet er med — et skjema som ikke kjenner
        // bilder skal ikke toemme dem.
        //
        // Bare filnavn vi selv har lagt ut: basename() klipper bort alt som
        // ligner en sti eller en adresse utenfra.
        if (array_key_exists('bilder', Foresporsel::kropp())) {
            $rene = [];
            foreach ((array) (Foresporsel::kropp()['bilder'] ?? []) as $f) {
                $navn = basename(trim((string) $f));
                if ($navn !== '') {
                    $rene[] = mb_substr($navn, 0, 191);
                }
            }
            $data['bilde'] = $rene[0] ?? null;
            if (DB::harKolonne('courses', 'bilder')) {
                $data['bilder'] = $rene ? json_encode(array_values($rene), JSON_UNESCAPED_SLASHES) : null;
            }
        }

        if ($id > 0) {
            DB::oppdater('courses', $data, ['id' => $id]);
            revider('kurs_endret', 'course', $id, ['tittel' => $tittel]);

            // Hvor mange datoer endringen gjelder.
            //
            // Datoene har ingen egen tittel, pris eller tekst — de peker paa
            // kurset. Endringen slaar altsaa gjennom paa alle sammen, og paa
            // nettsida. Det er meningen, men det skal staa: den som retter en
            // pris skal se hvor langt rettelsen rekker.
            $framover = (int) DB::verdi(
                "SELECT COUNT(*) FROM course_sessions
                  WHERE course_id = :k AND status <> 'avlyst'
                    AND start_tid >= UTC_TIMESTAMP()",
                ['k' => $id]
            );
            Svar::ok([
                'id'      => $id,
                'datoer'  => $framover,
                'beskjed' => $tittel . ' er lagret. '
                    . ($framover === 0
                        ? 'Kurset har ingen planlagte datoer ennå.'
                        : ($framover === 1
                            ? 'Endringen gjelder også den ene planlagte datoen.'
                            : 'Endringen gjelder også de ' . $framover . ' planlagte datoene.')),
            ]);
        }

        // Ny: lag en slug som ikke kolliderer med en eksisterende. Regelen
        // staar i Lenker::slug, sammen med artiklenes og butikkens.
        $grunn = Lenker::slug($tittel, 'kurs');
        $slug = $grunn;
        $n = 2;
        while (DB::en('SELECT id FROM courses WHERE slug = :s', ['s' => $slug]) !== null) {
            $slug = $grunn . '-' . $n++;
        }

        $nyId = DB::settInn('courses', $data + ['slug' => $slug]);
        revider('kurs_opprettet', 'course', $nyId, ['tittel' => $tittel]);
        Svar::ok(['id' => $nyId, 'slug' => $slug]);

    // ------------------------------------------------------------- ny dato
    case 'nydato':
        $kursId = Foresporsel::heltall('kursId');
        $start = $tilUtc(Foresporsel::tekst('start'));
        $slutt = $tilUtc(Foresporsel::tekst('slutt'));

        if ($kursId <= 0 || DB::en('SELECT id FROM courses WHERE id = :i', ['i' => $kursId]) === null) {
            Svar::feil('Ukjent kurs.');
        }
        if ($start === null) {
            Svar::feil('Skriv datoen som 2026-09-02 17:30.');
        }

        $nyOkt = [
            'course_id' => $kursId,
            'start_tid' => $start,
            'slutt_tid' => $slutt,
            'kapasitet' => Foresporsel::heltall('kapasitet') ?: null,
        ];
        // Kursholderen: den som ble valgt, ellers verkstedets standard.
        // Uten den maatte man valgt den samme personen paa hver eneste dato.
        if (DB::harKolonne('course_sessions', 'kursholder_id')) {
            $nyOkt['kursholder_id'] = array_key_exists('kursholderId', Foresporsel::kropp())
                ? $holderId('kursholderId')
                : (DB::harKolonne('kursholdere', 'standard')
                    ? DB::verdi('SELECT id FROM kursholdere WHERE standard = 1 AND aktiv = 1 LIMIT 1')
                    : null);
        }

        $oktId = DB::settInn('course_sessions', $nyOkt);
        revider('dato_lagt_til', 'course_session', $oktId, ['kurs' => $kursId, 'start' => $start]);
        Svar::ok(['oktId' => $oktId, 'naar' => Booking::norskDato($start)]);

    // ------------------------------------------------- fast ukedag (serie)
    //
    // «Hver torsdag 10:00» framfor én dato av gangen. Cron fyller paa
    // framover, saa kurset ikke gaar tomt og forsvinner fra nettsida.
    case 'serie':
        // Tabellen kommer med migrasjon 029. Uten den er det bedre aa si hva
        // som mangler enn aa la kallet doe paa en manglende tabell.
        if (!DB::harTabell('kurs_serier')) {
            Svar::feil('Faste ukedager krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
        }
        $kursId = Foresporsel::heltall('kursId');
        if ($kursId <= 0 || DB::en('SELECT id FROM courses WHERE id = :i', ['i' => $kursId]) === null) {
            Svar::feil('Ukjent kurs.');
        }
        // Gjentakelsen fra steg 2. Kolonnene kommer med migrasjon 056; uten
        // dem kan bare det ukentlige lagres, og det sier vi fra om framfor aa
        // late som om «annenhver uke» ble tatt vare paa.
        $utvidet = DB::harKolonne('kurs_serier', 'monster');
        $monster = Foresporsel::tekst('monster') ?: 'ukentlig';
        if (!in_array($monster, ['ukentlig', 'annenhver', 'manedlig'], true)) {
            $monster = 'ukentlig';
        }
        if (!$utvidet && $monster !== 'ukentlig') {
            Svar::feil('Annenhver uke og månedlig krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
        }

        $ukedag = Foresporsel::heltall('ukedag');
        $dagIMaaned = Foresporsel::heltall('dagIMaaned');
        if ($monster === 'manedlig') {
            if ($dagIMaaned < 1 || $dagIMaaned > 31) {
                Svar::feil('Velg hvilken dato i måneden kurset går, fra 1 til 31.');
            }
            // Ukedagen betyr ingenting for en manedlig regel. Den staar som 0
            // slik at den unike noekkelen ikke blander sammen to regler.
            $ukedag = 0;
        } else {
            if ($ukedag < 1 || $ukedag > 7) {
                Svar::feil('Velg en ukedag.');
            }
            $dagIMaaned = 0;
        }

        $klokke = static function (string $t): ?string {
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1 ? $t . ':00' : null;
        };
        $fra = $klokke(Foresporsel::tekst('fra'));
        $til = $klokke(Foresporsel::tekst('til'));
        if ($fra === null || $til === null) {
            Svar::feil('Skriv klokkeslettene som 10:00 og 13:00.');
        }

        // «Antall ganger». Tomt betyr «til noen tar regelen bort» — det er
        // slik feltet i veiviseren sier det, og slik det virket for.
        $antall = Foresporsel::heltall('antall');
        if ($antall < 0 || $antall > 500) {
            Svar::feil('Antall ganger må være mellom 1 og 500, eller stå tomt.');
        }

        if ($utvidet) {
            DB::kjor(
                'INSERT INTO kurs_serier (course_id, monster, ukedag, dag_i_maaned, fra, til,
                                          kapasitet, uker_fram, antall, start_dato, aktiv)
                 VALUES (:c, :m, :d, :dm, :f, :t, :k, :u, :n, :sd, 1)
                 ON DUPLICATE KEY UPDATE til = VALUES(til), kapasitet = VALUES(kapasitet),
                                         uker_fram = VALUES(uker_fram), antall = VALUES(antall),
                                         aktiv = 1',
                [
                    'c' => $kursId, 'm' => $monster, 'd' => $ukedag, 'dm' => $dagIMaaned,
                    'f' => $fra, 't' => $til,
                    'k' => Foresporsel::heltall('kapasitet') ?: null,
                    'u' => max(1, min(52, Foresporsel::heltall('ukerFram', 8))),
                    'n' => $antall > 0 ? $antall : null,
                    // Naar regelen begynner. Ogsaa holdepunktet for
                    // «annenhver uke» — flyttet det seg, ville annenhver uke
                    // byttet uke hver gang noen rorte regelen.
                    //
                    // «Ny kursdato» sender datoen du valgte. Da lager regelen
                    // ingenting for den dagen, og den forste gangen er den du
                    // faktisk satte opp.
                    'sd' => preg_match('/^\d{4}-\d{2}-\d{2}$/', Foresporsel::tekst('startDato')) === 1
                              ? Foresporsel::tekst('startDato') : gmdate('Y-m-d'),
                ]
            );
        } else {
            DB::kjor(
                'INSERT INTO kurs_serier (course_id, ukedag, fra, til, kapasitet, uker_fram, aktiv)
                 VALUES (:c, :d, :f, :t, :k, :u, 1)
                 ON DUPLICATE KEY UPDATE til = VALUES(til), kapasitet = VALUES(kapasitet),
                                         uker_fram = VALUES(uker_fram), aktiv = 1',
                [
                    'c' => $kursId, 'd' => $ukedag, 'f' => $fra, 't' => $til,
                    'k' => Foresporsel::heltall('kapasitet') ?: null,
                    'u' => max(1, min(52, Foresporsel::heltall('ukerFram', 8))),
                ]
            );
        }

        $laget = Serier::fyllPaa($kursId);
        revider('serie_lagret', 'course', $kursId,
                ['monster' => $monster, 'ukedag' => $ukedag, 'dagIMaaned' => $dagIMaaned, 'fra' => $fra]);
        Svar::ok([
            'serier'  => Serier::forKurs($kursId),
            'beskjed' => $laget > 0
                ? $laget . ($laget === 1 ? ' dato' : ' datoer') . ' er lagt ut framover.'
                : 'Datoene lå ute fra før.',
        ]);

    // Fjern en fast ukedag. Oktene som alt er lagt ut, blir staaende — folk
    // kan ha booket dem, og de skal avlyses én og én med «avlys».
    case 'serieAv':
        if (!DB::harTabell('kurs_serier')) {
            Svar::feil('Faste ukedager krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
        }
        $serieId = Foresporsel::heltall('serieId');
        $serie = DB::en('SELECT course_id FROM kurs_serier WHERE id = :i', ['i' => $serieId]);
        if ($serie === null) {
            Svar::feil('Fant ikke gjentakelsen.');
        }
        // Datoene regelen selv har laget.
        //
        // Her sto det bare at de ble staaende, og at de maatte avlyses én og
        // én. Et kurs som gjentar seg lager mange, og da er det ikke en jobb
        // noen gjor — lista vokser i stedet. Naa kan de ryddes bort samtidig.
        //
        // Bare datoene ingen har meldt seg paa, og bare de som ikke er
        // passert: en dato med paameldte peker paa bookinger og betalinger,
        // og en dato som har vaert er historikk.
        $ryddOgsaa = Foresporsel::tekst('slettDatoer') === 'ja';
        $slettet = 0;
        $beholdt = 0;
        if (DB::harKolonne('course_sessions', 'serie_id')) {
            $mine = DB::alle(
                "SELECT cs.id,
                        (SELECT COUNT(*) FROM bookings b
                          WHERE b.course_session_id = cs.id
                            AND b.status IN ('betalt','reservert')) AS pameldte
                   FROM course_sessions cs
                  WHERE cs.serie_id = :s AND cs.start_tid > UTC_TIMESTAMP()",
                ['s' => $serieId]
            );
            foreach ($mine as $m) {
                if ((int) $m['pameldte'] > 0) {
                    $beholdt++;
                    continue;
                }
                if ($ryddOgsaa) {
                    DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => (int) $m['id']]);
                    $slettet++;
                }
            }
        }

        DB::kjor('DELETE FROM kurs_serier WHERE id = :i', ['i' => $serieId]);
        revider('serie_fjernet', 'course', (int) $serie['course_id'],
                ['serie' => $serieId, 'datoer_slettet' => $slettet]);

        $tekst = 'Gjentakelsen er tatt bort, så det lages ingen nye datoer.';
        if ($ryddOgsaa) {
            $tekst .= $slettet > 0
                ? ' ' . $slettet . ($slettet === 1 ? ' dato' : ' datoer') . ' er ryddet bort.'
                : ' Det var ingen tomme datoer å rydde bort.';
            if ($beholdt > 0) {
                $tekst .= ' ' . $beholdt . ($beholdt === 1 ? ' dato har påmeldte og står igjen.'
                                                          : ' datoer har påmeldte og står igjen.');
            }
        } else {
            $tekst .= ' Datoene som alt ligger ute blir stående.';
        }

        Svar::ok([
            'serier'   => Serier::forKurs((int) $serie['course_id']),
            'slettet'  => $slettet,
            'beholdt'  => $beholdt,
            'beskjed'  => $tekst,
        ]);

    // --------------------------------------------------------------- avlys
    // ------------------------------------------------- antall plasser
    //
    // Plassene settes paa kurset, men den enkelte datoen kan avvike: en
    // kveld med to instruktoerer tar flere, en med sykdom faerre. Uten
    // dette matte man endre hele kurset for aa gi plass til én til paa
    // torsdag — og da gjaldt det alle datoene.
    case 'plasser':
        $oktId = Foresporsel::heltall('oktId');
        $okt = DB::en(
            'SELECT cs.id, cs.course_id, c.kapasitet AS kurskapasitet
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :o',
            ['o' => $oktId]
        );
        if ($okt === null) {
            Svar::feil('Fant ikke datoen.', 404);
        }

        $kropp = Foresporsel::kropp();
        $raa = trim((string) ($kropp['kapasitet'] ?? ''));
        // Tomt betyr «foelg kurset». Da er det ett sted aa endre plassene
        // for alle datoene, framfor et tall som maa rettes hver gang.
        $kapasitet = $raa === '' ? null : max(0, (int) $raa);

        // Plassene kan ikke settes lavere enn dem som alt har betalt. Da
        // ville lista vist «9 av 8», og neste kunde faatt en plass som ikke
        // finnes.
        $solgt = (int) DB::verdi(
            "SELECT COALESCE(SUM(antall), 0) FROM bookings
              WHERE course_session_id = :o AND status IN ('betalt','reservert')",
            ['o' => $oktId]
        );
        if ($kapasitet !== null && $kapasitet < $solgt) {
            Svar::feil('Det står allerede ' . $solgt . ' på denne datoen. Sett minst så mange plasser.');
        }

        DB::oppdater('course_sessions', ['kapasitet' => $kapasitet], ['id' => $oktId]);
        revider('dato_plasser', 'course_session', $oktId, ['kapasitet' => $kapasitet]);

        Svar::ok([
            'kapasitet' => $kapasitet ?? (int) $okt['kurskapasitet'],
            'ledige'    => Booking::ledigePlasser($oktId),
            'beskjed'   => $kapasitet === null
                ? 'Datoen følger kursets antall plasser igjen.'
                : 'Datoen har nå ' . $kapasitet . ' plasser.',
        ]);

    // ------------------------------------------------- flytte en dato
    //
    // Datoene kunne legges til, faa flere plasser og avlyses — men ikke
    // rettes. Ble klokkeslettet feil, var eneste vei aa avlyse og lage den
    // paa nytt, og da mistet de paameldte plassen sin. Naa flyttes den.
    case 'endredato':
        $oktId = Foresporsel::heltall('oktId');
        $okt = DB::en(
            'SELECT cs.id, cs.start_tid, cs.slutt_tid, c.tittel
               FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id
              WHERE cs.id = :o',
            ['o' => $oktId]
        );
        if ($okt === null) {
            Svar::feil('Fant ikke datoen.', 404);
        }

        $start = $tilUtc(Foresporsel::tekst('start'));
        $slutt = $tilUtc(Foresporsel::tekst('slutt'));
        if ($start === null) {
            Svar::feil('Skriv datoen som 2026-09-02 17:30.');
        }
        if ($slutt !== null && $slutt <= $start) {
            Svar::feil('Slutt må være etter start.');
        }

        DB::oppdater(
            'course_sessions',
            ['start_tid' => $start, 'slutt_tid' => $slutt],
            ['id' => $oktId]
        );
        revider('dato_flyttet', 'course_session', $oktId, [
            'fra' => (string) $okt['start_tid'],
            'til' => $start,
        ]);

        // De som alt staar paa lista faar ingen beskjed av seg selv. Det maa
        // sies her, ikke oppdages naar noen moeter opp paa feil klokkeslett.
        $berort = (int) DB::verdi(
            "SELECT COALESCE(SUM(antall), 0) FROM bookings
              WHERE course_session_id = :o AND status IN ('betalt','reservert')",
            ['o' => $oktId]
        );

        Svar::ok([
            'naar'    => Booking::norskDato($start),
            'berort'  => $berort,
            'beskjed' => $berort === 0
                ? 'Datoen er flyttet til ' . Booking::norskDato($start) . '.'
                : 'Datoen er flyttet til ' . Booking::norskDato($start) . '. '
                  . $berort . ($berort === 1 ? ' påmeldt' : ' påmeldte')
                  . ' får ikke beskjed automatisk — send den under Påmeldte.',
        ]);

    case 'avlys':
        $oktId = Foresporsel::heltall('oktId');
        $antall = (int) DB::verdi(
            "SELECT COUNT(*) FROM bookings WHERE course_session_id = :o AND status = 'betalt'",
            ['o' => $oktId]
        );

        DB::oppdater('course_sessions', ['status' => 'avlyst'], ['id' => $oktId]);
        revider('dato_avlyst', 'course_session', $oktId, ['betalte_bookinger' => $antall]);

        Svar::ok([
            'betalte' => $antall,
            'beskjed' => $antall > 0
                ? "Datoen er avlyst. {$antall} har betalt og må refunderes manuelt under Økonomi."
                : 'Datoen er avlyst.',
        ]);

    // Avlysingen angres.
    //
    // «avlys» satte status og hadde ingen vei tilbake: ble feil dato avlyst,
    // maatte den settes opp paa nytt, og de paameldte fulgte ikke med. Raden
    // er den samme — det er bare statusen som gaar tilbake til «planlagt».
    //
    // De som fikk beskjed om at datoen var avlyst faar ingen ny av seg selv.
    // Det sies her, framfor aa bli oppdaget den kvelden ingen moeter opp.
    case 'gjenopprett':
        $oktId = Foresporsel::heltall('oktId');
        $okt = DB::en('SELECT id, status FROM course_sessions WHERE id = :o', ['o' => $oktId]);
        if ($okt === null) {
            Svar::feil('Fant ikke datoen.', 404);
        }
        if ((string) $okt['status'] !== 'avlyst') {
            Svar::feil('Denne datoen er ikke avlyst.');
        }

        DB::oppdater('course_sessions', ['status' => 'planlagt'], ['id' => $oktId]);
        revider('dato_gjenopprettet', 'course_session', $oktId, []);

        $paa = (int) DB::verdi(
            "SELECT COALESCE(SUM(antall), 0) FROM bookings
              WHERE course_session_id = :o AND status IN ('betalt','reservert')",
            ['o' => $oktId]
        );
        Svar::ok([
            'beskjed' => $paa === 0
                ? 'Datoen går som planlagt igjen.'
                : 'Datoen går som planlagt igjen. ' . $paa
                  . ($paa === 1 ? ' påmeldt får' : ' påmeldte får')
                  . ' ikke beskjed automatisk — send den under Påmeldte.',
        ]);

    // ------------------------------------------- alt som gjelder én dato
    //
    // Pris, informasjon og samlingene i et flerdagerskurs. Alt tre hoerer til
    // datoen og ikke til kurset: en kveld kan koste noe annet, ha noe eget aa
    // si, og bestaa av tre moeter.
    case 'dato':
        $oktId = Foresporsel::heltall('oktId');
        $okt = DB::en('SELECT id, course_id FROM course_sessions WHERE id = :i', ['i' => $oktId]);
        if ($okt === null) {
            Svar::feil('Fant ikke datoen.', 404);
        }
        if (!DB::harKolonne('course_sessions', 'pris_ore')) {
            Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
        }

        $kropp = Foresporsel::kropp();
        $endring = [];

        // Tomt felt betyr «som kurset», ikke «gratis». Uten dette skillet
        // ville et tomt prisfelt satt datoen til null kroner.
        if (array_key_exists('pris', $kropp)) {
            $raa = trim((string) $kropp['pris']);
            $endring['pris_ore'] = $raa === '' ? null : max(0, (int) preg_replace('/[^\d]/', '', $raa)) * 100;
        }
        if (array_key_exists('info', $kropp)) {
            $endring['info'] = trim(mb_substr((string) $kropp['info'], 0, 4000)) ?: null;
        }
        // Kursholderen for denne gangen. Tomt valg betyr «ikke tildelt», og
        // det er en gyldig tilstand — ikke alt har en kursholder.
        if (array_key_exists('kursholderId', $kropp) && DB::harKolonne('course_sessions', 'kursholder_id')) {
            $endring['kursholder_id'] = $holderId('kursholderId');
        }
        if ($endring !== []) {
            DB::oppdater('course_sessions', $endring, ['id' => $oktId]);
        }

        $antall = null;
        if (array_key_exists('samlinger', $kropp)) {
            if (!DB::harTabell('okt_samlinger')) {
                Svar::feil('Flerdagerskurs krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
            }
            $antall = Samlinger::lagre($oktId, (array) $kropp['samlinger']);
        }

        revider('dato_endret', 'course_session', $oktId,
                ['felter' => array_keys($endring), 'samlinger' => $antall]);

        Svar::ok([
            'samlinger' => Samlinger::forOkt($oktId),
            'beskjed'   => $antall === null
                ? 'Lagret.'
                : ($antall === 0
                    ? 'Lagret. Kurset går på én dag.'
                    : 'Lagret. ' . $antall . ($antall === 1 ? ' samling.' : ' samlinger.')),
        ]);

    // ------------------------------------------------- slett én kursdato
    //
    // «Fjern» i steg 2 tok datoen ut av skjemaet og ikke noe mer. Lagringen
    // legger til datoer, men fjernet aldri noen — den som tok bort 6.
    // september saa den staa paa nettsiden etterpaa, uten et ord om hvorfor.
    //
    // Samme regel som for kurs: har noen meldt seg paa, kan raden ikke
    // forsvinne. Bookingen og betalingen peker paa den, og de er
    // bokforingspliktige. Da avlyses datoen i stedet, og det staar i svaret.
    case 'slettdato':
        $oktId = Foresporsel::heltall('oktId');
        $okt = DB::en(
            'SELECT cs.id, cs.start_tid, c.tittel FROM course_sessions cs
               JOIN courses c ON c.id = cs.course_id WHERE cs.id = :i',
            ['i' => $oktId]
        );
        if ($okt === null) {
            Svar::feil('Fant ikke datoen.', 404);
        }
        $naar = Booking::norskDato((string) $okt['start_tid']);

        $pameldte = (int) DB::verdi(
            "SELECT COUNT(*) FROM bookings
              WHERE course_session_id = :o AND status IN ('betalt','reservert')",
            ['o' => $oktId]
        );

        if ($pameldte > 0) {
            DB::oppdater('course_sessions', ['status' => 'avlyst'], ['id' => $oktId]);
            revider('dato_avlyst', 'course_session', $oktId, ['pameldte' => $pameldte, 'via' => 'veiviser']);
            Svar::ok([
                'slettet' => false,
                'beskjed' => $pameldte . ($pameldte === 1 ? ' har' : ' har') . ' meldt seg på '
                           . $naar . ', så datoen er avlyst og tatt av nettsiden i stedet for slettet. '
                           . 'Husk å gi beskjed og refundere.',
            ]);
        }

        // Avbestilte paameldinger peker fortsatt paa datoen. Det staar ingen
        // fremmednokkel paa den kolonnen, saa basen sier ikke fra — raden ble
        // bare staaende og pekte paa noe som ikke fantes. Vi loesner den med
        // vilje, saa bilaget beholder kurset og beloepet sitt.
        DB::kjor(
            'UPDATE bookings SET course_session_id = NULL WHERE course_session_id = :o',
            ['o' => $oktId]
        );
        DB::kjor('DELETE FROM course_sessions WHERE id = :i', ['i' => $oktId]);
        revider('dato_slettet', 'course_session', $oktId, ['kurs' => $okt['tittel'], 'naar' => $naar]);
        Svar::ok(['slettet' => true, 'beskjed' => $naar . ' er tatt bort.']);

    // ------------------------------------------- standard bekreftelsestekst
    //
    // Teksten eieren slipper aa skrive paa nytt for hvert kurs. Lagres naar
    // hun sier at akkurat denne skal vaere standarden.
    case 'bekreftelseStandard':
        $tekst = trim(mb_substr(Foresporsel::tekst('tekst'), 0, 4000));
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi) VALUES (:n, :v)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
            ['n' => 'kurs_bekreftelse', 'v' => $tekst]
        );
        Config::glemBasen();
        revider('bekreftelse_standard', 'innstilling', 0, ['lengde' => mb_strlen($tekst)]);
        Svar::ok([
            'bekreftelseStandard' => $tekst,
            'beskjed' => $tekst === ''
                ? 'Standardteksten er tømt. Nye kurs starter med et tomt felt.'
                : 'Teksten er lagret som standard for nye kurs.',
        ]);

    // ------------------------------------------------- slett et kurs
    //
    // «Slett» fjernet bare raden fra skjermbildet. Kurset var tilbake ved
    // neste sidelasting, for serveren hadde aldri hort om det.
    case 'slett':
        $kursId = Foresporsel::heltall('id');
        $kurs = DB::en('SELECT id, tittel FROM courses WHERE id = :i', ['i' => $kursId]);
        if ($kurs === null) {
            Svar::feil('Fant ikke kurset.', 404);
        }

        // Har noen meldt seg paa, kan raden ikke forsvinne: bookingene og
        // betalingene peker paa den, og de er bokforingspliktige. Kurset
        // avlyses i stedet — det er det sletting egentlig betyr her.
        $pameldte = (int) DB::verdi(
            "SELECT COUNT(*) FROM bookings
              WHERE course_id = :c AND status IN ('betalt','reservert')",
            ['c' => $kursId]
        );

        // Og de som har vaert: avbestilte, refunderte, og de som ikke motte.
        //
        // Her laa feilen. Telleren over saa bare etter paameldinger som lever
        // naa, saa et kurs der alle hadde avbestilt gikk videre til DELETE —
        // og der staar en fremmednokkel fra bookings med RESTRICT. Basen
        // nektet, kallet endte i en femhundre, og skjermen sa «Gikk ikke»
        // uten et ord om hvorfor.
        //
        // En avbestilt paamelding er fortsatt et bilag. Raden skal ikke
        // forsvinne, og da kan kurset heller ikke det.
        $historikk = (int) DB::verdi(
            'SELECT COUNT(*) FROM bookings WHERE course_id = :c',
            ['c' => $kursId]
        );

        if ($pameldte === 0 && $historikk > 0) {
            DB::oppdater('courses', ['status' => 'avlyst'], ['id' => $kursId]);
            DB::kjor(
                "UPDATE course_sessions SET status = 'avlyst'
                  WHERE course_id = :c AND status = 'planlagt'",
                ['c' => $kursId]
            );
            revider('kurs_avlyst', 'course', $kursId, ['historikk' => $historikk]);
            Svar::ok([
                'slettet' => false,
                'beskjed' => 'Kurset er avlyst og tatt av nettsiden. Det kan ikke slettes helt: '
                    . $historikk . ($historikk === 1 ? ' tidligere påmelding peker' : ' tidligere påmeldinger peker')
                    . ' på det, og de er bilag. Ingen som kommer er berørt — '
                    . 'de påmeldingene er avbestilt fra før.',
            ]);
        }

        if ($pameldte > 0) {
            DB::oppdater('courses', ['status' => 'avlyst'], ['id' => $kursId]);
            DB::kjor(
                "UPDATE course_sessions SET status = 'avlyst'
                  WHERE course_id = :c AND status = 'planlagt'",
                ['c' => $kursId]
            );
            revider('kurs_avlyst', 'course', $kursId, ['pameldte' => $pameldte]);
            Svar::ok([
                'slettet' => false,
                'beskjed' => $pameldte === 1
                    ? 'Én har meldt seg på, så kurset er avlyst og tatt av nettsiden i stedet for slettet. Husk å gi beskjed og refundere.'
                    : $pameldte . ' har meldt seg på, så kurset er avlyst og tatt av nettsiden i stedet for slettet. Husk å gi beskjed og refundere.',
            ]);
        }

        // Ingen paameldinger i det hele tatt: datoene og eventuelle faste
        // ukedager foelger med.
        if (DB::harTabell('kurs_serier')) {
            DB::kjor('DELETE FROM kurs_serier WHERE course_id = :c', ['c' => $kursId]);
        }
        DB::kjor('DELETE FROM course_sessions WHERE course_id = :c', ['c' => $kursId]);
        DB::kjor('DELETE FROM courses WHERE id = :i', ['i' => $kursId]);
        revider('kurs_slettet', 'course', $kursId, ['tittel' => $kurs['tittel']]);
        Svar::ok(['slettet' => true, 'beskjed' => '«' . $kurs['tittel'] . '» er slettet.']);

    default:
        Svar::feil('Ukjent handling.');
}
