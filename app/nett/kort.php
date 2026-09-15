<?php
/**
 * Kortene paa kundesidene: kurskortene og varekortene, regnet paa serveren.
 *
 * Reglene er de samme som i nettsida (kursKort, medServerdata, kortDatoer,
 * plasstekst, ledigTekst, forsideProdukter i lissom-2108.html), portert
 * hit. Dataene er de samme som appen faar fra /api/kurs.php og
 * /api/butikk.php — Katalog::offentlig() og products — saa kortet sier det
 * samme enten det er serveren eller appen som tegner det.
 */

declare(strict_types=1);

final class Kort
{
    private const FOTO = 'assets_photos_';

    /**
     * Designlista: de fem kortene nettsida alltid har hatt, med sitt eget
     * nivaa-ord og bilde. Basen fyller inn datoer, pris og status naar
     * kurset finnes der (matchet paa tittel); finnes det ikke, vises det
     * ikke. Rekkefoelgen betyr ingenting — lista sorteres paa dato.
     */
    private const DESIGN = [
        ['level' => 'Nybegynner',           'title' => 'Nybegynner dreiekurs', 'tema' => 'Dreiing',      'image' => 'uploads_shutterstock_2630283113.jpg'],
        ['level' => 'Kurs',                 'title' => 'Kurs boller',          'tema' => 'Plateteknikk', 'image' => self::FOTO . 'handbygging.jpg'],
        ['level' => 'Kurs',                 'title' => 'Store fat kurs',       'tema' => 'Plateteknikk', 'image' => 'uploads_shutterstock_2830576133.jpg'],
        ['level' => 'Event · For dere to',  'title' => 'Date Night',           'tema' => 'Events',       'image' => 'uploads_655787162_26415535508077928_8440401554777300898_n.jpg'],
        ['level' => 'Event · Den enkleste', 'title' => 'Paint on Pots',        'tema' => 'Events',       'image' => 'uploads_shutterstock_2830576853.jpg'],
    ];

    /** @var list<array<string,mixed>>|null */
    private static ?array $alle = null;

    /**
     * Alle kurskortene, i den rekkefoelgen de gaar — som kursKort() i nettsida.
     * @return list<array<string,mixed>>
     */
    public static function kurs(): array
    {
        if (self::$alle !== null) {
            return self::$alle;
        }
        $katalog = Katalog::offentlig(false);
        $perTittel = [];
        foreach ($katalog as $k) {
            $perTittel[mb_strtolower((string) $k['tittel'])] = $k;
        }

        $ut = [];
        // Designlista, med basens data.
        foreach (self::DESIGN as $d) {
            $kat = $perTittel[mb_strtolower($d['title'])] ?? null;
            if ($kat === null) {
                continue;   // utenDato: kan ikke bookes, vises ikke
            }
            $kort = self::medServerdata($d, $kat);
            if ($kort !== null) {
                $ut[] = $kort;
            }
        }
        // Resten av katalogen — serverKurs() i nettsida.
        $kjente = array_map(static fn(array $d): string => mb_strtolower($d['title']), self::DESIGN);
        foreach ($katalog as $k) {
            if (in_array(mb_strtolower((string) $k['tittel']), $kjente, true)) {
                continue;
            }
            if (($k['datoer'] ?? []) === [] && empty($k['utenDatoOk'])) {
                continue;
            }
            if (($k['tema'] ?? '') === 'Kun for medlemmer') {
                continue;
            }
            $d = [
                'level' => (string) ($k['tema'] ?: ($k['type'] === 'event' ? 'Event' : 'Kurs')),
                'title' => (string) $k['tittel'],
                'image' => (string) ($k['bilde'] ?: self::FOTO . 'handbygging.jpg'),
            ];
            $kort = self::medServerdata($d, $k);
            if ($kort !== null) {
                $ut[] = $kort;
            }
        }

        // I den rekkefoelgen de gaar. Uten datoer: bakerst, stabilt.
        $med = [];
        foreach ($ut as $i => $k) {
            $med[] = ['k' => $k, 'i' => $i, 'n' => self::foersteOkt($k)];
        }
        usort($med, static fn(array $a, array $b): int => ($a['n'] <=> $b['n']) ?: ($a['i'] <=> $b['i']));
        return self::$alle = array_map(static fn(array $x): array => $x['k'], $med);
    }

    /** medServerdata() + medBooking() i nettsida, for ett kort. */
    private static function medServerdata(array $d, array $kat): ?array
    {
        $datoer = $kat['datoer'] ?? [];
        $pris = !empty($kat['gjenstandIKassa'])
            ? (!empty($kat['prisFraOre']) ? 'Fra ' . $kat['prisFra'] : '')
            : ((int) $kat['prisOre'] === 0 ? 'Gratis' : (string) $kat['pris']);
        $slug = (string) ($kat['slug'] ?? '');
        $felles = [
            'slug'     => $slug,
            'level'    => (string) $d['level'],
            'title'    => (string) $d['title'],
            'price'    => $pris,
            'image'    => (string) (($kat['bilde'] ?? '') !== '' ? $kat['bilde'] : $d['image']),
            'imageAlt' => (string) ($kat['bildeAlt'] ?? ''),
            'text'     => trim((string) ($kat['kortBeskrivelse'] ?? '')),
            'href'     => '/kurs/' . rawurlencode($slug),
            'okter'    => $datoer,
        ];
        if ($datoer === []) {
            if (empty($kat['utenDatoOk'])) {
                return null;   // utenDato
            }
            return $felles + [
                'status'   => 'Ta kontakt for dato',
                'date'     => '',
                'duration' => '',
                'cta'      => 'Les mer',
            ];
        }
        $status = self::plasstekst($datoer, (int) ($kat['plasser'] ?? 0));
        return $felles + [
            'status'   => $status,
            'date'     => self::kortDatoer($datoer),
            'duration' => (string) ($kat['varighetVist'] ?? ''),
            'cta'      => $status === 'Fullbooket' ? 'Les mer' : 'Book plass',
        ];
    }

    /** Tre datoer paa kortet: «9. sep · 16. sep · 7. okt +9». */
    private static function kortDatoer(array $okter): string
    {
        $dager = [];
        foreach ($okter as $o) {
            $dag = (string) ($o['dag'] ?? '');
            if ($dag !== '' && !in_array($dag, $dager, true)) {
                $dager[] = $dag;
            }
        }
        if ($dager === []) {
            return '';
        }
        $kort = static function (string $dag): string {
            if (preg_match('/(\d+)\.\s*([a-zæøå]+)/iu', $dag, $m) !== 1) {
                return $dag;
            }
            return $m[1] . '. ' . mb_strtolower(mb_substr($m[2], 0, 3));
        };
        $vist = implode(' · ', array_map($kort, array_slice($dager, 0, 3)));
        $flere = count($dager) - min(3, count($dager));
        return $flere > 0 ? $vist . ' +' . $flere : $vist;
    }

    /** Hvor mange plasser som er igjen, slik kunden skal se det. */
    private static function plasstekst(array $datoer, int $kapasitet): string
    {
        $neste = $datoer[0] ?? null;
        if ($neste === null) {
            return 'Ingen datoer ennå';
        }
        return self::ledigTekst((int) ($neste['ledige'] ?? 0), (int) ($neste['plasser'] ?: $kapasitet), !empty($neste['sperret']));
    }

    private static function ledigTekst(int $l, int $kap, bool $sperret): string
    {
        if ($sperret && $l <= 0) {
            return 'Kurs i verkstedet';
        }
        if ($l <= 0) {
            return 'Fullbooket';
        }
        if ($kap <= 0) {
            $kap = 12;
        }
        if ($kap <= 8) {
            if ($l <= 3) { return 'Få plasser'; }
            if ($l <= 5) { return $l . ($l === 1 ? ' ledig plass' : ' ledige plasser'); }
            return 'Ledige plasser';
        }
        if ($l <= 4) { return 'Få ledige plasser'; }
        if ($l <= 6) { return $l . ' ledige plasser'; }
        return 'Ledige plasser';
    }

    /** Starttida paa foerste oekt, som tidsstempel. Uten oekter: uendelig. */
    private static function foersteOkt(array $k): float
    {
        $min = INF;
        foreach ($k['okter'] ?? [] as $o) {
            $t = strtotime(str_replace(' ', 'T', (string) ($o['startUtc'] ?? '')) . 'Z');
            if ($t !== false && $t < $min) {
                $min = $t;
            }
        }
        return $min;
    }

    /**
     * Varene paa forsida: de tre nyeste med bilde, pluss gavekortet —
     * forsideProdukter() i nettsida.
     * @return list<array{title:string,tekst:string,pris:string,image:string,fokus:string,cta:string,href:string}>
     */
    public static function forsideProdukter(): array
    {
        $rader = DB::alle(
            "SELECT id, tittel, beskrivelse, bilde, pris_ore, lager
               FROM products
              WHERE status = 'publisert' AND kun_medlemmer = 0
                AND (lager IS NULL OR lager > 0)"
        );
        usort($rader, static fn(array $a, array $b): int =>
            ((($b['bilde'] ?? '') !== '' ? 1 : 0) <=> (($a['bilde'] ?? '') !== '' ? 1 : 0)) ?: ((int) $b['id'] <=> (int) $a['id']));
        $ut = [];
        foreach (array_slice($rader, 0, 3) as $v) {
            $bilde = (string) ($v['bilde'] ?: self::FOTO . 'butikken.jpg');
            $ut[] = [
                'title' => (string) $v['tittel'],
                'tekst' => (string) ($v['beskrivelse'] ?: 'Håndlaget i verkstedet på Teie.'),
                'pris'  => Booking::kroner((int) $v['pris_ore']),
                'image' => $bilde,
                'fokus' => Nett::fokus($bilde),
                'cta'   => 'Se mer',
                'href'  => Lenker::vare((int) $v['id'], (string) $v['tittel']),
            ];
        }
        $gavekort = 'uploads_shutterstock_2829108657.jpg';
        $ut[] = [
            'title' => 'Gavekort',
            'tekst' => 'Kan brukes på alle våre tjenester og produkter.',
            'pris'  => 'Valgfritt beløp',
            'image' => $gavekort,
            'fokus' => Nett::fokus($gavekort),
            'cta'   => 'Se mer',
            'href'  => '/gavekort',
        ];
        return $ut;
    }
}
