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
  ['Markedsføring', '/admin/markedsforing'], ['Innboks (SoMe)', '/admin/markedsforing?apne=innboks'], ['SEO', '/admin/seo'],
  ['Maler', '/admin/maler'], ['Varsler', '/admin/varsler'], ['Feilmeldinger', '/admin/feilmeldinger'],
  ['Skisser', '/skisser.html'], ['Det gamle admin', '/admin'],
];

function hvilken() {
  const h = (location.hash || '').replace(/^#/, '');
  const aktive = MODULER.filter(m => !oppsett || oppsett.moduler[m.id] !== false);
  return aktive.find(m => m.adresse === h) || aktive[0];
}

// ── Det gamle admin inni det nye ─────────────────────────────────────
//
// Eieren, 30. september 2026: «når jeg bruker prøv ny admin, så bør den
// holde seg der også når jeg klikker inn på funksjonene helt til jeg trykker
// lukk ny admin eller gå til gammel admin».
//
// Det som ikke er flyttet ennå, aapnes derfor i en ramme i hovedfeltet, med
// den nye menyen rundt. Adressen blir /admin2#vis/admin/kalender osv., saa
// tilbake og oppdatering blir staaende. Bare «Gammelt admin» og «Lukk nytt
// admin» (data-forlat) gaar ut av det nye admin.
const RAMME = '#vis';
const iRamme = () => (location.hash || '').startsWith(RAMME + '/');
const rammeSti = () => decodeURI((location.hash || '').slice(RAMME.length));

/** Hvilket menypunkt en gammel adresse hoerer til. */
function modulFor(sti) {
  const s = sti.split(/[?#]/)[0];
  const er = (...p) => p.some(x => s === x || s.startsWith(x + '/'));
  let id = 'mer';
  if (er('/admin', '/admin/oversikt')) id = 'idag';
  else if (er('/admin/kurs', '/admin/kalender', '/admin/pameldte', '/admin/venteliste', '/admin/ferdigbrent',
    '/admin/arskalender', '/admin/kursholdere', '/admin/nye-pameldinger')) id = 'kurs';
  else if (er('/admin/medlemmer', '/admin/deltakere', '/admin/medlemskap', '/admin/ubesvarte', '/admin/godkjenning',
    '/admin/beskjeder', '/admin/ny-registrering')) id = 'folk';
  else if (er('/admin/uttak', '/admin/okonomi', '/admin/butikk')) id = 'penger';
  return MODULER.find(m => m.id === id) || MODULER[0];
}

/** Legger «innebygd=1» paa adressen som lastes i rammen. */
function medInnebygd(sti) {
  const [foer, hash] = sti.split('#');
  return foer + (foer.includes('?') ? '&' : '?') + 'innebygd=1' + (hash ? '#' + hash : '');
}
/** Tar den bort igjen, for adressen i det nye admin. */
function utenInnebygd(sti) {
  return sti.replace(/([?&])innebygd=1(&|$)/, (m, a, b) => (b ? a : '')).replace(/[?&]$/, '');
}
/** Skal lenken aapnes i rammen i stedet for aa forlate det nye admin? */
function tilRamme(href) {
  if (!href || !href.startsWith('/')) return false;
  if (href === '/admin2' || href.startsWith('/admin2#') || href.startsWith('/admin2?')) return false;
  return href === '/admin' || href.startsWith('/admin/') || href.startsWith('/admin?') || href.startsWith('/skisser.html');
}
function aapneIRamme(sti) {
  location.hash = RAMME + encodeURI(sti);
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
    ['Ny kursdato', '/admin/kalender?apne=nydato'], ['Nytt kurs', '/admin/kurs'], ['Nytt medlem', '/admin/ny-registrering'],
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
      el('a', { class: 'gammelt', href: '/admin', 'data-forlat': 'ja' }, 'Gammelt admin'),
      el('a', { class: 'pille lys lukknytt', href: '/admin', 'data-forlat': 'ja' }, 'Lukk nytt admin')));
  const bunn = el('nav', { class: 'bunn', 'aria-label': 'Meny' },
    aktive.map(m => el('a', { href: '#' + m.adresse, 'aria-current': m === aktiv ? 'page' : null }, el('i', {}, m.ikon), m.navn)));
  const pluss = el('button', { class: 'pluss', type: 'button', 'aria-label': 'Lag noe nytt', onclick: lagNytt }, '+');
  return { topp, bunn, pluss };
}

let rammeEl = null;
let rammeVakt = null;

function visRamme() {
  const sti = rammeSti();
  const m = modulFor(sti);
  document.body.classList.add('rammemodus');
  // Samme ramme naar vi bare bytter adresse inni det gamle admin — da blir
  // menyen staaende og siden slipper aa tegnes paa nytt.
  if (rammeEl && rammeEl.isConnected) {
    const naa = rammeEl.contentWindow ? utenInnebygd(lesRamme()) : '';
    if (naa !== sti) rammeEl.src = medInnebygd(sti);
    oppdaterMeny(m);
    return;
  }
  const { topp, bunn, pluss } = ramme(m);
  rammeEl = el('iframe', { class: 'gammel', src: medInnebygd(sti), title: 'Det gamle admin' });
  rammeEl.addEventListener('load', () => { synkRamme(); vaktFrame(); });
  const main = el('main', { class: 'ramme' }, rammeEl);
  app.replaceChildren(topp, main, pluss, bunn);
  document.title = m.navn + ' · Nytt admin · Lissom';
  clearInterval(rammeVakt);
  rammeVakt = setInterval(synkRamme, 700);
}

function lesRamme() {
  try {
    const l = rammeEl.contentWindow.location;
    return l.pathname + l.search + l.hash;
  } catch (e) { return ''; }
}

/** Adressen i det nye admin foelger det som vises i rammen. */
function synkRamme() {
  if (!rammeEl || !rammeEl.isConnected || !iRamme()) return;
  const inne = lesRamme();
  if (!inne || inne === 'about:blank') return;
  // Det gamle admin ba om aa gaa til det nye (for eksempel en lenke dit).
  if (inne.startsWith('/admin2')) { location.href = inne.replace(/[?&]innebygd=1/, ''); return; }
  const sti = utenInnebygd(inne);
  if (sti !== rammeSti()) {
    history.replaceState(null, '', location.pathname + location.search + RAMME + encodeURI(sti));
    oppdaterMeny(modulFor(sti));
  }
}

/** Lenker inni rammen som ville forlatt admin, apnes utenfor. */
function vaktFrame() {
  try {
    const d = rammeEl.contentDocument;
    d.addEventListener('click', (e) => {
      const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank') return;
      const href = a.getAttribute('href');
      if (href === '/admin2' || (href && href.startsWith('/admin2#'))) { e.preventDefault(); location.href = href; }
    }, true);
  } catch (e) { /* annet opphav: ingenting aa gjore */ }
}

function oppdaterMeny(m) {
  document.querySelectorAll('.meny a, .bunn a').forEach(a => {
    if (a.getAttribute('href') === '#' + m.adresse) a.setAttribute('aria-current', 'page');
    else a.removeAttribute('aria-current');
  });
  document.title = m.navn + ' · Nytt admin · Lissom';
}

async function vis() {
  if (iRamme()) { visRamme(); return; }
  document.body.classList.remove('rammemodus');
  clearInterval(rammeVakt);
  rammeEl = null;
  const m = hvilken();
  const { topp, bunn, pluss } = ramme(m);
  const main = el('main', {});
  app.replaceChildren(topp, main, pluss, bunn);
  document.title = m.navn + ' · Nytt admin · Lissom';
  try { await m.tegn(main, { oppsett }); }
  catch (e) { main.replaceChildren(feilboks('Noe gikk galt her. ' + (e.message || ''), vis)); }
}

// Alle lenker i det nye admin til det gamle aapnes i rammen. Ctrl/cmd-klikk
// (ny fane) og lenkene som skal ut (data-forlat) faar vaere som de er.
document.addEventListener('click', (e) => {
  const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
  if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  if (a.dataset.forlat === 'ja' || a.target === '_blank') return;
  const href = a.getAttribute('href');
  if (!tilRamme(href)) return;
  e.preventDefault();
  aapneIRamme(href);
});

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
