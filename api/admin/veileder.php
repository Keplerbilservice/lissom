<?php
/**
 * Kursveilederen — spoersmaalene, svarene og rekkefolgen.
 *
 *   GET                        alt, ogsaa det som er slaatt av
 *   POST handling=sporsmal     nytt eller endret spoersmaal
 *   POST handling=svar         nytt eller endret svar
 *   POST handling=flytt        flytt ett hakk opp eller ned
 *   POST handling=slett        fjern et spoersmaal eller et svar
 *
 * Redigeringen fantes fra for og saa ut til aa virke: feltene tok imot tekst,
 * «Legg til svar» la til en rad, og krysset slettet. Alt laa i nettleserens
 * minne og var borte ved neste oppfriskning. Dette er det som mangler.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (!Veileder::klar()) {
    Svar::feil('Migrasjon 066 er ikke kjørt. Kjør oppdateringen først.');
}

if (Foresporsel::metode() === 'GET') {
    Svar::json([
        'sporsmal' => Veileder::sporsmal(false),
        'typer'    => [
            ['verdi' => 'envalg',   'navn' => 'Ett svar'],
            ['verdi' => 'flervalg', 'navn' => 'Flere svar'],
            ['verdi' => 'janei',    'navn' => 'Ja eller nei'],
            ['verdi' => 'tall',     'navn' => 'Et tall'],
            ['verdi' => 'fritekst', 'navn' => 'Fritekst'],
            ['verdi' => 'avhuking', 'navn' => 'Avhuking'],
        ],
        // Ordlista er den samme som merkene paa kurset (migrasjon 065).
        // Skal anbefalingen sammenligne de to, maa de si det samme.
        'merker' => [
            'nivaa'    => [['nybegynner', 'Nybegynnere'], ['litt', 'Litt erfaring'], ['erfaren', 'Erfarne']],
            'hvem'     => [['alene', 'Deg alene'], ['par', 'To sammen'], ['venner', 'Venner'],
                           ['familie', 'Familie'], ['firma', 'Bedrift'], ['barn', 'Barn med voksen']],
            'metode'   => [['dreiing', 'Dreiing'], ['handbygging', 'Håndbygging'],
                           ['maling', 'Maling'], ['begge', 'Dreiing og håndbygging']],
            'varighet' => [['kort', 'Under to timer'], ['medium', 'To til fire timer'], ['lang', 'Over fire timer']],
        ],
        // Kursene svarene kan peke paa, med navn slik de staar.
        'kurs' => array_map(
            static fn($k) => (string) $k['tittel'],
            DB::alle("SELECT tittel FROM courses WHERE status <> 'avlyst' ORDER BY tittel")
        ),
        'sider' => [
            ['verdi' => 'SIDE:paintonpots', 'navn' => 'Paint on Pots-siden'],
            ['verdi' => 'SIDE:bedrift',     'navn' => 'Privat event-siden'],
        ],
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$kropp = Foresporsel::kropp();

/** Kommaseparert merkeliste, renset for det som ikke finnes i ordlista. */
$merkeliste = static function (string $raa, array $lovlige): ?string {
    $ut = [];
    foreach (explode(',', $raa) as $x) {
        $x = trim($x);
        if ($x !== '' && in_array($x, $lovlige, true) && !in_array($x, $ut, true)) {
            $ut[] = $x;
        }
    }
    return $ut === [] ? null : implode(',', $ut);
};

switch (Foresporsel::tekst('handling')) {

    case 'sporsmal':
        $id    = (int) ($kropp['id'] ?? 0);
        $tekst = trim(mb_substr((string) ($kropp['tekst'] ?? ''), 0, 255));
        if ($tekst === '') {
            Svar::feil('Spørsmålet må ha en tekst.');
        }
        $type = (string) ($kropp['type'] ?? 'envalg');
        if (!in_array($type, Veileder::TYPER, true)) {
            Svar::feil('Ukjent spørsmålstype.');
        }

        $felter = [
            'tekst'       => $tekst,
            'hjelpetekst' => mb_substr(trim((string) ($kropp['hjelpetekst'] ?? '')), 0, 255) ?: null,
            'type'        => $type,
            'aktiv'       => !empty($kropp['aktiv']) ? 1 : 0,
        ];

        // Betingelsen. Et spoersmaal kan ikke henge paa seg selv — da ville
        // det aldri blitt vist, og ingen ville skjont hvorfor.
        if (array_key_exists('visNarId', $kropp)) {
            $narId = (int) $kropp['visNarId'];
            if ($narId === $id && $narId !== 0) {
                Svar::feil('Et spørsmål kan ikke henge på sitt eget svar.');
            }
            if ($narId > 0 && DB::en('SELECT id FROM veileder_sporsmal WHERE id = :i', ['i' => $narId]) === null) {
                Svar::feil('Fant ikke spørsmålet betingelsen peker på.');
            }
            $felter['vis_nar_id']    = $narId > 0 ? $narId : null;
            $felter['vis_nar_verdi'] = $narId > 0
                ? (mb_substr(trim((string) ($kropp['visNarVerdi'] ?? '')), 0, 80) ?: null) : null;
        }

        if ($type === 'tall') {
            $min = (int) ($kropp['minVerdi'] ?? 1);
            $maks = (int) ($kropp['maksVerdi'] ?? 12);
            if ($maks < $min) {
                Svar::feil('Det høyeste tallet kan ikke være mindre enn det laveste.');
            }
            $felter['min_verdi']  = max(0, min(999, $min));
            $felter['maks_verdi'] = max(0, min(999, $maks));
            $felter['maks_tekst'] = mb_substr(trim((string) ($kropp['maksTekst'] ?? '')), 0, 60) ?: null;
        }

        if ($id > 0) {
            if (DB::en('SELECT id FROM veileder_sporsmal WHERE id = :i', ['i' => $id]) === null) {
                Svar::feil('Fant ikke spørsmålet.');
            }
            DB::oppdater('veileder_sporsmal', $felter, ['id' => $id]);
        } else {
            // Nytt spoersmaal legges nederst.
            $felter['sortering'] = (int) DB::verdi('SELECT COALESCE(MAX(sortering), 0) + 10 FROM veileder_sporsmal');
            $id = DB::settInn('veileder_sporsmal', $felter);
        }
        revider('veileder_sporsmal', null, $id, ['tekst' => $tekst]);
        Svar::ok(['beskjed' => 'Spørsmålet er lagret.', 'id' => $id]);

    case 'svar':
        $id      = (int) ($kropp['id'] ?? 0);
        $forId   = (int) ($kropp['sporsmalId'] ?? 0);
        $tekst   = trim(mb_substr((string) ($kropp['tekst'] ?? ''), 0, 255));
        if ($tekst === '') {
            Svar::feil('Svaret må ha en tekst.');
        }

        $lovlige = [
            'passer_nivaa' => ['nybegynner', 'litt', 'erfaren'],
            'passer_hvem'  => ['alene', 'par', 'venner', 'familie', 'firma', 'barn'],
            'metode'       => ['dreiing', 'handbygging', 'maling', 'begge'],
            'varighet'     => ['kort', 'medium', 'lang'],
        ];
        $felter = [
            'tekst'        => $tekst,
            'aktiv'        => !empty($kropp['aktiv']) ? 1 : 0,
            'passer_nivaa' => $merkeliste((string) ($kropp['passerNivaa'] ?? ''), $lovlige['passer_nivaa']),
            'passer_hvem'  => $merkeliste((string) ($kropp['passerHvem'] ?? ''), $lovlige['passer_hvem']),
            'metode'       => $merkeliste((string) ($kropp['metode'] ?? ''), $lovlige['metode']),
            'varighet'     => $merkeliste((string) ($kropp['varighet'] ?? ''), $lovlige['varighet']),
            'mal'          => mb_substr(trim((string) ($kropp['mal'] ?? '')), 0, 80) ?: null,
            'begrunnelse'  => mb_substr(trim((string) ($kropp['begrunnelse'] ?? '')), 0, 255) ?: null,
        ];

        if ($id > 0) {
            if (DB::en('SELECT id FROM veileder_svar WHERE id = :i', ['i' => $id]) === null) {
                Svar::feil('Fant ikke svaret.');
            }
            DB::oppdater('veileder_svar', $felter, ['id' => $id]);
        } else {
            if (DB::en('SELECT id FROM veileder_sporsmal WHERE id = :i', ['i' => $forId]) === null) {
                Svar::feil('Fant ikke spørsmålet svaret skal ligge under.');
            }
            $felter['sporsmal_id'] = $forId;
            $felter['sortering'] = (int) DB::verdi(
                'SELECT COALESCE(MAX(sortering), 0) + 10 FROM veileder_svar WHERE sporsmal_id = :s',
                ['s' => $forId]
            );
            $id = DB::settInn('veileder_svar', $felter);
        }
        revider('veileder_svar', null, $id, ['tekst' => $tekst]);
        Svar::ok(['beskjed' => 'Svaret er lagret.', 'id' => $id]);

    case 'flytt':
        $hva     = (string) ($kropp['hva'] ?? 'sporsmal');
        $id      = (int) ($kropp['id'] ?? 0);
        $retning = ((int) ($kropp['retning'] ?? 0)) < 0 ? -1 : 1;
        $innenfor = isset($kropp['sporsmalId']) ? (int) $kropp['sporsmalId'] : null;
        $ok = Veileder::flytt($hva === 'svar' ? 'svar' : 'sporsmal', $id, $retning, $innenfor);
        Svar::ok(['beskjed' => $ok ? 'Flyttet.' : 'Den ligger allerede ytterst.', 'flyttet' => $ok]);

    case 'slett':
        $hva = (string) ($kropp['hva'] ?? 'sporsmal');
        $id  = (int) ($kropp['id'] ?? 0);
        if ($hva === 'svar') {
            DB::kjor('DELETE FROM veileder_svar WHERE id = :i', ['i' => $id]);
            revider('veileder_svar_slettet', null, $id);
            Svar::ok(['beskjed' => 'Svaret er slettet.']);
        }
        // Et spoersmaal andre spoersmaal henger paa: betingelsen deres ville
        // pekt paa noe som ikke finnes, og de ville aldri blitt vist igjen.
        // De loesnes framfor aa forsvinne i stillhet.
        $lost = DB::kjor(
            'UPDATE veileder_sporsmal SET vis_nar_id = NULL, vis_nar_verdi = NULL WHERE vis_nar_id = :i',
            ['i' => $id]
        )->rowCount();
        DB::kjor('DELETE FROM veileder_sporsmal WHERE id = :i', ['i' => $id]);
        revider('veileder_sporsmal_slettet', null, $id, ['lost' => $lost]);
        Svar::ok(['beskjed' => $lost > 0
            ? 'Spørsmålet er slettet. ' . $lost . ($lost === 1 ? ' spørsmål' : ' spørsmål')
              . ' som hang på det, vises nå til alle.'
            : 'Spørsmålet er slettet.']);

    default:
        Svar::feil('Ukjent handling.');
}
