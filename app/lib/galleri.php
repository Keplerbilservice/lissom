<?php
/**
 * Galleriet paa forsida: «Bilder fra verkstedet vaart».
 *
 * Eieren, 27. september 2026: butikkfeltet paa forsida blir et galleri med
 * medlemmenes egne bilder. Bildene er medlemsforslagene fra Min side
 * (Medlemsforslag, migrasjon 207); admin godkjenner hvert bilde til
 * Instagram, til galleriet, eller begge (api/admin/medlemsforslag.php).
 * Kortene ruller, ett om gangen. Knappen til butikken blir staaende.
 *
 * ── Plassene og maanedsbyttet ──────────────────────────────────────────
 *
 * Galleriet har PLASSER plasser. Et godkjent medlemsbilde faar plass saa
 * snart det er ledig, og staar i en maaned fra det foerst ble vist
 * («galleri_vist_fra»). Etter en maaned viker det for et godkjent bilde som
 * venter paa plass — det som har ventet lengst kommer foerst. Venter ingen,
 * blir det staaende. (Eieren, 27. september 2026.)
 *
 * ── Fyllbildene ────────────────────────────────────────────────────────
 *
 * Er det faerre medlemsbilder enn plasser, fylles resten med verkstedets
 * egne bilder fra galleri-fyll.json i rota: [{"fil": "...jpg", "tittel": "..."}].
 * Fyllbildene viker alltid for medlemsbilder. Kommer det til sammen ikke
 * opp i MINST, staar butikkfeltet som foer, med varene.
 */

declare(strict_types=1);

final class Galleri
{
    public const PLASSER = 12;
    /** Faerre bilder enn dette, og forsida viser varene som foer. */
    public const MINST = 4;
    public const MAANED_DAGER = 30;
    /** Navnet under fyllbildene — de er verkstedets egne. */
    public const FYLL_NAVN = 'Lissom';

    public static function klar(): bool
    {
        return DB::harTabell('medlemsforslag') && DB::harKolonne('medlemsforslag', 'galleri_vist_fra');
    }

    /**
     * Kortteksten paa kortet: foerste linje av det medlemmet skrev, uten
     * hashtagger, og kort nok til aa staa paa kortet.
     */
    public static function tittel(string $tekst): string
    {
        $linje = trim((string) (preg_split('/\R/u', trim($tekst))[0] ?? ''));
        $linje = trim((string) preg_replace('/(^|\s)#\S+/u', ' ', $linje));
        $linje = trim((string) preg_replace('/\s+/u', ' ', $linje));
        if (mb_strlen($linje) <= 48) {
            return $linje;
        }
        $kort = mb_substr($linje, 0, 48);
        $mellom = mb_strrpos($kort, ' ');
        return rtrim($mellom !== false && $mellom > 24 ? mb_substr($kort, 0, $mellom) : $kort, " ,.;:-–") . '…';
    }

    /**
     * Alt-teksten til et galleribilde. Google leser den; den vises ikke.
     * Eieren, 27. september 2026: bildene skal kunne indekseres.
     */
    public static function alt(string $tittel): string
    {
        return ($tittel !== '' ? $tittel : 'Keramikk') . ', laget på Lissom keramikkverksted på Teie';
    }

    /**
     * Setter plassene: fyller ledige, og bytter ut bilder som har staatt en
     * maaned naar andre venter. Trygg aa kjore paa hver visning — den
     * endrer bare noe naar det er noe aa endre.
     *
     * @param DateTimeImmutable|null $naa UTC; testene kan sette klokka
     */
    public static function fordel(?DateTimeImmutable $naa = null): void
    {
        if (!self::klar()) {
            return;
        }
        $naa ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $naaTekst = $naa->format('Y-m-d H:i:s');
        $grense = $naa->modify('-' . self::MAANED_DAGER . ' days')->format('Y-m-d H:i:s');

        $aktive = DB::alle(
            "SELECT id, galleri_vist_fra FROM medlemsforslag
              WHERE galleri = 1 AND type = 'bilde' AND galleri_vist_fra IS NOT NULL
              ORDER BY galleri_vist_fra, id"
        );
        $venter = DB::alle(
            "SELECT id FROM medlemsforslag
              WHERE galleri = 1 AND type = 'bilde' AND galleri_vist_fra IS NULL
              ORDER BY COALESCE(galleri_godkjent_at, behandlet_at, created_at), id"
        );

        // Ledige plasser foerst.
        $ledige = self::PLASSER - count($aktive);
        while ($ledige > 0 && $venter !== []) {
            $ny = array_shift($venter);
            DB::kjor('UPDATE medlemsforslag SET galleri_vist_fra = :t WHERE id = :i', ['t' => $naaTekst, 'i' => (int) $ny['id']]);
            $ledige--;
        }

        // Saa maanedsbyttet: det eldste som har staatt en maaned, viker for
        // det som har ventet lengst. Det som tas ut, er ute av galleriet.
        foreach ($aktive as $a) {
            if ($venter === []) {
                break;
            }
            if ((string) $a['galleri_vist_fra'] > $grense) {
                break;   // sortert: resten er yngre enn en maaned
            }
            $ny = array_shift($venter);
            DB::iTransaksjon(static function () use ($a, $ny, $naaTekst): void {
                DB::kjor('UPDATE medlemsforslag SET galleri = 0 WHERE id = :i', ['i' => (int) $a['id']]);
                DB::kjor('UPDATE medlemsforslag SET galleri_vist_fra = :t WHERE id = :i', ['t' => $naaTekst, 'i' => (int) $ny['id']]);
            });
            revider('galleri_byttet', 'medlemsforslag', (int) $a['id'], ['ny' => (int) $ny['id']]);
        }
    }

    /**
     * Bildene paa forsida, i den rekkefoelgen de vises: medlemsbildene
     * (nyeste plass foerst), saa fyllbildene. Tom liste naar det ikke er
     * nok til et galleri — da viser forsida varene.
     *
     * @return list<array{bilde: string, tittel: string, navn: string, alt: string, fyll: bool}>
     */
    public static function kort(?DateTimeImmutable $naa = null): array
    {
        $ut = [];
        if (self::klar()) {
            try {
                self::fordel($naa);
            } catch (Throwable $e) {
                // Et bytte som feiler skal ikke ta forsida med seg.
                logg_feil('Galleriet fikk ikke fordelt plassene', $e);
            }
            $rader = DB::alle(
                "SELECT f.fil, f.tekst, m.navn FROM medlemsforslag f JOIN members m ON m.id = f.member_id
                  WHERE f.galleri = 1 AND f.type = 'bilde' AND f.galleri_vist_fra IS NOT NULL
                    AND f.status IN ('godkjent', 'publisert', 'galleri')
                  ORDER BY f.galleri_vist_fra DESC, f.id DESC
                  LIMIT " . self::PLASSER
            );
            foreach ($rader as $r) {
                $tittel = self::tittel((string) $r['tekst']);
                $ut[] = [
                    'bilde'  => '/api/bilde.php?forslag=' . rawurlencode((string) $r['fil']),
                    'tittel' => $tittel,
                    'navn'   => Medlemsforslag::fornavn((string) $r['navn']),
                    'alt'    => self::alt($tittel),
                    'fyll'   => false,
                ];
            }
        }
        foreach (self::fyll() as $f) {
            if (count($ut) >= self::PLASSER) {
                break;
            }
            $ut[] = $f;
        }
        return count($ut) >= self::MINST ? $ut : [];
    }

    /**
     * Verkstedets egne bilder, fra galleri-fyll.json.
     *
     * @return list<array{bilde: string, tittel: string, navn: string, alt: string, fyll: bool}>
     */
    public static function fyll(): array
    {
        $fil = dirname(APP_DIR) . '/galleri-fyll.json';
        if (!is_file($fil)) {
            // Paa webhotellet ligger rota i public_html, ved siden av lissom-app.
            $fil = dirname(APP_DIR, 2) . '/public_html/galleri-fyll.json';
        }
        $liste = is_file($fil) ? json_decode((string) file_get_contents($fil), true) : null;
        $ut = [];
        foreach (is_array($liste) ? $liste : [] as $r) {
            $navn = is_array($r) ? (string) ($r['fil'] ?? '') : '';
            // Bare vanlige nettbilder i rota: ingen stier, ingen adresser.
            if ($navn === '' || basename($navn) !== $navn || preg_match('/^[a-z0-9_\-]+\.(jpe?g|png|webp)$/i', $navn) !== 1) {
                continue;
            }
            $tittel = trim((string) ($r['tittel'] ?? ''));
            $ut[] = ['bilde' => '/' . $navn, 'tittel' => $tittel, 'navn' => self::FYLL_NAVN,
                     'alt' => self::alt($tittel), 'fyll' => true];
        }
        return $ut;
    }
}
