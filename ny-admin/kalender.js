// Ny admin (/ny-admin), del B: Kalender. Bygget etter prototypen lissom-enklere-admin-2.html
// (eierens GO 08.10.2026). Samme data som nettsidens booking: kalender.php leser okter,
// paameldinger og venteliste; alle endringer gaar til endepunktene som finnes fra foer
// (kurs.php, pamelding.php, venteliste.php, beskjed.php, apningstider.php). Nytt her er bare
// okt-varsel.php (eksisterende maler til alle paa en dato) og skoleferier.php (Vestfold).
//
// Skallet (del A, ny-admin/felles.js) gir: registrerSide, api, apneArk/lukkArk, toast, hentHvert.
// De slaas opp ved bruk (window.nyAdmin eller globalt), saa rekkefolgen skriptene lastes i er likegyldig.
// Felles hjelpere for Kurs-siden (ny-admin/kurs.js) eksporteres herfra.

const F = () => window.nyAdmin || window;

// ── Skjoeten mot skallet ─────────────────────────────────────────────────────
export async function api(sti, data) {
  const f = F();
  if (typeof f.api === 'function') return f.api(sti, data ? {metode: 'POST', data} : {});
  // Reserve til skallet er flettet inn: samme kall som admin-ny/ui.js.
  const r = await fetch('/api/admin/' + sti, {credentials: 'same-origin', cache: 'no-store',
    ...(data ? {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data)} : {})});
  let d; try { d = await r.json(); } catch { throw Error('Serveren svarte ikke som forventet. Prøv igjen.'); }
  if (!r.ok || d.ok === false) throw Object.assign(Error(d.feil || 'Kunne ikke hente opplysningene.'), {status: r.status, data: d});
  return d;
}
export const ark = html => F().apneArk(html);
export const lukk = tving => F().lukkArk(tving);
export const toast = html => F().toast(html);
export function registrer(id, opp) {
  const prov = () => { if (typeof F().registrerSide === 'function') { F().registrerSide(id, opp); return true; } return false; };
  if (!prov()) document.addEventListener('DOMContentLoaded', () => { if (!prov()) setTimeout(prov, 0); }, {once: true});
}
// Henting hvert 15. sekund. Skallet (hentHvert) stopper tidtakeren naar man bytter side eller fanen er skjult,
// saa den startes paa nytt hver gang siden tegnes. Reserven uten skall: én per side.
const pollere = new Map();
export function poll(id, fn) {
  const f = F();
  if (typeof f.hentHvert === 'function') return f.hentHvert(15000, fn);
  if (pollere.has(id)) return;
  pollere.set(id, true);
  setInterval(() => { if (!document.hidden) fn(); }, 15000); window.addEventListener('focus', fn);
}
// Til en annen side (Kurs fra Kalender). Skallet ruter paa #side?parametre.
export function gaaTil(side, param = {}) {
  const q = new URLSearchParams(param).toString();
  const f = F();
  if (typeof f.gaaTil === 'function') return f.gaaTil(side, param);
  location.hash = '#' + side + (q ? '?' + q : '');
}

// ── Smaa hjelpere ────────────────────────────────────────────────────────────
export const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
export const DAG = ['Mandag', 'Tirsdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lørdag', 'Søndag'];
export const MND = ['januar', 'februar', 'mars', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'desember'];
export const idag = () => new Intl.DateTimeFormat('sv-SE', {timeZone: 'Europe/Oslo'}).format(new Date());
export const tilDato = s => new Date(s + 'T12:00:00');
export const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
export const pluss = (s, n) => { const d = tilDato(s); d.setDate(d.getDate() + n); return iso(d); };
export const mandag = s => { const d = tilDato(s); d.setDate(d.getDate() - (d.getDay() + 6) % 7); return iso(d); };
export const ukedag = s => (tilDato(s).getDay() + 6) % 7;
export const kortDato = s => { const d = tilDato(s); return d.getDate() + '.' + (d.getMonth() + 1); };
export const langDato = s => { const d = tilDato(s); return DAG[ukedag(s)] + ' ' + d.getDate() + '. ' + MND[d.getMonth()]; };
export const kr = ore => new Intl.NumberFormat('nb-NO', {maximumFractionDigits: 0}).format(Math.round((Number(ore) || 0) / 100)) + ' kr';
// Samme regel som skallet: mobil = berøring og smal skjerm (ikke en PC med zoom).
export const erMobil = () => typeof F().erMobil === 'function' ? F().erMobil() : matchMedia('(pointer:coarse) and (max-width:600px)').matches;
function ukenr(s) { const d = tilDato(s); const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate())); const n = t.getUTCDay() || 7; t.setUTCDate(t.getUTCDate() + 4 - n); const y = new Date(Date.UTC(t.getUTCFullYear(), 0, 1)); return Math.ceil(((t - y) / 86400000 + 1) / 7); }
const minutter = k => { const [h, m] = String(k || '').split(':').map(Number); return h * 60 + (m || 0); };
const klokke = n => String(Math.floor(n / 60) % 24).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0');

// Kalenderens stil (bare det kalenderen og kurssiden trenger utover skallet).
if (!document.querySelector('link[data-nk-css]')) {
  const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = '/ny-admin/kalender.css'; l.dataset.nkCss = '';
  document.head.append(l);
}

// ── Oktene, samlet ett sted (Kalender og Kurs leser fra samme kart) ─────────
export const OKTER = new Map();
export let KURSHOLDERE = [];
export let STENGTE = {};
export async function hentPeriode(fra, til) {
  const d = await api(`kalender.php?fra=${fra}&til=${til}`);
  // En økt som er flyttet eller slettet (av en annen, eller i en annen fane) skal ikke bli stående med gammel dato.
  const nye = new Set((d.hendelser || []).filter(h => h.oktId > 0 && !String(h.id).startsWith('saml-')).map(h => h.oktId));
  for (const [id, h] of OKTER) if (h.dato >= fra && h.dato <= til && !nye.has(id)) OKTER.delete(id);
  for (const h of d.hendelser || []) if (h.oktId > 0 && !String(h.id).startsWith('saml-')) OKTER.set(h.oktId, h);
  if (Array.isArray(d.kursholdere)) KURSHOLDERE = d.kursholdere;
  STENGTE = {...STENGTE, ...(d.stengte || {})};
  return d;
}
export const erOkt = h => h.oktId > 0 && !String(h.id).startsWith('saml-');
export const ubetalte = h => (h.deltakere || []).filter(d => d.status === 'Ikke betalt');

// Én dag: oktene for seg, plassene fra aapningstida samlet til én linje per kurs
// (som kalenderen i admin-ny), og resten (verksted, brenning, notater) som graa linjer.
export function samleDag(hendelser, dato) {
  const dagens = hendelser.filter(h => h.dato === dato).sort((a, b) => (a.tid || '').localeCompare(b.tid || ''));
  const ut = [], auto = new Map();
  for (const h of dagens) {
    if (erOkt(h) && h.auto) {
      const g = auto.get(h.kursId);
      if (g) { g.pameldt += h.pameldt; g.kap += h.kap; g.slutt = h.slutt || g.slutt; g.ider.push(h.oktId); }
      else { const n = {...h, ider: [h.oktId], gruppe: true}; auto.set(h.kursId, n); ut.push(n); }
    } else ut.push(h);
  }
  return ut;
}

// ── Kalender-siden ──────────────────────────────────────────────────────────
const K = {vis: 'uke', uke: mandag(idag()), mnd: idag().slice(0, 7) + '-01', valgt: new Map(), data: null, nokkel: '', kopiert: null, el: null, feil: ''};

// Mobil (≤760 px): grunnlaget er mobilkalenderen i admin-ny (kalender-mobil.js, eierens favoritt):
// «Går nå» og «Neste» øverst, Dag · Uke · Måned med I dag, ukestripe man sveiper, valgt dag som timekort.
const KM = {modus: 'dag', valgt: idag()};
function periode() {
  if (erMobil()) {
    const s = KM.modus === 'maaned' ? KM.valgt.slice(0, 8) + '01' : mandag(KM.valgt);
    const e = KM.modus === 'maaned' ? pluss(s, 41) : pluss(s, 6), a = pluss(idag(), -1), b = pluss(idag(), 30);
    return [s < a ? s : a, e > b ? e : b];
  }
  const gridStart = mandag(K.mnd);
  const fra = gridStart < K.uke ? gridStart : K.uke;
  const a = pluss(gridStart, 41), b = pluss(K.uke, 6);
  return [fra, a > b ? a : b];
}
async function hentKalender(tving) {
  const [fra, til] = periode();
  const n = fra + til;
  if (!tving && K.data && K.nokkel === n) return;
  try { K.data = await hentPeriode(fra, til); K.nokkel = n; K.feil = ''; }
  catch (e) { K.feil = e.message; }
}
export async function oppfrisk() {
  await hentKalender(true);
  if (K.el?.isConnected) tegnKalender();
  for (const fn of etterOppfrisk) await fn();
}
const etterOppfrisk = [];
export const vedOppfrisk = fn => etterOppfrisk.push(fn);

function oktKort(h) {
  const valgt = K.valgt.has(h.oktId);
  const pr = h.kap > 0 ? Math.min(100, Math.round(h.pameldt / h.kap * 100)) : 0;
  const merker = (h.avlyst ? ' <span class="merke rod">Avlyst</span>' : '')
    + (!h.avlyst && h.visFullt ? ' <span class="merke">Stengt for påmelding</span>' : '')
    + (h.flyttet ? ' <span class="merke gul">Flyttet</span>' : '');
  const navn = `${h.tid} ${h.tittel}`;
  return `<div class="okt ${h.avlyst ? 'avlyst' : ''} ${valgt ? 'valgt' : ''}" data-okt="${h.oktId}">
    <button type="button" class="okt-apne" data-k="apneOkt" data-okt="${h.oktId}"><b>${esc(h.tid)}</b> ${esc(h.tittel)}${merker}
    ${h.kap > 0 ? `<span class="fyll" aria-hidden="true"><i style="width:${pr}%"></i></span><small>${h.pameldt}/${h.kap} påmeldt</small>` : `<small>${h.pameldt} påmeldt</small>`}</button>
    ${h.gruppe ? '' : `<div class="okt-fot">${h.avlyst ? '' : `<label class="okt-velg"><input type="checkbox" data-velg="${h.oktId}" ${valgt ? 'checked' : ''} aria-label="Velg ${esc(navn)}"></label>`}<button type="button" class="valg-knapp" data-k="valg" data-okt="${h.oktId}" aria-label="Valg for ${esc(navn)}">Valg</button></div>`}</div>`;
}
function annenLinje(h) {
  const okt = h.oktId > 0 ? ` data-k="apneOkt" data-okt="${h.oktId}" role="button" tabindex="0"` : '';
  return `<small class="annet"${okt}>${esc(h.tid || '')} ${esc(h.samlingKort ? h.tittel + ' · ' + h.samlingKort : h.tittel)}</small>`;
}

function miniMaaned(hendelser) {
  const f = tilDato(K.mnd), start = mandag(K.mnd), i0 = idag();
  let html = `<div class="mini-head"><button class="lukk" data-k="kal" data-kal="mnd-1" aria-label="Forrige måned">‹</button><b>${MND[f.getMonth()]} ${f.getFullYear()}</b><button class="lukk" data-k="kal" data-kal="mnd+1" aria-label="Neste måned">›</button></div><div class="mini">`;
  html += ['ma', 'ti', 'on', 'to', 'fr', 'lø', 'sø'].map(x => `<span class="mini-d">${x}</span>`).join('');
  for (let i = 0; i < 42; i++) {
    const d = pluss(start, i);
    const n = hendelser.some(h => h.dato === d && erOkt(h) && !h.avlyst);
    html += `<button type="button" class="mini-dag ${d.slice(5, 7) !== K.mnd.slice(5, 7) ? 'ut' : ''} ${mandag(d) === K.uke ? 'iuke' : ''} ${d === i0 ? 'idag' : ''}" data-k="tilDag" data-dato="${d}">${tilDato(d).getDate()}${n ? '<i></i>' : ''}</button>`;
  }
  return html + '</div>';
}

function tegnKalender() {
  const el = K.el; if (!el) return;
  if (erMobil()) return tegnMobil();
  const faner = `<div class="faner" style="margin:0"><button data-k="kal" data-kal="vis-uke" aria-pressed="${K.vis === 'uke'}">Uke</button><button data-k="kal" data-kal="vis-mnd" aria-pressed="${K.vis === 'mnd'}">Måned</button><button data-k="kal" data-kal="vis-aar" aria-pressed="${K.vis === 'aar'}">År</button></div>`;
  if (K.vis === 'aar') {
    el.innerHTML = `<div class="nk-kal">
    <div class="head"><div><div class="eyebrow">Årsplan ${AR.aar}</div><h1>Kalender</h1></div></div>
    <div class="kal-verktoy">${faner}
      <div style="display:flex;gap:6px;flex-wrap:wrap"><button class="knapp" data-k="aarNav" data-d="-1">‹ ${AR.aar - 1}</button><button class="knapp" data-k="aarNav" data-d="0">I år</button><button class="knapp" data-k="aarNav" data-d="1">${AR.aar + 1} ›</button></div></div>
    ${aarHtml()}</div>`;
    return;
  }
  const hend = K.data?.hendelser || [];
  const u = K.uke, slutt = pluss(u, 6), i0 = idag();
  let hoved;
  if (K.vis === 'uke') {
    hoved = `<div class="ukegrid">${[0, 1, 2, 3, 4, 5, 6].map(i => {
      const d = pluss(u, i), linjer = samleDag(hend, d), stengt = d in (K.data?.stengte || {});
      return `<div class="kol ${d === i0 ? 'idag' : ''}" data-kol="${d}"><div class="kol-head"><span><b>${DAG[i].slice(0, 3)}</b> ${kortDato(d)}${stengt ? ' <span class="merke rod">Stengt</span>' : ''}</span><button type="button" class="dag-valg" data-k="dagValg" data-dato="${d}" aria-label="Valg for ${esc(langDato(d))}">Valg</button></div>
        ${linjer.length ? linjer.map(h => erOkt(h) ? oktKort(h) : annenLinje(h)).join('') : '<small class="tom">Ingen kurs</small>'}</div>`;
    }).join('')}</div>`;
  } else {
    const st = mandag(K.mnd);
    hoved = `<div class="mndgrid">${DAG.map(x => `<span class="mini-d">${x.slice(0, 3)}</span>`).join('')}${[...Array(42)].map((_, i) => {
      const d = pluss(st, i), os = hend.filter(h => h.dato === d && erOkt(h) && !h.auto);
      return `<button type="button" class="mnd-dag ${d.slice(5, 7) !== K.mnd.slice(5, 7) ? 'ut' : ''} ${d === i0 ? 'idag' : ''}" data-k="tilDag" data-dato="${d}" data-kol="${d}"><b>${tilDato(d).getDate()}</b>${os.map(o => `<span class="mnd-okt ${o.avlyst ? 'avlyst' : ''}">${esc(o.tid)} ${esc(o.tittel.split(' ')[0])} · ${o.pameldt}/${o.kap}</span>`).join('')}</button>`;
    }).join('')}</div>`;
  }
  const uka = hend.filter(h => erOkt(h) && !h.avlyst && h.dato >= u && h.dato <= slutt);
  const n = K.valgt.size, p = [...K.valgt.values()].reduce((s, o) => s + o.pameldt, 0);
  const fm = tilDato(K.mnd);
  el.innerHTML = `<div class="nk-kal">
  <div class="head"><div><div class="eyebrow">${K.vis === 'uke' ? `Uke ${ukenr(u)} · ${tilDato(u).getDate()}. ${MND[tilDato(u).getMonth()]} – ${tilDato(slutt).getDate()}. ${MND[tilDato(slutt).getMonth()]}` : `${MND[fm.getMonth()]} ${fm.getFullYear()}`}</div><h1>Kalender</h1></div><button class="knapp hoved bare-stor" data-k="serie">＋ Nytt kurs eller serie</button></div>
  ${K.feil ? `<p class="merke rod">${esc(K.feil)}</p>` : ''}
  <div class="kal-verktoy">
    ${faner}
    <div style="display:flex;gap:6px;flex-wrap:wrap"><button class="knapp" data-k="kal" data-kal="${K.vis === 'uke' ? 'uke-1' : 'mnd-1'}">‹ Forrige ${K.vis === 'uke' ? 'uke' : 'måned'}</button><button class="knapp" data-k="kal" data-kal="idag">I dag</button><button class="knapp" data-k="kal" data-kal="${K.vis === 'uke' ? 'uke+1' : 'mnd+1'}">Neste ${K.vis === 'uke' ? 'uke' : 'måned'} ›</button></div>
  </div>
  <div class="kal">
    <aside class="kort kal-side">${K.vis === 'mnd' ? '' : `<div><h3 style="margin-bottom:8px">Velg dato</h3>${miniMaaned(hend)}</div>`}
      <div class="kal-sum"><div><b>${uka.length}</b><small>kurs denne uka</small></div><div><b>${uka.reduce((s, o) => s + o.pameldt, 0)}</b><small>påmeldte</small></div></div>
      <small>Trykk på et kurs for kurssiden. «Valg» gir flytt, avlys, kopier og mer. Huk av boksen for å flytte eller avlyse flere samtidig. Høyreklikk virker også på PC.</small></aside>
    <div style="min-width:0">${hoved}</div>
  </div>
  ${n ? `<div class="handlingslinje"><span><b>${n} valgt</b> · ${p} påmeldte</span><span style="display:flex;gap:8px;flex-wrap:wrap"><button class="knapp" data-k="avvelg">Fjern valg</button><button class="knapp" data-k="avlysValgte">Avlys ${n}</button><button class="knapp hoved" data-k="flyttValgte">Flytt ${n}</button></span></div>` : ''}
  </div>`;
}

registrer('kalender', {
  tittel: 'Kalender', ikon: '▦', mobil: true,
  async tegn(el) {
    K.el = el;
    if (!K.data) el.innerHTML = '<div class="nk-kal"><div class="head"><h1>Kalender</h1></div><p class="muted">Henter kalenderen …</p></div>';
    else tegnKalender();
    await hentKalender(false);
    if (K.el === el) tegnKalender();
    if ((erMobil() ? KM.modus : K.vis) === 'aar' && !AR.punkter) hentAar(AR.aar);
    poll('kalender', async () => {
      if (!K.el?.isConnected) return;
      if ((erMobil() ? KM.modus : K.vis) === 'aar') return hentAar(AR.aar);
      await hentKalender(true); if (K.el?.isConnected) tegnKalender();
    });
  },
});

// ── Handlinger (ett klikkpunkt for begge sidene) ────────────────────────────
export const H = {};
document.addEventListener('click', ev => {
  const b = ev.target.closest('[data-k]');
  if (!b || !H[b.dataset.k]) return;
  if (b._lang) { b._lang = false; return; }
  ev.preventDefault();
  Promise.resolve(H[b.dataset.k](b, ev)).catch(e => toast(`<b>Gikk ikke:</b> ${esc(e.message)}`));
});
document.addEventListener('keydown', ev => {
  if (ev.key === 'Escape') lukkMeny();
});
const tegnPaaNytt = async () => { await hentKalender(false); tegnKalender(); };

// ── Mobil ────────────────────────────────────────────────────────────────────
const naaMin = () => { const p = Object.fromEntries(new Intl.DateTimeFormat('nb-NO', {timeZone: 'Europe/Oslo', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).formatToParts(new Date()).map(x => [x.type, x.value])); return +p.hour * 60 + +p.minute; };
const varer = h => { const s = minutter(h.tid); let e = h.dagSlutt || h.slutt ? minutter(h.dagSlutt || h.slutt) : s + 60; if (e < s) e += 1440; return e - s; };
const erKursM = h => erOkt(h) && !h.avlyst && ['kurs', 'pop', 'event'].includes(h.type);
function naKort(h, merkeTekst, klasse) {
  const fyll = h.kap ? Math.min(100, Math.round(h.pameldt / h.kap * 100)) : 0;
  const tidsrom = `${h.tid}${h.slutt ? '–' + h.slutt : ''}`;
  const naar = h.dato === idag() ? tidsrom : `${DAG[ukedag(h.dato)].slice(0, 3).toLowerCase()} ${kortDato(h.dato)} · ${tidsrom}`;
  const ub = ubetalte(h).length;
  return `<button type="button" class="nkm-na ${klasse}" data-k="apneOkt" data-okt="${h.oktId}"><span class="nkm-na-e">${merkeTekst}</span><strong class="nkm-na-h">${esc(h.tittel)}</strong><span class="nkm-na-p">${esc([naar, h.holder].filter(Boolean).join(' · '))}</span>${h.kap ? `<span class="nkm-na-bar" aria-hidden="true"><span style="width:${fyll}%"></span></span><span class="nkm-na-p">${h.pameldt}/${h.kap} påmeldt${ub ? ' · ' + ub + ' ikke betalt' : ''}</span>` : ''}</button>`;
}
const TYPENAVN = {pop: 'Paint on Pots', kurs: 'Kurs', event: 'Arrangement', verksted: 'Verkstedet', brenning: 'Brenning', notat: 'Notat', stengt: 'Stengt'};
function timekort(h) {
  if (!erOkt(h)) return `<div class="nkm-kort annet"><span class="nkm-tid"><b>${esc(h.tid || '')}</b>${h.slutt ? `<small>${esc(h.slutt)}</small>` : ''}</span><span class="nkm-hva"><strong>${esc(h.tittel)}</strong><small>${esc(h.samlingKort || TYPENAVN[h.type] || '')}</small></span></div>`;
  const ub = ubetalte(h).length;
  const info = [h.holder, h.avlyst ? 'Avlyst' : h.visFullt ? 'Stengt for påmelding' : null, h.kap ? `${h.pameldt}/${h.kap} påmeldt` : null].filter(Boolean).join(' · ');
  return `<button type="button" class="nkm-kort ${h.avlyst ? 'avlyst' : ''}" data-k="apneOkt" data-okt="${h.oktId}"><span class="nkm-tid"><b>${esc(h.tid)}</b>${h.slutt ? `<small>${esc(h.slutt)}</small>` : ''}</span><span class="nkm-hva"><strong>${esc(h.tittel)}</strong><small>${esc(info)}</small>${ub && !h.avlyst ? `<span class="merke rod">${ub} ikke betalt</span>` : ''}</span></button>`;
}
function gaarNaa(h, i0, n) {
  const s = minutter(h.tid);
  const siden = h.dato === i0 ? n - s : h.dato === pluss(i0, -1) ? n + 1440 - s : -1;
  return siden >= 0 && siden < varer(h);
}
function tegnMobil() {
  const hend = K.data?.hendelser || [], i0 = idag(), n = naaMin(), stengte = K.data?.stengte || {};
  const gaar = hend.filter(h => erKursM(h) && !h.auto && gaarNaa(h, i0, n)).sort((a, b) => a.tid.localeCompare(b.tid))[0];
  const neste = hend.filter(h => erKursM(h) && !h.auto && (h.dato > i0 || (h.dato === i0 && minutter(h.tid) > n))).sort((a, b) => (a.dato + a.tid).localeCompare(b.dato + b.tid))[0];
  const paaDag = d => samleDag(hend, d);
  const dagKnapp = (d, kl) => `<button type="button" class="${kl} ${d === i0 ? 'idag' : ''}" data-k="mDag" data-dato="${d}" aria-pressed="${d === KM.valgt}" aria-label="${esc(langDato(d))}"><i>${DAG[ukedag(d)].slice(0, 2).toLowerCase()}</i><b>${tilDato(d).getDate()}</b><span class="nkm-prikk ${paaDag(d).some(erOkt) ? '' : 'tom'}" aria-hidden="true"></span></button>`;
  const dagListe = (d, hn) => { const l = paaDag(d); return `<section class="nkm-dag" data-kol="${d}"><${hn}>${esc(langDato(d))}</${hn}>${d in stengte ? '<span class="merke rod">Stengt</span>' : ''}${l.length ? l.map(timekort).join('') : '<p class="muted">Ingen kurs</p>'}</section>`; };
  let flate, liste, mndTekst;
  if (KM.modus === 'aar') {
    mndTekst = 'Årsplan ' + AR.aar;
    flate = `<div class="nkm-styr"><button type="button" class="nkm-pille" data-k="aarNav" data-d="-1" aria-label="Forrige år">‹ ${AR.aar - 1}</button><button type="button" class="nkm-pille" data-k="aarNav" data-d="1" aria-label="Neste år">${AR.aar + 1} ›</button></div>`;
    liste = aarHtml();
  } else if (KM.modus === 'maaned') {
    const forste = KM.valgt.slice(0, 8) + '01', f = tilDato(forste), antall = new Date(f.getFullYear(), f.getMonth() + 1, 0).getDate();
    mndTekst = MND[f.getMonth()] + ' ' + f.getFullYear();
    flate = `<div class="nkm-mnd-rute" data-sveip="mnd">${'<span></span>'.repeat(ukedag(forste))}${[...Array(antall)].map((_, i) => dagKnapp(pluss(forste, i), 'nkm-celle')).join('')}</div>`;
    liste = dagListe(KM.valgt, 'h2');
  } else {
    const st = mandag(KM.valgt);
    mndTekst = MND[tilDato(st).getMonth()] + ' ' + tilDato(st).getFullYear();
    flate = `<div class="nkm-uke" data-sveip="uke">${[0, 1, 2, 3, 4, 5, 6].map(i => dagKnapp(pluss(st, i), 'nkm-ukedag')).join('')}</div>`;
    if (KM.modus === 'dag') liste = dagListe(KM.valgt, 'h2');
    else {
      const fulle = [0, 1, 2, 3, 4, 5, 6].map(i => pluss(st, i)).filter(d => paaDag(d).length);
      liste = fulle.length ? fulle.map(d => dagListe(d, 'h3')).join('') : '<p class="muted">Ingen kurs denne uka</p>';
    }
  }
  K.el.innerHTML = `<div class="nk-kal nkm">
    <div class="head"><h1>Kalender</h1></div>
    ${K.feil ? `<p class="merke rod">${esc(K.feil)}</p>` : ''}
    ${gaar ? naKort(gaar, 'Går nå', 'gaar') : ''}${neste ? naKort(neste, 'Neste', 'neste') : ''}
    <div class="nkm-styr"><div class="nkm-seg" role="group" aria-label="Kalendervisning">${[['dag', 'Dag'], ['uke', 'Uke'], ['maaned', 'Måned'], ['aar', 'År']].map(([v, t]) => `<button type="button" class="nkm-pille" data-k="mModus" data-modus="${v}" aria-pressed="${KM.modus === v}">${t}</button>`).join('')}</div><button type="button" class="nkm-pille" data-k="mDag" data-dato="${i0}">I dag</button></div>
    <p class="nkm-mnd">${mndTekst}</p>${flate}${liste}</div>`;
  const fl = K.el.querySelector('[data-sveip]');
  if (fl) sveip(fl, fl.dataset.sveip);
}
// Sveip sidelengs på ukestripa eller måneden (som kalender-mobil.js); loddrett rulling er nettleserens.
function sveip(node, hva) {
  let x0 = 0, y0 = 0, dx = 0, r = null;
  node.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; dx = 0; r = null; node.style.transition = 'none'; }, {passive: true});
  node.addEventListener('touchmove', e => { dx = e.touches[0].clientX - x0; const dy = e.touches[0].clientY - y0; if (!r && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) r = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y'; if (r === 'x') node.style.transform = `translateX(${dx * .6}px)`; }, {passive: true});
  node.addEventListener('touchend', async () => {
    node.style.transition = ''; node.style.transform = '';
    if (r === 'x' && Math.abs(dx) > 50) {
      const s = dx < 0 ? 1 : -1;
      if (hva === 'uke') KM.valgt = pluss(KM.valgt, 7 * s);
      else { const d = tilDato(KM.valgt.slice(0, 8) + '01'); d.setMonth(d.getMonth() + s); KM.valgt = iso(d); }
      tegnKalender(); await tegnPaaNytt();
    }
    r = null;
  });
}
H.mModus = async b => { KM.modus = b.dataset.modus; tegnKalender(); if (KM.modus === 'aar') return hentAar(AR.aar); await tegnPaaNytt(); };

// ── År: årsplanleggeren (api/admin/arskalender.php), Monicas egne punkter måned for måned. Intern. ──
const AR = {aar: +idag().slice(0, 4), punkter: null, feil: ''};
async function hentAar(aar) {
  try { const d = await api('arskalender.php?aar=' + aar); AR.aar = d.aar; AR.punkter = d.punkter || []; AR.feil = ''; }
  catch (e) { AR.feil = e.message; }
  if (K.el?.isConnected) tegnKalender();
}
const STOR = s => s.charAt(0).toUpperCase() + s.slice(1);
function aarHtml() {
  if (!AR.punkter) return '<p class="muted">Henter årsplanen …</p>';
  const naa = idag(), denne = +naa.slice(0, 4) === AR.aar ? +naa.slice(5, 7) : 0;
  return `${AR.feil ? `<p class="merke rod">${esc(AR.feil)}</p>` : ''}<div class="aargrid">${MND.map((m, i) => {
    const p = AR.punkter.filter(x => x.mnd === i + 1);
    return `<section class="kort aar-mnd ${denne === i + 1 ? 'denne' : ''}"><h3>${STOR(m)}</h3>
      ${p.map(x => `<button type="button" class="aar-punkt" data-k="aarPunkt" data-id="${x.id}">${esc(x.tekst)}</button>`).join('')}
      <button type="button" class="knapp liten" data-k="aarNy" data-mnd="${i + 1}">＋ Legg til</button></section>`;
  }).join('')}</div>`;
}
H.aarNav = async b => { const d = +b.dataset.d; await hentAar(d === 0 ? +idag().slice(0, 4) : AR.aar + d); };
H.aarNy = b => {
  const mnd = +b.dataset.mnd;
  ark(`<div class="ark-head"><h2>${STOR(MND[mnd - 1])} ${AR.aar}</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <label class="en-felt" style="max-width:none"><small>Punkt</small><textarea id="aar-tekst" class="felt" rows="3" maxlength="300"></textarea></label>
    <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" data-k="aarNyOk" data-mnd="${mnd}">Legg til</button></div>`);
  document.getElementById('aar-tekst')?.focus();
};
H.aarNyOk = async b => {
  const tekst = (document.getElementById('aar-tekst')?.value || '').trim(); if (!tekst) return toast('Skriv punktet først.');
  const d = await api('arskalender.php', {handling: 'legg-til', aar: AR.aar, mnd: +b.dataset.mnd, tekst});
  AR.punkter = d.punkter || AR.punkter; lukk(true); toast(esc(d.beskjed || 'Punktet er lagt inn.')); tegnKalender();
};
H.aarPunkt = b => {
  const x = AR.punkter.find(p => p.id === +b.dataset.id); if (!x) return;
  ark(`<div class="ark-head"><h2>${STOR(MND[x.mnd - 1])} ${x.aar}</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <label class="en-felt" style="max-width:none"><small>Punkt</small><textarea id="aar-tekst" class="felt" rows="3" maxlength="300">${esc(x.tekst)}</textarea></label>
    <div class="to-felt"><label><small>Måned</small><select id="aar-mnd">${MND.map((m, i) => `<option value="${i + 1}" ${i + 1 === x.mnd ? 'selected' : ''}>${STOR(m)}</option>`).join('')}</select></label>
    <label><small>År</small><input id="aar-aar" type="number" min="2000" max="2100" value="${x.aar}"></label></div>
    <div class="ark-fot"><button class="knapp rod" data-k="aarSlett" data-id="${x.id}">Slett</button><span style="flex:1"></span><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" data-k="aarLagre" data-id="${x.id}">Lagre</button></div>`);
};
H.aarLagre = async b => {
  const x = AR.punkter.find(p => p.id === +b.dataset.id); if (!x) return;
  const tekst = (document.getElementById('aar-tekst')?.value || '').trim(), mnd = +document.getElementById('aar-mnd').value, aar = +document.getElementById('aar-aar').value || x.aar;
  if (!tekst) return toast('Skriv punktet først.');
  if (tekst !== x.tekst) await api('arskalender.php', {handling: 'endre', id: x.id, tekst});
  if (mnd !== x.mnd || aar !== x.aar) await api('arskalender.php', {handling: 'flytt', id: x.id, mnd, aar});
  lukk(true); toast('Punktet er lagret.'); await hentAar(AR.aar);
};
H.aarSlett = async b => {
  const d = await api('arskalender.php', {handling: 'slett', id: +b.dataset.id});
  lukk(true); toast(esc(d.beskjed || 'Punktet er slettet.')); await hentAar(AR.aar);
};
H.mDag = async b => { KM.valgt = b.dataset.dato; if (KM.modus === 'uke') KM.modus = 'dag'; tegnKalender(); await tegnPaaNytt(); };
let varMobil = erMobil();
addEventListener('resize', () => { if (erMobil() !== varMobil) { varMobil = erMobil(); if (K.el?.isConnected) tegnPaaNytt(); } });

H.kal = async b => {
  const v = b.dataset.kal;
  if (v === 'vis-aar') { K.vis = 'aar'; tegnKalender(); return hentAar(AR.aar); }
  if (v === 'vis-uke') K.vis = 'uke';
  if (v === 'vis-mnd') { K.vis = 'mnd'; K.mnd = K.uke.slice(0, 7) + '-01'; }
  if (v === 'uke-1' || v === 'uke+1') { K.uke = pluss(K.uke, v === 'uke-1' ? -7 : 7); K.mnd = K.uke.slice(0, 7) + '-01'; }
  if (v === 'mnd-1' || v === 'mnd+1') { const d = tilDato(K.mnd); d.setMonth(d.getMonth() + (v === 'mnd-1' ? -1 : 1)); K.mnd = iso(d).slice(0, 7) + '-01'; }
  if (v === 'idag') { K.uke = mandag(idag()); K.mnd = idag().slice(0, 7) + '-01'; }
  tegnKalender(); await tegnPaaNytt();
};
H.tilDag = async b => { K.uke = mandag(b.dataset.dato); K.vis = 'uke'; K.mnd = b.dataset.dato.slice(0, 7) + '-01'; tegnKalender(); await tegnPaaNytt(); };
document.addEventListener('change', e => {
  const boks = e.target.closest?.('input[data-velg]'); if (!boks) return;
  const id = +boks.dataset.velg, h = OKTER.get(id); if (!h) return;
  boks.checked ? K.valgt.set(id, h) : K.valgt.delete(id);
  tegnKalender();
  K.el?.querySelector(`input[data-velg="${id}"]`)?.focus();
});
H.dagValg = (b, ev) => { const r = b.getBoundingClientRect(); dagMeny(b.dataset.dato, r.left, r.bottom + 4); ev.stopPropagation(); };
H.avvelg = () => { K.valgt.clear(); tegnKalender(); };
H.flyttValgte = () => arkFlytt([...K.valgt.values()], false);
H.avlysValgte = () => arkFlytt([...K.valgt.values()], true);
H.apneOkt = b => { lukkMeny(); gaaTil('kurs', {okt: b.dataset.okt, fra: b.dataset.fra || (K.el?.isConnected ? 'kalender' : 'kurs')}); };
H.serie = () => arkSerie({start: pluss(idag(), 1)});
H.lukk = () => lukk();
H.valg = (b, ev) => { const r = b.getBoundingClientRect(); oktMeny(+b.dataset.okt, r.left, r.bottom + 4); ev.stopPropagation(); };
// «Kopier økta» fra kurssiden: kopierer med en gang (ingen meny), lim inn fra dagens «Valg» i kalenderen.
export function kopierOkt(h) { if (!h) return; K.kopiert = h; toast('Kopiert. Trykk «Valg» på en dag i kalenderen for å lime inn.'); }

// ── Hoeyreklikk / langt trykk / «Valg» ──────────────────────────────────────
let meny = null, menyFor = null;
function visMeny(x, y, html) {
  if (!meny) { meny = document.createElement('div'); meny.className = 'nk-meny'; document.body.append(meny); }
  meny.innerHTML = html; meny.hidden = false;
  const w = meny.offsetWidth, h = meny.offsetHeight;
  meny.style.left = Math.max(8, Math.min(x, innerWidth - w - 8)) + 'px';
  meny.style.top = Math.max(8, Math.min(y, innerHeight - h - 8)) + 'px';
  meny.querySelector('button:not([disabled])')?.focus();
}
export function lukkMeny() { if (meny) meny.hidden = true; }
document.addEventListener('pointerdown', e => { if (meny && !meny.hidden && !meny.contains(e.target) && !e.target.closest('.valg-knapp,.dag-valg')) lukkMeny(); });
export function oktMeny(id, x, y) {
  const h = OKTER.get(id); if (!h) return;
  menyFor = h;
  visMeny(x, y, `<div class="hm-tittel">${esc(h.tid)} · ${esc(h.tittel)}</div>
    <button data-k="hm" data-hm="apne">Kurset og deltakerne</button>
    <button data-k="hm" data-hm="leggtil">＋ Legg til deltaker</button>
    <button data-k="hm" data-hm="paaminnelse">Send påminnelse</button>
    <button data-k="hm" data-hm="beskjed">✉ Send beskjed til alle</button>
    <hr>
    <button data-k="hm" data-hm="flytt">Endre dato og tid</button>
    <button data-k="hm" data-hm="kopier">Kopier økta</button>
    <button data-k="hm" data-hm="kopieruke">Kopier til hele uka</button>
    <button data-k="hm" data-hm="steng">${h.visFullt ? 'Åpne for påmelding' : 'Steng for påmelding'}</button>
    <hr>
    <button data-k="hm" data-hm="avlys" class="fare" ${h.avlyst ? 'disabled' : ''}>Avlys dato</button>`);
}
function dagMeny(dato, x, y) {
  menyFor = dato;
  const stengt = dato in STENGTE;
  visMeny(x, y, `<div class="hm-tittel">${esc(langDato(dato))}</div>
    <button data-k="hm" data-hm="nytt">＋ Nytt kurs her</button>
    <button data-k="hm" data-hm="serie">＋ Ny serie fra denne dagen</button>
    <button data-k="hm" data-hm="lim" ${K.kopiert ? '' : 'disabled'}>Lim inn ${K.kopiert ? esc(K.kopiert.tid + ' ' + K.kopiert.tittel) : '(kopier en økt først)'}</button>
    <button data-k="hm" data-hm="stengdag">${stengt ? 'Åpne dagen' : 'Steng dagen'}</button>`);
}
document.addEventListener('contextmenu', e => {
  const okt = e.target.closest('.nk-kal [data-okt], .nk-kurs [data-okt]');
  const kol = e.target.closest('.nk-kal [data-kol]');
  if (okt && OKTER.has(+okt.dataset.okt)) { e.preventDefault(); oktMeny(+okt.dataset.okt, e.clientX, e.clientY); }
  else if (kol) { e.preventDefault(); dagMeny(kol.dataset.kol, e.clientX, e.clientY); }
});
let trykk = null;
document.addEventListener('pointerdown', e => {
  if (e.pointerType !== 'touch') return;
  const t = e.target.closest('.nk-kal [data-okt], .nk-kal [data-kol]'); if (!t) return;
  const x = e.clientX, y = e.clientY, klikk = e.target.closest('[data-k]') || t;
  trykk = setTimeout(() => { klikk._lang = true; t.dataset.okt && OKTER.has(+t.dataset.okt) ? oktMeny(+t.dataset.okt, x, y) : dagMeny(t.dataset.kol || t.closest('[data-kol]')?.dataset.kol, x, y); }, 550);
});
['pointerup', 'pointercancel'].forEach(n => document.addEventListener(n, () => clearTimeout(trykk)));
document.addEventListener('pointermove', e => { if (Math.abs(e.movementX) + Math.abs(e.movementY) >= 4) clearTimeout(trykk); });

H.hm = async b => {
  lukkMeny();
  const v = b.dataset.hm, h = menyFor;
  if (v === 'apne') return gaaTil('kurs', {okt: h.oktId, fra: K.el?.isConnected ? 'kalender' : 'kurs'});
  if (v === 'leggtil') { gaaTil('kurs', {okt: h.oktId, fra: K.el?.isConnected ? 'kalender' : 'kurs'}); return (await import('./kurs.js')).arkLeggTil(h.oktId); }
  if (v === 'paaminnelse') return arkPaaminnelse(h.oktId);
  if (v === 'beskjed') return arkBeskjed(h.oktId);
  if (v === 'flytt') return arkFlytt([h], false);
  if (v === 'avlys') return arkFlytt([h], true);
  if (v === 'kopier') return kopierOkt(h);
  if (v === 'kopieruke') return arkKopierUke(h);
  if (v === 'steng') return stengOkt(h);
  if (v === 'nytt') return arkSerie({start: h, enDato: true});
  if (v === 'serie') return arkSerie({start: h});
  if (v === 'lim') return limInn(h);
  if (v === 'stengdag') return stengDag(h);
};

export async function stengOkt(h) {
  const r = await api('kurs.php', {handling: 'visFullt', oktId: h.oktId, paa: h.visFullt ? 'nei' : 'ja'});
  toast(esc(r.beskjed || (h.visFullt ? 'Åpen for påmelding.' : 'Stengt for påmelding.')));
  await oppfrisk();
}
async function stengDag(dato) {
  const stengt = dato in STENGTE;
  const r = await api('apningstider.php', {handling: stengt ? 'aapne' : 'steng', dato});
  if (stengt) delete STENGTE[dato];
  toast(esc(r.beskjed || (stengt ? 'Dagen er åpnet.' : 'Dagen er stengt. Ingen kan melde seg på denne dagen.')));
  await oppfrisk();
}
function nyDatoBody(h, dato) {
  const body = {handling: 'nydato', kursId: h.kursId, start: `${dato} ${h.tid}`, slutt: h.slutt && minutter(h.slutt) > minutter(h.tid) ? `${dato} ${h.slutt}` : ''};
  if (h.kap > 0) body.kapasitet = h.kap;
  if (h.kursholderId) body.kursholderId = h.kursholderId;
  return body;
}
async function limInn(dato) {
  const h = K.kopiert; if (!h) return;
  await api('kurs.php', nyDatoBody(h, dato));
  toast(`<b>Limt inn:</b> ${esc(h.tid + ' ' + h.tittel)} ${esc(kortDato(dato))}.`);
  await oppfrisk();
}
function arkKopierUke(h) {
  const m = mandag(h.dato), hend = K.data?.hendelser || [];
  const dager = [0, 1, 2, 3, 4, 5, 6].map(i => pluss(m, i)).filter(d => d !== h.dato);
  const finnes = d => hend.some(x => erOkt(x) && x.kursId === h.kursId && x.dato === d && x.tid === h.tid && !x.avlyst);
  ark(`<div class="ark-head"><h2>Kopier til hele uka</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <p class="muted">${esc(h.tid)} · ${esc(h.tittel)}</p>
    <div class="ferier">${dager.map(d => { const f = finnes(d), s = d in STENGTE; return `<label class="ferie"><input type="checkbox" name="nk-dag" value="${d}" ${f || s ? 'disabled' : ''}> <span><b>${DAG[ukedag(d)]}</b><small>${kortDato(d)}${f ? ' · finnes' : s ? ' · stengt' : ''}</small></span></label>`; }).join('')}</div>
    <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" data-k="kopierUkeOk" id="nk-kopierok" disabled>Kopier</button></div>`);
  H.kopierUkeOk = async () => {
    const valgt = [...document.querySelectorAll('input[name="nk-dag"]:checked')].map(x => x.value);
    let ok = 0, feil = [];
    for (const d of valgt) { try { await api('kurs.php', nyDatoBody(h, d)); ok++; } catch (e) { feil.push(kortDato(d) + ': ' + e.message); } }
    lukk(true);
    toast(`<b>Kopiert til ${ok} ${ok === 1 ? 'dag' : 'dager'}.</b>${feil.length ? ' ' + esc(feil.join(' ')) : ''}`);
    await oppfrisk();
  };
}

// ── Flytt eller avlys én eller mange ────────────────────────────────────────
// Ingenting er valgt på forhånd (eierens regel): ny dato og om de påmeldte skal få beskjed velges hver gang. Kanalen står på malen (Innstillinger › Meldinger).
const FL = {liste: [], avlys: false, til: '', dato: '', tid: '', varsle: '', kanal: ''};
function nyTid(h) {
  if (FL.til === 'uke') return {dato: pluss(h.dato, 7), tid: h.tid};
  if (FL.til === 'dag') return {dato: pluss(h.dato, 1), tid: h.tid};
  return {dato: FL.dato || h.dato, tid: FL.tid || h.tid};
}
// Kurs over flere dager: avgjøres av serverens tall (kalender.php antSamlinger), hentet på nytt før arket åpnes — ikke bare
// av det Kalender har lastet (K.data). Fra Kurs uten Kalender lastet flyttet «Endre dato og tid» ellers bare dag 1
// (kurs.php endredato flytter ikke samlingene; kontrolløren 09.10.2026).
const flerdager = h => (h.antSamlinger || 0) > 1 || (K.data?.hendelser || []).some(x => String(x.id).startsWith('saml-' + h.oktId + '-'));
export async function arkFlytt(liste, avlys) {
  liste = liste.filter(h => h && !h.avlyst);
  if (!liste.length) return toast('Ingen kurs å endre.');
  if (!avlys) {
    for (const d of new Set(liste.map(h => h.dato))) await hentPeriode(d, d);
    liste = liste.map(h => OKTER.get(h.oktId) || h);
    if (liste.some(flerdager)) return toast('Kurs over flere dager flyttes i kursoppsettet.');
  }
  Object.assign(FL, {liste, avlys, til: '', dato: liste[0].dato, tid: liste[0].tid, varsle: '', kanal: ''});
  tegnFlytt();
}
// Hovedknappen er låst til valgene er gjort.
function flyttKlar() {
  const p = FL.liste.reduce((s, o) => s + o.pameldt, 0);
  // Avlys (eieren 09.10.2026): malen «Kurset er avlyst» eller ingen beskjed. Kanalen står på malen (Innstillinger › Meldinger).
  if (FL.avlys) return !p || !!FL.varsle;
  if (!FL.til) return false;
  return !p || !!FL.varsle;
}
const sjekkFlytt = () => { const b = document.getElementById('nk-flyttok'); if (b) b.disabled = !flyttKlar(); };
function tegnFlytt() {
  const {liste, avlys} = FL, p = liste.reduce((s, o) => s + o.pameldt, 0), n = liste.length;
  ark(`<div class="ark-head"><h2>${avlys ? 'Avlys' : 'Flytt'} ${n} kurs</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
   ${liste.map(o => `<div class="rad"><div class="tekst"><b>${kortDato(o.dato)} ${esc(o.tid)} · ${esc(o.tittel)}</b>${avlys || !FL.til ? '' : `<small>Ny: ${esc(langDato(nyTid(o).dato))} kl. ${esc(nyTid(o).tid)}</small>`}</div><span class="merke">${o.pameldt} påmeldt</span>${avlys && ubetalte(o).length < (o.deltakere || []).length ? `<span class="merke gul">${(o.deltakere || []).filter(d => d.status === 'Betalt').length} betalt</span>` : ''}</div>`).join('')}
   ${avlys ? '' : `<div><small>Ny dato og tid</small><div class="valgknapper" style="margin-top:6px">${[['uke', 'Én uke senere'], ['dag', 'Én dag senere'], ['velg', 'Velg dato og tid']].map(([k, t]) => `<button class="knapp ${FL.til === k ? 'valgt-knapp' : ''}" data-k="flyttTil" data-til="${k}" aria-pressed="${FL.til === k}">${t}</button>`).join('')}</div>
     ${FL.til === 'velg' ? `<div class="to-felt"><label><small>Dato</small><input type="date" id="nk-fdato" value="${esc(FL.dato)}"></label><label><small>Tid</small><input type="time" id="nk-ftid" value="${esc(FL.tid)}"></label></div>` : ''}</div>`}
   ${p ? (avlys
     ? `<div><small>Gi beskjed til de påmeldte (betalt og reservert)?</small><div class="valgknapper" style="margin-top:6px">${[['ja', 'Send «Kurset er avlyst»'], ['nei', 'Ikke send']].map(([k, t]) => `<button class="knapp ${FL.varsle === k ? 'valgt-knapp' : ''}" data-k="flyttVarsle" data-v="${k}" aria-pressed="${FL.varsle === k}">${t}</button>`).join('')}</div>
        ${FL.varsle === 'ja' ? '<div class="sms" id="nk-avforh"><small>Henter teksten …</small></div>' : ''}
        <small>De påmeldte velger selv ny dato eller pengene tilbake på Min side.</small></div>`
     : `<div><small>Gi beskjed om ny dato?</small><div class="valgknapper" style="margin-top:6px">${[['ja', 'Send «Ny dato på kurset»'], ['nei', 'Ikke send']].map(([k, t]) => `<button class="knapp ${FL.varsle === k ? 'valgt-knapp' : ''}" data-k="flyttVarsle" data-v="${k}" aria-pressed="${FL.varsle === k}">${t}</button>`).join('')}</div></div>
        ${FL.varsle === 'ja' ? `<div class="sms" id="nk-forh"><small>${FL.til ? 'Henter teksten …' : 'Velg ny dato først, så vises teksten.'}</small></div>` : ''}`)
     : '<p class="muted">Ingen påmeldte å gi beskjed.</p>'}
   <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" id="nk-flyttok" data-k="${avlys ? 'avlysOk' : 'flyttOk'}" ${flyttKlar() ? '' : 'disabled'}>${avlys ? `Avlys ${n}` : `Flytt ${n}`}</button></div>`);
  sjekkFlytt();
  if (!avlys && p && FL.til && FL.varsle === 'ja') forhandsvisFlytt();
  if (avlys && p && FL.varsle === 'ja') forhandsvisAvlyst();
}
export function ikkeNaaddFor(r, kanal) {
  const utenE = r.utenEpost || [], utenT = r.smsMulig ? (r.utenTelefon || []) : null;
  if (kanal === 'epost') return utenE;
  const alleNavn = [...new Set([...(r.utenEpost || []), ...(r.utenTelefon || [])])];
  if (kanal === 'sms') return utenT === null ? ['alle (SMS er ikke satt opp)'] : utenT;
  // Begge: ikke nådd bare når verken e-post eller SMS når fram.
  return alleNavn.filter(n => utenE.includes(n) && (utenT === null || utenT.includes(n)));
}
// Det som faktisk sendes: kanalen på malen og tallene per økt fra serveren. Ved flere økter vises teksten for den første.
async function forhandsvisFlytt() {
  const boks0 = document.getElementById('nk-forh'); if (!boks0 || !FL.til) return;
  const linjer = []; let tekst = '';
  for (const h of FL.liste.filter(o => o.pameldt > 0)) {
    const t = nyTid(h);
    try {
      const r = await api('okt-varsel.php', {handling: 'forhandsvis', mal: 'pamelding_flyttet', oktId: h.oktId, til: `${t.dato} ${t.tid}`});
      if (!r.aktiv) { tekst = '<b>Meldingen «Ny dato på kurset» er slått av.</b> Ingen får beskjed. Slå den på under Innstillinger › Meldinger.'; break; }
      if (!tekst) tekst = `${esc(r.emne)}<br><span class="forh-tekst">${esc(r.tekst)}</span>`;
      linjer.push(`<b>${esc(kortDato(h.dato))} ${esc(h.tittel)}:</b> ${r.naas} av ${r.antall} får beskjed (${r.epost} e-post, ${r.sms} SMS)${r.ikkeNaadd?.length ? ` · <span class="ikke-naadd">Ikke nådd: ${r.ikkeNaadd.map(esc).join(', ')}</span>` : ''}`);
    } catch (e) { linjer.push(esc(e.message)); }
  }
  const boks = document.getElementById('nk-forh'); if (boks) boks.innerHTML = (linjer.length ? linjer.join('<br>') + '<br>' : '') + tekst;
}
// «Kurset er avlyst»: den faktiske teksten og hvem den når, per økt, fra serveren (okt-varsel.php, mal kurs_avlyst).
async function forhandsvisAvlyst() {
  const linjer = []; let tekst = '';
  for (const h of FL.liste.filter(o => o.pameldt > 0)) {
    try {
      const r = await api('okt-varsel.php', {handling: 'forhandsvis', mal: 'kurs_avlyst', oktId: h.oktId});
      if (!r.aktiv) { tekst = '<b>Meldingen «Kurset er avlyst» er slått av.</b> Ingen får beskjed. Slå den på under Innstillinger › Meldinger.'; break; }
      if (!tekst) tekst = `${r.epost ? esc(r.emne) + '<br>' : ''}<span class="forh-tekst">${esc(r.sms && !r.epost ? r.smsTekst : r.tekst)}</span>`;
      linjer.push(`<b>${esc(kortDato(h.dato))} ${esc(h.tittel)}:</b> ${r.naas} av ${r.antall} får beskjed (${r.epost} e-post, ${r.sms} SMS)${r.ikkeNaadd?.length ? ` · <span class="ikke-naadd">Ikke nådd: ${r.ikkeNaadd.map(esc).join(', ')}</span>` : ''}`);
    } catch (e) { linjer.push(esc(e.message)); }
  }
  const boks = document.getElementById('nk-avforh'); if (boks) boks.innerHTML = (linjer.length ? linjer.join('<br>') + '<br>' : '') + tekst;
}
H.flyttTil = b => { lesFlytt(); FL.til = b.dataset.til; tegnFlytt(); };
H.flyttVarsle = b => { lesFlytt(); FL.varsle = b.dataset.v; tegnFlytt(); };
function lesFlytt() {
  const d = document.getElementById('nk-fdato'), t = document.getElementById('nk-ftid');
  if (d?.value) FL.dato = d.value; if (t?.value) FL.tid = t.value;
}
document.addEventListener('change', e => {
  if (e.target.id === 'nk-fdato' || e.target.id === 'nk-ftid') { lesFlytt(); forhandsvisFlytt(); }
  if (e.target.name === 'nk-dag') { const b = document.getElementById('nk-kopierok'); if (b) b.disabled = !document.querySelector('input[name="nk-dag"]:checked'); }
});
document.addEventListener('input', e => { if (e.target.id === 'nk-beskjed') sjekkBeskjed(); });
export const kanalFelt = k => k === 'sms' ? {ogsaaSms: 'ja', bareSms: 'ja'} : k === 'begge' ? {ogsaaSms: 'ja'} : {ogsaaSms: 'nei'};
const ikkeNaaddTekst = l => l.length ? ` <b>Ikke nådd:</b> ${esc([...new Set(l)].join(', '))}.` : '';
H.flyttOk = async b => {
  lesFlytt(); if (!flyttKlar()) return; b.disabled = true;
  let ok = 0, varslet = 0, alt = 0; const feil = [], ikke = [];
  for (const h of FL.liste) {
    const t = nyTid(h);
    let slutt = '';
    if (h.slutt && minutter(h.slutt) > minutter(h.tid)) slutt = `${t.dato} ${klokke(minutter(t.tid) + minutter(h.slutt) - minutter(h.tid))}`;
    try {
      await api('kurs.php', {handling: 'endredato', oktId: h.oktId, start: `${t.dato} ${t.tid}`, slutt});
      ok++;
      if (FL.varsle === 'ja' && h.pameldt > 0) { const r = await api('okt-varsel.php', {handling: 'flyttet', oktId: h.oktId, fra: `${h.dato} ${h.tid}`, forventet: `${t.dato} ${t.tid}`}); varslet += r.sendt || 0; alt += r.alleredeSendt || 0; ikke.push(...(r.ikkeNaadd || [])); }
    } catch (e) { feil.push(`${kortDato(h.dato)} ${h.tittel}: ${e.message}`); }
  }
  K.valgt.clear(); lukk(true);
  toast(`<b>${ok} kurs flyttet.</b>${FL.varsle === 'ja' ? ` ${varslet} påmeldte har fått «Ny dato på kurset».${alt ? ` ${alt} hadde alt fått beskjed om denne datoen.` : ''}` : ''}${ikkeNaaddTekst(ikke)}${feil.length ? ' ' + esc(feil.join(' ')) : ''}`);
  await oppfrisk();
};
H.avlysOk = async b => {
  if (!flyttKlar()) return;
  b.disabled = true;
  let ok = 0, varslet = 0, alt = 0; const feil = [], ikke = [];
  for (const h of FL.liste) {
    try {
      await api('kurs.php', {handling: 'avlys', oktId: h.oktId});
      ok++;
      // «Kurset er avlyst» til betalt og aktive reservasjoner, én gang per påmelding og kanal (okt-varsel.php avlyst).
      if (FL.varsle === 'ja' && h.pameldt > 0) {
        try { const r = await api('okt-varsel.php', {handling: 'avlyst', oktId: h.oktId}); varslet += r.sendt || 0; alt += r.alleredeSendt || 0; ikke.push(...(r.ikkeNaadd || [])); }
        catch (e) { feil.push(`${kortDato(h.dato)} ${h.tittel}: beskjeden gikk ikke ut (${e.message})`); }
      }
    } catch (e) { feil.push(`${kortDato(h.dato)} ${h.tittel}: ${e.message}`); }
  }
  K.valgt.clear(); lukk(true);
  toast(`<b>${ok} kurs avlyst.</b>${FL.varsle === 'ja' ? ` ${varslet} påmeldte har fått «Kurset er avlyst».${alt ? ` ${alt} hadde alt fått den.` : ''}` : ''}${ikkeNaaddTekst(ikke)}${feil.length ? ' ' + esc(feil.join(' ')) : ''}`);
  await oppfrisk();
};

// ── Send beskjed / paaminnelse ───────────────────────────────────────────────
// Ett «Send beskjed»-ark for hele adminen: deltakerne på en dato (til: okt) eller alle medlemmene (til: medlemmer).
// Antallet og «ikke nådd» kommer fra serveren (beskjed.php handling=antall), med samme utvalg som utsendingen.
const BS = {kanal: '', mal: null, svar: null};
export async function arkBeskjed(oktIdEllerMal) {
  const mal = typeof oktIdEllerMal === 'object' ? oktIdEllerMal : {til: 'okt', oktId: oktIdEllerMal};
  const h = mal.til === 'okt' ? OKTER.get(+mal.oktId) : null;
  if (mal.til === 'okt' && !h) return;
  Object.assign(BS, {kanal: '', mal, svar: null});
  const under = h ? `${esc(h.tittel)} · ${esc(langDato(h.dato))} kl. ${esc(h.tid)}`
    : mal.til === 'en' ? esc([mal.navn, mal.epost, mal.telefon].filter(Boolean).join(' · '))
    : 'Alle aktive medlemmer. Beskjeden legges også ut på Min side.';
  const kanaler = mal.til === 'medlemmer' ? [['epost', 'E-post'], ['begge', 'E-post og SMS']] : [['sms', 'SMS'], ['epost', 'E-post'], ['begge', 'Begge']];
  ark(`<div class="ark-head"><h2>✉ Send beskjed</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
   <p class="muted">${under}</p><p id="nk-bmottakere" class="muted">Henter mottakerne …</p>
   <div><small>Send som</small><div class="valgknapper" style="margin-top:6px">${kanaler.map(([k, t]) => `<button class="knapp" data-k="beskjedKanal" data-kanal="${k}" aria-pressed="false">${t}</button>`).join('')}</div></div>
   ${mal.til === 'okt' ? '' : '<label class="en-felt" style="max-width:none"><small>Overskrift (valgfritt)</small><input id="nk-bemne" class="felt" maxlength="191"></label>'}
   <label class="en-felt" style="max-width:none"><small>Beskjeden</small><textarea id="nk-beskjed" class="felt" rows="5"></textarea></label>
   <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" id="nk-bok" data-k="beskjedOk" disabled>Send</button></div>`);
  try {
    BS.svar = await api('beskjed.php', {handling: 'antall', ...mottakerFelt(mal)});
  } catch (e) { const m = document.getElementById('nk-bmottakere'); if (m) m.textContent = e.message; return; }
  tegnMottakere();
}
const mottakerFelt = mal => mal.til === 'okt' ? {til: 'okt', oktId: +mal.oktId, medReserverte: 'ja'}
  : mal.til === 'en' ? {til: 'en', navn: mal.navn || '', epost: mal.epost || '', telefon: mal.telefon || ''} : {til: 'medlemmer'};
function tegnMottakere() {
  const m = document.getElementById('nk-bmottakere'), r = BS.svar; if (!m || !r) return;
  const ikke = BS.kanal ? ikkeNaaddFor(r, BS.kanal) : [];
  m.innerHTML = `<b>${r.alle} mottakere</b>${BS.kanal ? ` · ${r.alle - (ikke[0]?.startsWith?.('alle (') ? r.alle : ikke.length)} nås` : ' · velg hvordan den skal sendes'}${ikke.length ? ` · <span class="ikke-naadd">Ikke nådd: ${ikke.map(esc).join(', ')}</span>` : ''}`;
  sjekkBeskjed();
}
function sjekkBeskjed() {
  const b = document.getElementById('nk-bok'); if (!b) return;
  const tekst = (document.getElementById('nk-beskjed')?.value || '').trim();
  b.disabled = !(BS.kanal && BS.svar && BS.svar.alle > 0 && tekst.length >= 3);
  b.textContent = BS.svar ? `Send til ${BS.svar.alle}` : 'Send';
}
H.beskjedKanal = b => { BS.kanal = b.dataset.kanal; document.querySelectorAll('[data-k="beskjedKanal"]').forEach(x => { x.classList.toggle('valgt-knapp', x === b); x.setAttribute('aria-pressed', String(x === b)); }); tegnMottakere(); };
H.beskjedOk = async b => {
  const tekst = (document.getElementById('nk-beskjed')?.value || '').trim(), emne = (document.getElementById('nk-bemne')?.value || '').trim();
  if (tekst.length < 3 || !BS.kanal) return;
  b.disabled = true;
  try {
    const r = await api('beskjed.php', {...mottakerFelt(BS.mal), tekst, ...(emne ? {emne} : {}), ...kanalFelt(BS.kanal)});
    lukk(true); toast(`<b>Lagt i kø:</b> ${r.epost || 0} e-post og ${r.sms || 0} SMS til ${esc(r.hvem || '')}.${ikkeNaaddTekst(r.ikke_naadd || [])}`);
  } catch (e) { b.disabled = false; throw e; }
};
export async function arkPaaminnelse(oktId) {
  const h = OKTER.get(oktId); if (!h) return;
  ark(`<div class="ark-head"><h2>Send påminnelse</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
   <p class="muted">${esc(h.tittel)} · ${esc(langDato(h.dato))} kl. ${esc(h.tid)}</p><div class="sms" id="nk-pforh"><small>Henter teksten …</small></div>
   <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" id="nk-pok" data-k="paaminnelseOk" data-okt="${oktId}" disabled>Send påminnelse</button></div>`);
  const r = await api('okt-varsel.php', {handling: 'forhandsvis', mal: 'kurspaaminnelse', oktId});
  const boks = document.getElementById('nk-pforh'); if (!boks) return;
  // Sendt fra før (for hånd eller dagen før): da sendes den ikke igjen herfra.
  boks.innerHTML = r.paaminnelseSendt && !r.kanSendes
    ? `<b>Påminnelse sendt ${esc(r.paaminnelseSendt)}.</b> Den sendes ikke en gang til.`
    : r.aktiv
      ? `${r.alleredeSendt ? `<small>Sist sendt ${esc(r.paaminnelseSendt)}. ${r.alleredeSendt} har alt fått den og får den ikke igjen.</small><br>` : ''}<b>${r.naas} av ${r.antall} får påminnelsen (${r.epost} e-post, ${r.sms} SMS):</b>${r.ikkeNaadd?.length ? ` <span class="ikke-naadd">Ikke nådd: ${r.ikkeNaadd.map(esc).join(', ')}</span>` : ''}${r.ikkeMed ? `<br><small>${r.ikkeMed} på lista får den ikke: påminnelsen går bare til dem som har betalt og meldte seg på for minst 14 dager siden.</small>` : ''}<br>${r.epost ? esc(r.emne) + '<br>' : ''}<span class="forh-tekst">${esc(r.tekst)}</span><br><small>Den automatiske påminnelsen dagen før sendes da ikke til dem som får den nå.</small>`
      : '<b>Meldingen «Påminnelse før kurset» er slått av.</b> Slå den på under Innstillinger › Meldinger.';
  const k = document.getElementById('nk-pok');
  if (k) { k.disabled = !r.kanSendes || !r.aktiv; if (r.paaminnelseSendt && !r.kanSendes) k.textContent = 'Påminnelse sendt'; }
}
H.paaminnelseOk = async b => {
  b.disabled = true;
  const h = OKTER.get(+b.dataset.okt);
  const r = await api('okt-varsel.php', {handling: 'paaminnelse', oktId: +b.dataset.okt, forventet: h ? `${h.dato} ${h.tid}` : ''});
  lukk(true); toast(`<b>Påminnelse sendt ${esc(r.sendtAt || '')}</b> til ${r.sendt}.${ikkeNaaddTekst(r.ikkeNaadd || [])}`);
};

// ── Nytt kurs eller serie ────────────────────────────────────────────────────
let KURS = null, FERIER = null, POP = null;
// Paint on Pots: nivåene og prisene fra samme kilde som nettsida og kassa (pop-priser.php → PopPris::nivaer()).
// Feiler pop-priser.php, vet vi ikke hvilke kurs som er Paint on Pots. Da skal de ikke behandles som vanlige kurs
// (Registrer betaling med kursets beløp, pris i serie): popUkjent() stopper dem, og neste kall prøver igjen (09.10.2026).
export async function hentPop() { if (!POP || POP.feil) { try { POP = await api('pop-priser.php'); } catch { POP = {nivaer: [], depositumKurs: [], feil: true}; } } return POP; }
export const erPop = kursId => !!POP?.depositumKurs?.includes(kursId);
export const popUkjent = () => !!POP?.feil;
export const POP_FEIL = 'Fikk ikke hentet Paint on Pots-oppsettet. Last siden på nytt og prøv igjen.';
export const popPrisliste = () => (POP?.nivaer || []).length ? `<div class="hgruppe"><div class="type">Paint on Pots · prislisten</div><div class="valgknapper">${POP.nivaer.map(n => `<span class="merke" title="${esc(n.gjenstander)}">${esc(n.navn)} ${esc(n.pris)}</span>`).join('')}</div><small>Gjenstanden betales i kassa.</small></div>` : '';
export async function hentKurs(tving) { if (!KURS || tving) KURS = await api('kurs.php'); return KURS; }
async function hentFerier() { if (!FERIER) { try { FERIER = (await api('skoleferier.php')).perioder || []; } catch { FERIER = []; } } return FERIER; }
const VARIGHET = [['4 uker', 4], ['8 uker', 8], ['12 uker', 12], ['Til jul', null, 'jul'], ['Til sommeren', null, 'sommer'], ['Velg sluttdato', null, 'egen']];
const SE = {};
export async function arkSerie({start, enDato = false, kursId = 0} = {}) {
  ark('<div class="ark-head"><h2>Nytt kurs eller serie</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div><p class="muted">Henter kursene …</p>');
  const [k] = await Promise.all([hentKurs(), hentFerier(), hentPop()]);
  const liste = (k.kurs || []).filter(x => x.status !== 'avlyst');
  const forste = liste.find(x => x.id === kursId) || null;
  // Ingen forhåndsvalg (eierens regel): klokkeslett, hvor ofte, hvor lenge og skoleferiene velges hver gang.
  Object.assign(SE, {kursId: forste?.id || 0, holderId: forste?.kursholderId || 0, fra: '', til: '',
    plasser: forste?.kapasitet || '', pris: forste ? String(forste.pris) : '', start: start || pluss(idag(), 1),
    gjentas: enDato ? 1 : 0, varighet: enDato ? -1 : null, egen: '', ferier: new Set(), hoppFerier: false, enDato});
  tegnSerie();
}
function sluttDato() {
  if (SE.varighet === -1 || SE.varighet === null || !SE.gjentas) return SE.start;
  const v = VARIGHET[SE.varighet];
  if (v[1]) return pluss(SE.start, (v[1] - 1) * 7 * SE.gjentas);
  const ferie = navn => (FERIER || []).find(f => f.navn.toLowerCase().startsWith(navn) && f.fra > SE.start);
  if (v[2] === 'jul') { const f = ferie('jule'); return f ? pluss(f.fra, -1) : SE.start.slice(0, 4) + '-12-20'; }
  if (v[2] === 'sommer') { const f = ferie('sommer'); return f ? pluss(f.fra, -1) : (+SE.start.slice(0, 4) + (SE.start.slice(5, 7) > '06' ? 1 : 0)) + '-06-20'; }
  return SE.egen || SE.start;
}
function serieDatoer() {
  const ut = [], slutt = sluttDato();
  for (let d = SE.start; d <= slutt && ut.length < 60; d = pluss(d, 7 * SE.gjentas)) {
    const fe = (FERIER || []).find(f => SE.ferier.has(f.fra) && d >= f.fra && d <= f.til);
    ut.push([d, fe ? fe.navn : (d in STENGTE ? 'Stengt' : null)]);
  }
  return ut;
}
function tegnSerie() {
  const liste = (KURS?.kurs || []).filter(x => x.status !== 'avlyst');
  const holdere = KURS?.kursholdere?.length ? KURS.kursholdere.filter(h => h.aktiv !== false) : KURSHOLDERE;
  const D = serieDatoer(), aktive = D.filter(x => !x[1]).length, slutt = sluttDato();
  const klar = SE.kursId && SE.fra && SE.til && (SE.enDato || (SE.gjentas && SE.varighet !== null && (VARIGHET[SE.varighet]?.[2] !== 'egen' || SE.egen)));
  const ferier = (FERIER || []).filter(f => f.til >= SE.start && f.fra <= slutt);
  const fmt = d => DAG[ukedag(d)].slice(0, 3) + ' ' + kortDato(d);
  ark(`<div class="ark-head"><h2>Nytt kurs eller serie</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
   <div class="to-felt">
     <label><small>Kursmal</small><select id="se-kurs"><option value="0">Velg kurs …</option>${liste.map(x => `<option value="${x.id}" ${x.id === SE.kursId ? 'selected' : ''}>${esc(x.tittel)}${x.status !== 'publisert' ? ' (' + esc(x.status) + ')' : ''}</option>`).join('')}</select></label>
     <label><small>Kursholder</small><select id="se-holder"><option value="0">Som kurset</option>${holdere.map(h => `<option value="${h.id}" ${h.id === SE.holderId ? 'selected' : ''}>${esc(h.navn)}</option>`).join('')}</select></label>
     <label><small>Fra kl.</small><input type="time" id="se-fra" value="${esc(SE.fra)}"></label>
     <label><small>Til kl.</small><input type="time" id="se-til" value="${esc(SE.til)}"></label>
     <label><small>Plasser</small><input type="number" min="1" id="se-plasser" value="${esc(SE.plasser)}"></label>
     ${erPop(SE.kursId) ? `<div class="pop-pris"><small>Beløp ved booking (fra kurset)</small><b>${esc(SE.pris)} kr</b></div>` : `<label><small>Pris (kr)</small><input inputmode="numeric" id="se-pris" value="${esc(SE.pris)}"></label>`}
     <label><small>${SE.enDato ? 'Dato' : 'Første dato'}</small><input type="date" id="se-start" value="${esc(SE.start)}"></label>
     ${SE.enDato ? '' : `<label><small>Gjentas</small><select id="se-gjentas"><option value="0">Velg …</option><option value="1" ${SE.gjentas === 1 ? 'selected' : ''}>Hver uke, samme dag</option><option value="2" ${SE.gjentas === 2 ? 'selected' : ''}>Annenhver uke</option></select></label>`}
   </div>
   ${erPop(SE.kursId) ? popPrisliste() : ''}
   ${SE.enDato ? '' : `<div><small>Hvor lenge</small><div class="valgknapper" style="margin-top:6px">${VARIGHET.map((v, i) => `<button class="knapp ${SE.varighet === i ? 'valgt-knapp' : ''}" data-k="seVarighet" data-i="${i}" aria-pressed="${SE.varighet === i}">${v[0]}</button>`).join('')}</div>
     ${VARIGHET[SE.varighet]?.[2] === 'egen' ? `<label class="en-felt"><small>Siste dato</small><input type="date" id="se-egen" value="${esc(SE.egen)}"></label>` : ''}</div>
   <div><label class="ferie hopp-ferier"><input type="checkbox" id="se-hopp-ferier" ${SE.hoppFerier ? 'checked' : ''}> <span><b>Hopp over skoleferier</b><small>Vestfold – du velger hvilke</small></span></label>
     ${SE.hoppFerier ? (ferier.length ? `<div class="ferier">${ferier.map(f => `<label class="ferie"><input type="checkbox" data-ferie="${f.fra}" ${SE.ferier.has(f.fra) ? 'checked' : ''}> <span><b>${esc(f.navn)}</b><small>${kortDato(f.fra)}${f.til !== f.fra ? '–' + kortDato(f.til) : ''}</small></span></label>`).join('')}</div>` : '<p class="muted">Ingen skoleferier i perioden.</p>') : ''}
     ${SE.hoppFerier ? '<small>Datoene er fra skoleruta til Vestfold fylkeskommune.</small>' : ''}</div>`}
   ${klar ? `<div><small>${aktive} ${aktive === 1 ? 'kursdag blir laget' : 'kurskvelder blir laget'}${D.length > aktive ? `, ${D.length - aktive} hoppes over` : ''}</small>
     <div class="seriedatoer">${D.map(([d, fe]) => `<span class="sd ${fe ? 'hopp' : ''}" title="${esc(fe || '')}">${fmt(d)}${fe ? ` · ${esc(fe)}` : ''}</span>`).join('')}</div></div>`
     : '<p class="muted">Velg kurs, klokkeslett' + (SE.enDato ? '' : ', hvor ofte og hvor lenge') + ', så vises datoene.</p>'}
   <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" data-k="seOk" ${klar && aktive ? '' : 'disabled'}>Publiser ${klar ? aktive : ''} ${aktive === 1 ? 'dato' : 'datoer'}</button></div>`);
}
function lesSerie() {
  const v = id => document.getElementById(id);
  if (v('se-kurs')) SE.kursId = +v('se-kurs').value;
  if (v('se-holder')) SE.holderId = +v('se-holder').value;
  for (const [id, k] of [['se-fra', 'fra'], ['se-til', 'til'], ['se-plasser', 'plasser'], ['se-pris', 'pris'], ['se-start', 'start'], ['se-egen', 'egen']]) if (v(id)) SE[k] = v(id).value;
  if (!SE.start) SE.start = pluss(idag(), 1);
  if (v('se-gjentas')) SE.gjentas = +v('se-gjentas').value;
}
H.seVarighet = b => { lesSerie(); SE.varighet = +b.dataset.i; tegnSerie(); };
document.addEventListener('change', e => {
  const t = e.target;
  if (t.dataset?.ferie) { lesSerie(); t.checked ? SE.ferier.add(t.dataset.ferie) : SE.ferier.delete(t.dataset.ferie); tegnSerie(); return; }
  // Eieren 09.10.2026: «la meg få mulighet til å huke av hopp over skoleferier, så det er valgfritt». Av som standard;
  // huker du av, velges alle feriene i perioden, og du kan ta bort enkeltferier.
  if (t.id === 'se-hopp-ferier') { lesSerie(); SE.hoppFerier = t.checked; SE.ferier = new Set(t.checked ? (FERIER || []).map(f => f.fra) : []); tegnSerie(); return; }
  if (t.id === 'se-kurs') {
    lesSerie(); const k = (KURS?.kurs || []).find(x => x.id === SE.kursId);
    if (k) { SE.plasser = k.kapasitet || ''; SE.pris = String(k.pris); SE.holderId = k.kursholderId || 0; }
    tegnSerie(); return;
  }
  if (['se-start', 'se-egen', 'se-gjentas', 'se-fra', 'se-til'].includes(t.id)) { lesSerie(); tegnSerie(); }
});
H.seOk = async b => {
  lesSerie();
  const k = (KURS?.kurs || []).find(x => x.id === SE.kursId); if (!k) return toast('Velg kursmal først.');
  if (!SE.fra || !SE.til) return toast('Velg klokkeslett først.');
  if (minutter(SE.til) <= minutter(SE.fra)) return toast('Sluttida må være etter starttida.');
  // Prisen i hele kroner. «500,50» eller «-500» avvises i stedet for å bli skrevet om (Codex 08.10.2026).
  const pris = String(SE.pris ?? '').trim();
  if (popUkjent()) return toast(POP_FEIL);
  if (!erPop(k.id) && pris !== '' && !/^\d+$/.test(pris)) return toast('Skriv prisen i hele kroner, uten komma eller minus.');
  const datoer = serieDatoer().filter(x => !x[1]).map(([d]) => ({start: `${d} ${SE.fra}`, slutt: `${d} ${SE.til}`}));
  b.disabled = true;
  const felles = {kursId: k.id, ...(SE.holderId ? {kursholderId: SE.holderId} : {}), ...(+SE.plasser > 0 ? {kapasitet: +SE.plasser} : {})};
  let lagtInn = [], hoppet = 0;
  try {
    const r = await api('kurs.php', {handling: 'nydatoer', ...felles, datoer});
    lagtInn = r.lagtInn || []; hoppet = (r.hoppet || []).length;
  } catch (e) {
    if (e.status !== 409) { b.disabled = false; throw e; }
    // Gjentakelse er slått av (Vis/kalendergjenta): én og én, med samme regler.
    for (const d of datoer) { try { lagtInn.push(await api('kurs.php', {handling: 'nydato', ...felles, ...d})); } catch { hoppet++; } }
  }
  // En pris som ikke ble lagret skal sies fra om: ellers ligger datoen ute med kursets pris uten at noen vet det.
  let utenPris = 0;
  if (!erPop(k.id) && pris !== '' && +pris !== Math.round(k.pris)) for (const o of lagtInn) { try { await api('kurs.php', {handling: 'dato', oktId: o.oktId, pris}); } catch { utenPris++; } }
  lukk(true);
  toast(`<b>${lagtInn.length} ${lagtInn.length === 1 ? 'dato' : 'datoer'} publisert</b> for ${esc(k.tittel)}.${hoppet ? ` ${hoppet} hoppet over (finnes fra før eller stengt).` : ''}${utenPris ? ` <b>Prisen ble ikke lagret på ${utenPris} ${utenPris === 1 ? 'dato' : 'datoer'}</b> — de har kursets pris. Rett den på datoen.` : ''}`);
  KURS = null; await oppfrisk();
};

// Det felles «Send beskjed»-arket brukes også av Medlemmer (vanlig skript, ikke modul).
if (window.NA) window.NA.arkBeskjed = arkBeskjed;
