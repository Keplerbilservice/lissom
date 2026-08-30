<?php
/**
 * Ressursene i verkstedet — opprett, endre, slaa av og slett.
 *
 *   GET                     alle, med hvor mange kurs som bruker dem
 *   POST handling=lagre     { id?, navn, antall, merknad }
 *   POST handling=veksle    { id }   paa eller av
 *   POST handling=slett     { id }
 *
 * Eieren, 30. august: «1 dreieskive = 1 ressurs = 1 plass, 1 kursplass = 1
 * ressurs = 1 plass», og «maa kunne endre, slette og legge til for aa mote
 * endringer i verkstedet».
 *
 * Alt som skjer samtidig og bruker den samme ressursen deler taket — kurs,
 * medlemsbooking og drop-in. Regnestykket staar i Booking::ledigePlasserFlere.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (!DB::harTabell('ressurser')) {
    Svar::feil('Dette krever en oppdatering av databasen. Kjør vedlikeholdet fra menyen nederst til venstre.');
}

/**
 * Ressursene, med hvor mange kurs som peker paa hver.
 *
 * Tallet er det som avgjor om en ressurs kan slettes, og det er det samme
 * tallet skjermen viser. Regnet ett sted, saa de to ikke kan sprike.
 */
function ressursene(): array
{
    return array_map(static fn($r) => [
        'id'      => (int) $r['id'],
        'navn'    => (string) $r['navn'],
        'antall'  => (int) $r['antall'],
        'merknad' => (string) ($r['merknad'] ?? ''),
        'aktiv'   => (bool) $r['aktiv'],
        'kurs'    => (int) $r['kurs'],
    ], DB::alle(
        'SELECT r.*, (SELECT COUNT(*) FROM courses c WHERE c.ressurs_id = r.id) AS kurs
           FROM ressurser r
          ORDER BY r.aktiv DESC, r.navn'
    ));
}

if (Foresporsel::metode() === 'GET') {
    Svar::json(['ressurser' => ressursene()]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

switch (Foresporsel::tekst('handling')) {

    // ------------------------------------------------------------ lagre
    case 'lagre':
        $id     = Foresporsel::heltall('id');
        $navn   = trim(mb_substr(Foresporsel::tekst('navn'), 0, 64));
        $antall = Foresporsel::heltall('antall');
        $merk   = trim(mb_substr(Foresporsel::tekst('merknad'), 0, 255));

        if ($navn === '') {
            Svar::feil('Ressursen må ha et navn — for eksempel «Dreieskive».');
        }
        // Null plasser er ikke en ressurs, det er en stengt dor. Skal den
        // ikke telle, slaas den av i stedet — da staar koblingene igjen.
        if ($antall < 1 || $antall > 999) {
            Svar::feil('Antallet må være mellom 1 og 999.');
        }

        // Navnet er unikt. Uten denne sjekken kom en databasefeil i fjeset
        // paa den som skrev inn det samme navnet to ganger.
        $finnes = DB::en('SELECT id FROM ressurser WHERE navn = :n AND id <> :i',
                         ['n' => $navn, 'i' => $id]);
        if ($finnes !== null) {
            Svar::feil('Det finnes alt en ressurs som heter «' . $navn . '».');
        }

        if ($id > 0) {
            if (DB::en('SELECT id FROM ressurser WHERE id = :i', ['i' => $id]) === null) {
                Svar::feil('Fant ikke ressursen.');
            }
            DB::oppdater('ressurser',
                ['navn' => $navn, 'antall' => $antall, 'merknad' => $merk ?: null],
                ['id' => $id]);
            revider('ressurs_endret', 'ressurs', $id, ['navn' => $navn, 'antall' => $antall]);
        } else {
            $id = DB::settInn('ressurser',
                ['navn' => $navn, 'antall' => $antall, 'merknad' => $merk ?: null, 'aktiv' => 1]);
            revider('ressurs_opprettet', 'ressurs', $id, ['navn' => $navn, 'antall' => $antall]);
        }

        Booking::glemTak();
        Svar::ok([
            'ressurser' => ressursene(),
            'beskjed'   => $navn . ' står med ' . $antall . ' plasser.',
        ]);

    // ----------------------------------------------------------- veksle
    //
    // Av betyr «teller ikke». Koblingene blir staaende, saa et kurs som
    // pekte hit gaar tilbake til sitt eget plasstall — og faar taket igjen
    // den dagen ressursen slaas paa.
    case 'veksle':
        $id = Foresporsel::heltall('id');
        $r  = DB::en('SELECT id, navn, aktiv FROM ressurser WHERE id = :i', ['i' => $id]);
        if ($r === null) {
            Svar::feil('Fant ikke ressursen.');
        }
        $ny = (int) $r['aktiv'] === 1 ? 0 : 1;
        DB::oppdater('ressurser', ['aktiv' => $ny], ['id' => $id]);
        Booking::glemTak();
        revider('ressurs_vekslet', 'ressurs', $id, ['aktiv' => $ny]);
        Svar::ok([
            'ressurser' => ressursene(),
            'beskjed'   => $ny
                ? $r['navn'] . ' teller igjen.'
                : $r['navn'] . ' teller ikke lenger. Kursene går tilbake til sitt eget plasstall.',
        ]);

    // ------------------------------------------------------------ slett
    //
    // En ressurs kurs peker paa slettes ikke. Eieren, spurt om hva som skal
    // skje: «nekt, og si hvilke kurs». Ellers ville taket forsvunnet stille,
    // og verkstedet kunne solgt seksten plasser paa aatte skiver uten at noe
    // sa fra.
    case 'slett':
        $id = Foresporsel::heltall('id');
        $r  = DB::en('SELECT id, navn FROM ressurser WHERE id = :i', ['i' => $id]);
        if ($r === null) {
            Svar::feil('Fant ikke ressursen.');
        }
        $bruker = array_column(
            DB::alle('SELECT tittel FROM courses WHERE ressurs_id = :i ORDER BY tittel', ['i' => $id]),
            'tittel'
        );
        if ($bruker !== []) {
            $vis = array_slice($bruker, 0, 5);
            Svar::feil(
                $r['navn'] . ' brukes av ' . count($bruker) . ' kurs: '
                . implode(', ', $vis)
                . (count($bruker) > 5 ? ' og ' . (count($bruker) - 5) . ' til' : '')
                . '. Flytt dem til en annen ressurs først, i kursoppsettet under Plasser.'
            );
        }
        DB::kjor('DELETE FROM ressurser WHERE id = :i', ['i' => $id]);
        Booking::glemTak();
        revider('ressurs_slettet', 'ressurs', $id, ['navn' => $r['navn']]);
        Svar::ok(['ressurser' => ressursene(), 'beskjed' => $r['navn'] . ' er slettet.']);

    default:
        Svar::feil('Ukjent handling.');
}
