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
                'kortBeskrivelse' => 'To timer i verkstedet der du jobber med dine egne prosjekter.',
                'laerer'          => 'Du jobber selvstendig. Vi hjelper når du trenger det.',
                'lagerDu'         => 'Det du selv vil lage.',
                'medHjem'         => 'Du får med deg det du lager. Vi glaserer og brenner det for deg. '
                    . self::HENTING,
            ],

            '*' => [
                'nivaaTekst'      => self::NIVAA_UTE,
                'kortBeskrivelse' => 'Et hyggelig kurs med leire mellom hendene. Ingen erfaring nødvendig.',
                'laerer'          => self::PLATE . 'det du lager.',
                'lagerDu'         => 'Ditt eget arbeid i keramikk.',
                'medHjem'         => 'Du får med deg det du lager. Vi glaserer og brenner det for deg. '
                    . self::HENTING,
            ],
        ];
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

        $mal = $maler[$tema] ?? $maler['*'];

        // Kurs med et eget navn i bestillingen. De arver malen for temaet og
        // retter det som er saerskilt for dem — saa «to boller» ikke blir
        // «ditt eget arbeid».
        $egne = [
            'Kurs boller' => [
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
            ],
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
        return $mal;
    }

    /**
     * Varigheten paa en oekt, skrevet ut.
     *
     *   17:00–20:00   «3 timer»
     *   18:00–20:30   «2 timer og 30 minutter»
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
        $min = (int) round(($b - $a) / 60);
        return self::minutterSomTekst($min);
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
     * Har eieren skrevet en egen tekst, er det den som gjelder. Ellers
     * regnes den av oektene som ligger framover. Gaar kurset over flere
     * samlinger, staar bade lengden paa hver og hvor mange det er.
     *
     * @param list<array{start:?string,slutt:?string,samlinger:int}> $okter
     */
    public static function varighetFor(array $kurs, array $okter): string
    {
        $egen = trim((string) ($kurs['varighet_tekst'] ?? ''));
        if ($egen !== '') {
            return $egen;
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
