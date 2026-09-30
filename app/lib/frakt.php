<?php
declare(strict_types=1);

/**
 * Frakten fra Pakke-Express paa samlebestillingen.
 *
 * Eieren, 30. september 2026: fraktprisene fra Pakke-Express er «til bruk på
 * handlelisten innkjøp». Frakten deles paa medlemmene som har varer i
 * bestillingen, etter vekt («ta vekt pr vare»). Admin kan rette totalvekten.
 *
 * Prisene, sonene og energitillegget staar i innstillinger
 * (frakt_pakke_express, migrasjon 237) — aldri her. Mangler oppsettet, regnes
 * ingen frakt automatisk, og admin skriver den inn for haand som foer.
 *
 * Alt er i oere og gram. Vektklassen finnes paa totalvekten; en sending paa
 * 3 kg er i klassen «0–3 kg», 3,001 kg i «4–10 kg».
 */
final class Frakt
{
    public const NOKKEL = 'frakt_pakke_express';

    /** Oppsettet fra basen, eller null naar det ikke finnes eller ikke kan leses. */
    public static function oppsett(): ?array
    {
        if (!DB::harTabell('innstillinger')) {
            return null;
        }
        $raa = DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => self::NOKKEL]);
        $o = json_decode((string) $raa, true);
        return is_array($o) ? self::rens($o) : null;
    }

    /**
     * Et oppsett slik det skal lagres: soner, klasser sortert paa tilKg, og
     * tallene som heltall. Kaster ved noe som ikke henger sammen.
     */
    public static function rens(array $o): ?array
    {
        $soner = [];
        foreach ((array) ($o['soner'] ?? []) as $s) {
            $kode = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($s['kode'] ?? '')));
            $navn = trim((string) ($s['navn'] ?? ''));
            if ($kode !== '' && $navn !== '') {
                $soner[$kode] = ['kode' => $kode, 'navn' => mb_substr($navn, 0, 60)];
            }
        }
        if ($soner === []) {
            return null;
        }
        $klasser = [];
        foreach ((array) ($o['klasser'] ?? []) as $k) {
            $til = (int) ($k['tilKg'] ?? 0);
            if ($til <= 0) {
                continue;
            }
            $ore = [];
            foreach ($soner as $kode => $_) {
                $ore[$kode] = max(0, (int) (($k['ore'] ?? [])[$kode] ?? 0));
            }
            $klasser[$til] = ['tilKg' => $til, 'ore' => $ore];
        }
        if ($klasser === []) {
            return null;
        }
        ksort($klasser);
        // «Fra» er alltid én over forrige «til»: 0–3, 4–10, 11–30 …
        $forrige = -1;
        foreach ($klasser as &$k) {
            $k['fraKg'] = $forrige + 1;
            $forrige = $k['tilKg'];
        }
        unset($k);
        $energi = (float) str_replace(',', '.', (string) ($o['energiProsent'] ?? 0));
        return [
            'soner'         => array_values($soner),
            'klasser'       => array_values($klasser),
            'energiProsent' => max(0.0, min(100.0, round($energi, 2))),
            'budKrPerKm'    => max(0, (int) ($o['budKrPerKm'] ?? 0)),
        ];
    }

    public static function harSone(?array $oppsett, ?string $kode): bool
    {
        if ($oppsett === null || $kode === null || $kode === '') {
            return false;
        }
        foreach ($oppsett['soner'] as $s) {
            if ($s['kode'] === $kode) {
                return true;
            }
        }
        return false;
    }

    public static function soneNavn(array $oppsett, string $kode): string
    {
        foreach ($oppsett['soner'] as $s) {
            if ($s['kode'] === $kode) {
                return $s['navn'];
            }
        }
        return $kode;
    }

    /**
     * Prisen for én sending.
     *
     * @return array{ok:bool, feil?:string, klasse?:string, grunnOre?:int, energiOre?:int, sumOre?:int}
     */
    public static function beregn(array $oppsett, string $sone, int $gram): array
    {
        if (!self::harSone($oppsett, $sone)) {
            return ['ok' => false, 'feil' => 'Ukjent sone for frakten.'];
        }
        $gram = max(0, $gram);
        foreach ($oppsett['klasser'] as $k) {
            if ($gram <= $k['tilKg'] * 1000) {
                $grunn = (int) ($k['ore'][$sone] ?? 0);
                $energi = (int) round($grunn * $oppsett['energiProsent'] / 100);
                return [
                    'ok'        => true,
                    'klasse'    => $k['fraKg'] . '–' . $k['tilKg'] . ' kg',
                    'grunnOre'  => $grunn,
                    'energiOre' => $energi,
                    'sumOre'    => $grunn + $energi,
                ];
            }
        }
        $maks = (int) end($oppsett['klasser'])['tilKg'];
        return ['ok' => false, 'feil' => 'Totalvekten er over ' . $maks . ' kg. Pakke-Express har ingen pris for det. Del bestillingen i flere sendinger.'];
    }

    /**
     * Deler et beloep etter vekt (eller annen andel) slik at summen blir
     * eksakt: hver faar gulvet av sin andel, og de oerene som er igjen gaar til
     * dem med stoerst rest. Er alle andelene null, deles det likt.
     *
     * @param array<int|string,int> $andeler
     * @return array<int|string,int>
     */
    public static function fordel(int $sumOre, array $andeler): array
    {
        if ($andeler === []) {
            return [];
        }
        $total = array_sum($andeler);
        if ($total <= 0) {
            $andeler = array_fill_keys(array_keys($andeler), 1);
            $total = count($andeler);
        }
        $ut = [];
        $rester = [];
        $brukt = 0;
        foreach ($andeler as $k => $a) {
            $eksakt = $sumOre * $a / $total;
            $ut[$k] = (int) floor($eksakt);
            $rester[$k] = $eksakt - $ut[$k];
            $brukt += $ut[$k];
        }
        arsort($rester);
        $igjen = $sumOre - $brukt;
        foreach (array_keys($rester) as $k) {
            if ($igjen <= 0) {
                break;
            }
            $ut[$k]++;
            $igjen--;
        }
        return $ut;
    }

    /** Har basen feltene fra migrasjon 237? */
    public static function klar(): bool
    {
        static $klar = null;
        return $klar ??= DB::harKolonne('leverandorer', 'frakt_sone_standard')
            && DB::harKolonne('products', 'vekt_g')
            && DB::harKolonne('handleliste_linjer', 'vekt_g');
    }

    /**
     * Frakten for bestillingene som ligger naa, per leverandoer som har sone.
     * Leverandoerer uten sone er ikke med — der skriver admin frakten selv.
     *
     * @return array<int,array<string,mixed>> levId => bildet
     */
    public static function perLeverandor(): array
    {
        $oppsett = self::oppsett();
        if ($oppsett === null || !self::klar()) {
            return [];
        }
        $lev = DB::alle('SELECT id, frakt_sone_standard, frakt_sone, frakt_vekt_g FROM leverandorer WHERE aktiv = 1');
        $linjer = DB::alle(
            "SELECT h.id, h.member_id, h.antall, h.pris_ore,
                    COALESCE(h.vekt_g, p.vekt_g) AS vekt_g,
                    COALESCE(p.leverandor_id, h.leverandor_id) AS leverandor_id
               FROM handleliste_linjer h
          LEFT JOIN products p ON p.id = h.product_id
              WHERE h.status = 'sendt'"
        );
        $ut = [];
        foreach ($lev as $l) {
            $sone = (string) ($l['frakt_sone'] ?? '') !== '' ? (string) $l['frakt_sone'] : (string) ($l['frakt_sone_standard'] ?? '');
            if (!self::harSone($oppsett, $sone)) {
                continue;
            }
            $levId = (int) $l['id'];
            $mine = array_values(array_filter($linjer, static fn($x) => (int) $x['leverandor_id'] === $levId));
            $vektSum = 0;
            $mangler = 0;
            $vekter = [];
            $belop = [];
            $alleHarPris = true;
            foreach ($mine as $x) {
                $m = (int) $x['member_id'];
                $vekter[$m] = $vekter[$m] ?? 0;
                $belop[$m] = $belop[$m] ?? 0;
                if ($x['vekt_g'] === null) {
                    $mangler++;
                } else {
                    $g = (int) $x['vekt_g'] * (int) $x['antall'];
                    $vekter[$m] += $g;
                    $vektSum += $g;
                }
                if ($x['pris_ore'] === null) {
                    $alleHarPris = false;
                } else {
                    $belop[$m] += (int) $x['pris_ore'] * (int) $x['antall'];
                }
            }
            $overstyrt = $l['frakt_vekt_g'] !== null ? (int) $l['frakt_vekt_g'] : null;
            $bilde = [
                'sone'        => $sone,
                'soneNavn'    => self::soneNavn($oppsett, $sone),
                'soneValgt'   => (string) ($l['frakt_sone'] ?? ''),
                'linjer'      => count($mine),
                'mangler'     => $mangler,
                'vektG'       => $vektSum,
                'overstyrtG'  => $overstyrt,
                'brukG'       => $overstyrt ?? $vektSum,
                'energiProsent' => $oppsett['energiProsent'],
                'ok'          => false,
                'advarsel'    => '',
                'andeler'     => [],
                'deling'      => '',
            ];
            if ($mine === []) {
                $ut[$levId] = $bilde;
                continue;
            }
            if ($mangler > 0 && $overstyrt === null) {
                $bilde['advarsel'] = $mangler . ($mangler === 1 ? ' varelinje mangler' : ' varelinjer mangler')
                    . ' vekt. Sett vekten, eller rett totalvekten, før du sender krav eller bestilling.';
                $ut[$levId] = $bilde;
                continue;
            }
            $pris = self::beregn($oppsett, $sone, $bilde['brukG']);
            if (!$pris['ok']) {
                $bilde['advarsel'] = (string) $pris['feil'];
                $ut[$levId] = $bilde;
                continue;
            }
            if ($mangler === 0 && $vektSum > 0) {
                $andeler = $vekter;
                $bilde['deling'] = 'vekt';
            } elseif ($alleHarPris && array_sum($belop) > 0) {
                $andeler = $belop;
                $bilde['deling'] = 'belop';
            } else {
                $andeler = array_fill_keys(array_keys($vekter), 1);
                $bilde['deling'] = 'likt';
            }
            $bilde = array_merge($bilde, $pris, [
                'ok'      => true,
                'andeler' => self::fordel((int) $pris['sumOre'], $andeler),
            ]);
            $ut[$levId] = $bilde;
        }
        return $ut;
    }

    /** Frakten ett medlem har i bestillingene som ligger naa, i oere. */
    public static function andelFor(int $medlemId): int
    {
        $sum = 0;
        foreach (self::perLeverandor() as $b) {
            $sum += (int) ($b['andeler'][$medlemId] ?? 0);
        }
        return $sum;
    }

    /** Gram fra et felt i kilo, «2,5» → 2500. Tomt → null. Kaster ved tull. */
    public static function gramFraKg(string $tekst): ?int
    {
        $t = trim(str_replace([' ', "\u{a0}"], '', $tekst));
        if ($t === '') {
            return null;
        }
        $t = str_replace(',', '.', $t);
        if (!is_numeric($t) || (float) $t < 0 || (float) $t > 100000) {
            throw new InvalidArgumentException('Vekten må være et tall i kilo, for eksempel 2,5.');
        }
        return (int) round((float) $t * 1000);
    }

    /** «2,5» fra 2500 gram. */
    public static function kg(?int $gram): string
    {
        if ($gram === null) {
            return '';
        }
        return rtrim(rtrim(number_format($gram / 1000, 3, ',', ''), '0'), ',');
    }
}
