#!/usr/bin/env node
/**
 * Kurset finnes i to utgaver, og de skal se like ut.
 *
 *   app/nett/sider/kursside.php   tegnes av serveren. Kommer opp med det
 *                                 samme, og er den Google leser.
 *   lissom-2108.html              appens bookingskjerm. Der bookes det.
 *
 * Begge er skrevet for haand, og derfor sklir de fra hverandre. Eieren,
 * 19. september 2026, med bilde fra to telefoner: «paa min telefon ser det
 * slik ut, veldig bra. Mens paa andre telefoner staar det feil» — og
 * etterpaa: «du maa soerge for at det aldri er to utgaver, det er jo helt
 * feil».
 *
 * Utgavene kan ikke slaas sammen uten aa gi opp enten Google eller
 * bookingen. Men de kan holdes i takt, og det er det denne gjor: den ser
 * etter de grepene som MAA finnes begge steder. Mangler ett av dem, er de
 * to sidene ulike igjen, og da sier den fra foer noen publiserer.
 */
import { readFileSync } from 'node:fs';

const php = readFileSync(new URL('../app/nett/sider/kursside.php', import.meta.url), 'utf8');
const app = readFileSync(new URL('../lissom-2108.html', import.meta.url), 'utf8');
const css = readFileSync(new URL('../nett.css', import.meta.url), 'utf8');

// Hvert krav: hva det heter, og hva som maa staa i hver av de tre filene.
const krav = [
  ['rutenettet er merket',         'lx-split lx-kurs',        'lx-split lx-kurs'],
  ['toppen er skilt ut',           'class="lx-kurs-topp"',    'class="lx-kurs-topp"'],
  ['resten er skilt ut',           'class="lx-kurs-resten"',  'class="lx-kurs-resten"'],
  ['boksen er merket',             'class="lx-kurs-boks"',    'class="lx-kurs-boks"'],
  ['merkelappene over tittelen',   'Maks \' . $plasserN',     '{{ bFliser }}'],
  ['faktaene staar som linjer',    'flex: 0 0 96px;',         'flex: 0 0 96px;'],
  ['det lange bak «Les mer»',      '<details',               '<details'],
];

let feil = 0;
for (const [navn, iPhp, iApp] of krav) {
  const a = php.includes(iPhp), b = app.includes(iApp);
  if (a && b) { console.log('  OK    ' + navn); continue; }
  feil++;
  console.log('  FEIL  ' + navn + ' — mangler i '
    + [!a && 'kursside.php', !b && 'lissom-2108.html'].filter(Boolean).join(' og '));
}

// Rekkefoelgen paa mobil maa staa i BEGGE stilarkene, ellers havner boksen
// nederst i den ene utgaven — som var nettopp det som skjedde.
for (const [navn, tekst] of [['nett.css', css], ['lissom-2108.html', app]]) {
  const har = ['.lx-kurs > .lx-kurs-topp   { order: 1; }',
               '.lx-kurs > .lx-kurs-boks   { order: 2; position: static !important; }',
               '.lx-kurs > .lx-kurs-resten { order: 3; }'].every(r => tekst.includes(r));
  if (har) { console.log('  OK    rekkefoelgen paa mobil staar i ' + navn); continue; }
  feil++;
  console.log('  FEIL  rekkefoelgen paa mobil mangler i ' + navn);
}

console.log('');
if (feil) {
  console.log(feil + ' avvik: de to utgavene av kurset er ikke like.');
  process.exit(1);
}
console.log('De to utgavene av kurset er i takt.');
