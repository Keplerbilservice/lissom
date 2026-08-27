<?php
/**
 * Publiseringen av en artikkel.
 *
 * En artikkel har fire tilstander (migrasjon 063): kladd, planlagt,
 * publisert, avpublisert. Bare «publisert» vises paa nettsida.
 *
 * En planlagt artikkel skal ut naar klokka passerer tidspunktet. Det gjores
 * ikke av en egen cron-jobb: klokkeslettet ville da avhengt av at eieren fikk
 * lagt inn en linje til i cPanel, og en artikkel som skulle ut klokka ni ville
 * ligget der til noen husket det. I stedet flyttes de som er forfalt hver gang
 * noen spor etter artikler — av nettsida eller av admin. Faller den mellom to
 * stoler er det fordi ingen leser, og da er det ingen som ser det heller.
 *
 * Kallet koster ingenting naar det ikke er noe aa gjore: en UPDATE med en
 * WHERE som ikke treffer noe, paa en indeks laget for nettopp det.
 */

declare(strict_types=1);

final class Artikler
{
    /** De fire tilstandene, i den rekkefolgen de opptrer i. */
    public const TILSTANDER = ['kladd', 'planlagt', 'publisert', 'avpublisert'];

    /**
     * Setter planlagte artikler som har naadd tidspunktet sitt til publisert.
     *
     * Returnerer hvor mange som ble flyttet. Trygg aa kalle sa ofte man vil.
     */
    public static function publiserForfalte(): int
    {
        // Taaler at migrasjon 063 ikke er kjort: da finnes ingen planlagte
        // artikler, og alt oppforer seg som for.
        if (!DB::harKolonne('articles', 'planlagt_til')) {
            return 0;
        }

        $svar = DB::kjor(
            "UPDATE articles
                SET status = 'publisert',
                    publisert_at = COALESCE(planlagt_til, UTC_TIMESTAMP())
              WHERE status = 'planlagt'
                AND planlagt_til IS NOT NULL
                AND planlagt_til <= UTC_TIMESTAMP()
                AND TRIM(COALESCE(innhold, '')) <> ''"
        );
        return $svar->rowCount();
    }

    /**
     * Hva som staar paa knappen og merket for en gitt tilstand.
     *
     * Én kilde, saa lista i admin og skjermen bak den ikke kan bli uenige.
     *
     * @return array{merke: string, forklaring: string}
     */
    public static function merke(string $status): array
    {
        return match ($status) {
            'publisert'   => ['merke' => 'Ute',         'forklaring' => 'Ligger på nettsiden nå.'],
            'planlagt'    => ['merke' => 'Planlagt',    'forklaring' => 'Går ut av seg selv på tidspunktet du satte.'],
            'avpublisert' => ['merke' => 'Tatt ned',    'forklaring' => 'Har ligget ute, men er tatt ned igjen.'],
            default       => ['merke' => 'Kladd',       'forklaring' => 'Har aldri vært ute.'],
        };
    }
}
