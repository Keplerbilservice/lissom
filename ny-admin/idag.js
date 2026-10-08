/* Ny admin — I dag (del A, eieren 08.10.2026). Ovnen, Dagens kurs, Må gjøres og Til info.
 * Alt er ekte data og eksisterende handlinger:
 *   Ovnen          /api/ovn.php (tomt, raabrann, glasurbrann). «Ovn er tømt» = ferdigbrent: velg kursdatoene som er
 *                  klare, og «Klar til henting» sendes med ferdigbrent.php handling=meld-alle (samme utsending som i dag).
 *   Dagens kurs    oversikt.php «kommende» for i dag + kursstart3.php?okt= (deltakerne, betalt/ikke betalt, Møtt).
 *                  «Møtt» = pamelding.php handling=status, samme regel som kursstart3.js.
 *   Må gjøres      oversikt.php «maGjores» (uten påmeldinger) + medlemssalg som venter (medlemssalg.php).
 *   Til info       nye påmeldinger (oversikt.php «maGjores» type pamelding, tre dager). Utført = pamelding.php handling=sett.
 */
(function () {
  'use strict';
  const NA = window.NA;
  const {esc, kr} = NA;
  const OVN = {raabrann: 'Råbrann satt', glasurbrann: 'Glasurbrann satt', tomt: 'Ovn er tømt'};
  /* Samme nøkkel som admin-ny (ramme.js): «Sett» huskes i nettleseren før migrasjon 265 er kjørt. */
  const SETT = 'lissom-sett-pameldinger';
  const settLes = () => { try { return new Set(JSON.parse(localStorage.getItem(SETT) || '[]')); } catch { return new Set(); } };
  const settLegg = id => { const s = settLes(); s.add(id); try { localStorage.setItem(SETT, JSON.stringify([...s].slice(-300))); } catch {} };

  let el = null;
  let ovn = null;
  let salg = [], salgTid = 0;
  const kurs = new Map(); // oktId → {d, tid}
  const gjort = new Set();
  const nokkel = s => s.type + ':' + s.id;

  const idagIso = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const hilsen = () => { const t = new Date().getHours(); return t < 10 ? 'God morgen' : t < 18 ? 'Hei' : 'God kveld'; };
  const klokke = s => new Date(s * 1000).toLocaleTimeString('nb-NO', {hour: '2-digit', minute: '2-digit'});
  const naarTekst = s => { const d = new Date(s * 1000); const i = new Date(); const igaar = new Date(i.getFullYear(), i.getMonth(), i.getDate() - 1);
    return (d.toDateString() === i.toDateString() ? 'i dag ' : d.toDateString() === igaar.toDateString() ? 'i går ' : d.toLocaleDateString('nb-NO', {day: 'numeric', month: 'numeric'}) + ' ') + klokke(s); };

  async function hentOvn() { try { ovn = await NA.api('/api/ovn.php'); } catch { ovn = null; } }
  async function hentSalg(d) {
    if (!(Number(d?.koer?.medlemsvarer) > 0)) { salg = []; return; }
    if (Date.now() - salgTid < 60000) return;
    salgTid = Date.now();
    try { salg = ((await NA.api('medlemssalg.php')).salg || []).filter(x => x.status === 'til_godkjenning'); } catch { salg = []; }
  }
  const dagensOkter = d => (d?.kommende || []).filter(o => String(o.startTid || '').slice(0, 10) === idagIso());
  async function hentKurs(d, tving) {
    await Promise.all(dagensOkter(d).map(async o => {
      const c = kurs.get(o.oktId);
      if (!tving && c && Date.now() - c.tid < 60000) return;
      try { kurs.set(o.oktId, {d: await NA.api('kursstart3.php?okt=' + o.oktId), tid: Date.now()}); } catch { kurs.set(o.oktId, {d: null, tid: Date.now()}); }
    }));
  }
  const ikkeBetalt = k => (k?.deltakere || []).filter(p => p.status === 'Ikke betalt' && Number(p.skyldigOre) > 0);

  /* ── tegning ─────────────────────────────────────────────────────────── */
  function tegn(d) {
    if (!el || !el.isConnected) return;
    const t = ovn?.tomt;
    const status = t ? OVN[t.slag] || 'Ovn er tømt' : 'Ingen status';
    const okter = dagensOkter(d);
    const sett = d.settPaaServer ? new Set() : settLes();
    const maa = NA.maGjores(d).filter(s => s.type !== 'taut' && !gjort.has(nokkel(s)));
    const salgIgjen = salg.filter(x => !gjort.has('salg:' + x.id));
    const info = (d.maGjores || []).filter(s => s.type === 'pamelding' && !gjort.has(nokkel(s)) && !(s.ider || [s.id]).every(i => sett.has(i)));
    const dato = new Date().toLocaleDateString('nb-NO', {weekday: 'long', day: 'numeric', month: 'long'});
    const hurtig = NA.hurtig();
    el.innerHTML = `
      <div class="head"><div><div class="eyebrow">${esc(dato.charAt(0).toUpperCase() + dato.slice(1))}</div><h1>${hilsen()}, ${esc(String(NA.meg?.navn || '').split(' ')[0])}</h1></div></div>
      <div style="display:grid;gap:20px">
        ${hurtig.length ? `<div class="bare-mobil"><div class="fliser">${hurtig.map(h => `<button class="flis" type="button" data-hurtig="${h[0]}"><span class="flis-ikon" aria-hidden="true">${h[2]}</span><span class="flis-tittel">${h[1]}</span></button>`).join('')}</div></div>` : ''}
        <div class="grid">
          <section class="kort" aria-label="Ovnen">
            <div class="kort-head"><h2>Ovnen</h2></div>
            <div class="ovnstatus">${status}</div>
            <p class="muted" style="margin:4px 0 16px">${t ? esc(t.av) + ' · ' + naarTekst(t.naar) + ' · medlemmene ser det på Min side' : 'Ingen har trykket det siste døgnet'}</p>
            <div class="ovn-knapper">${Object.entries(OVN).map(([id, txt]) => `<button type="button" data-ovn="${id}" aria-pressed="${t?.slag === id}" ${ovn && ovn.klar === false ? 'disabled' : ''}>${txt}</button>`).join('')}</div>
            <p class="muted" style="margin-top:12px;font-size:15px">«Ovn er tømt» = ferdigbrent. Du velger hvilke kursdatoer som får «Klar til henting».</p>
          </section>
          <section class="kort" aria-label="Dagens kurs">
            <div class="kort-head"><h2>Dagens kurs</h2></div>
            ${okter.length ? okter.map(o => { const k = kurs.get(o.oktId)?.d; const ub = ikkeBetalt(k).length; const n = k ? k.deltakere.reduce((s, p) => s + (Number(p.antall) || 1), 0) : o.pameldte;
              return `<div class="rad"><div class="tekst"><b>${esc(o.tittel)}</b><span class="muted">${esc(o.klokke)} · ${n} av ${o.kapasitet} plasser</span></div>${ub ? `<span class="merke rod">${ub} ikke betalt</span>` : (k ? '<span class="merke gronn">Alle har betalt</span>' : '')}
                <button class="knapp hoved liten" type="button" data-startkurs="${o.oktId}">Start dagens kurs</button></div>`; }).join('') : '<p class="tom">Ingen kurs i dag.</p>'}
          </section>
        </div>
        <section class="kort" aria-label="Må gjøres">
          <div class="kort-head"><h2>Må gjøres</h2></div>
          ${maa.length || salgIgjen.length ? salgIgjen.map(radSalg).join('') + maa.map(radSak).join('') : '<p class="tom">Alt er gjort. Fint!</p>'}
          <div class="kort-head" style="margin:22px 0 4px"><h3>Til info</h3><small>Forsvinner av seg selv etter 3 dager</small></div>
          ${info.length ? info.map(s => `<div class="rad"><div class="tekst"><span class="type">Ny påmelding</span><b>${esc(String(s.tittel).replace(/^Ny påmelding: /, ''))}</b><small>${esc(s.under)}</small></div><button class="knapp liten" type="button" data-utfort="${esc(nokkel(s))}">Utført</button></div>`).join('') : '<p class="tom">Ingen nye.</p>'}
        </section>
      </div>`;
  }
  const TYPE = {henvendelse: 'Forespørsel', soknad: 'Medlemssøknad', frys: 'Frys', bidrag: 'Medlemsbidrag', betaling: 'Betaling', venteliste: 'Venteliste',
    henting: 'Klar til henting', leire: 'Leirebestilling', dugnad: 'Dugnad', lager: 'Lager', chat: 'Chat', innboks: 'Innboks', feil: 'Feilmelding'};
  const knapp = (tekst, gjor, hoved, ekstra = '') => `<button class="knapp liten ${hoved ? 'hoved' : ''}" type="button" data-gjor="${gjor}" ${ekstra}>${tekst}</button>`;
  function radSalg(x) {
    return `<div class="rad"><div class="tekst"><span class="type">Medlemssalg</span><b>${esc(x.medlem)} vil selge ${esc(x.tittel)}</b><small>${esc([x.pris, x.antall ? x.antall + ' stk' : '', x.dato].filter(Boolean).join(' · '))}</small></div>
      <div class="knapper">${knapp('Avslå', 'salg-avvis', false, `data-id="${x.id}"`)}${knapp('Godkjenn', 'salg-godkjenn', true, `data-id="${x.id}"`)}</div></div>`;
  }
  function radSak(s) {
    const k = esc(nokkel(s));
    const gammel = `<a class="knapp liten" href="/admin-ny#${esc(String(s.rute || 'idag'))}">Åpne i gammel admin</a>`;
    const h = {
      henvendelse: () => knapp('Ferdig', 'besvart', false, `data-k="${k}"`) + knapp('Svar', 'svar', true, `data-k="${k}"`),
      soknad: () => knapp('Godkjenn', 'soknad', true, `data-k="${k}"`),
      frys: () => knapp('Avslå', 'frys-avslag', false, `data-k="${k}"`) + knapp('Godkjenn frys', 'frys-godkjenn', true, `data-k="${k}"`),
      bidrag: () => s.bilde ? knapp('Avvis', 'bidrag-avvis', false, `data-k="${k}"`) + knapp('Godkjenn til galleriet', 'bidrag-godkjenn', true, `data-k="${k}"`) : gammel,
      betaling: () => `<button class="knapp liten hoved" type="button" data-medlem="${Number(s.id)}">Åpne medlemmet</button>`,
      venteliste: () => knapp('Tilby plassen til ' + esc(s.fornavn || 'første på lista'), 'venteliste', true, `data-k="${k}"`),
      henting: () => knapp('Send «Klar til henting»', 'henting', true, `data-k="${k}"`),
      lager: () => knapp('Bestill mer', 'bestill', true, `data-k="${k}"`),
      feil: () => knapp('Løst', 'feil-lost', false, `data-k="${k}"`) + gammel,
    }[s.type] || (() => gammel);
    return `<div class="rad"><div class="tekst"><span class="type">${TYPE[s.type] || esc(s.gruppe || '')}</span><b>${esc(s.tittel)}</b>${s.under ? `<small>${esc(s.under)}</small>` : ''}</div><div class="knapper">${h()}</div></div>`;
  }

  /* ── oppdatering ─────────────────────────────────────────────────────── */
  async function oppdater(tving) {
    const d = await NA.oversikt(tving);
    await Promise.all([hentOvn(), hentSalg(d), hentKurs(d)]);
    tegn(d);
  }
  const ferdig = (nok, tekst) => { gjort.add(nok); if (tekst) NA.toast(esc(tekst)); salgTid = 0; oppdater(true).catch(() => {}); };
  const finnSak = k => (NA.sist?.maGjores || []).find(s => nokkel(s) === k);

  /* ── ovnen ───────────────────────────────────────────────────────────── */
  async function trykkOvn(b) {
    const slag = b.dataset.ovn;
    el.querySelectorAll('[data-ovn]').forEach(x => { x.disabled = true; });
    try {
      ovn = await NA.api('/api/ovn.php', {data: {handling: slag}});
      tegn(NA.sist);
      if (slag === 'tomt') arkFerdigbrent();
      else NA.toast(`<b>${OVN[slag]}.</b> Medlemmene ser det på Min side.`);
    } catch (e) { NA.toast(esc(e.message)); tegn(NA.sist); }
  }
  /* Kursdatoene som er klare til henting (samme liste som «Klar til henting» i Må gjøres). Ingenting er valgt på forhånd. */
  function arkFerdigbrent() {
    const klare = (NA.sist?.maGjores || []).filter(s => s.type === 'henting');
    if (!klare.length) { NA.toast('<b>Ovn er tømt.</b> Ingen kursdatoer venter på «Klar til henting».'); return; }
    const inn = NA.apneArk(`${NA.arkHode('Ovn er tømt · hvem er ferdigbrent?')}
      <p class="muted">Kryss av kursdatoene som var i ovnen. De får «Klar til henting» slik som før, og lissom.no/ferdigbrent oppdateres.</p>
      <div>${klare.map(s => `<div class="rad"><label class="velg-rad"><input type="checkbox" value="${Number(s.id)}"><span><b>${esc(String(s.tittel).replace(/^Klar til henting: /, ''))}</b><small>${esc(s.under)}</small></span></label></div>`).join('')}</div>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Ikke nå</button><button class="knapp hoved" type="button" data-send disabled>Send «Klar til henting»</button></div>`);
    const send = inn.querySelector('[data-send]');
    inn.onchange = () => { send.disabled = !inn.querySelector('input:checked'); };
    send.onclick = async () => {
      const ider = [...inn.querySelectorAll('input:checked')].map(i => Number(i.value));
      send.disabled = true;
      const svar = [];
      for (const oktId of ider) {
        try { const r = await NA.api('ferdigbrent.php', {data: {handling: 'meld-alle', oktId}}); svar.push(r.beskjed || ''); gjort.add('henting:' + oktId); }
        catch (e) { svar.push(e.message); }
      }
      NA.lukkArk(true);
      NA.toast(esc(svar.filter(Boolean).join(' ')) || 'Sendt.');
      oppdater(true).catch(() => {});
    };
  }

  /* ── start dagens kurs ───────────────────────────────────────────────── */
  const forMott = new Map();
  async function arkStartKurs(oktId) {
    NA.apneArk(NA.arkHode('Start dagens kurs') + '<p class="laster">Henter deltakerne …</p>');
    let k;
    try { k = await NA.api('kursstart3.php?okt=' + oktId); kurs.set(oktId, {d: k, tid: Date.now()}); }
    catch (e) { NA.apneArk(NA.arkHode('Start dagens kurs') + `<p class="feil">${esc(e.message)}</p>`); return; }
    const o = dagensOkter(NA.sist).find(x => x.oktId === oktId) || {};
    const rang = p => (p.status === 'Ikke betalt' && Number(p.skyldigOre) > 0 ? 0 : 1);
    const liste = [...(k.deltakere || [])].sort((a, b) => rang(a) - rang(b));
    const inn = NA.apneArk(`${NA.arkHode(esc((k.okt?.tittel || o.tittel || 'Kurset') + (o.klokke ? ' · ' + o.klokke : '')))}
      <p class="muted">Kryss av hvem som har kommet. De som ikke har betalt står øverst.</p>
      <div>${liste.length ? liste.map(p => { const kan = ['betalt', 'reservert', 'ikke_mott'].includes(p.statusKode); const ub = rang(p) === 0;
        return `<div class="rad"><label class="mott tekst"><input type="checkbox" data-mott="${Number(p.bookingId)}" ${p.mott ? 'checked' : ''} ${kan ? '' : 'disabled'}><b>${esc(p.navn)}${Number(p.antall) > 1 ? ' · ' + Number(p.antall) + ' plasser' : ''}</b></label>
          ${ub ? `<span class="merke rod">Ikke betalt · ${esc(p.skyldig)}</span>` : `<span class="merke ${p.status === 'Betalt' ? 'gronn' : ''}">${esc(p.status)}</span>`}
          ${ub && !NA.erMobil() ? '<a class="knapp liten" href="/kasse">Ta betalt</a>' : ''}</div>`; }).join('') : '<p class="tom">Ingen påmeldte.</p>'}</div>
      ${liste.some(p => rang(p) === 0) && !NA.erMobil() ? '<small>«Ta betalt» åpner kassa. Velg personen der.</small>' : ''}
      <div class="ark-fot"><button class="knapp hoved" type="button" data-lukk>Kurset er i gang</button></div>`);
    inn.onchange = async e => {
      const boks = e.target.closest('[data-mott]'); if (!boks) return;
      const id = Number(boks.dataset.mott);
      const p = liste.find(x => Number(x.bookingId) === id); if (!p) return;
      const mott = boks.checked;
      if (!mott) forMott.set(id, p.statusKode);
      const status = mott ? (forMott.get(id) || p.forStatus || 'reservert') : 'ikke_mott';
      boks.disabled = true;
      try { await NA.api('pamelding.php', {data: {handling: 'status', id, status}}); if (mott) forMott.delete(id); p.statusKode = status; p.mott = mott; }
      catch (err) { boks.checked = !mott; NA.toast(esc(err.message)); }
      boks.disabled = false;
    };
    document.querySelector('#ark').addEventListener('close', () => { kurs.delete(oktId); oppdater(true).catch(() => {}); }, {once: true});
  }

  /* ── handlinger i Må gjøres ──────────────────────────────────────────── */
  async function svarForesporsel(s) {
    NA.apneArk(NA.arkHode('Svar på forespørsel') + '<p class="laster">Henter …</p>');
    let f;
    try { f = ((await NA.api('foresporsler.php')).foresporsler || []).find(x => x.id === Number(s.id)); } catch {}
    if (!f) f = {navn: '', epost: '', tlf: '', hva: '', tekst: s.under};
    const inn = NA.apneArk(`${NA.arkHode('Svar på forespørsel')}
      <div class="sms"><b>${esc(f.hva || 'Forespørsel')}:</b> ${esc(f.tekst)}<br><small>Fra: ${esc([f.navn, f.epost, f.tlf].filter(Boolean).join(' · '))}${f.tid ? ' · ' + esc(f.tid) : ''}</small></div>
      <label class="felt"><small>Svaret ditt</small><textarea id="svartekst" placeholder="Skriv svaret ditt …"></textarea></label>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="button" data-sendsvar>Send svar</button></div>`);
    inn.querySelector('[data-sendsvar]').onclick = async e => {
      const tekst = inn.querySelector('#svartekst').value.trim();
      if (!tekst) { NA.toast('Skriv svaret først.'); return; }
      e.target.disabled = true;
      try { const r = await NA.api('foresporsler.php', {data: {handling: 'svar', id: Number(s.id), tekst}}); NA.lukkArk(true); ferdig(nokkel(s), r.beskjed || 'Svar sendt.'); }
      catch (err) { NA.toast(esc(err.message)); e.target.disabled = false; }
    };
  }
  async function avvisSalg(id) {
    const x = salg.find(v => v.id === id);
    const inn = NA.apneArk(`${NA.arkHode('Avslå salget')}
      <p>${esc(x ? x.medlem + ': ' + x.tittel : '')}</p>
      <label class="felt"><small>Hvorfor (valgfritt)</small><textarea id="grunn"></textarea></label>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp rod" type="button" data-avvis>Avslå</button></div>`);
    inn.querySelector('[data-avvis]').onclick = async e => {
      e.target.disabled = true;
      try { const r = await NA.api('medlemssalg.php', {data: {handling: 'avvis', id, grunn: inn.querySelector('#grunn').value.trim()}}); NA.lukkArk(true); ferdig('salg:' + id, r.beskjed || 'Salget er avslått.'); }
      catch (err) { NA.toast(esc(err.message)); e.target.disabled = false; }
    };
  }
  async function gjor(b) {
    const g = b.dataset.gjor, s = b.dataset.k ? finnSak(b.dataset.k) : null;
    const kall = async (sti, data, tekst) => { b.disabled = true; try { const r = await NA.api(sti, {data}); ferdig(s ? nokkel(s) : b.dataset.nok, r.beskjed || tekst); } catch (e) { NA.toast(esc(e.message)); b.disabled = false; } };
    if (g === 'salg-godkjenn') { b.dataset.nok = 'salg:' + b.dataset.id; return kall('medlemssalg.php', {handling: 'godkjenn', id: Number(b.dataset.id)}, 'Salget er godkjent.'); }
    if (g === 'salg-avvis') return avvisSalg(Number(b.dataset.id));
    if (!s) return;
    const id = Number(s.id);
    switch (g) {
      case 'svar': return svarForesporsel(s);
      case 'besvart': return kall('foresporsler.php', {id, status: 'besvart'}, 'Merket som besvart.');
      case 'soknad':
        if (!await NA.bekreft('Godkjenn søknaden?', 'Godkjenn søknaden fra ' + s.under + '. Betalingen i Vipps settes i gang etter medlemskapet.', 'Godkjenn')) return;
        return kall('soknader.php', {id, vedtak: 'godkjent'}, 'Søknaden er godkjent.');
      case 'frys-godkjenn': return kall('frys.php', {handling: 'godkjenn', id}, 'Frysen er godkjent.');
      case 'frys-avslag': return kall('frys.php', {handling: 'avslag', id}, 'Frysen er avslått.');
      case 'bidrag-godkjenn': return kall('medlemsforslag.php', {handling: 'godkjenn', id, instagram: '0', galleri: '1'}, 'Godkjent til galleriet.');
      case 'bidrag-avvis':
        if (!await NA.bekreft('Avvis', 'Avvis bidraget. Opplastingen slettes.', 'Avvis')) return;
        return kall('medlemsforslag.php', {handling: 'avvis', id}, 'Bidraget er avvist.');
      case 'venteliste': return kall('venteliste.php', {handling: 'varsle', id}, 'Plassen er tilbudt.');
      case 'henting':
        if (!await NA.bekreft('Send «Klar til henting»?', 'Publiser ' + String(s.tittel).replace(/^Klar til henting: /, '') + ' på lissom.no/ferdigbrent og legg beskjed i kø til de som ikke har fått den.', 'Send')) return;
        return kall('ferdigbrent.php', {handling: 'meld-alle', oktId: id}, 'Sendt.');
      case 'bestill': return kall('produkter.php', {handling: 'bestillMer', id}, 'Lagt i handlelista.');
      case 'feil-lost': return kall('feilrapporter.php', {handling: 'status', id, status: 'lukket'}, 'Merket som løst.');
    }
  }
  async function utfort(b) {
    const s = finnSak(b.dataset.utfort); if (!s) return;
    b.disabled = true;
    try {
      for (const id of s.ider || [s.id]) { const r = await NA.api('pamelding.php', {data: {handling: 'sett', id}}); if (!r || !r.lagret) settLegg(id); }
      gjort.add(nokkel(s)); tegn(NA.sist); NA.toast('Merket som utført.');
    } catch (e) { NA.toast(esc(e.message)); b.disabled = false; }
  }

  registrerSide('idag', {
    tittel: 'I dag', ikon: '☀', mobil: true,
    async tegn(side) {
      el = side;
      el.addEventListener('click', e => {
        const b = e.target.closest('button'); if (!b || b.disabled) return;
        if (b.dataset.ovn) trykkOvn(b);
        else if (b.dataset.startkurs) arkStartKurs(Number(b.dataset.startkurs));
        else if (b.dataset.gjor) gjor(b);
        else if (b.dataset.utfort) utfort(b);
      });
      el.innerHTML = '<p class="laster">Henter …</p>';
      await oppdater();
      hentHvert(15000, () => oppdater());
    },
  });
})();
