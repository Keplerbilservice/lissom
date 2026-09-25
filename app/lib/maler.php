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
                // Fornavnet (migrasjon 213, «Hei {navn}, og takk …»).
                'navn'       => 'Fornavnet til kunden',
                'ordre'      => 'Bestillingsnummeret, f.eks. B-260901-C4D7EC',
                'varelinjer' => 'Varene, én per linje, med antall og pris',
                'sum'        => 'Totalsummen',
                // Tom for den som har betalt. Valgte kunden «betal ved
                // henting», står summen og måten her.
                'betaling' => 'Setningen om betaling ved henting',
            ],
        ],
        // Bestillingen til leverandoren. Migrasjon 184: handlelistene fra
        // medlemmene, slaatt sammen og sendt videre.
        'leverandorbestilling' => [
            'tittel' => 'Bestilling til leverandøren',
            'hvor'   => 'Sendes når verkstedet trykker «Bestill hos …» under Nettbutikk → Handlelister.',
            'felter' => [
                'nummer'     => 'Bestillingsnummeret, f.eks. B-2609-014',
                'leverandor' => 'Navnet på leverandøren',
                'dato'       => 'Dagen bestillingen sendes',
                'varer'      => 'Varelinjene, delt opp per medlem med navnet over',
            ],
        ],
        'butikkordre_pakke' => [
            'tittel' => 'Butikkbestilling — sendes',
            'hvor'   => 'Sendes når kunden valgte «Send som pakke» i kassa.',
            'felter' => [
                // Fornavnet (migrasjon 213, «Hei {navn}, og takk …»).
                'navn'       => 'Fornavnet til kunden',
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
                // «belop» er tatt bort: ingen pris i bekreftelsen (eieren,
                // 24. september 2026). Feltet sendes tomt, se Booking.
                // Tom for den som har betalt. Valgte kunden «betal ved
                // oppmøte», står måten her (uten summen).
                'betaling' => 'Setningen om betaling ved oppmøte',
                // Eieren, 25. september 2026. Se Booking::kursinfo.
                'kursinfo' => 'Lengden, dag 1 og dag 2 og «Praktisk» fra kurset',
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
        'pamelding_flyttet' => [
            'tittel' => 'Ny dato på kurset',
            'hvor'   => 'Sendes når du flytter en deltaker til en ny dato med «Bytt dato» i kalenderen.',
            'felter' => [
                'navn'  => 'Navnet på deltakeren',
                'kurs'  => 'Kurset',
                'fra'   => 'Datoen hen sto på',
                'til'   => 'Den nye datoen',
                'lenke' => 'Lenken til Min side',
            ],
        ],
        'kurspaaminnelse' => [
            'tittel' => 'Påminnelse før kurset',
            // Sto «dagen før». Den gaar inntil 30 timer for kursstart, saa
            // den kan lande samme morgen — se bin/cron.php («paaminnelser»).
            'hvor'   => 'Sendes automatisk før kurset, senest morgenen samme dag.',
            'felter' => [
                'fornavn' => 'Fornavnet til deltakeren',
                'navn'    => 'Fornavnet også (fra før)',
                'kurs'    => 'Kursets navn',
                'tid'     => 'Klokkeslettet kurset starter',
                'naar'    => 'Dagen og klokkeslettet. Går kurset over flere dager, står hver dag på sin egen linje',
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
            'hvor'   => 'Sendes noen timer etter kurset, til dem som betalte.',
            'felter' => [
                'fornavn' => 'Fornavnet til deltakeren',
                'navn'  => 'Fornavnet også',
                'kurs'  => 'Kurset de var på',
                'lenke' => 'Lenken de legger igjen ordene på',
            ],
        ],
        // Kom med medlemsinvitasjonen 12. september, men sto ikke her.
        // bin/cron.php kaller Varsel::mal('fortsett', ...), og Varsel::mal()
        // skriver bare en linje i loggen og gaar videre naar malen mangler i
        // registeret — saa teksten var usynlig under Tekst maler, og kunne
        // verken leses eller rettes av eieren.
        'fortsett' => [
            'tittel' => 'Vil du fortsette med leire?',
            'hvor'   => 'Sendes noen dager etter kurset, til dem som ikke alt er medlemmer.',
            'felter' => [
                'navn'      => 'Fornavnet til deltakeren',
                'visste'    => 'Prisboksen for «Prøv Lissom», hentet fra medlemskapene',
                'avmelding' => 'Lenken de melder seg av med',
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
            // Lenka sto her til 7. september. Den var Vipps sin egen adresse,
            // og den lever i ti minutter — den var doed for e-posten ble
            // lest. Eieren: «fjern linken. Fortell at man kan se faste trekk
            // i vipps appen», og «Fjern lenka overalt».
            'hvor'   => 'Sendes når noen melder seg inn med fast trekk i Vipps, og når'
                        . ' verkstedet trykker «Send Vipps-avtale». Den sier hvor avtalen'
                        . ' står — under Faste trekk i Vipps-appen — og har ingen lenke.',
            'felter' => [
                'navn'  => 'Navnet på det nye medlemmet',
                'type'  => 'Medlemskapet de valgte',
                'belop' => 'Prisen i måneden',
            ],
        ],
        'innmelding_ordner_selv' => [
            'tittel' => 'Innmelding — ordner selv',
            // «lenke» sto her til 6. september. Innmeldingen sender soekeren
            // rett til Vipps, saa brevet ba om en betaling som nettopp var
            // gjort — og lenka var en betaling til paa det samme
            // medlemskapet. Eieren: «de maa betale naar de booker!!»
            //
            // Feltet kan fortsatt settes inn i teksten under Beskjeder, men
            // det staar ikke lenger som noe malen skal ha.
            'hvor'   => 'Sendes når noen melder seg inn på et medlemskap uten fast trekk.'
                        . ' Betalingen skjer ved innmeldingen, i Vipps — brevet sier'
                        . ' derfor ingenting om penger. Kvitteringen kommer i'
                        . ' «Medlemskapet er i gang».',
            'felter' => [
                'navn'  => 'Navnet på det nye medlemmet',
                'type'  => 'Medlemskapet de valgte',
                'belop' => 'Prisen for perioden',
            ],
        ],
        'medlemskap_betalt' => [
            'tittel' => 'Medlemskapet er i gang',
            'hvor'   => 'Sendes når betalingen faktisk er registrert, og medlemskapet'
                        . ' slås på. Uten den fikk medlemmet aldri et ord fra oss om at'
                        . ' det gikk i orden — bare Vipps sin egen kvittering.',
            'felter' => [
                'navn'   => 'Navnet på medlemmet',
                'type'   => 'Medlemskapet',
                'belop'  => 'Beløpet som ble betalt',
                'gyldig' => 'Når det går ut, eller at vi tar kontakt før neste periode',
            ],
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
        'intern_ny_vare_ute' => [
            'tittel' => 'Til deg: vare gikk rett ut',
            'hvor'   => 'Sendes til verkstedet når et medlem legger ut en vare og auto-godkjenn står på.',
            'felter' => [
                'produsent' => 'Medlemmet som laget den',
                'tittel'    => 'Varens navn',
                'pris'      => 'Prisen de satte',
            ],
        ],
        'intern_ny_pamelding' => [
            'tittel' => 'Til deg: ny påmelding',
            'hvor'   => 'Sendes til verkstedet når noen melder seg på et kurs.',
            'felter' => [
                'navn'     => 'Navnet på den som meldte seg på',
                'kurs'     => 'Kurset',
                'naar'     => 'Når kurset er',
                'belop'    => 'Summen',
                'betaling' => '«Betalt» eller «Ubetalt»',
                'epost'    => 'E-postadressen deres',
                'telefon'  => 'Telefonnummeret, eller «(ikke oppgitt)»',
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

        // ── Dugnad (migrasjon 189/190) ──────────────────────────────
        'intern_dugnad_sporsmal' => [
            'tittel' => 'Til deg: dugnad — noen vil bidra',
            'hvor'   => 'Sendes til verkstedet når et medlem ber om å jobbe dugnad.',
            'felter' => ['navn' => 'Navnet på medlemmet', 'tekst' => 'Det medlemmet vil gjøre'],
        ],
        'intern_dugnad_ferdig' => [
            'tittel' => 'Til deg: dugnad — tid til godkjenning',
            'hvor'   => 'Sendes til verkstedet når medlemmet stempler ut fra dugnad.',
            'felter' => [
                'navn'     => 'Navnet på medlemmet',
                'tekst'    => 'Det medlemmet gjorde',
                'varighet' => 'Stemplet tid, f.eks. «1 t 25 min»',
                'forslag'  => 'Tida rundet til kvarter, i timer',
            ],
        ],
        'dugnad_godkjent' => [
            'tittel' => 'Dugnad godkjent',
            'hvor'   => 'Sendes til medlemmet når du godkjenner forespørselen.',
            'felter' => ['fornavn' => 'Fornavnet', 'tekst' => 'Det medlemmet vil gjøre', 'svar' => 'Det du skrev, eller tom'],
        ],
        'dugnad_avslatt' => [
            'tittel' => 'Dugnad avslått',
            'hvor'   => 'Sendes til medlemmet når du avslår forespørselen.',
            'felter' => ['fornavn' => 'Fornavnet', 'tekst' => 'Det medlemmet ville gjøre', 'svar' => 'Det du skrev, eller tom'],
        ],
        'dugnad_tid_godkjent' => [
            'tittel' => 'Dugnadstid lagt til',
            'hvor'   => 'Sendes til medlemmet når du godkjenner tida.',
            'felter' => ['fornavn' => 'Fornavnet', 'tekst' => 'Det medlemmet gjorde', 'timer' => 'Timene som ble lagt til', 'svar' => 'Det du skrev, eller tom'],
        ],
        'dugnad_tid_avvist' => [
            'tittel' => 'Dugnadstid ikke godkjent',
            'hvor'   => 'Sendes til medlemmet når du ikke godkjenner tida.',
            'felter' => ['fornavn' => 'Fornavnet', 'tekst' => 'Det medlemmet gjorde', 'svar' => 'Det du skrev, eller tom'],
        ],
    ];
}
