/**
 * Rammen rundt det nye admin: menyen (PC oppe, mobil nede), søk, «+»,
 * Stemple inn og hvilken modul som vises. Se admin2.html.
 *
 * Hver modul er sin egen fil og har sin egen bryter (Vis/admin2_<modul>
 * under ⊙ Synlighet). En modul som er slått av, vises ikke i menyen.
 * Adressen er /admin2#i-dag, #kurs osv., så «tilbake» i nettleseren virker.
 */
import { el, hent, send, bekreft, ark, melding, feilboks } from './kjerne.js';
import idag from './idag.js';
import kurs from './kurs.js';
import folk from './folk.js';
import penger from './penger.js';
import mer from './mer.js';

const MODULER = [idag, kurs, folk, penger, mer];
const app = document.getElementById('app');
let oppsett = null;

/** Steder søket kan finne. Det som ikke er flyttet ennå, går til det gamle admin. */
const STEDER = [
  ['I dag', '#i-dag'], ['Kurs og datoer', '/admin/kurs'], ['Kalender', '/admin/kalender'],
  ['Påmeldte', '/admin/pameldte'], ['Venteliste', '/admin/venteliste'], ['Klar til henting', '/admin/ferdigbrent'],
  ['Årskalender', '/admin/arskalender'], ['Kursholdere', '/admin/kursholdere'],
  ['Medlemmer', '/admin/medlemmer/alle'], ['Kursdeltakere', '/admin/deltakere/alle'], ['Medlemskap', '/admin/medlemskap'],
  ['Forespørsler', '/admin/ubesvarte'], ['Til godkjenning', '/admin/godkjenning'], ['Beskjeder', '/admin/beskjeder'],
  ['Kasse · ta betalt', '/admin/uttak'], ['Økonomi og dagsoppgjør', '/admin/okonomi'], ['Nettbutikk', '/admin/butikk'],
  ['Markedsføring', '/admin/markedsforing'], ['Innboks (SoMe)', '/admin/markedsforing'], ['SEO', '/admin/seo'],
  ['Maler', '/admin/maler'], ['Varsler', '/admin/varsler'], ['Feilmeldinger', '/admin/feilmeldinger'],
  ['Skisser', '/skisser.html'], ['Det gamle admin', '/admin'],
];

function hvilken() {
  const h = (location.hash || '').replace(/^#/, '');
  const aktive = MODULER.filter(m => !oppsett || oppsett.moduler[m.id] !== false);
  return aktive.find(m => m.adresse === h) || aktive[0];
}

function sok() {
  const felt = el('input', { type: 'text', placeholder: 'Søk i admin', 'aria-label': 'Søk i admin', autocomplete: 'off' });
  const liste = el('div', { class: 'g' });
  const tegn = () => {
    const q = felt.value.trim().toLowerCase();
    liste.replaceChildren(...STEDER.filter(([n]) => !q || n.toLowerCase().includes(q)).slice(0, 12)
      .map(([n, a]) => el('a', { class: 'rad', href: a, onclick: () => lukk() }, el('span', { tekst: n }))));
  };
  felt.addEventListener('input', tegn);
  const { lukk } = ark([el('h2', { tekst: 'Søk i admin' }), felt, liste]);
  tegn();
  setTimeout(() => felt.focus(), 0);
}

function lagNytt() {
  const valg = [
    ['Ny kursdato', '/admin/kalender'], ['Nytt kurs', '/admin/kurs'], ['Nytt medlem', '/admin/ny-registrering'],
    ['Melding til medlemmene', '/admin/beskjeder'], ['Legg ut på SoMe', '/admin/markedsforing'], ['Ny tavle i Skisser', '/skisser.html'],
  ];
  ark([
    el('h2', { tekst: 'Lag noe nytt' }),
    el('div', { class: 'g' }, valg.map(([n, a]) => el('a', { class: 'rad', href: a }, el('span', { tekst: n })))),
  ]);
}

async function stemple(knapp) {
  const s = await hent('/api/stempling.php');
  const inne = !!s.d.innstemplet;
  if (!(await bekreft(inne ? 'Du stempler ut nå.' : 'Du stempler inn nå.', inne ? 'Stemple ut' : 'Stemple inn'))) return;
  try {
    await send('/api/stempling.php', { handling: inne ? 'ut' : 'inn' });
    melding(inne ? 'Du er stemplet ut.' : 'Du er stemplet inn.');
    oppdaterStemple(knapp);
  } catch (e) { melding(e.message); }
}
async function oppdaterStemple(knapp) {
  const s = await hent('/api/stempling.php');
  knapp.textContent = s.d.innstemplet ? '● Stemple ut' : '○ Stemple inn';
}

function ramme(aktiv) {
  const aktive = MODULER.filter(m => oppsett.moduler[m.id] !== false);
  const lenke = (m) => el('a', { href: '#' + m.adresse, 'aria-current': m === aktiv ? 'page' : null }, m.navn);
  const stempleK = el('button', { class: 'pille lys', type: 'button', tekst: '○ Stemple inn', 'data-stemple': 'ja' });
  stempleK.onclick = () => stemple(stempleK);
  oppdaterStemple(stempleK);
  const topp = el('header', { class: 'topp' },
    el('a', { class: 'logo', href: '#i-dag' }, 'lissom'),
    el('nav', { class: 'meny', 'aria-label': 'Meny' }, aktive.map(lenke)),
    el('div', { class: 'hoyre' },
      el('button', { class: 'sok', type: 'button', onclick: sok }, '⌕ Søk i admin'),
      el('button', { class: 'pille lys sokknapp-mob', type: 'button', 'aria-label': 'Søk', onclick: sok }, '⌕'),
      stempleK,
      el('a', { class: 'gammelt', href: '/admin' }, 'Gammelt admin')));
  const bunn = el('nav', { class: 'bunn', 'aria-label': 'Meny' },
    aktive.map(m => el('a', { href: '#' + m.adresse, 'aria-current': m === aktiv ? 'page' : null }, el('i', {}, m.ikon), m.navn)));
  const pluss = el('button', { class: 'pluss', type: 'button', 'aria-label': 'Lag noe nytt', onclick: lagNytt }, '+');
  return { topp, bunn, pluss };
}

async function vis() {
  const m = hvilken();
  const { topp, bunn, pluss } = ramme(m);
  const main = el('main', {});
  app.replaceChildren(topp, main, pluss, bunn);
  document.title = m.navn + ' · Nytt admin · Lissom';
  try { await m.tegn(main, { oppsett }); }
  catch (e) { main.replaceChildren(feilboks('Noe gikk galt her. ' + (e.message || ''), vis)); }
}

async function start() {
  const r = await hent('/api/admin/admin2.php');
  if (r.status === 401) {
    app.replaceChildren(el('main', {}, el('div', { class: 'k' },
      el('h1', { tekst: 'Logg inn først' }),
      el('p', { class: 'dempet', tekst: 'Det nye admin bruker den samme innloggingen som det gamle.' }),
      el('div', {}, el('a', { class: 'pille f', href: '/admin/logg-inn' }, 'Logg inn')))));
    return;
  }
  if (r.status !== 200 || !r.d.ok) {
    app.replaceChildren(el('main', {}, feilboks(r.status === 404 ? 'Fant ikke siden.' : (r.d.feil || 'Fikk ikke kontakt med serveren.'), start)));
    return;
  }
  oppsett = r.d;
  window.addEventListener('hashchange', vis);
  vis();
}

start();
