// Ny admin (/ny-admin), del B: Kurs. Lista (denne og neste uke) og kurssiden for én dato,
// etter prototypen lissom-enklere-admin-2.html. Samme data som Kalender (kalender.php) og de
// samme endepunktene som admin-ny: pamelding.php for deltakerne, venteliste.php for koen,
// kurs.php for datoen, beskjed.php og okt-varsel.php for beskjeder med eksisterende maler.
import {api, ark, lukk, toast, registrer, poll, gaaTil, esc, idag, pluss, langDato, kortDato, kr, erMobil,
  OKTER, KURSHOLDERE, hentPeriode, erOkt, samleDag, ubetalte, H, oppfrisk, vedOppfrisk,
  arkFlytt, arkBeskjed, arkPaaminnelse, arkSerie, stengOkt, oktMeny, hentKurs, hentPop, erPop, popPrisliste} from './kalender.js';

const KS = {okt: 0, fra: '', el: null, liste: null, feil: '', bareUbetalt: false, delt: null};

function lesHash() {
  const q = new URLSearchParams((location.hash.split('?')[1]) || '');
  if (location.hash.startsWith('#kurs')) { KS.okt = +q.get('okt') || 0; KS.fra = q.get('fra') || ''; }
}

async function hentListe() {
  try {
    const d = await hentPeriode(idag(), pluss(idag(), 13));
    KS.liste = d.hendelser || []; KS.feil = '';
  } catch (e) { KS.feil = e.message; }
}
// Oekta paa kurssiden. Finnes den ikke i kartet (aapnet rett fra en adresse), slaas datoen opp i kurs.php.
async function hentOkt(tving) {
  let h = OKTER.get(KS.okt);
  if (!h || tving) {
    let dato = h?.dato;
    if (!dato) {
      const k = await hentKurs();
      for (const kurs of k.kurs || []) for (const o of kurs.datoer || []) if (o.oktId === KS.okt) dato = new Intl.DateTimeFormat('sv-SE', {timeZone: 'Europe/Oslo'}).format(new Date(o.startUtc.replace(' ', 'T') + 'Z'));
    }
    if (dato) await hentPeriode(dato, dato);
    h = OKTER.get(KS.okt);
  }
  return h;
}

// Paint on Pots: beløpet regnes ikke her. Prisen etter gjenstanden (nivåene fra prislisten) tas i kassa.
function merke(d, pop) {
  if (d.status === 'Ikke betalt') return pop || !d.belopOre ? '<span class="merke rod">Ikke betalt</span>' : `<span class="merke rod">Ikke betalt · ${kr(d.belopOre)}</span>`;
  if (d.status === 'Betalt') return '<span class="merke gronn">Betalt</span>';
  return `<span class="merke">${esc(d.status)}</span>`;
}
const rekke = {'Ikke betalt': 0, 'Betalt': 1};
const sortert = l => [...l].sort((a, b) => (rekke[a.status] ?? 2) - (rekke[b.status] ?? 2) || a.navn.localeCompare(b.navn, 'nb'));

function tegnListe() {
  const el = KS.el; if (!el) return;
  const linjer = [];
  for (let i = 0; i < 14; i++) for (const h of samleDag(KS.liste || [], pluss(idag(), i))) if (erOkt(h) && !h.avlyst) linjer.push(h);
  el.innerHTML = `<div class="nk-kurs">
  <div class="head"><div><div class="eyebrow">Denne og neste uke</div><h1>Kurs</h1></div><button class="knapp hoved bare-stor" data-k="serie">＋ Nytt kurs</button></div>
  ${KS.feil ? `<p class="merke rod">${esc(KS.feil)}</p>` : ''}
  ${KS.liste ? '' : '<p class="muted">Henter kursene …</p>'}
  <div class="kursliste">${linjer.map(h => { const ub = ubetalte(h).length; return `
    <button class="kort kurskort" data-k="apneOkt" data-okt="${h.oktId}" data-fra="kurs">
      <span class="kurskort-navn"><b>${esc(h.tittel)}</b><span class="muted">${esc(langDato(h.dato))} ${esc(h.tid)}${h.slutt ? '–' + esc(h.slutt) : ''}</span></span>
      <span class="merke">${h.pameldt}/${h.kap} påmeldt</span>${ub ? `<span class="merke rod">${ub} ikke betalt</span>` : (h.pameldt ? '<span class="merke gronn">Alle har betalt</span>' : '')}
    </button>`; }).join('') || (KS.liste ? '<p class="muted">Ingen kurs de neste to ukene.</p>' : '')}</div></div>`;
}

const gruppe = (tittel, knapper) => `<div class="hgruppe"><div class="type">${tittel}</div><div class="valgknapper">${knapper.map(([t, k, x]) => `<button class="knapp liten" data-k="${k}"${x ? ' ' + x : ''}>${t}</button>`).join('')}</div></div>`;
function tegnKursside(h) {
  const el = KS.el; if (!el) return;
  const tilbake = ({kalender: 'Tilbake til kalenderen', idag: 'Tilbake til I dag', kurs: 'Alle kurs'})[KS.fra] || 'Alle kurs';
  if (!h) { el.innerHTML = `<div class="nk-kurs"><button class="knapp liten" data-k="kursTilbake">← ${tilbake}</button><p class="muted" style="margin-top:16px">${esc(KS.feil || 'Fant ikke kurset.')}</p></div>`; return; }
  const delt = h.deltakere || [], ub = ubetalte(h).length, bet = delt.filter(d => d.status === 'Betalt').length, vente = (h.venteliste || []).length;
  const status = h.avlyst ? '<span class="merke rod">Avlyst</span>' : h.visFullt ? '<span class="merke">Stengt for påmelding</span>' : '<span class="merke gronn">Åpen for påmelding</span>';
  const vis = KS.bareUbetalt ? delt.filter(d => d.status === 'Ikke betalt') : delt;
  el.innerHTML = `<div class="nk-kurs">
  <div class="head"><div><button class="knapp liten" data-k="kursTilbake">← ${tilbake}</button>
    <div class="eyebrow" style="margin-top:14px">${esc(langDato(h.dato))} · ${esc(h.tid)}${h.slutt ? '–' + esc(h.slutt) : ''}</div><h1>${esc(h.tittel)}</h1>
    <p class="muted" style="margin-top:6px">Kursholder: <button class="knapp liten" data-k="holder">${esc(h.holder || 'Velg')} ▾</button> · ${status}</p></div>
    <button class="knapp hoved" data-k="kursBeskjed" ${delt.length ? '' : 'disabled'}>✉ Send beskjed til ${delt.length}</button></div>
  <section class="kort" style="margin-bottom:20px"><div class="hgrupper">
    ${gruppe('Deltakere', [['＋ Legg til deltaker', 'leggTil'], [`Venteliste ${vente}${vente ? ' · Gi plass' : ''}`, 'venteliste'], ['Skriv ut liste', 'utskrift']])}
    ${gruppe('Send', [['Påminnelse', 'kursPaaminnelse'], [`Kursbevis til ${bet} betalte`, 'kursbevis'], ['Bekreftelse på nytt', 'bekreftAlle']])}
    ${gruppe('Datoen', [['Endre dato og tid', 'kursFlytt'], ['Avlys dato', 'kursAvlys', h.avlyst ? 'disabled' : ''], ['Kopier økta', 'kursKopier'], [h.visFullt ? 'Åpne for påmelding' : 'Steng for påmelding', 'kursSteng']])}
    ${erPop(h.kursId) ? popPrisliste() : ''}
    ${erMobil() ? '' : gruppe('Kurset', [['Rediger kurset', 'kursRediger'], ['Notat', 'kursNotat'], ['Legg til bilde', 'kursBilde'], ['Slett kurs', 'kursSlett', 'style="color:var(--red)"']])}
  </div></section>
  <section class="kort">
    <div class="kort-head"><h2>Deltakere ${h.pameldt}/${h.kap}</h2>${ub ? `<button class="knapp liten rod" data-k="filterUbetalt" aria-pressed="${KS.bareUbetalt}">${ub} ikke betalt</button>` : ''}</div>
    ${sortert(vis).map(d => `<div class="rad"><button class="tekst lenke" data-k="deltaker" data-booking="${d.bookingId}"><b>${esc(d.navn)}${d.antall > 1 ? ` · ${d.antall} plasser` : ''}</b><small>Trykk for å endre påmelding, flytte eller sperre kursbevis</small></button>${merke(d, erPop(h.kursId))}${d.status === 'Ikke betalt' && !erMobil() ? `<button class="knapp liten" data-k="taBetalt" data-booking="${d.bookingId}">Ta betalt</button>` : ''}</div>`).join('') || '<p class="muted">Ingen påmeldte ennå.</p>'}
  </section></div>`;
}

async function tegn(el) {
  KS.el = el; lesHash();
  await hentPop();
  if (KS.okt) {
    const h = OKTER.get(KS.okt);
    if (h) tegnKursside(h); else el.innerHTML = '<div class="nk-kurs"><p class="muted">Henter kurset …</p></div>';
    try { const ny = await hentOkt(!!h); if (KS.el === el) tegnKursside(ny); }
    catch (e) { KS.feil = e.message; if (KS.el === el) tegnKursside(null); }
  } else {
    tegnListe(); await hentListe(); if (KS.el === el && !KS.okt) tegnListe();
  }
  poll('kurs', oppdater);
}
async function oppdater() {
  if (!KS.el?.isConnected) return;
  if (KS.okt) { try { const h = await hentOkt(true); if (KS.el?.isConnected) tegnKursside(h); } catch {} }
  else { await hentListe(); if (KS.el?.isConnected) tegnListe(); }
}
vedOppfrisk(oppdater);
registrer('kurs', {tittel: 'Kurs', ikon: '◉', mobil: true, tegn});

const aktiv = () => OKTER.get(KS.okt);
H.kursTilbake = () => { const til = KS.fra === 'kalender' || KS.fra === 'idag' ? KS.fra : 'kurs'; KS.okt = 0; KS.bareUbetalt = false; gaaTil(til); };
H.filterUbetalt = () => { KS.bareUbetalt = !KS.bareUbetalt; tegnKursside(aktiv()); };
H.kursBeskjed = () => arkBeskjed(KS.okt);
H.kursPaaminnelse = () => arkPaaminnelse(KS.okt);
H.kursFlytt = () => arkFlytt([aktiv()], false);
H.kursAvlys = () => arkFlytt([aktiv()], true);
H.kursSteng = () => stengOkt(aktiv());
H.kursKopier = b => { const r = b.getBoundingClientRect(); oktMeny(KS.okt, r.left, r.bottom + 4); };
H.kursRediger = () => { location.href = '/admin-ny#kurs'; };
H.kursBilde = () => { location.href = '/admin-ny#kurs'; };

// Ta betalt: kassa (/kasse) tar ikke imot person og beløp i adressen i dag. Den åpnes, og
// personen står under «Dagens kurs» der (samme påmeldinger). booking= ligger med til kassa kan lese den.
H.taBetalt = b => { window.open('/kasse?booking=' + encodeURIComponent(b.dataset.booking), 'lissom-kasse'); toast('Kassa er åpnet. Personen står under «Dagens kurs».'); };

// ── Kursholder ──────────────────────────────────────────────────────────────
H.holder = () => {
  const h = aktiv();
  ark(`<div class="ark-head"><h2>Velg kursholder</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <div class="valgknapper">${KURSHOLDERE.map(k => `<button class="knapp ${h.kursholderId === k.id ? 'hoved' : ''}" data-k="settHolder" data-id="${k.id}">${esc(k.navn)}</button>`).join('')}</div>
    <small>Kursholderen ser kurset og deltakerlista på sin side.</small>`);
};
H.settHolder = async b => { await api('kurs.php', {handling: 'dato', oktId: KS.okt, kursholderId: +b.dataset.id}); lukk(true); toast('Kursholder er lagret.'); await oppfrisk(); };

// ── Deltakere ───────────────────────────────────────────────────────────────
const MAATER = ['Ikke betalt', 'Kontant', 'Vipps', 'Gavekort', 'Faktura', 'Gratis'];
export function arkLeggTil(oktId) {
  KS.okt = +oktId || KS.okt;
  ark(`<div class="ark-head"><h2>Legg til deltaker</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <div class="to-felt"><label><small>Navn</small><input id="nd-navn" autocomplete="off"></label><label><small>Telefon</small><input id="nd-tlf" inputmode="tel"></label>
    <label><small>E-post</small><input id="nd-epost" type="email"></label><label><small>Antall plasser</small><input id="nd-antall" type="number" min="1" value="1"></label></div>
    <div><small>Betaling</small><div class="valgknapper" style="margin-top:6px">${MAATER.map((m, i) => `<button class="knapp ${i ? '' : 'hoved'}" data-k="ndMaate" data-maate="${m}">${m}</button>`).join('')}</div></div>
    <label class="sjekk"><input type="checkbox" id="nd-varsle" checked> Send bekreftelse på e-post</label>
    <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" data-k="ndOk">Legg til</button></div>`);
}
H.leggTil = () => arkLeggTil(KS.okt);
H.ndMaate = b => document.querySelectorAll('[data-k="ndMaate"]').forEach(x => x.classList.toggle('hoved', x === b));
H.ndOk = async b => {
  const v = id => (document.getElementById(id)?.value || '').trim();
  if (!v('nd-navn')) return toast('Skriv navnet først.');
  b.disabled = true;
  try {
    const r = await api('pamelding.php', {handling: 'legg-til', oktId: KS.okt, navn: v('nd-navn'), telefon: v('nd-tlf'), epost: v('nd-epost'),
      antall: +v('nd-antall') || 1, betaltMaate: document.querySelector('[data-k="ndMaate"].hoved')?.dataset.maate || 'Ikke betalt',
      varsle: document.getElementById('nd-varsle')?.checked && v('nd-epost') ? 'ja' : 'nei'});
    lukk(true); toast(esc(r.beskjed || `${v('nd-navn')} er lagt til.`)); await oppfrisk();
  } catch (e) { b.disabled = false; throw e; }
};

const finnDelt = id => (aktiv()?.deltakere || []).find(d => d.bookingId === +id);
const tilbakeDelt = () => KS.delt ? `<button class="knapp liten" data-k="deltaker" data-booking="${KS.delt.bookingId}">← Tilbake til ${esc(KS.delt.navn)}</button>` : '';
H.deltaker = b => {
  const d = finnDelt(b.dataset.booking) || KS.delt; if (!d) return;
  KS.delt = d;
  ark(`<div class="ark-head"><h2>${esc(d.navn)}</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
   <p class="muted">${[d.tlf, d.epost].filter(Boolean).map(esc).join(' · ') || 'Ingen kontaktinfo'}${d.antall > 1 ? ` · ${d.antall} plasser` : ''} · ${merke(d, erPop(aktiv()?.kursId))}</p>
   ${d.merknad ? `<p class="sms">${esc(d.merknad)}</p>` : ''}
   <div class="valgknapper"><button class="knapp" data-k="dRediger">Rediger påmelding (antall, rabatt)</button><button class="knapp" data-k="dBekreft">Send bekreftelse på nytt</button><button class="knapp" data-k="dFlytt">Flytt til annen dato</button><button class="knapp" data-k="dVente">Flytt til venteliste</button><button class="knapp rod" data-k="dSperr">Sperr kursbevis</button></div>`);
};
H.dRediger = () => {
  const d = KS.delt;
  ark(`${tilbakeDelt()}<div class="ark-head"><h2>Rediger påmelding</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <div style="display:grid;gap:5px"><small>Antall plasser</small><div class="ant stor"><button data-k="dAnt" data-d="-1" aria-label="Færre">−</button><b id="d-ant">${d.antall}</b><button data-k="dAnt" data-d="1" aria-label="Flere">+</button></div></div>
    <div class="to-felt"><label><small>Rabatt (%)</small><input id="d-rabatt" inputmode="decimal" value="${d.rabatt || ''}"></label><label><small>Beløp (kr)</small><input id="d-belop" inputmode="numeric" value="${Math.round((d.belopOre || 0) / 100)}"></label></div>
    <div class="ark-fot"><button class="knapp" data-k="deltaker" data-booking="${d.bookingId}">Avbryt</button><button class="knapp hoved" data-k="dRedigerOk">Lagre</button></div>`);
};
H.dAnt = b => { const e = document.getElementById('d-ant'); e.textContent = Math.max(1, +e.textContent + +b.dataset.d); };
H.dRedigerOk = async () => {
  const d = KS.delt, body = {handling: 'endre', id: d.bookingId};
  const ant = +document.getElementById('d-ant').textContent, rab = document.getElementById('d-rabatt').value.trim(), bel = document.getElementById('d-belop').value.trim();
  if (ant !== d.antall) body.antall = ant;
  if (rab !== String(d.rabatt || '')) body.rabatt = rab || '0';
  else if (bel !== String(Math.round((d.belopOre || 0) / 100))) body.belop = bel;
  const r = await api('pamelding.php', body);
  toast(esc(r.beskjed || 'Påmeldingen er endret.')); await oppfrisk(); KS.delt = finnDelt(d.bookingId) || d; H.deltaker({dataset: {booking: d.bookingId}});
};
H.dBekreft = async () => { const r = await api('pamelding.php', {handling: 'bekreftelse', id: KS.delt.bookingId}); toast(esc(r.beskjed || 'Bekreftelsen er sendt.')); };
H.dSperr = async () => { await api('pamelding.php', {handling: 'bevis', id: KS.delt.bookingId, sperret: 'ja'}); lukk(true); toast('Kursbeviset er sperret for denne deltakeren.'); };
H.dVente = async () => {
  const r = await api('pamelding.php', {handling: 'til-venteliste', id: KS.delt.bookingId});
  lukk(true); toast(esc(r.beskjed || 'Flyttet til ventelista. Plassen er ledig for andre.')); await oppfrisk();
};
H.dFlytt = async () => {
  const h = aktiv(), d = KS.delt;
  ark(`${tilbakeDelt()}<div class="ark-head"><h2>Flytt til annen dato</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div><p class="muted">Henter datoene …</p>`);
  await hentPeriode(idag(), pluss(idag(), 90));
  const andre = [...OKTER.values()].filter(o => o.kursId === h.kursId && o.oktId !== h.oktId && !o.avlyst && o.dato >= idag()).sort((a, b) => (a.dato + a.tid).localeCompare(b.dato + b.tid));
  ark(`${tilbakeDelt()}<div class="ark-head"><h2>Flytt til annen dato</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <div class="valgknapper">${andre.map(o => `<button class="knapp" data-k="dFlyttOk" data-okt="${o.oktId}">${esc(langDato(o.dato))} ${esc(o.tid)} · ${o.pameldt}/${o.kap}</button>`).join('') || '<p class="muted">Ingen andre datoer på dette kurset de neste tre månedene.</p>'}</div>
    <div class="sms">${esc(d.navn)} får e-post med ny dato (malen «Ny dato på kurset»).</div>`);
};
H.dFlyttOk = async b => {
  const r = await api('pamelding.php', {handling: 'flytt', id: KS.delt.bookingId, oktId: +b.dataset.okt});
  lukk(true); toast(esc(r.beskjed || 'Deltakeren er flyttet.')); await oppfrisk();
};

// ── Venteliste ──────────────────────────────────────────────────────────────
H.venteliste = () => {
  const h = aktiv(), ko = h.venteliste || [];
  if (!ko.length) return toast('Ingen på ventelista.');
  ark(`<div class="ark-head"><h2>Venteliste · ${ko.length}</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    ${ko.map((w, i) => `<div class="rad"><div class="tekst"><b>${esc(w.navn)}</b><small>Nr. ${w.posisjon}${w.varslet ? ' · varslet' : ''}${w.paaKurset ? ' · venter på kurset' : ''}</small></div><button class="knapp liten ${i ? '' : 'hoved'}" data-k="giPlass" data-id="${w.id}">Gi plass</button></div>`).join('')}`);
};
H.giPlass = async b => {
  const r = await api('venteliste.php', {handling: 'gi-plass', id: +b.dataset.id, oktId: KS.okt});
  lukk(true); toast(esc(r.beskjed || 'Har fått plass.')); await oppfrisk();
};

// ── Send: kursbevis og bekreftelse til alle (samme kall som for én) ──────────
async function tilAlle(rader, handling, tittel) {
  let ok = 0; const feil = [];
  for (const d of rader) { try { await api('pamelding.php', {handling, id: d.bookingId}); ok++; } catch (e) { feil.push(`${d.navn}: ${e.message}`); } }
  lukk(true);
  toast(`<b>${tittel} sendt til ${ok}.</b>${feil.length ? ' ' + esc(feil.join(' ')) : ''}`);
}
H.kursbevis = () => {
  const rader = (aktiv().deltakere || []).filter(d => d.status === 'Betalt');
  if (!rader.length) return toast('Ingen har betalt ennå.');
  ark(`<div class="ark-head"><h2>Kursbevis til ${rader.length} betalte</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <p class="muted">${rader.map(d => esc(d.navn)).join(', ')}</p><small>Samme e-post som etter kurset («Be om en anmeldelse» med kursbeviset).</small>
    <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" data-k="kursbevisOk">Send kursbevis</button></div>`);
};
H.kursbevisOk = b => { b.disabled = true; return tilAlle((aktiv().deltakere || []).filter(d => d.status === 'Betalt'), 'kursbevis', 'Kursbevis'); };
H.bekreftAlle = () => {
  const rader = (aktiv().deltakere || []).filter(d => d.epost && (d.status === 'Betalt' || d.status === 'Ikke betalt'));
  if (!rader.length) return toast('Ingen påmeldte med e-post.');
  ark(`<div class="ark-head"><h2>Bekreftelse på nytt</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <p class="muted">${rader.length} får påmeldingsbekreftelsen på e-post igjen: ${rader.map(d => esc(d.navn)).join(', ')}</p>
    <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" data-k="bekreftAlleOk">Send til ${rader.length}</button></div>`);
};
H.bekreftAlleOk = b => { b.disabled = true; return tilAlle((aktiv().deltakere || []).filter(d => d.epost && (d.status === 'Betalt' || d.status === 'Ikke betalt')), 'bekreftelse', 'Bekreftelsen'); };

// ── Notat, slett, utskrift ──────────────────────────────────────────────────
H.kursNotat = () => ark(`<div class="ark-head"><h2>Notat</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
  <p class="muted">Internt notat i kalenderen denne dagen, bare synlig i admin.</p><textarea id="nk-notat" class="felt" rows="4" maxlength="500"></textarea>
  <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp hoved" data-k="notatOk">Lagre</button></div>`);
H.notatOk = async () => {
  const h = aktiv(), tekst = (document.getElementById('nk-notat')?.value || '').trim();
  if (!tekst) return toast('Skriv notatet først.');
  await api('verkstedet.php', {handling: 'kalendernotat', id: 0, dato: h.dato, fra: h.tid, til: h.slutt && h.slutt > h.tid ? h.slutt : '', tekst});
  lukk(true); toast('Notatet er lagret.'); await oppfrisk();
};
H.kursSlett = () => {
  const h = aktiv();
  ark(`<div class="ark-head"><h2>Slett kurs</h2><button class="lukk" data-k="lukk" aria-label="Lukk">×</button></div>
    <p>Hele kurset <b>${esc(h.tittel)}</b> med alle datoene. ${h.pameldt ? `<b>${h.pameldt} påmeldt</b> på denne datoen – har noen meldt seg på, blir kurset avlyst i stedet for slettet.` : ''}</p>
    <div class="ark-fot"><button class="knapp" data-k="lukk">Avbryt</button><button class="knapp rod" data-k="slettOk">Er du sikker? Slett</button></div>`);
};
H.slettOk = async () => {
  const h = aktiv(); const r = await api('kurs.php', {handling: 'slett', id: h.kursId});
  lukk(true); toast(esc(r.beskjed || 'Kurset er slettet.')); KS.okt = 0; gaaTil('kurs'); await oppfrisk();
};
H.utskrift = () => {
  const h = aktiv(); const rader = sortert(h.deltakere || []);
  const ramme = document.createElement('iframe');
  ramme.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
  ramme.srcdoc = `<!doctype html><html lang="nb"><head><meta charset="utf-8"><title>${esc(h.tittel)}</title><style>body{font-family:Georgia,serif;color:#4D1D12;padding:24px}table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:8px 6px;border-bottom:1px solid #E8DCC8;text-align:left}@page{margin:14mm}</style></head><body>
    <h1 style="font-size:22px">${esc(h.tittel)}</h1><p>${esc(langDato(h.dato))} kl. ${esc(h.tid)}${h.slutt ? '–' + esc(h.slutt) : ''} · ${h.pameldt}/${h.kap} påmeldt${h.holder ? ' · ' + esc(h.holder) : ''}</p>
    <table><tr><th></th><th>Navn</th><th>Antall</th><th>Betaling</th><th>Telefon</th><th>Merknad</th></tr>${rader.map(d => `<tr><td>☐</td><td>${esc(d.navn)}</td><td>${d.antall}</td><td>${esc(d.status)}${d.status === 'Ikke betalt' && !erPop(h.kursId) && d.belopOre ? ' · ' + kr(d.belopOre) : ''}</td><td>${esc(d.tlf)}</td><td>${esc(d.merknad)}</td></tr>`).join('')}</table></body></html>`;
  ramme.onload = () => { ramme.contentWindow.focus(); ramme.contentWindow.print(); setTimeout(() => ramme.remove(), 60000); };
  document.body.append(ramme);
};

// «＋ Nytt kurs» fra Kurs-lista bruker det samme arket som Kalender (H.serie).
export {arkSerie};
