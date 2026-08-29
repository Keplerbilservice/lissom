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
     * En overskrift som ikke kolliderer med en artikkel som alt finnes.
     *
     * articles.tittel er UNIQUE (migrasjon 018). Slug-en ble ryddet for det
     * — «-2» bak — men overskriften ble skrevet rett inn. Lagde eieren en
     * artikkel til om det samme, kom hele SQLSTATE-feilen opp paa skjermen:
     *
     *   SQLSTATE[23000]: Duplicate entry '...' for key 'uq_articles_tittel'
     *
     * Det er ikke en beskjed noen kan gjore noe med, og det stoppet
     * «Publiser naa» uten aa si hvorfor. Her faar den et tall bak i stedet,
     * og den som publiserte faar vite at den fikk det — saa hun kan gi den
     * et bedre navn framfor aa lure paa hva som skjedde.
     *
     * @param int|null $utenom Artikkelen som selv har tittelen (ved lagring).
     */
    public static function ledigTittel(string $tittel, ?int $utenom = null): string
    {
        $tittel = trim($tittel) !== '' ? trim($tittel) : 'Uten overskrift';
        $grunn  = $tittel;
        $n      = 2;
        while (self::tittelTatt($tittel, $utenom)) {
            // 191 tegn er hele feltet. Kappes det ikke, bytter vi én feil ut
            // med en annen.
            $hale   = ' (' . $n . ')';
            $tittel = mb_substr($grunn, 0, 191 - mb_strlen($hale)) . $hale;
            $n++;
            if ($n > 99) {
                return mb_substr($grunn, 0, 180) . ' ' . bin2hex(random_bytes(4));
            }
        }
        return $tittel;
    }

    private static function tittelTatt(string $tittel, ?int $utenom): bool
    {
        return (int) DB::verdi(
            'SELECT COUNT(*) FROM articles WHERE tittel = :t' . ($utenom ? ' AND id <> :i' : ''),
            $utenom ? ['t' => $tittel, 'i' => $utenom] : ['t' => $tittel]
        ) > 0;
    }

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

    /** Plasseringene og stoerrelsene et bilde i teksten kan ha. */
    public const PLASSERINGER = ['full', 'venstre', 'hoyre', 'midtstilt'];
    public const STORRELSER   = ['liten', 'medium', 'stor'];

    /**
     * Bildene som staar inne i teksten paa en artikkel.
     *
     * articles.bilde er noe annet: det er bildet lista viser og det som
     * foelger med naar noen deler lenken. Disse staar mellom avsnittene.
     *
     * @return list<array<string,mixed>>
     */
    public static function bilder(int $artikkelId): array
    {
        if (!DB::harTabell('artikkel_bilder')) {
            return [];
        }
        return array_map(static fn(array $b): array => [
            'id'         => (int) $b['id'],
            'fil'        => (string) $b['fil'],
            // Hvilket avsnitt bildet staar etter. 0 = foerst i artikkelen.
            'etter'      => (int) $b['rekkefolge'],
            'bildetekst' => (string) ($b['bildetekst'] ?? ''),
            // Tom alt-tekst er et valg, ikke en mangel: da er bildet pynt,
            // og skjermleseren hopper over det framfor aa lese opp et
            // filnavn.
            'alt'        => (string) ($b['alt_tekst'] ?? ''),
            'plassering' => (string) $b['plassering'],
            'storrelse'  => (string) $b['storrelse'],
        ], DB::alle(
            'SELECT * FROM artikkel_bilder WHERE artikkel_id = :a ORDER BY rekkefolge, id',
            ['a' => $artikkelId]
        ));
    }

    /**
     * Lagrer hele bildelista paa en artikkel.
     *
     * Hele lista om gangen, ikke ett og ett. Rekkefolgen og plasseringene
     * henger sammen — en halv lagring ville gitt en artikkel der to bilder
     * staar etter samme avsnitt fordi det tredje ikke rakk fram.
     *
     * @param list<array<string,mixed>> $liste
     */
    public static function lagreBilder(int $artikkelId, array $liste): int
    {
        if (!DB::harTabell('artikkel_bilder')) {
            return 0;
        }

        DB::kjor('DELETE FROM artikkel_bilder WHERE artikkel_id = :a', ['a' => $artikkelId]);

        $antall = 0;
        foreach ($liste as $b) {
            $fil = trim((string) ($b['fil'] ?? ''));
            if ($fil === '') {
                continue;   // en tom rad er en rad som ble angret
            }
            $plassering = (string) ($b['plassering'] ?? 'full');
            $storrelse  = (string) ($b['storrelse'] ?? 'medium');
            DB::settInn('artikkel_bilder', [
                'artikkel_id' => $artikkelId,
                'fil'         => mb_substr($fil, 0, 255),
                'rekkefolge'  => max(0, min(999, (int) ($b['etter'] ?? 0))),
                'bildetekst'  => mb_substr(trim((string) ($b['bildetekst'] ?? '')), 0, 255) ?: null,
                'alt_tekst'   => mb_substr(trim((string) ($b['alt'] ?? '')), 0, 255) ?: null,
                'plassering'  => in_array($plassering, self::PLASSERINGER, true) ? $plassering : 'full',
                'storrelse'   => in_array($storrelse, self::STORRELSER, true) ? $storrelse : 'medium',
            ]);
            $antall++;
        }
        return $antall;
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
