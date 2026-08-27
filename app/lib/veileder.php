<?php
/**
 * Kursveilederen: sporsmalene, svarene og anbefalingen.
 *
 * Sporsmalene laa i koden og svarene i nettleserens minne. Redigeringen i
 * admin saa ut til aa virke — feltene tok imot tekst, «Legg til svar» la til
 * en rad — men ingenting overlevde en oppfriskning av sida. Naa staar de i
 * basen (migrasjon 066), og bade nettsida og admin leser det samme.
 */

declare(strict_types=1);

final class Veileder
{
    public const TYPER = ['envalg', 'flervalg', 'janei', 'tall', 'fritekst', 'avhuking'];

    /** Er tabellene der? Migrasjon 066. */
    public static function klar(): bool
    {
        return DB::harTabell('veileder_sporsmal') && DB::harTabell('veileder_svar');
    }

    /**
     * Sporsmalene med svarene sine.
     *
     * @param bool $baraAktive Nettsida vil bare ha de aktive; admin vil ha alt.
     * @return list<array<string,mixed>>
     */
    public static function sporsmal(bool $baraAktive = true): array
    {
        if (!self::klar()) {
            return [];
        }

        $hvor = $baraAktive ? 'WHERE aktiv = 1' : '';
        $rader = DB::alle("SELECT * FROM veileder_sporsmal {$hvor} ORDER BY sortering, id");
        if ($rader === []) {
            return [];
        }

        // Ett oppslag for alle svarene, ikke ett per sporsmal.
        $svarHvor = $baraAktive ? 'WHERE aktiv = 1' : '';
        $alleSvar = DB::alle("SELECT * FROM veileder_svar {$svarHvor} ORDER BY sortering, id");
        $perSporsmal = [];
        foreach ($alleSvar as $s) {
            $perSporsmal[(int) $s['sporsmal_id']][] = [
                'id'          => (int) $s['id'],
                'tekst'       => (string) $s['tekst'],
                'aktiv'       => (bool) $s['aktiv'],
                'passerNivaa' => (string) ($s['passer_nivaa'] ?? ''),
                'passerHvem'  => (string) ($s['passer_hvem'] ?? ''),
                'metode'      => (string) ($s['metode'] ?? ''),
                'varighet'    => (string) ($s['varighet'] ?? ''),
                'mal'         => (string) ($s['mal'] ?? ''),
                'begrunnelse' => (string) ($s['begrunnelse'] ?? ''),
            ];
        }

        return array_map(static fn(array $q): array => [
            'id'          => (int) $q['id'],
            'nokkel'      => (string) ($q['nokkel'] ?? ''),
            'tekst'       => (string) $q['tekst'],
            'hjelpetekst' => (string) ($q['hjelpetekst'] ?? ''),
            'type'        => (string) $q['type'],
            'sortering'   => (int) $q['sortering'],
            'aktiv'       => (bool) $q['aktiv'],
            // Betinget sporsmal: vises bare naar et annet sporsmal har et
            // bestemt svar. «Hvem er dere to?» spor ikke alle.
            'visNarId'    => $q['vis_nar_id'] !== null ? (int) $q['vis_nar_id'] : 0,
            'visNarVerdi' => (string) ($q['vis_nar_verdi'] ?? ''),
            'minVerdi'    => $q['min_verdi'] !== null ? (int) $q['min_verdi'] : null,
            'maksVerdi'   => $q['maks_verdi'] !== null ? (int) $q['maks_verdi'] : null,
            'maksTekst'   => (string) ($q['maks_tekst'] ?? ''),
            'svar'        => $perSporsmal[(int) $q['id']] ?? [],
        ], $rader);
    }

    /**
     * Flytter et sporsmal eller et svar én plass opp eller ned.
     *
     * Bytter sortering med naboen framfor aa skrive nye tall paa hele lista:
     * da kan to rader som ble flyttet samtidig ikke ende paa samme plass.
     */
    public static function flytt(string $tabell, int $id, int $retning, ?int $innenfor = null): bool
    {
        $tabell = $tabell === 'svar' ? 'veileder_svar' : 'veileder_sporsmal';
        $rad = DB::en("SELECT * FROM {$tabell} WHERE id = :i", ['i' => $id]);
        if ($rad === null) {
            return false;
        }

        $avgrens = $tabell === 'veileder_svar' && $innenfor !== null
            ? ' AND sporsmal_id = ' . (int) $innenfor : '';
        $opp = $retning < 0;
        $nabo = DB::en(
            "SELECT * FROM {$tabell}
              WHERE (sortering, id) " . ($opp ? '<' : '>') . " (:s, :i){$avgrens}
           ORDER BY sortering " . ($opp ? 'DESC' : 'ASC') . ", id " . ($opp ? 'DESC' : 'ASC') . "
              LIMIT 1",
            ['s' => (int) $rad['sortering'], 'i' => $id]
        );
        if ($nabo === null) {
            return false;   // alt oeverst eller nederst
        }

        // Er de like, ville et bytte ikke flyttet noe. Da skyver vi naboen.
        $mitt = (int) $rad['sortering'];
        $hans = (int) $nabo['sortering'];
        if ($mitt === $hans) {
            $hans = $opp ? $hans - 1 : $hans + 1;
        }
        DB::oppdater($tabell, ['sortering' => $hans], ['id' => $id]);
        DB::oppdater($tabell, ['sortering' => $mitt], ['id' => (int) $nabo['id']]);
        return true;
    }
}
