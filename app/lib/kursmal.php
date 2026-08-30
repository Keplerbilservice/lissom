<?php
/**
 * Standardtekstene til kursene, og varigheten regnet av oektene.
 *
 * Tekstene ligger her og ikke i nettsida: de skal kunne endres uten at noen
 * roerer frontend, og de skal vaere de samme enten de leses av kurssida, av
 * «Kursene vaare» eller av kursoppsettet i admin.
 *
 * De er et *utgangspunkt*. Har eieren skrevet noe selv paa et kurs, er det
 * hennes tekst som gjelder — standarden legges aldri oppaa. Og de skrives
 * ikke inn av seg selv: eieren ber om dem fra kursoppsettet, ett kurs om
 * gangen eller for alle, saa ingen publiserte kurs endrer seg bak ryggen
 * paa henne.
 */

declare(strict_types=1);

final class Kursmal
{
    /** Nivaaene som finnes internt. Ute staar «nivaa_tekst». */
    public const NIVAAER = [
        'nybegynner'   => 'Nybegynner',
        'videregaende' => 'Videregående',
    ];

    /** Det kunden leser naar ingenting annet er skrevet. */
    public const NIVAA_UTE = 'For alle';

    /**
     * Slutten paa «Dette faar du med hjem», lik paa alle kurs.
     *
     * Eieren ba om at den staar overalt: naar keramikken er klar, og at hun
     * sier fra. Den staar her ett sted, saa den er den samme i alle malene.
     */
    public const HENTING = 'Den er normalt klar til henting etter 2–3 uker. Du får beskjed når den er klar.';

    /** Plateteknikk-innledningen, som gjelder alle unntatt dreiing og Date Night. */
    private const PLATE = 'Du får en grunnleggende innføring i plateteknikk og lærer hvordan du '
        . 'former leiren med hendene. Du får også en innføring i dekorering, slik at du kan '
        . 'sette ditt eget preg på ';

    /**
     * Malene, slaatt opp paa kursets tema.
     *
     * «laerer» er hele setningen — den staar under «Dette laerer du».
     * «laererKort» er de tre-fire ordene som faar plass i faktaboksen paa
     * kurssida. Boksen er smal, og hele setningen brakk den i fem linjer.
     *
     * «*» er reserven: den brukes for et kurs vi ikke har en egen mal til,
     * slik at et nytt kurs ogsaa faar et utgangspunkt.
     *
     * @return array<string, array<string, string>>
     */
    public static function maler(): array
    {
        return [
            'Dreiing' => [
                'nivaaTekst'      => self::NIVAA_UTE,
                'laererKort'      => 'Innføring i dreiing og dekor',
                'kortBeskrivelse' => 'Prøv den gode følelsen av å forme leire på dreieskiven. '
                    . 'Du lærer det grunnleggende og får hjelp hele veien, helt uten krav om erfaring.',
                'beskrivelse'     => "Har du lyst til å prøve å dreie? På dette kurset blir du kjent med "
                    . "dreieskiven og får oppleve hvordan en klump med leire langsomt kan formes til noe "
                    . "helt eget.\n\nVi tar det rolig og går gjennom prosessen steg for steg. Det viktigste "
                    . "er ikke at alt blir perfekt, men at du får prøve, lære og kjenne på gleden ved å "
                    . "skape noe med egne hender. Du trenger ingen erfaring fra før, og vi hjelper deg "
                    . "underveis.",
                'laerer'          => 'Du lærer å sentrere leiren, åpne formen, dreie og trimme. '
                    . 'Du får også en enkel innføring i hvordan keramikken kan dekoreres.',
                'lagerDu'         => 'Du lager din egen keramikk på dreieskiven. '
                    . 'Resultatet vil variere ut fra hva du får til og ønsker å lage.',
                'medHjem'         => 'Du får med deg kreasjonen du klarer å lage på dreieskiven. '
                    . 'Vi ferdigstiller, glaserer og brenner den for deg. ' . self::HENTING,
            ],

            'Plateteknikk' => [
                'nivaaTekst'      => self::NIVAA_UTE,
                'laererKort'      => 'Innføring i plateteknikk og dekor',
                'kortBeskrivelse' => 'Lag noe fint og personlig i keramikk. Et hyggelig kurs for deg '
                    . 'som vil prøve plateteknikk og skape noe du faktisk kan bruke hjemme.',
                'beskrivelse'     => "Bli med på en rolig og kreativ stund med leire mellom hendene. "
                    . "Du trenger ingen erfaring fra før, og du får veiledning gjennom hele "
                    . "prosessen.\n\nDu velger selv uttrykk, form og dekor. Her er det rom for å prøve "
                    . "seg frem, senke skuldrene og kose seg med den kreative prosessen.",
                'laerer'          => self::PLATE . 'det du lager.',
                'lagerDu'         => 'Ditt eget arbeid i keramikk.',
                'medHjem'         => 'Du får med deg det du lager. Vi glaserer og brenner det for deg. '
                    . self::HENTING,
            ],

            'Events' => [
                'nivaaTekst'      => self::NIVAA_UTE,
                'laererKort'      => 'Innføring i forming og dekor',
                'kortBeskrivelse' => 'En hyggelig kveld med leire — for venner, par, kolleger '
                    . 'eller familien. Ingen trenger erfaring.',
                'beskrivelse'     => "En kveld der dere lager noe sammen. Vi viser dere alt underveis, "
                    . "og dere trenger ingen forkunnskaper.\n\nDere velger selv uttrykk og dekor. "
                    . "Vi ordner resten.",
                'laerer'          => 'Dere får en innføring i å forme leiren med hendene, '
                    . 'og i hvordan dere kan dekorere det dere lager.',
                'lagerDu'         => 'Deres egne arbeider i keramikk.',
                'medHjem'         => 'Dere får med dere det dere lager. Vi glaserer og brenner det for dere. '
                    . self::HENTING,
            ],

            'Paint on pots' => [
                'nivaaTekst'      => self::NIVAA_UTE,
                'laererKort'      => 'Maling og dekor',
                // Plassen varer halvannen time fra tidspunktet man velger —
                // se Apent::PLASS_MINUTTER. Lissom endret den fra to timer
                // 27. august.
                'varighetTekst'   => 'Halvannen time fra tidspunktet du velger',
                // «Leire, verktoy, glasur og brenning er inkludert» sto her
                // som paa alle andre kurs. Paa Paint on Pots faar man ingen
                // leire — man maler ferdig brent keramikk.
                'punkter'         => "Farger, pensler og hjelp er inkludert.\n"
                    . "Glasering og brenning er inkludert.\n"
                    . "Du velger og betaler gjenstanden i verkstedet.\n"
                    . "Passer fra 6 år, med voksen.",
                'kortBeskrivelse' => 'Bestill en hyggelig stund med keramikkmaling hos oss. Velg blant '
                    . 'et stort utvalg kopper, skåler, fat og figurer når du kommer, og skap noe helt unikt.',
                'beskrivelse'     => "Paint on Pots passer for både barn og voksne, enten du kommer alene, "
                    . "med venner, familie eller kollegaer.\n\nDu bestiller kun tidspunkt på forhånd. Når "
                    . "du kommer til verkstedet velger du selv hvilken keramikk du ønsker å male blant "
                    . "utvalget som er tilgjengelig hos oss.\n\nVi har alt fra kopper og boller til fat, "
                    . "figurer og dekorasjoner i ulike prisklasser.\n\nDu velger produkt når du kommer og "
                    . "betaler hos oss etter valgt produkt.",
                'laerer'          => 'Du får vist hvordan fargene oppfører seg på leiren, '
                    . 'og hvordan du får det uttrykket du er ute etter.',
                'lagerDu'         => 'Du maler den gjenstanden du velger når du kommer.',
                'medHjem'         => 'Du reserverer plass ved bordet vårt for Paint on Pots. Selve '
                    . 'produktet velges når du kommer til verkstedet. Vi glaserer og brenner keramikken '
                    . 'for deg etter at du har malt den. ' . self::HENTING,
                'tillegg'         => 'Produktene prises individuelt. Aktuelle priser vises i verkstedet '
                    . 'og oppdateres fortløpende. Du velger selv produkt når du kommer.',
            ],

            'Drop-in' => [
                'nivaaTekst'      => 'Krever kurs hos oss, eller et medlem med deg',
                'laererKort'      => 'Du jobber selvstendig',
                'kortBeskrivelse' => 'Halvannen time i verkstedet der du jobber med dine egne prosjekter.',
                'laerer'          => 'Du jobber selvstendig. Vi hjelper når du trenger det.',
                'lagerDu'         => 'Det du selv vil lage.',
                'medHjem'         => 'Du får med deg det du lager. Vi glaserer og brenner det for deg. '
                    . self::HENTING,
            ],

            '*' => [
                'nivaaTekst'      => self::NIVAA_UTE,
                'laererKort'      => 'Innføring i plateteknikk og dekor',
                'kortBeskrivelse' => 'Et hyggelig kurs med leire mellom hendene. Ingen erfaring nødvendig.',
                'laerer'          => self::PLATE . 'det du lager.',
                'lagerDu'         => 'Ditt eget arbeid i keramikk.',
                'medHjem'         => 'Du får med deg det du lager. Vi glaserer og brenner det for deg. '
                    . self::HENTING,
            ],
        ];
    }

    /**
     * De fire feltene eieren selv setter standardteksten paa.
     *
     * De ligger ikke i maler() over: de er ikke skrevet av oss, de skrives av
     * verkstedet fra kursoppsettet, og de skal kunne endres uten en ny
     * utlegging. Resten av malene staar i koden fordi de er lange
     * salgstekster som ble skrevet én gang.
     *
     * «tillegg» — «Godt aa vite» — sto her og ble tatt ut samme kveld: feltet
     * var det samme slaget opplysning som «Praktisk informasjon», og eieren
     * ba om ett av dem. En standardtekst til et felt som ikke finnes er bare
     * en ting til aa lure paa.
     */
    public const EGNE_FELT = ['punkter', 'praktisk', 'ferdigTid'];

    /** Kategoriene en standardtekst kan settes for. */
    public const KATEGORIER = ['Dreiing', 'Håndbygging', 'Events', 'Kun medlemmer', 'Drop-in'];

    /** @var array<string, array<string, string>>|null */
    private static ?array $standard = null;

    /**
     * Standardtekstene verkstedet har skrevet, per kategori.
     *
     * Ligger som JSON under én noekkel i innstillinger. Ett oppslag, ingen ny
     * tabell, og ingenting aa migrere naar et felt kommer til.
     *
     * Taaler at noekkelen ikke finnes, at JSON-en er oedelagt og at tabellen
     * mangler: da er det ingen standardtekst, ikke en hvit side.
     *
     * @return array<string, array<string, string>>
     */
    public static function standardtekster(): array
    {
        if (self::$standard !== null) {
            return self::$standard;
        }
        self::$standard = [];
        try {
            $rad = DB::en("SELECT verdi FROM innstillinger WHERE nokkel = 'kurs_standardtekster'");
            $raa = json_decode((string) ($rad['verdi'] ?? ''), true);
            if (is_array($raa)) {
                foreach ($raa as $kategori => $felt) {
                    if (!is_array($felt) || !in_array((string) $kategori, self::KATEGORIER, true)) {
                        continue;
                    }
                    $rein = [];
                    foreach (self::EGNE_FELT as $n) {
                        $v = trim((string) ($felt[$n] ?? ''));
                        if ($v !== '') {
                            $rein[$n] = $v;
                        }
                    }
                    if ($rein !== []) {
                        self::$standard[(string) $kategori] = $rein;
                    }
                }
            }
        } catch (Throwable $e) {
            self::$standard = [];
        }
        return self::$standard;
    }

    /** Leses paa nytt naar noen har lagret. */
    public static function glemStandard(): void
    {
        self::$standard = null;
    }

    /**
     * Kategorien et kurs staar i.
     *
     * Den samme regelen som kategoriFor() i nettsida: temaet foerst, saa de
     * gamle temanavnene, og til slutt navnet — Paint on Pots sto med tema
     * NULL i basen og er likevel et event.
     */
    public static function kategoriAv(array $kurs): string
    {
        $tema = trim((string) ($kurs['tema'] ?? ''));
        $rett = [
            'Dreiing' => 'Dreiing',
            'Håndbygging' => 'Håndbygging',
            'Events' => 'Events',
            'Kun for medlemmer' => 'Kun medlemmer',
            'Kun medlemmer' => 'Kun medlemmer',
            'Drop-in' => 'Drop-in',
            // Temaer som ikke lenger er egne kategorier.
            'Workshop' => 'Håndbygging',
            'Plateteknikk' => 'Håndbygging',
            'Event' => 'Events',
            'Sip & Clay' => 'Events',
            'Date Night' => 'Events',
            'Paint on pots' => 'Events',
            'Paint on Pots' => 'Events',
        ];
        if (isset($rett[$tema])) {
            return $rett[$tema];
        }
        $tittel = mb_strtolower(trim((string) ($kurs['tittel'] ?? '')));
        foreach (['paint on pots' => 'Events', 'date night' => 'Events', 'sip & clay' => 'Events',
                  'drop-in' => 'Drop-in', 'dreie' => 'Dreiing'] as $del => $til) {
            if ($del !== '' && mb_strpos($tittel, $del) !== false) {
                return $til;
            }
        }
        return '';
    }

    /**
     * Malen for et kurs.
     *
     * Slaas opp paa temaet. Kurs boller og Store fat er begge plateteknikk,
     * og bruker den samme innledningen — det var nettopp det eieren ba om.
     *
     * @return array<string, string>
     */
    public static function forKurs(array $kurs): array
    {
        $maler = self::maler();
        $tema  = trim((string) ($kurs['tema'] ?? ''));

        // Temaene som staar for det samme.
        $samme = ['Date Night' => 'Events', 'Sip & Clay' => 'Events', 'Workshop' => 'Plateteknikk'];
        $tema  = $samme[$tema] ?? $tema;

        // Uten tema: les det av navnet.
        //
        // Paint on Pots sto med tema NULL i basen, og falt dermed paa
        // standardmalen — «Innfoering i plateteknikk og dekor» paa et kurs
        // der man maler ferdig brent keramikk. Et kurs uten tema skal
        // gjenkjennes paa det det heter.
        if ($tema === '') {
            $tittel = mb_strtolower(trim((string) ($kurs['tittel'] ?? '')));
            foreach (['paint on pots' => 'Paint on pots', 'date night' => 'Events',
                      'sip & clay' => 'Events', 'drop-in' => 'Drop-in',
                      'workshop' => 'Plateteknikk', 'dreie' => 'Dreiing'] as $del => $til) {
                if (mb_strpos($tittel, $del) !== false) {
                    $tema = $til;
                    break;
                }
            }
        }

        $mal = $maler[$tema] ?? $maler['*'];

        // Kurs med et eget navn i bestillingen. De arver malen for temaet og
        // retter det som er saerskilt for dem — saa «to boller» ikke blir
        // «ditt eget arbeid».
        // Bollekurset heter «Lag din egen bolle» fra migrasjon 087. Begge
        // navnene staar: kjores koden for migrasjonen — og det gjor den, de
        // legges ut hver for seg — ville kurset ellers mistet teksten sin i
        // mellomtiden og staatt med den generelle plateteknikk-malen.
        $bolle = [
            'kortBeskrivelse' => 'Lag to fine og personlige boller i keramikk. Et hyggelig kurs for '
                . 'deg som vil prøve plateteknikk og skape noe du faktisk kan bruke hjemme.',
            'beskrivelse'     => "Bli med på en rolig og kreativ stund med leire mellom hendene. På "
                . "dette kurset lager du to egne boller ved hjelp av plateteknikk. Du trenger ingen "
                . "erfaring fra før, og du får veiledning gjennom hele prosessen.\n\nDu velger selv "
                . "uttrykk, form og dekor, slik at bollene blir akkurat så personlige som du ønsker. "
                . "Her er det rom for å prøve seg frem, senke skuldrene og kose seg med den kreative "
                . "prosessen.",
            'laerer'          => self::PLATE . 'bollene.',
            'lagerDu'         => 'To personlige boller i keramikk.',
            'medHjem'         => 'Du får med deg de to bollene du lager. Vi glaserer og brenner dem '
                . 'for deg. ' . self::HENTING,
        ];

        $egne = [
            'Lag din egen bolle' => $bolle,
            'Kurs boller'        => $bolle,
            'Store fat kurs' => [
                'kortBeskrivelse' => 'Lag et stort og personlig keramikkfat med ditt eget uttrykk. Vi '
                    . 'bruker plateteknikk og dekor for å skape et fat du kan glede deg over hjemme.',
                'beskrivelse'     => "På dette kurset får du lage ditt eget store fat i keramikk. Du "
                    . "arbeider med plateteknikk og former fatet med hendene, før du setter ditt "
                    . "personlige preg på det med struktur, mønster og dekor.\n\nKurset passer godt for "
                    . "deg som vil lage noe både fint og anvendelig. Du trenger ingen erfaring fra før. "
                    . "Vi hjelper deg gjennom hele prosessen, fra en enkel leireplate til et ferdig fat "
                    . "med ditt eget uttrykk.",
                'laerer'          => 'Du får en grunnleggende innføring i plateteknikk og lærer hvordan '
                    . 'du former et stort fat. Du får også en innføring i dekorering og hvordan du kan '
                    . 'bruke mønster, struktur og detaljer for å gjøre fatet personlig.',
                'lagerDu'         => 'Ett stort fat i keramikk.',
                'medHjem'         => 'Du får med deg det store fatet du lager. Vi glaserer og brenner det '
                    . 'for deg. ' . self::HENTING,
            ],
        ];

        $tittel = trim((string) ($kurs['tittel'] ?? ''));
        if (isset($egne[$tittel])) {
            $mal = array_merge($mal, $egne[$tittel]);
        }

        // Til slutt de fire feltene verkstedet setter selv. De legges oeverst
        // fordi de er nyere enn koden: har eieren skrevet en standardtekst
        // for haandbygging, er det hennes som gjelder, ikke vaar.
        $kategori = self::kategoriAv($kurs);
        if ($kategori !== '') {
            $mal = array_merge($mal, self::standardtekster()[$kategori] ?? []);
        }
        return $mal;
    }

    /**
     * Varigheten paa en oekt, skrevet ut.
     *
     *   17:00–20:00              «3 timer»
     *   18:00–20:30              «2 timer og 30 minutter»
     *   9. sep 15:00 – 10. sep 18:00  «3 timer per gang · 2 ganger»
     *
     * Det siste er et kurs over flere dager. Der er «slutt_tid» slutten paa
     * siste dag, ikke slutten paa en sammenhengende oekt: regnet rett fram
     * ble det «27 timer», som ingen har vaert paa.
     *
     * Null naar sluttida mangler: da vet vi det ikke, og da skal det ikke
     * staa noe. En gjettet varighet er verre enn ingen.
     */
    public static function varighetAv(?string $start, ?string $slutt): ?string
    {
        if ($start === null || $slutt === null || trim($slutt) === '') {
            return null;
        }
        $a = strtotime($start);
        $b = strtotime($slutt);
        if ($a === false || $b === false || $b <= $a) {
            return null;
        }

        $dagA = date('Y-m-d', $a);
        $dagB = date('Y-m-d', $b);
        if ($dagA === $dagB) {
            return self::minutterSomTekst((int) round(($b - $a) / 60));
        }

        // Flere dager: lengden er klokkeslettene, og antallet er dagene.
        $dager = (int) ((new DateTimeImmutable($dagA))->diff(new DateTimeImmutable($dagB))->days) + 1;
        $perDag = (int) round((strtotime($dagA . ' ' . date('H:i:s', $b)) - $a) / 60);
        if ($perDag <= 0) {
            return null;
        }
        return self::minutterSomTekst($perDag) . ' per gang · ' . $dager . ' ganger';
    }

    /** Minutter skrevet slik man sier det. */
    public static function minutterSomTekst(int $min): string
    {
        if ($min <= 0) {
            return '';
        }
        $t = intdiv($min, 60);
        $m = $min % 60;
        $timer = $t === 1 ? '1 time' : $t . ' timer';
        $mins  = $m === 1 ? '1 minutt' : $m . ' minutter';
        if ($t === 0) {
            return $mins;
        }
        return $m === 0 ? $timer : $timer . ' og ' . $mins;
    }

    /**
     * Varigheten slik den skal staa paa kurset.
     *
     * Regnes av oektene som ligger framover. Gaar kurset over flere
     * samlinger, staar bade lengden paa hver og hvor mange det er.
     *
     * Kolonnen varighet_tekst overstyrte dette: sto det noe der, gjaldt den
     * teksten uansett hva klokka paa datoene sa, og de to kunne si hver sin
     * ting. Eieren, 30. august: «fjern varighet og bruk tidene paa datoen».
     * Feltet er borte fra kursoppsettet og overstyringa er borte her.
     * Kolonnen staar urort i basen; den leses bare ikke lenger.
     *
     * @param list<array{start:?string,slutt:?string,samlinger:int}> $okter
     */
    public static function varighetFor(array $kurs, array $okter): string
    {
        // Kurs der gjenstanden betales i verkstedet er lagt ut paa
        // aapningstidene: oekta er hele det aapne vinduet, ofte ti–tolv
        // timer. Det er naar doeren staar aapen, ikke hvor lenge man sitter
        // der. Malen sier hva som gjelder i stedet.
        if ((int) ($kurs['gjenstand_i_kassa'] ?? 0) === 1) {
            $mal = trim((string) (self::forKurs($kurs)['varighetTekst'] ?? ''));
            if ($mal !== '') {
                return $mal;
            }
        }
        foreach ($okter as $o) {
            $v = self::varighetAv($o['start'] ?? null, $o['slutt'] ?? null);
            if ($v === null) {
                continue;
            }
            $ant = (int) ($o['samlinger'] ?? 1);
            if ($ant > 1) {
                return $ant . ' samlinger à ' . $v;
            }
            return $v;
        }
        return '';
    }
}
