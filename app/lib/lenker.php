<?php
/**
 * Adressene ting faar paa nettsida.
 *
 * ── Hvorfor denne fila finnes ─────────────────────────────────────────
 *
 * Slugen ble laget to steder — én gang for artikler, én gang for kurs — med
 * nesten samme kode. «Nesten» er problemet: den ene brukte mb_strtolower og
 * den andre strtolower, og den dagen en tittel med en stor Ø kom inn, kunne
 * de to gitt hver sin adresse for det samme navnet. Naa staar regelen ett
 * sted.
 *
 * ── Varene i butikken ─────────────────────────────────────────────────
 *
 * Butikken hadde ingen adresser i det hele tatt: /butikk var én side, og
 * ingen kopp kunne rangere paa «haandlaget kopp Vestfold» eller ligge i
 * Googles gratis handletreff.
 *
 * Adressen er «/butikk/8-kaffekopp-gronn». Tallet er det som gjelder; navnet
 * bak staar der for menneskene og for soket. Det betyr at et produkt kan
 * doepes om uten at gamle lenker ryker — de peker fortsatt paa riktig vare,
 * og canonical sier hva adressen heter naa.
 *
 * Derfor ligger det ingen slug-kolonne paa products. En lagret slug ville
 * blitt staaende feil den dagen navnet ble endret, og maattet vedlikeholdes
 * i et felt ingen forstaar hvorfor er der.
 */

declare(strict_types=1);

final class Lenker
{
    /**
     * Tittel → adressebit: «Kaffekopp, grønn» blir «kaffekopp-gronn».
     *
     * @param string $reserve det den blir naar tittelen ikke gir noe igjen —
     *                        en tittel som bare er emojier, for eksempel
     */
    public static function slug(string $tekst, string $reserve = 'side'): string
    {
        $s = mb_strtolower(trim($tekst));
        $s = strtr($s, [
            'æ' => 'ae', 'ø' => 'o', 'å' => 'a',
            'Æ' => 'ae', 'Ø' => 'o', 'Å' => 'a',
        ]);
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');

        return $s !== '' ? $s : $reserve;
    }

    /** Adressen til én vare i butikken. */
    public static function vare(int $id, string $tittel): string
    {
        return '/butikk/' . $id . '-' . self::slug($tittel, 'vare');
    }

    /**
     * Id-en ut av en vareadresse, eller null om det ikke er en.
     *
     * Navnet bak tallet leses ikke. Er det utdatert, er det fortsatt riktig
     * vare — og canonical sender soekemotoren til den gjeldende adressen.
     */
    public static function vareId(string $sti): ?int
    {
        return preg_match('~^/butikk/(\d+)(?:-[^/]*)?$~', $sti, $t) === 1
            ? (int) $t[1]
            : null;
    }
}
