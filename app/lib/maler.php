<?php
/**
 * Hva hver mal heter, hvor den sendes fra, og hvilke felter den kan bruke.
 *
 * Eieren, 1. september: «jeg vil ha en oversikt over komandoer som jeg kan
 * kopiere, slike som denne {varelinjer} slik at jeg faktisk kan legge inne
 * selv».
 *
 * Feltene er ikke like for alle. «{varelinjer}» finnes i butikkbestillingen
 * og ingen andre steder; skrives den i velkomstbrevet, staar den igjen som
 * raa tekst i e-posten kunden faar. Derfor staar de her, per mal, og
 * lagringen i api/admin/maler.php avviser et felt malen ikke kjenner.
 *
 * ── Hvorfor lista er skrevet for haand ───────────────────────────────
 *
 * Feltene kommer fra kallet i koden — Varsel::mal('butikkordre', ..., [...]).
 * De kunne vaert lest ut av kildekoden, men da ville lista vaert riktig
 * akkurat saa lenge ingen skrev kallet paa en ny maate. Her staar den som en
 * paastand, og tests/backend.php holder paastanden opp mot kallene: legger
 * noen til et felt uten aa foere det opp, blir proeven roed.
 */

declare(strict_types=1);

final class Maler
{
    /**
     * Malene koden kaller. De kan slaas av, men ikke slettes.
     *
     * Varsel::mal() skriver en linje i loggen og gaar videre naar malen
     * mangler. En slettet butikkbekreftelse ville altsaa betydd at ingen
     * kunder fikk kvittering, uten at noe sa fra.
     *
     * @return list<string>
     */
    public static function iBruk(): array
    {
        return array_keys(self::ALLE);
    }

    /** Navnet slik det staar paa skjermen. */
    public static function tittel(string $navn): string
    {
        return self::ALLE[$navn]['tittel'] ?? $navn;
    }

    /** Naar den sendes, i én setning. */
    public static function hvor(string $navn): string
    {
        return self::ALLE[$navn]['hvor'] ?? 'Denne malen sendes ikke av systemet.';
    }

    /**
     * Feltene malen kan bruke, med forklaring.
     *
     * @return list<array{felt:string,hva:string}>
     */
    public static function felter(string $navn): array
    {
        $ut = [];
        foreach (self::ALLE[$navn]['felter'] ?? [] as $felt => $hva) {
            $ut[] = ['felt' => $felt, 'hva' => $hva];
        }
        return $ut;
    }

    private const ALLE = [
        // ── Butikken ────────────────────────────────────────────────
        'butikkordre' => [
            'tittel' => 'Butikkbestilling — hentes',
            'hvor'   => 'Sendes når en bestilling i butikken er betalt, og kunden valgte å hente selv.',
            'felter' => [
                'ordre'      => 'Bestillingsnummeret, f.eks. B-260901-C4D7EC',
                'varelinjer' => 'Varene, én per linje, med antall og pris',
                'sum'        => 'Totalsummen',
            ],
        ],
        'butikkordre_pakke' => [
            'tittel' => 'Butikkbestilling — sendes',
            'hvor'   => 'Sendes når kunden valgte «Send som pakke» i kassa.',
            'felter' => [
                'ordre'      => 'Bestillingsnummeret',
                'varelinjer' => 'Varene, én per linje, med antall og pris',
                'sum'        => 'Totalsummen, inkludert frakt',
                'adresse'    => 'Adressen pakken sendes til, eller tom',
            ],
        ],

        // ── Kurs og booking ─────────────────────────────────────────
        'ordrebekreftelse' => [
            'tittel' => 'Kurspåmelding bekreftet',
            'hvor'   => 'Sendes når en plass på et kurs er betalt.',
            'felter' => [
                'navn'  => 'Navnet på den som meldte seg på',
                'kurs'  => 'Navnet på kurset',
                'naar'  => 'Dagen og klokkeslettet',
                // «{ordre}» er kurset og datoen limt sammen. Det var det
                // eneste feltet for, og staar igjen for at en mal som er
                // skrevet om for haand ikke skal miste innholdet sitt.
                'ordre' => 'Kurset og datoen i ett (fra før)',
                'belop' => 'Det som ble betalt',
            ],
        ],
        'avbestilling' => [
            'tittel' => 'Avbestilling bekreftet',
            'hvor'   => 'Sendes når en deltaker avbestiller plassen sin.',
            'felter' => [
                'navn'  => 'Navnet på deltakeren',
                'kurs'  => 'Kurset som ble avbestilt',
                'belop' => 'Beløpet som refunderes',
            ],
        ],
        'kurspaaminnelse' => [
            'tittel' => 'Påminnelse dagen før',
            'hvor'   => 'Sendes automatisk kvelden før kurset.',
            'felter' => [
                'navn' => 'Navnet på deltakeren',
                'kurs' => 'Kursets navn',
                'tid'  => 'Klokkeslettet kurset starter',
            ],
        ],
        'ferdig_brent' => [
            'tittel' => 'Keramikken er ferdig',
            'hvor'   => 'Sendes når du merker en kursdato som ferdig brent.',
            'felter' => [
                'navn' => 'Navnet på deltakeren',
                'kurs' => 'Kurset arbeidene kom fra',
            ],
        ],
        'anmeldelse' => [
            'tittel' => 'Be om en anmeldelse',
            'hvor'   => 'Sendes noen dager etter kurset.',
            'felter' => [
                'navn'  => 'Navnet på deltakeren',
                'kurs'  => 'Kurset de var på',
                'lenke' => 'Lenken de legger igjen ordene på',
            ],
        ],

        // ── Venteliste ──────────────────────────────────────────────
        'venteliste_satt' => [
            'tittel' => 'Satt på venteliste',
            'hvor'   => 'Sendes når noen setter seg på ventelisten for et fullt kurs.',
            'felter' => [
                'navn'     => 'Navnet på den som venter',
                'kurs'     => 'Kurset de venter på',
                'dato'     => 'Datoen, eller tom hvis den ikke er satt',
                'posisjon' => 'Hvilken plass i køen de har',
            ],
        ],
        'venteliste_ledig' => [
            'tittel' => 'Det ble ledig plass',
            'hvor'   => 'Sendes til dem på ventelisten når en plass blir ledig.',
            'felter' => [
                'navn'  => 'Navnet på den som venter',
                'kurs'  => 'Kurset det ble plass på',
                'dato'  => 'Datoen',
                'lenke' => 'Lenken de booker på',
            ],
        ],
        'venteliste_tildelt' => [
            'tittel' => 'Fikk plassen',
            'hvor'   => 'Sendes når du gir noen fra ventelisten en plass.',
            'felter' => [
                'navn'  => 'Navnet på deltakeren',
                'kurs'  => 'Kurset',
                'dato'  => 'Datoen',
                'lenke' => 'Lenken til Min side',
            ],
        ],

        // ── Gavekort ────────────────────────────────────────────────
        'gavekort_mottaker' => [
            'tittel' => 'Gavekort til mottakeren',
            'hvor'   => 'Sendes til den gavekortet er kjøpt til.',
            'felter' => [
                'belop'  => 'Beløpet på kortet',
                'hilsen' => 'Hilsenen kjøperen skrev, eller tom',
                'kode'   => 'Gavekortkoden',
                'gyldig' => 'Datoen kortet går ut',
            ],
        ],
        'gavekort_kjoper' => [
            'tittel' => 'Kvittering på gavekort',
            'hvor'   => 'Sendes til den som kjøpte gavekortet.',
            'felter' => [
                'navn'     => 'Navnet på kjøperen',
                'belop'    => 'Beløpet på kortet',
                'mottaker' => 'E-postadressen kortet ble sendt til',
                'kode'     => 'Gavekortkoden',
                'gyldig'   => 'Datoen kortet går ut',
            ],
        ],

        // ── Medlemskap ──────────────────────────────────────────────
        'innmelding_fast_trekk' => [
            'tittel' => 'Innmelding — fast trekk',
            'hvor'   => 'Sendes når noen melder seg inn med fast trekk i Vipps. Avtalen'
                        . ' er ikke gyldig før medlemmet har godkjent den i appen, så'
                        . ' lenka er det viktigste i denne.',
            'felter' => [
                'navn'  => 'Navnet på det nye medlemmet',
                'type'  => 'Medlemskapet de valgte',
                'lenke' => 'Adressen til Vipps, der avtalen godkjennes',
            ],
        ],
        'avtale_ikke_godkjent' => [
            'tittel' => 'Avtalen er ikke godkjent',
            'hvor'   => 'Sendes dagen etter, og igjen etter tre dager, når en'
                        . ' Vipps-avtale blir liggende uten godkjenning. Uten den når'
                        . ' lenka aldri fram til noen, og medlemskapet starter ikke.',
            'felter' => [
                'navn'  => 'Navnet på medlemmet',
                'type'  => 'Medlemskapet de valgte',
                'belop' => 'Prisen i måneden',
                'lenke' => 'Adressen til Vipps, der avtalen godkjennes',
            ],
        ],
        'innmelding_ordner_selv' => [
            'tittel' => 'Innmelding — ordner selv',
            'hvor'   => 'Sendes når noen melder seg inn og betaler hver periode selv.',
            'felter' => ['navn' => 'Navnet på det nye medlemmet', 'type' => 'Medlemskapet de valgte'],
        ],
        'soknad_godkjent' => [
            'tittel' => 'Søknad godkjent',
            'hvor'   => 'Sendes når du godkjenner en medlemssøknad.',
            'felter' => ['navn' => 'Navnet på søkeren'],
        ],
        'soknad_godkjent_sms' => [
            'tittel' => 'Søknad godkjent (SMS)',
            'hvor'   => 'Sendes som SMS sammen med e-posten over, når søkeren har oppgitt nummer.',
            'felter' => ['navn' => 'Navnet på søkeren'],
        ],
        'soknad_avslatt' => [
            'tittel' => 'Søknad avslått',
            'hvor'   => 'Sendes når du avslår en medlemssøknad.',
            'felter' => [
                'navn'        => 'Navnet på søkeren',
                'begrunnelse' => 'Grunnen du skrev, eller tom',
            ],
        ],
        'medlemstrekk_varsel' => [
            'tittel' => 'Varsel før månedstrekk',
            'hvor'   => 'Sendes noen dager før medlemskapet trekkes i Vipps.',
            'felter' => [
                'navn'  => 'Navnet på medlemmet',
                'belop' => 'Beløpet som trekkes',
                'plan'  => 'Medlemskapet',
                'dag'   => 'Dagen trekket går',
            ],
        ],
        'medlemskap_fornyet' => [
            'tittel' => 'Medlemskap fornyet',
            'hvor'   => 'Sendes når månedstrekket har gått gjennom.',
            'felter' => ['navn' => 'Navnet på medlemmet', 'abonnement' => 'Medlemskapet'],
        ],
        'betaling_feilet' => [
            'tittel' => 'Trekket gikk ikke gjennom',
            'hvor'   => 'Sendes når Vipps ikke fikk trukket månedsbeløpet.',
            'felter' => ['navn' => 'Navnet på medlemmet', 'abonnement' => 'Medlemskapet'],
        ],

        // ── Forespørsler ────────────────────────────────────────────
        'foresporsel_mottatt' => [
            'tittel' => 'Forespørsel mottatt',
            'hvor'   => 'Sendes med det samme noen sender inn kontaktskjemaet.',
            'felter' => ['navn' => 'Navnet på den som spurte', 'melding' => 'Det de skrev'],
        ],
        'foresporsel_svar' => [
            'tittel' => 'Svar på forespørsel',
            'hvor'   => 'Sendes når du svarer på en forespørsel fra admin.',
            'felter' => ['svar' => 'Svaret du skrev'],
        ],
        'foresporsel_svar_sms' => [
            'tittel' => 'Svar på forespørsel (SMS)',
            'hvor'   => 'Sendes som SMS når den som spurte ikke oppga e-post.',
            'felter' => ['svar' => 'Svaret du skrev'],
        ],

        // ── Medlemmenes egne varer ──────────────────────────────────
        'medlemsvare_godkjent' => [
            'tittel' => 'Medlemsvare godkjent',
            'hvor'   => 'Sendes til medlemmet når du legger varen deres ut i butikken.',
            'felter' => ['navn' => 'Navnet på medlemmet', 'tittel' => 'Varens navn'],
        ],
        'medlemsvare_avvist' => [
            'tittel' => 'Medlemsvare avvist',
            'hvor'   => 'Sendes til medlemmet når du ikke legger varen ut.',
            'felter' => [
                'navn'   => 'Navnet på medlemmet',
                'tittel' => 'Varens navn',
                'grunn'  => 'Grunnen du skrev, eller tom',
            ],
        ],

        // ── Til verkstedet, ikke til kunden ─────────────────────────
        'intern_ny_foresporsel' => [
            'tittel' => 'Til deg: ny forespørsel',
            'hvor'   => 'Sendes til verkstedet når noen fyller ut kontaktskjemaet.',
            'felter' => ['navn' => 'Navnet på den som spurte', 'oppsummering' => 'Hele skjemaet'],
        ],
        'intern_nytt_medlem' => [
            'tittel' => 'Til deg: nytt medlem',
            'hvor'   => 'Sendes til verkstedet når noen melder seg inn.',
            'felter' => [
                'navn'     => 'Navnet på det nye medlemmet',
                'epost'    => 'E-postadressen deres',
                'telefon'  => 'Telefonnummeret, eller «(ikke oppgitt)»',
                'type'     => 'Medlemskapet de valgte',
                'betaling' => 'Fast trekk eller ordner selv',
                'erfaring' => 'Det de skrev om erfaring, eller tom',
                'melding'  => 'Meldingen de skrev, eller tom',
            ],
        ],
        'intern_nytt_medlem_sms' => [
            'tittel' => 'Til deg: nytt medlem (SMS)',
            'hvor'   => 'Sendes som SMS til verkstedet når noen melder seg inn.',
            'felter' => [
                'navn'     => 'Navnet på det nye medlemmet',
                'type'     => 'Medlemskapet de valgte',
                'betaling' => 'Fast trekk eller ordner selv',
            ],
        ],
        'intern_gave_pakkes' => [
            'tittel' => 'Til deg: gave skal pakkes',
            'hvor'   => 'Sendes til verkstedet når en bestilling er merket som gave.',
            'felter' => [
                'ordre'      => 'Bestillingsnummeret',
                'navn'       => 'Navnet på kjøperen',
                'varelinjer' => 'Varene som skal pakkes',
                'hilsen'     => 'Hilsenen til kortet',
            ],
        ],
        'intern_ny_vare' => [
            'tittel' => 'Til deg: vare til godkjenning',
            'hvor'   => 'Sendes til verkstedet når et medlem legger ut en vare.',
            'felter' => [
                'produsent' => 'Medlemmet som laget den',
                'tittel'    => 'Varens navn',
                'pris'      => 'Prisen de satte',
            ],
        ],
        'intern_gave_lost_inn' => [
            'tittel' => 'Til deg: gave løst inn',
            'hvor'   => 'Sendes til verkstedet når et medlem løser inn en gave.',
            'felter' => [
                'tittel'  => 'Gaven',
                'navn'    => 'Navnet på medlemmet',
                'kontakt' => 'E-post eller telefon',
                'beskjed' => 'Det medlemmet skrev',
            ],
        ],
    ];
}
