/* Ny admin — I dag (del A, eieren 08.10.2026). Ovnen, Dagens kurs, Må gjøres og Til info.
 * Alt er ekte data og eksisterende handlinger:
 *   Ovnen          /api/ovn.php (tomt, raabrann, glasurbrann). «Ovn er tømt» = ferdigbrent: velg kursdatoene som er
 *                  klare, og «Klar til henting» sendes med ferdigbrent.php handling=meld-alle (samme utsending som i dag).
 *   Dagens kurs    oversikt.php «kommende» for i dag. Plassene og «ikke betalt» fra kalender.php, samme tall som
 *                  Kalender og Kurs (én definisjon). «Start dagens kurs» bruker kursstart3.php?okt= når bryteren
 *                  Vis/kursstart3 er på, ellers deltakerne fra kalender.php. Ingen kall til kursstart3 i oppdateringen.
 *                  Møtt/Ikke møtt = pamelding.php handling=status, samme regel som kursstart3.js.
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
  let kal = new Map(); // oktId → økta fra kalender.php (i dag)
  let migr = null; // databaseoppdateringer som venter (NA.oppdateringer, api/migrer.php)
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
  async function hentKal(d) {
    if (!dagensOkter(d).length) { kal = new Map(); return; }
    try {
      const k = await NA.api('kalender.php?fra=' + idagIso() + '&til=' + idagIso());
      kal = new Map((k.hendelser || []).filter(h => h.oktId > 0 && !String(h.id).startsWith('saml-')).map(h => [h.oktId, h]));
    } catch { /* tallene fra oversikt.php står da */ }
  }
  const ikkeBetalt = h => (h?.deltakere || []).filter(p => p.status === 'Ikke betalt');

  /* ── tegning ─────────────────────────────────────────────────────────── */
  function tegn(d) {
    if (!el || !el.isConnected) return;
    const t = ovn?.tomt;
    const status = t ? OVN[t.slag] || 'Ovn er tømt' : 'Ingen status';
    const okter = dagensOkter(d);
    const sett = d.settPaaServer ? new Set() : settLes();
    const maa = NA.maGjores(d).filter(s => s.type !== 'taut' && !gjort.has(nokkel(s)));
    const salgIgjen = salg.filter(x => !gjort.has('salg:' + x.id));
    const nMigr = migr?.mangler?.length || 0;
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
            ${okter.length ? okter.map(o => { const h = kal.get(o.oktId); const ub = ikkeBetalt(h).length; const n = h ? h.pameldt : o.pameldte, kap = h ? h.kap : o.kapasitet;
              return `<div class="rad"><div class="tekst"><b>${esc(o.tittel)}</b><span class="muted">${esc(o.klokke)} · ${n} av ${kap} plasser</span></div>${ub ? `<span class="merke rod">${ub} ikke betalt</span>` : (h && n ? '<span class="merke gronn">Alle har betalt</span>' : '')}
                <button class="knapp hoved liten" type="button" data-startkurs="${o.oktId}">Start dagens kurs</button></div>`; }).join('') : '<p class="tom">Ingen kurs i dag.</p>'}
          </section>
        </div>
        <section class="kort" aria-label="Må gjøres">
          <div class="kort-head"><h2>Må gjøres</h2></div>
          ${maa.length || salgIgjen.length || nMigr ? (nMigr ? radMigr(nMigr) : '') + salgIgjen.map(radSalg).join('') + maa.map(radSak).join('') : '<p class="tom">Alt er gjort. Fint!</p>'}
          <div class="kort-head" style="margin:22px 0 4px"><h3>Til info</h3><small>Forsvinner av seg selv etter 3 dager</small></div>
          ${info.length ? info.map(s => `<div class="rad"><div class="tekst"><span class="type">Ny påmelding</span><b>${esc(String(s.tittel).replace(/^Ny påmelding: /, ''))}</b><small>${esc(s.under)}</small></div><button class="knapp liten" type="button" data-utfort="${esc(nokkel(s))}">Utført</button></div>`).join('') : '<p class="tom">Ingen nye.</p>'}
        </section>
      </div>`;
  }
  const TYPE = {henvendelse: 'Forespørsel', soknad: 'Medlemssøknad', frys: 'Frys', bidrag: 'Medlemsbidrag', betaling: 'Betaling', venteliste: 'Venteliste',
    henting: 'Klar til henting', leire: 'Leirebestilling', dugnad: 'Dugnad', lager: 'Lager', chat: 'Chat', innboks: 'Innboks', feil: 'Feilmelding',
    tilbakebetal: 'Avlyst dato'};
  const knapp = (tekst, gjor, hoved, ekstra = '') => `<button class="knapp liten ${hoved ? 'hoved' : ''}" type="button" data-gjor="${gjor}" ${ekstra}>${tekst}</button>`;
  /* Databaseoppdateringer som venter (eieren 09.10.2026). Samme knapp som Innstillinger › Oppdateringer. */
  const radMigr = n => `<div class="rad"><div class="tekst"><span class="type">Vedlikehold</span><b>${n} ${n === 1 ? 'databaseoppdatering venter' : 'databaseoppdateringer venter'}</b><small>${esc(migr.mangler.join(' · '))}</small></div><div class="knapper">${knapp('Kjør oppdateringer', 'migrer', true)}</div></div>`;
  function radSalg(x) {
    return `<div class="rad"><div class="tekst"><span class="type">Medlemssalg</span><b>${esc(x.medlem)} vil selge ${esc(x.tittel)}</b><small>${esc([x.pris, x.antall ? x.antall + ' stk' : '', x.dato].filter(Boolean).join(' · '))}</small></div>
      <div class="knapper">${knapp('Avslå', 'salg-avvis', false, `data-id="${x.id}"`)}${knapp('Godkjenn', 'salg-godkjenn', true, `data-id="${x.id}" data-tittel="${esc(x.tittel)}"`)}</div></div>`;
  }
  function radSak(s) {
    const k = esc(nokkel(s));
    const gammel = `<a class="knapp liten" href="/admin-ny#${esc(String(s.rute || 'idag'))}">I gammel admin</a>`;
    const h = {
      henvendelse: () => knapp('Ferdig', 'besvart', false, `data-k="${k}"`) + knapp('Svar', 'svar', true, `data-k="${k}"`),
      soknad: () => knapp('Godkjenn', 'soknad', true, `data-k="${k}"`),
      frys: () => knapp('Avslå', 'frys-avslag', false, `data-k="${k}"`) + knapp('Godkjenn frys', 'frys-godkjenn', true, `data-k="${k}"`),
      /* Eieren 09.10.2026: alt i ny admin. Bidraget, dugnaden, leira, chatten, innboksen og feilmeldingen åpnes i et ark her. */
      bidrag: () => knapp('Avvis', 'bidrag-avvis', false, `data-k="${k}"`) + knapp('Godkjenn', 'bidrag', true, `data-k="${k}"`),
      betaling: () => NA.erMobil() ? knapp('Til medlemmet', 'medlem-ark', true, `data-k="${k}"`) : `<button class="knapp liten hoved" type="button" data-medlem="${Number(s.id)}">Til medlemmet</button>`,
      dugnad: () => (dugnadTid(s) ? knapp('Avvis timer', 'dugnad', false, `data-k="${k}" data-h="avvis_tid"`) + knapp('Godkjenn timer', 'dugnad', true, `data-k="${k}" data-h="godkjenn_tid"`)
        : knapp('Avslå', 'dugnad', false, `data-k="${k}" data-h="avslaa"`) + knapp('Godkjenn jobb', 'dugnad', true, `data-k="${k}" data-h="godkjenn"`)),
      leire: () => knapp('Åpne bestillingen', 'leire', true, `data-k="${k}"`),
      chat: () => knapp('Marker som lest', 'chat-lest', false, `data-k="${k}"`) + knapp('Svar', 'chat', true, `data-k="${k}"`),
      innboks: () => knapp('Svar', 'innboks', true, `data-k="${k}"`),
      venteliste: () => knapp('Tilby plassen til ' + esc(s.fornavn || 'første på lista'), 'venteliste', true, `data-k="${k}"`),
      henting: () => knapp('Send «Klar til henting»', 'henting', true, `data-k="${k}"`),
      lager: () => knapp('Bestill mer', 'bestill', true, `data-k="${k}"`),
      feil: () => knapp('Løst', 'feil-lost', false, `data-k="${k}"`) + knapp('Les detaljer', 'feil', true, `data-k="${k}"`),
      /* Avlyst dato, betalt kontant/kort i kassa (eieren 09.10.2026): verkstedet gir pengene tilbake og merker det her. */
      tilbakebetal: () => knapp('Betalt tilbake', 'tilbakebetalt', true, `data-k="${k}"`),
    }[s.type] || (() => gammel);
    return `<div class="rad"><div class="tekst"><span class="type">${TYPE[s.type] || esc(s.gruppe || '')}</span><b>${esc(s.tittel)}</b>${s.under ? `<small>${esc(s.under)}</small>` : ''}</div><div class="knapper">${h()}</div></div>`;
  }

  /* ── oppdatering ─────────────────────────────────────────────────────── */
  async function oppdater(tving) {
    const d = await NA.oversikt(tving);
    const [m] = await Promise.all([NA.oppdateringer ? NA.oppdateringer() : null, hentOvn(), hentSalg(d), hentKal(d)]);
    migr = m;
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
  /* Hvem har kommet. Ingen er krysset av på forhånd (eierens regel): hver person får «Møtt» eller «Ikke møtt», og
     «Kurset er i gang» er låst til alle har et valg. En som alt står som «Møtte ikke opp», står med det valget.
     Lagres med pamelding.php handling=status når «Kurset er i gang» trykkes (samme regel som kursstart3.js):
     ikke møtt = ikke_mott, møtt igjen = statusen før (forStatus). */
  async function hentDeltakere(oktId) {
    // Kalenderen sier om «Start kurset i tre steg» (Vis/kursstart3) er på. Er den av, spørres ikke kursstart3.php.
    const k = await NA.api('kalender.php?fra=' + idagIso() + '&til=' + idagIso());
    if (k.brytere?.kursstart3) {
      try {
        const t = await NA.api('kursstart3.php?okt=' + oktId);
        return {tittel: t.okt?.tittel, liste: (t.deltakere || []).map(p => ({id: Number(p.bookingId), navn: p.navn, antall: Number(p.antall) || 1,
          kode: p.statusKode, forStatus: p.forStatus || null, ub: p.status === 'Ikke betalt' && Number(p.skyldigOre) > 0, skyldig: p.skyldig, status: p.status}))};
      } catch (e) {
        if (e.status !== 403) throw e;
      }
    }
    // Av: deltakerne fra kalenderen (samme som Kurs-siden).
    const h = (k.hendelser || []).find(x => x.oktId === oktId);
    const kode = {'Betalt': 'betalt', 'Ikke betalt': 'reservert', 'Møtte ikke opp': 'ikke_mott'};
    return {tittel: h?.tittel, utenForStatus: true, liste: (h?.deltakere || []).map(p => ({id: Number(p.bookingId), navn: p.navn, antall: Number(p.antall) || 1,
      kode: kode[p.status] || 'annet', forStatus: null, ub: p.status === 'Ikke betalt', skyldig: p.belopOre ? kr(p.belopOre) : '', status: p.status}))};
  }
  async function arkStartKurs(oktId) {
    NA.apneArk(NA.arkHode('Start dagens kurs') + '<p class="laster">Henter deltakerne …</p>');
    let k;
    try { k = await hentDeltakere(oktId); }
    catch (e) { NA.apneArk(NA.arkHode('Start dagens kurs') + `<p class="feil">${esc(e.message)}</p>`); return; }
    const o = dagensOkter(NA.sist).find(x => x.oktId === oktId) || {};
    const liste = [...k.liste].sort((a, b) => (a.ub ? 0 : 1) - (b.ub ? 0 : 1) || String(a.navn).localeCompare(String(b.navn), 'nb'));
    const kan = p => ['betalt', 'reservert', 'ikke_mott'].includes(p.kode);
    const valg = new Map(liste.filter(p => p.kode === 'ikke_mott').map(p => [p.id, 'nei']));
    const mottKan = p => !(p.kode === 'ikke_mott' && !p.forStatus);
    const inn = NA.apneArk(`${NA.arkHode(esc((k.tittel || o.tittel || 'Kurset') + (o.klokke ? ' · ' + o.klokke : '')))}
      <p class="muted">Velg «Møtt» eller «Ikke møtt» for hver. De som ikke har betalt står øverst.</p>
      <div>${liste.length ? liste.map(p => `<div class="rad"><div class="tekst"><b>${esc(p.navn)}${p.antall > 1 ? ' · ' + p.antall + ' plasser' : ''}</b>
          ${p.ub ? `<span class="merke rod">Ikke betalt${p.skyldig ? ' · ' + esc(p.skyldig) : ''}</span>` : `<span class="merke ${p.status === 'Betalt' ? 'gronn' : ''}">${esc(p.status)}</span>`}</div>
          ${kan(p) ? `<div class="mott-valg" role="group" aria-label="Oppmøte for ${esc(p.navn)}">
            <button type="button" data-mott="${p.id}" data-v="ja" aria-pressed="false" ${mottKan(p) ? '' : 'disabled title="Endres på kurssiden"'}>Møtt</button>
            <button type="button" class="nei" data-mott="${p.id}" data-v="nei" aria-pressed="${valg.get(p.id) === 'nei'}">Ikke møtt</button></div>` : ''}
          ${p.ub && !NA.erMobil() ? `<button class="knapp liten" type="button" data-kasse="${p.id}">Ta betalt</button>` : ''}</div>`).join('') : '<p class="tom">Ingen påmeldte.</p>'}</div>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="button" data-igang disabled>Kurset er i gang</button></div>`);
    const igang = inn.querySelector('[data-igang]');
    const sjekk = () => { igang.disabled = !liste.filter(kan).every(p => valg.has(p.id)); };
    sjekk();
    inn.onclick = async e => {
      const b = e.target.closest('button'); if (!b || b.disabled) return;
      if (b.dataset.mott) {
        const id = Number(b.dataset.mott); valg.set(id, b.dataset.v);
        inn.querySelectorAll(`[data-mott="${id}"]`).forEach(x => x.setAttribute('aria-pressed', String(x === b)));
        return sjekk();
      }
      // «Ta betalt»: kassa med personen og det som står igjen i kurven (eieren 09.10.2026).
      if (b.dataset.kasse) { NA.tilKassa(Number(b.dataset.kasse)); return; }
      if (b.dataset.igang === undefined) return;
      b.disabled = true;
      const feil = [];
      let endret = 0;
      for (const p of liste.filter(kan)) {
        const v = valg.get(p.id);
        const status = v === 'nei' && p.kode !== 'ikke_mott' ? 'ikke_mott' : (v === 'ja' && p.kode === 'ikke_mott' ? p.forStatus : null);
        if (!status) continue;
        try { await NA.api('pamelding.php', {data: {handling: 'status', id: p.id, status}}); endret++; }
        catch (err) { feil.push(p.navn + ': ' + err.message); }
      }
      NA.lukkArk(true);
      NA.toast(`<b>Kurset er i gang.</b>${endret ? ' Oppmøtet er lagret.' : ''}${feil.length ? ' ' + esc(feil.join(' ')) : ''}`);
      oppdater(true).catch(() => {});
    };
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
  /* ── Alt i ny admin (eieren 09.10.2026) ─────────────────────────────────
     Samme endepunkter og regler som admin-ny, ingen egne kopier:
       Medlemsbidrag  medlemsforslag.php (godkjenn {instagram, galleri, tekst}, avvis, mal, bruk-bilde) og
                      gemini.php forbedreForslag — som markedstillegg.js og bildeforslag.js. Ingenting er valgt på forhånd.
       Dugnad         dugnad.php godkjenn/avslaa/godkjenn_tid/avvis_tid med «Svar til medlemmet» — som volunteering().
       Leirebestilling handlelister.php (leire: frist, bestill, krav, pris) — som leirebestilling.js.
       Chat           /api/chat.php (tråden, svar, lest) — som chat.js.
       Innboks        meta.php kommentarer/svarKommentar/nyttForslag/ikkeSvar — som inbox() i marked.js.
       Feilmelding    feilrapporter.php — samme visning som «Les detaljer» i Feilmeldinger. */
  const feilArk = (tittel, e) => NA.apneArk(NA.arkHode(esc(tittel)) + `<p class="feil">${esc(e.message || e)}</p>`);
  const laster = tittel => NA.apneArk(NA.arkHode(esc(tittel)) + '<p class="laster">Henter …</p>');
  /* Bekreftelsen i samme ark (arket lukkes ikke imellom, så Avbryt går tilbake til saken). Samme ordlyd som NA.bekreft. */
  const sporHer = (tittel, tekst, ja) => new Promise(svar => {
    const inn = NA.apneArk(`${NA.arkHode(esc(tittel))}<p>${esc(tekst)}</p><div class="ark-fot"><button class="knapp" type="button" data-nei>Avbryt</button><button class="knapp hoved" type="button" data-ja>${esc(ja)}</button></div>`);
    inn.onclick = e => { const b = e.target.closest('button'); if (!b) return; if (b.dataset.ja !== undefined) svar(true); else if (b.dataset.nei !== undefined) svar(false); };
  });
  const dugnadTid = s => (s.status ? s.status !== 'venter' : /timer/i.test(String(s.tittel)));

  /* Medlemsbidrag: bildet (eller videoen), medlemmets tekst, hvor det skal ut, bildeteksten og den faste linja. */
  async function arkBidrag(s, tilstand = null) {
    if (!tilstand) laster('Medlemsbidrag');
    let d;
    try { d = await NA.api('medlemsforslag.php'); } catch (e) { feilArk('Medlemsbidrag', e); return; }
    const r = (d.venter || []).find(x => x.id === Number(s.id));
    if (!r) { NA.apneArk(NA.arkHode('Medlemsbidrag') + '<p class="tom">Alt er gjort. Fint!</p>'); gjort.add(nokkel(s)); oppdater(true).catch(() => {}); return; }
    const t = tilstand || {instagram: false, galleri: false, tekst: r.bildetekst, nytt: null};
    const bilde = r.type === 'bilde';
    const inn = NA.apneArk(`${NA.arkHode('Medlemsbidrag · ' + esc(r.navn))}
      ${bilde ? `<img class="ark-bilde" src="${esc(r.fil)}" alt="${esc(r.tittel || 'Medlemmets keramikk')}">` : `<video class="ark-bilde" src="${esc(r.fil)}" controls playsinline></video>`}
      ${r.tekst ? `<div class="sms">${esc(r.tekst)}${r.instagram ? `<br><small>${esc(r.instagram)}</small>` : ''}</div>` : ''}
      ${bilde ? (t.nytt ? `<div class="kort-head" style="margin-top:14px"><h3>Nytt forslag</h3><small>Kostnad: ${esc(t.nytt.kostnad)}</small></div>
          <img class="ark-bilde" src="${esc(t.nytt.url.startsWith('/') ? t.nytt.url : '/' + t.nytt.url)}" alt="${esc(r.tittel || 'Medlemmets keramikk')}">
          <div class="valgknapper"><button class="knapp hoved" type="button" data-bruk>Bruk det nye bildet</button></div>`
        : '<div class="valgknapper" style="margin-top:10px"><button class="knapp" type="button" data-forbedre>Forbedre bildet</button></div>') : ''}
      <div style="margin-top:14px">
        <label class="velg-rad"><input type="checkbox" data-hvor="instagram" ${t.instagram ? 'checked' : ''}><span><b>Legg ut på Instagram</b></span></label>
        ${bilde ? `<label class="velg-rad"><input type="checkbox" data-hvor="galleri" ${t.galleri ? 'checked' : ''}><span><b>Galleriet på forsida</b></span></label>` : ''}
      </div>
      <label class="felt"><small>Bildetekst</small><textarea id="bd-tekst">${esc(t.tekst)}</textarea></label>
      <details style="margin-top:10px"><summary>Fast bildetekst</summary>
        <label class="felt"><small>Tekst</small><textarea id="bd-mal" data-ikke-endret>${esc(d.mal || '')}</textarea></label>
        <div class="valgknapper"><button class="knapp" type="button" data-mal>Lagre</button></div></details>
      <div class="ark-fot"><button class="knapp rod" type="button" data-avvis>Avvis</button><button class="knapp hoved" type="button" data-godkjenn ${t.instagram || t.galleri ? '' : 'disabled'}>Godkjenn</button></div>`);
    const les = () => ({instagram: !!inn.querySelector('[data-hvor="instagram"]')?.checked, galleri: !!inn.querySelector('[data-hvor="galleri"]')?.checked,
      tekst: inn.querySelector('#bd-tekst').value, nytt: t.nytt});
    inn.onchange = () => { const v = les(); inn.querySelector('[data-godkjenn]').disabled = !v.instagram && !v.galleri; };
    inn.onclick = async e => {
      const b = e.target.closest('button'); if (!b || b.disabled) return;
      if (b.dataset.godkjenn !== undefined) {
        const v = les();
        if (!v.instagram && !v.galleri) { NA.toast('Velg hvor innholdet skal vises.'); return; }
        if (!await sporHer('Publiser innholdet?', 'Publiser innholdet fra ' + r.navn + ' ' + [v.instagram ? 'på Instagram' : null, v.galleri ? 'i galleriet' : null].filter(Boolean).join(' og ') + '.', 'Publiser')) return arkBidrag(s, v);
        laster('Medlemsbidrag');
        try {
          const svar = await NA.api('medlemsforslag.php', {data: {handling: 'godkjenn', id: r.id, instagram: v.instagram ? '1' : '0', galleri: v.galleri ? '1' : '0', ...(v.instagram ? {tekst: v.tekst} : {})}});
          NA.lukkArk(true); ferdig(nokkel(s), svar.beskjed || 'Godkjent.');
        } catch (err) { NA.toast(esc(err.message)); arkBidrag(s, v); }
        return;
      }
      if (b.dataset.avvis !== undefined) {
        const v = les();
        if (!await sporHer('Avvis', 'Avvis bidraget. Opplastingen slettes.', 'Avvis')) return arkBidrag(s, v);
        try { const svar = await NA.api('medlemsforslag.php', {data: {handling: 'avvis', id: r.id}}); NA.lukkArk(true); ferdig(nokkel(s), svar.beskjed || 'Bidraget er avvist.'); }
        catch (err) { NA.toast(esc(err.message)); arkBidrag(s, v); }
        return;
      }
      if (b.dataset.mal !== undefined) {
        const tekst = inn.querySelector('#bd-mal').value.trim();
        if (!tekst) { NA.toast('Den faste teksten kan ikke være tom.'); return; }
        b.disabled = true;
        try { await NA.api('medlemsforslag.php', {data: {handling: 'mal', tekst}}); NA.toast('Lagret.'); } catch (err) { NA.toast(esc(err.message)); }
        b.disabled = false;
        return;
      }
      if (b.dataset.forbedre !== undefined) {
        const v = les();
        if (!await sporHer('Lag bildeforslag?', 'Lag et nytt bilde av ' + r.navn + ' sitt bidrag med AI. Dette bruker AI-budsjettet. Du velger etterpå om bildet skal brukes.', 'Lag forslag')) return arkBidrag(s, v);
        laster('Medlemsbidrag');
        try { const g = await NA.api('gemini.php', {data: {handling: 'forbedreForslag', id: r.id}}); arkBidrag(s, {...v, nytt: {url: g.url, kostnad: g.kostnad}}); }
        catch (err) { NA.toast(esc(err.message)); arkBidrag(s, v); }
        return;
      }
      if (b.dataset.bruk !== undefined) {
        const v = les();
        b.disabled = true;
        try { const svar = await NA.api('medlemsforslag.php', {data: {handling: 'bruk-bilde', id: r.id, url: t.nytt.url}}); NA.toast(esc(svar.beskjed || 'Bildet er byttet.')); arkBidrag(s, {...v, nytt: null}); }
        catch (err) { NA.toast(esc(err.message)); b.disabled = false; }
      }
    };
  }

  /* Dugnad: godkjenn eller avslå jobben, godkjenn eller avvis timene. «Svar til medlemmet» som i admin-ny. */
  const DUGNAD = {godkjenn: ['Godkjenn jobb', true], avslaa: ['Avslå', false], godkjenn_tid: ['Godkjenn timer', true], avvis_tid: ['Avvis timer', false]};
  async function arkDugnad(s, h) {
    const [tittel, hoved] = DUGNAD[h] || DUGNAD.godkjenn;
    laster(tittel);
    let r;
    try { r = ((await NA.api('dugnad.php')).dugnader || []).find(x => x.id === Number(s.id)); } catch (e) { feilArk(tittel, e); return; }
    if (!r) { NA.apneArk(NA.arkHode(esc(tittel)) + '<p class="tom">Alt er gjort. Fint!</p>'); gjort.add(nokkel(s)); oppdater(true).catch(() => {}); return; }
    const tid = [r.dag, r.inn && r.ut ? r.inn + '–' + r.ut : r.inn, r.varighet].filter(Boolean).join(' · ');
    const inn = NA.apneArk(`${NA.arkHode(esc(tittel))}
      <div class="sms"><b>${esc(r.navn)}:</b> ${esc(r.tekst)}${tid ? `<br><small>${esc(tid)}</small>` : ''}</div>
      ${h === 'godkjenn_tid' ? `<label class="felt"><small>Godkjente timer</small><input id="dg-timer" type="number" min="0.25" step="0.25" value="${esc(String(r.forslagTimer).replace(',', '.'))}"></label>` : ''}
      <label class="felt"><small>Svar til medlemmet</small><textarea id="dg-svar">${esc(r.svar)}</textarea></label>
      <p class="muted">${r.epost ? 'Medlemmet får e-post om dette.' : 'Medlemmet har ingen e-post — svaret står på Min side.'}</p>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp ${hoved ? 'hoved' : 'rod'}" type="button" data-dg>${esc(tittel)}</button></div>`);
    inn.querySelector('[data-dg]').onclick = async e => {
      const data = {handling: h, id: r.id, svar: inn.querySelector('#dg-svar').value.trim()};
      if (h === 'godkjenn_tid') data.timer = inn.querySelector('#dg-timer').value;
      e.target.disabled = true;
      try { const svar = await NA.api('dugnad.php', {data}); NA.lukkArk(true); ferdig(nokkel(s), svar.beskjed || 'Oppdatert.'); }
      catch (err) { NA.toast(esc(err.message)); e.target.disabled = false; }
    };
  }

  /* Neste leirebestilling: frist, per leverandør varene og hvem som har bestilt, frakten, «Send bestilling» og «Krev inn med Vipps». */
  async function arkLeire(s) {
    laster('Neste leirebestilling');
    let L;
    try { L = (await NA.api('handlelister.php')).leire; } catch (e) { feilArk('Neste leirebestilling', e); return; }
    if (!L) { feilArk('Neste leirebestilling', 'Kunne ikke hente opplysningene.'); return; }
    if (!L.neste.length) { gjort.add(nokkel(s)); oppdater(true).catch(() => {}); }
    const vare = v => `<div class="rad"><div class="tekst"><b>${esc(v.navn)}</b><small>${esc([v.antall + ' stk.', v.pris ? v.pris + ' per stk.' : ''].filter(Boolean).join(' · '))}</small>
        <span>${v.hvem.map(x => `<span class="merke">${esc(x.navn + ' ' + x.antall)}</span>`).join(' ')}</span></div>
        ${v.pris ? '' : `<span class="merke gul">Pris mangler</span><label class="felt" style="max-width:150px"><small>Pris per stk. (kr)</small><input type="number" min="0" step="0.01" inputmode="decimal" data-pris="${Number(v.produktId)}"></label><button class="knapp liten" type="button" data-lagrepris="${Number(v.produktId)}">Lagre</button>`}</div>`;
    const inn = NA.apneArk(`${NA.arkHode('Neste leirebestilling')}
      <div class="rad"><label class="felt" style="flex:1"><small>Frist</small><input type="date" id="lb-frist" value="${esc(L.frist.dato)}"></label>
        <button class="knapp liten" type="button" data-frist>${L.frist.dato ? 'Endre frist' : 'Sett frist'}</button></div>
      ${L.neste.length ? L.neste.map(r => `<section data-leverandor="${Number(r.id)}" style="margin-top:16px"><h3>${esc(r.navn)}</h3>${r.varer.map(vare).join('')}
          <p class="muted">${r.frakt ? esc(`Frakt ${r.frakt}, delt på ${r.deltPaa}.`) : 'Frakt er ikke satt.'}</p>
          <div class="valgknapper"><button class="knapp hoved" type="button" data-bestill="${Number(r.id)}">Send bestilling</button></div></section>`).join('')
        : '<p class="tom">Ingen varer venter på neste bestilling.</p>'}
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button>${L.neste.length ? '<button class="knapp" type="button" data-krav>Krev inn med Vipps</button>' : ''}</div>`);
    const lagre = async (b, data) => {
      b.disabled = true;
      try { const r = await NA.api('handlelister.php', {data}); NA.toast(esc(r.beskjed || 'Oppdatert.')); arkLeire(s); }
      catch (err) { NA.toast(esc(err.message)); b.disabled = false; }
    };
    inn.onclick = async e => {
      const b = e.target.closest('button'); if (!b || b.disabled) return;
      if (b.dataset.frist !== undefined) return lagre(b, {handling: 'frist', dato: inn.querySelector('#lb-frist').value || ''});
      if (b.dataset.lagrepris) {
        const v = inn.querySelector(`[data-pris="${b.dataset.lagrepris}"]`).value.trim();
        if (v === '') { NA.toast('Skriv prisen først.'); return; }
        return lagre(b, {handling: 'pris', produktId: Number(b.dataset.lagrepris), kroner: v});
      }
      if (b.dataset.bestill) {
        const r = L.neste.find(x => x.id === Number(b.dataset.bestill)); if (!r) return;
        if (!await sporHer('Send bestilling', `Send bestillingen til ${r.navn} (${r.epost}). Linjene markeres som bestilt.`, 'Send bestilling')) return arkLeire(s);
        laster('Neste leirebestilling');
        try { const svar = await NA.api('handlelister.php', {data: {handling: 'bestill', leverandorId: r.id}}); NA.toast(esc(svar.beskjed || 'Oppdatert.')); }
        catch (err) { NA.toast(esc(err.message)); }
        return arkLeire(s);
      }
      if (b.dataset.krav !== undefined) {
        if (!await sporHer('Krev inn med Vipps', 'Send betalingskrav til medlemmene som ikke allerede har fått krav. Kontroller priser og frakt først.', 'Send betalingskrav')) return arkLeire(s);
        laster('Neste leirebestilling');
        let svar;
        try { svar = await NA.api('handlelister.php', {data: {handling: 'krav'}}); }
        catch (err) { NA.toast(esc(err.message)); return arkLeire(s); }
        return kravResultat(s, svar);
      }
    };
  }

  /* Resultatet av «Krev inn med Vipps»: hvem fikk krav, hvem ikke og hvorfor (kontrolløren 09.10.2026). */
  function kravResultat(s, svar) {
    const sendt = svar.sendt || [], feilet = svar.feilet || [];
    const inn = NA.apneArk(`${NA.arkHode('Krev inn med Vipps')}
      ${sendt.length ? `<h3>Krav sendt</h3>${sendt.map(n => `<div class="rad" data-sendt><div class="tekst"><b>${esc(n)}</b></div><span class="merke gronn">Sendt</span></div>`).join('')}` : ''}
      ${feilet.length ? `<h3>Ikke sendt</h3>${feilet.map(f => `<div class="rad" data-feilet><div class="tekst"><b>${esc(f.navn)}</b><small>${esc(f.grunn)}</small></div><span class="merke rod">Ikke sendt</span></div>`).join('')}` : ''}
      ${sendt.length || feilet.length ? '' : '<p class="tom">Ingen nye krav å sende. Alle med pris har fått krav.</p>'}
      <div class="ark-fot"><button class="knapp hoved" type="button" data-tilbake>Tilbake til bestillingen</button></div>`);
    inn.querySelector('[data-tilbake]').onclick = () => arkLeire(s);
  }

  /* Medlemschatten: tråden, svarfeltet og «lest» (det som står framme er lest, som i chat.js). */
  async function arkChat(s) {
    laster('Medlemschat');
    let d;
    try { d = await NA.api('/api/chat.php'); } catch (e) { feilArk('Medlemschat', e); return; }
    const lest = siste => NA.api('/api/chat.php', {data: {handling: 'lest', siste}}).then(() => { gjort.add(nokkel(s)); }).catch(() => {});
    const melding = m => `<div class="chat-m ${m.egen ? 'egen' : ''}"><small>${esc(m.navn)} ${esc(m.tid)}</small><span${m.slettet ? ' class="slettet"' : ''}>${esc(m.tekst)}</span></div>`;
    const inn = NA.apneArk(`${NA.arkHode('Medlemschat')}
      <div class="chat-trad" id="chat-trad">${(d.meldinger || []).map(melding).join('') || '<p class="tom">Ingen meldinger.</p>'}</div>
      <label class="felt"><small>Synlig for alle medlemmer</small><textarea id="chat-tekst" maxlength="500" placeholder="Skriv"></textarea></label>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="button" data-send>Send</button></div>`);
    const trad = inn.querySelector('#chat-trad');
    trad.scrollTop = trad.scrollHeight;
    lest(d.siste || 0);
    inn.querySelector('[data-send]').onclick = async e => {
      const felt = inn.querySelector('#chat-tekst'), tekst = felt.value.trim();
      if (!tekst) { felt.focus(); return; }
      e.target.disabled = true;
      try {
        const r = await NA.api('/api/chat.php', {data: {tekst}});
        felt.value = ''; NA.arkEndret(false);
        const ny = await NA.api('/api/chat.php');
        trad.innerHTML = (ny.meldinger || []).map(melding).join('');
        trad.scrollTop = trad.scrollHeight;
        lest(ny.siste || r.siste || 0);
      } catch (err) { NA.toast(esc(err.message)); }
      e.target.disabled = false;
    };
  }

  /* Innboksen: kommentarene som venter, med AI-forslaget i svarfeltet. «Svar» legger svaret ut, «Ferdig» lar den stå uten svar. */
  async function arkInnboks(s) {
    laster('Innboks · SoMe');
    let r;
    try { r = await NA.api('meta.php', {data: {handling: 'kommentarer'}}); } catch (e) { feilArk('Innboks · SoMe', e); return; }
    const venter = (r.poster || []).filter(c => c.status === 'venter');
    if (!venter.length) { gjort.add(nokkel(s)); oppdater(true).catch(() => {}); }
    const aiPaa = r.autosvarPaa !== false;
    const inn = NA.apneArk(`${NA.arkHode('Innboks · SoMe')}
      ${aiPaa && r.aiFeil ? '<p class="feil">AI-svarene står stille: AI svarer ikke nå. Kommentarene venter til det virker igjen.</p>' : ''}
      ${venter.length ? venter.map((c, i) => `<section class="kort" style="margin-top:12px" data-i="${i}">
          <span class="type">${esc(c.kanal)}</span><b>${esc(c.navn || c.fra || 'Kommentar')}</b>${c.paa ? `<small class="muted"> · På: ${esc(c.paa)}</small>` : ''}
          <p>${esc(c.tekst || c.message || '')}</p>
          <label class="felt"><small>Forslag fra AI</small><textarea data-svar="${i}">${esc(c.forslag || '')}</textarea></label>
          <div class="valgknapper"><button class="knapp hoved" type="button" data-publiser="${i}">Svar</button>${aiPaa ? `<button class="knapp" type="button" data-nytt="${i}">Nytt forslag</button>` : ''}<button class="knapp" type="button" data-ferdig="${i}">Ferdig</button></div></section>`).join('')
        : '<p class="tom">Alt er gjort. Fint!</p>'}`);
    inn.onclick = async e => {
      const b = e.target.closest('button'); if (!b || b.disabled) return;
      const i = b.dataset.publiser ?? b.dataset.nytt ?? b.dataset.ferdig; if (i === undefined) return;
      const c = venter[Number(i)]; const felt = inn.querySelector(`[data-svar="${i}"]`);
      if (b.dataset.nytt !== undefined) {
        b.disabled = true;
        try { const d = await NA.api('meta.php', {data: {handling: 'nyttForslag', id: c.id}}); felt.value = d.forslag || ''; } catch (err) { NA.toast(esc(err.message)); }
        b.disabled = false; return;
      }
      if (b.dataset.ferdig !== undefined) {
        b.disabled = true;
        try { await NA.api('meta.php', {data: {handling: 'ikkeSvar', id: c.id, kanal: c.kanal}}); } catch (err) { NA.toast(esc(err.message)); }
        return arkInnboks(s);
      }
      const tekst = felt.value.trim();
      if (!tekst) { felt.focus(); return; }
      if (!await sporHer('Publiser svar?', `Legg svaret ut på ${c.kanal}.`, 'Publiser svar')) return arkInnboks(s);
      laster('Innboks · SoMe');
      try { const d = await NA.api('meta.php', {data: {handling: 'svarKommentar', id: c.id, kanal: c.kanal, tekst}}); NA.toast(esc(d.beskjed || 'Oppdatert.')); }
      catch (err) { NA.toast(esc(err.message)); }
      arkInnboks(s);
    };
  }

  /* Feilmeldingen slik den kom inn: meldingen, feilteksten, siden, skjermen og skjermbildet. */
  async function arkFeil(s) {
    laster('Feilrapport');
    let r;
    try { r = ((await NA.api('feilrapporter.php')).rapporter || []).find(x => x.id === Number(s.id)); } catch (e) { feilArk('Feilrapport', e); return; }
    if (!r) { NA.apneArk(NA.arkHode('Feilrapport') + '<p class="tom">Alt er gjort. Fint!</p>'); gjort.add(nokkel(s)); oppdater(true).catch(() => {}); return; }
    const inn = NA.apneArk(`${NA.arkHode('Feilrapport')}
      ${r.melding ? `<div class="sms">«${esc(r.melding)}»</div>` : ''}
      ${r.feiltekst ? `<pre style="white-space:pre-wrap;overflow-wrap:anywhere">${esc(r.feiltekst)}</pre>` : ''}
      <p class="muted">${esc([r.side, r.nettleser, r.skjerm].filter(Boolean).join(' · '))}</p>
      ${r.navn || r.kontakt ? `<p class="muted">${esc([r.navn, r.kontakt].filter(Boolean).join(' · '))}</p>` : ''}
      ${r.bilde ? `<img class="ark-bilde" src="${esc(r.bilde)}" alt="Vedlagt skjermbilde">` : ''}
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="button" data-lost>Løst</button></div>`);
    inn.querySelector('[data-lost]').onclick = async e => {
      e.target.disabled = true;
      try { const svar = await NA.api('feilrapporter.php', {data: {handling: 'status', id: r.id, status: 'lukket'}}); NA.lukkArk(true); ferdig(nokkel(s), svar.beskjed || 'Merket som løst.'); }
      catch (err) { NA.toast(esc(err.message)); e.target.disabled = false; }
    };
  }

  async function gjor(b) {
    const g = b.dataset.gjor, s = b.dataset.k ? finnSak(b.dataset.k) : null;
    const kall = async (sti, data, tekst) => { b.disabled = true; try { const r = await NA.api(sti, {data}); ferdig(s ? nokkel(s) : b.dataset.nok, r.beskjed || tekst); } catch (e) { NA.toast(esc(e.message)); b.disabled = false; } };
    if (g === 'salg-godkjenn') {
      // Samme bekreftelse som admin-ny (kontrolløren 09.10.2026).
      if (!await NA.bekreft('Godkjenn og publiser', `Publiser varen ${b.dataset.tittel || ''}. Selgeren kan få beskjed.`, 'Godkjenn og publiser')) return;
      b.dataset.nok = 'salg:' + b.dataset.id; return kall('medlemssalg.php', {handling: 'godkjenn', id: Number(b.dataset.id)}, 'Salget er godkjent.'); }
    if (g === 'salg-avvis') return avvisSalg(Number(b.dataset.id));
    if (g === 'migrer') { b.disabled = true; await NA.kjorOppdateringer(); return oppdater(true).catch(() => {}); }
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
      case 'bidrag': return arkBidrag(s);
      case 'medlem-ark': return NA.arkMedlem ? NA.arkMedlem(id) : NA.gaTil('medlemmer', {person: id});
      case 'dugnad': return arkDugnad(s, b.dataset.h);
      case 'leire': return arkLeire(s);
      case 'chat': return arkChat(s);
      case 'chat-lest': return kall('/api/chat.php', {handling: 'lest', siste: Number(s.siste) || 0}, 'Merket som lest.');
      case 'innboks': return arkInnboks(s);
      case 'feil': return arkFeil(s);
      case 'bidrag-avvis':
        if (!await NA.bekreft('Avvis', 'Avvis bidraget. Opplastingen slettes.', 'Avvis')) return;
        return kall('medlemsforslag.php', {handling: 'avvis', id}, 'Bidraget er avvist.');
      case 'venteliste': return kall('venteliste.php', {handling: 'varsle', id}, 'Plassen er tilbudt.');
      case 'henting':
        if (!await NA.bekreft('Send «Klar til henting»?', 'Publiser ' + String(s.tittel).replace(/^Klar til henting: /, '') + ' på lissom.no/ferdigbrent og legg beskjed i kø til de som ikke har fått den.', 'Send')) return;
        return kall('ferdigbrent.php', {handling: 'meld-alle', oktId: id}, 'Sendt.');
      case 'bestill': return kall('produkter.php', {handling: 'bestillMer', id}, 'Lagt i handlelista.');
      case 'feil-lost': return kall('feilrapporter.php', {handling: 'status', id, status: 'lukket'}, 'Merket som løst.');
      case 'tilbakebetalt':
        if (!await NA.bekreft('Betalt tilbake?', String(s.tittel) + '. Merk saken som betalt tilbake når pengene er gitt.', 'Betalt tilbake')) return;
        return kall('pamelding.php', {handling: 'tilbakebetalt', id}, 'Merket som betalt tilbake.');
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
