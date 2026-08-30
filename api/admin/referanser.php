<?php
/**
 * Referansekundene — opprett, endre, flytt og slaa av.
 *
 *   GET                     alle, ogsaa de som ikke staar ute
 *   POST handling=lagre     ny eller endret kunde
 *   POST handling=flytt     { id, retning: opp|ned }
 *   POST handling=slett     { id }
 *
 * Samtykket er et eget felt, og et kort kan ikke staa ute uten. Det er
 * verkstedet som maa ha lov til aa vise navn, logo, bilde og sitat — det kan
 * ikke koden avgjore, men den kan la vaere aa publisere for noen har sagt at
 * lov finnes.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (!DB::harTabell('referansekunder')) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
}

/** Alle kundene, slik admin viser dem. */
function kundene(): array
{
    return array_map(static fn($r) => [
        'id'        => (int) $r['id'],
        'navn'      => (string) $r['navn'],
        'bilde'     => (string) ($r['bilde'] ?? ''),
        // Logoen til kunden. Bildet sier hva som ble laget, logoen hvem det
        // ble laget for. Kom med migrasjon 067.
        'logo'      => (string) ($r['logo'] ?? ''),
        'tekst'     => (string) ($r['tekst'] ?? ''),
        'sitat'     => (string) ($r['sitat'] ?? ''),
        'sitatAv'   => (string) ($r['sitat_av'] ?? ''),
        'lenke'     => (string) ($r['lenke'] ?? ''),
        'sortering' => (int) $r['sortering'],
        'aktiv'     => (int) $r['aktiv'] === 1,
        'samtykke'  => (int) $r['samtykke'] === 1,
        // Staar den faktisk ute? Begge maa vaere paa.
        'ute'       => (int) $r['aktiv'] === 1 && (int) $r['samtykke'] === 1,
    ], DB::alle('SELECT * FROM referansekunder ORDER BY sortering, navn'));
}

if (Foresporsel::metode() === 'GET') {
    Svar::json(['kunder' => kundene()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$handling = Foresporsel::tekst('handling', 'lagre');

// ── Ny eller endret ────────────────────────────────────────────────────────
if ($handling === 'lagre') {
    $id   = Foresporsel::heltall('id');
    $navn = mb_substr(trim(Foresporsel::tekst('navn')), 0, 191);
    if ($navn === '') {
        Svar::feil('Kunden må ha et navn.');
    }

    $lenke = trim(Foresporsel::tekst('lenke'));
    // Bare http og https. En javascript:-adresse i et felt som blir til en
    // lenke paa forsida er et hull, ikke en lenke.
    if ($lenke !== '' && preg_match('#^https?://#i', $lenke) !== 1) {
        Svar::feil('Lenken må begynne med http:// eller https://');
    }

    $data = [
        'navn'     => $navn,
        'tekst'    => trim(mb_substr(Foresporsel::tekst('tekst'), 0, 2000)) ?: null,
        'sitat'    => trim(mb_substr(Foresporsel::tekst('sitat'), 0, 500)) ?: null,
        'sitat_av' => trim(mb_substr(Foresporsel::tekst('sitatAv'), 0, 191)) ?: null,
        'lenke'    => $lenke ?: null,
        'aktiv'    => Foresporsel::tekst('aktiv') === 'ja' ? 1 : 0,
        'samtykke' => Foresporsel::tekst('samtykke') === 'ja' ? 1 : 0,
    ];

    // Bare naar feltet er med: et skjema som ikke kjenner bildet skal ikke
    // toemme det. basename() klipper bort alt som ligner en sti.
    //
    // Velgeren gir enten et filnavn fra repoet eller «api/bilde.php?artikkel=»
    // for noe eieren har lastet opp selv. Begge skal bestaa: basename() paa
    // den siste ville klippet bort spoersmaalstegnet og latt bildet peke
    // ingen steder.
    $rentBilde = static function ($raa): ?string {
        $b = trim((string) $raa);
        if ($b === '') {
            return null;
        }
        if (preg_match('~^api/bilde\.php\?artikkel=[A-Za-z0-9._-]{1,120}$~', $b)) {
            return $b;
        }
        $fil = basename($b);
        return $fil !== '' ? mb_substr($fil, 0, 255) : null;
    };

    if (array_key_exists('bilde', Foresporsel::kropp())) {
        $data['bilde'] = $rentBilde(Foresporsel::kropp()['bilde'] ?? '');
    }
    if (array_key_exists('logo', Foresporsel::kropp()) && DB::harKolonne('referansekunder', 'logo')) {
        $data['logo'] = $rentBilde(Foresporsel::kropp()['logo'] ?? '');
    }

    if ($id > 0) {
        if (DB::en('SELECT id FROM referansekunder WHERE id = :i', ['i' => $id]) === null) {
            Svar::feil('Fant ikke kunden.', 404);
        }
        DB::oppdater('referansekunder', $data, ['id' => $id]);
        revider('referanse_endret', 'referanse', $id, ['navn' => $navn]);
    } else {
        // Nye legger seg bakerst.
        $data['sortering'] = ((int) DB::verdi('SELECT COALESCE(MAX(sortering), 0) FROM referansekunder')) + 1;
        $id = DB::settInn('referansekunder', $data);
        revider('referanse_opprettet', 'referanse', $id, ['navn' => $navn]);
    }

    Svar::ok([
        'id'      => $id,
        'kunder'  => kundene(),
        'beskjed' => $data['aktiv'] && !$data['samtykke']
            ? 'Lagret, men kortet vises ikke ute før du har krysset av for at kunden har sagt ja.'
            : ($data['aktiv'] ? 'Lagret. Kortet står på forsiden.' : 'Lagret som skjult.'),
    ]);
}

// ── Rekkefolge ─────────────────────────────────────────────────────────────
//
// Bytter plass med naboen. Enklere enn aa dra, og det er en liste paa en
// haandfull rader.
if ($handling === 'flytt') {
    $id = Foresporsel::heltall('id');
    $opp = Foresporsel::tekst('retning') !== 'ned';

    $meg = DB::en('SELECT id, sortering FROM referansekunder WHERE id = :i', ['i' => $id]);
    if ($meg === null) {
        Svar::feil('Fant ikke kunden.', 404);
    }
    $nabo = DB::en(
        $opp
            ? 'SELECT id, sortering FROM referansekunder WHERE sortering < :s ORDER BY sortering DESC LIMIT 1'
            : 'SELECT id, sortering FROM referansekunder WHERE sortering > :s ORDER BY sortering LIMIT 1',
        ['s' => (int) $meg['sortering']]
    );
    if ($nabo === null) {
        Svar::ok(['kunder' => kundene(), 'beskjed' => $opp ? 'Står allerede øverst.' : 'Står allerede nederst.']);
    }

    DB::oppdater('referansekunder', ['sortering' => (int) $nabo['sortering']], ['id' => (int) $meg['id']]);
    DB::oppdater('referansekunder', ['sortering' => (int) $meg['sortering']], ['id' => (int) $nabo['id']]);
    revider('referanse_flyttet', 'referanse', $id, ['opp' => $opp]);
    Svar::ok(['kunder' => kundene(), 'beskjed' => 'Rekkefølgen er endret.']);
}

// ── Vis eller skjul ────────────────────────────────────────────────────────
//
// Eieren, 30. august: «kan jeg faa mulighet aa vise eller ikke vise
// referansekundene, saa slipper jeg aa slette de». Bryteren fantes fra for,
// men bare inne i redigeringsskjemaet — skulle et kort bort en uke, maatte
// man aapne det, finne haken, lagre, og gjenta naar det skulle tilbake. Eller
// slette det, og skrive alt paa nytt senere.
//
// Samtykket roeres ikke. Det er en avtale med kunden, ikke en synlighet, og
// et kort uten samtykke skal ikke kunne slaas paa ved et uhell herfra.
if ($handling === 'veksle') {
    $id = Foresporsel::heltall('id');
    $k = DB::en('SELECT navn, samtykke, bilde FROM referansekunder WHERE id = :i', ['i' => $id]);
    if ($k === null) {
        Svar::feil('Fant ikke kunden.', 404);
    }
    $paa = Foresporsel::tekst('paa') === 'ja';
    DB::oppdater('referansekunder', ['aktiv' => $paa ? 1 : 0], ['id' => $id]);
    revider('referanse_' . ($paa ? 'vist' : 'skjult'), 'referanse', $id, ['navn' => $k['navn']]);

    // Slaas den paa uten at de to andre kravene er der, staar den fortsatt
    // ikke ute. Da skal svaret si det, framfor aa melde «paa forsiden» om
    // noe ingen ser.
    $mangler = [];
    if ((int) $k['samtykke'] !== 1) {
        $mangler[] = 'kunden har sagt ja';
    }
    if (trim((string) ($k['bilde'] ?? '')) === '') {
        $mangler[] = 'et bilde';
    }

    Svar::ok([
        'kunder'  => kundene(),
        'beskjed' => $paa
            ? ($mangler === []
                ? '«' . $k['navn'] . '» står på forsiden.'
                : '«' . $k['navn'] . '» er slått på, men vises ikke før '
                  . implode(' og ', $mangler) . ' er på plass.')
            : '«' . $k['navn'] . '» er skjult. Den ligger her til du vil ha den fram igjen.',
    ]);
}

// ── Slett ──────────────────────────────────────────────────────────────────
if ($handling === 'slett') {
    $id = Foresporsel::heltall('id');
    $k = DB::en('SELECT navn FROM referansekunder WHERE id = :i', ['i' => $id]);
    if ($k === null) {
        Svar::feil('Fant ikke kunden.', 404);
    }
    DB::kjor('DELETE FROM referansekunder WHERE id = :i', ['i' => $id]);
    revider('referanse_slettet', 'referanse', $id, ['navn' => $k['navn']]);
    Svar::ok(['kunder' => kundene(), 'beskjed' => '«' . $k['navn'] . '» er slettet.']);
}

Svar::feil('Ukjent handling.');
