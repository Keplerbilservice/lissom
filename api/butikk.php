<?php
/**
 * Varene i butikken.
 *
 * Aapent endepunkt for det som er til salgs for alle. Internbutikkens varer
 * — leire, ekstra brenning — sendes kun til innloggede medlemmer, ellers
 * ville de dukket opp i den offentlige butikken.
 */

declare(strict_types=1);

require __DIR__ . '/_boot.php';

Foresporsel::krevMetode('GET');

$medlem = Sesjon::medlem();
// Nettbutikken og Internt (migrasjon 252, eieren 04.10.2026): en vare kan
// vaere begge. Gjester faar nettbutikkvarene; innloggede ogsaa internvarene.
// En utsolgt vare vises ikke i nettbutikken, og kommer tilbake naar den er
// paa lager igjen (eieren, 27. september 2026). Internvarene staar.
$iNett = Lager::iNettbutikkSql();
$hvor = $medlem === null
    ? "{$iNett} AND (lager IS NULL OR lager > 0)"
    : "(({$iNett} AND (lager IS NULL OR lager > 0)) OR kun_medlemmer = 1)";
// Leire er inkludert i Prøv Lissom (eieren, 29. september 2026): den som
// har den, faar ikke leirevarene i medlemsbutikken. Se Lager::skjulLeire().
if (Lager::skjulLeire($medlem)) {
    $hvor .= ' AND leire = 0';
}

// Frakten. Sto som «kr. 89,-» fire steder i nettleseren og kom aldri hit;
// naa staar tallet i basen, og kassa henter det derfra. Da kan ikke skjermen
// og betalingen si hver sin ting.
$fraktOre = (int) (DB::harTabell('innstillinger')
    ? (DB::verdi('SELECT verdi FROM innstillinger WHERE nokkel = :n', ['n' => 'frakt_ore']) ?? 0)
    : 0);

// Betales ved henting? Én bryter for hele butikken (migrasjon 198). Lest
// her, én gang, saa alle varene i svaret sier det samme.
$oppmoteButikk = Oppmote::butikk();

$varer = DB::alle(
    "SELECT *
       FROM products
      WHERE status = 'publisert' AND {$hvor}
      ORDER BY kun_medlemmer, kategori, tittel"
);

Svar::json(['varer' => array_map(static fn($v) => [
    'id'           => (int) $v['id'],
    // Varens egen adresse. Regnes her, ett sted, saa nettsida og serveren
    // ikke kan lage hver sin — det er serveren som svarer paa den.
    // Bare internvarer faar ingen: de skal ikke ha en side noen kan lenke til.
    'sti'          => !Lager::iNettbutikk($v)
                        ? '' : Lenker::vare((int) $v['id'], (string) $v['tittel']),
    'tittel'       => $v['tittel'],
    'detalj'       => $v['beskrivelse'],
    'bilde'        => $v['bilde'],
    'kategori'     => $v['kategori'],
    'pris'         => Booking::kroner((int) $v['pris_ore']),
    // Kan varen bestilles og betales ved henting? Én bryter for hele
    // butikken — ⊙ Synlighet → Betal ved oppmøte → Butikken (migrasjon 198).
    'utenForskudd' => $oppmoteButikk,
    'prisOre'      => (int) $v['pris_ore'],
    'utsolgt'      => $v['lager'] !== null && (int) $v['lager'] <= 0,
    // «kunMedlemmer» = bare internt (ikke i nettbutikken) — nettsida skiller
    // nettbutikk og internbutikk paa den. «internt» = i internbutikken, ogsaa
    // naar varen i tillegg er i nettbutikken (migrasjon 252).
    'kunMedlemmer' => !Lager::iNettbutikk($v),
    'internt'      => (bool) $v['kun_medlemmer'],
], $varer),
    'fokus'    => Bilder::fokus(),
    'fraktOre' => $fraktOre,
    'frakt'    => Booking::kroner($fraktOre),
]);
