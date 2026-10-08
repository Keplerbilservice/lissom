/* Ny admin — felles skall (eieren 08.10.2026, GO på «samlet forslag»). Ved siden av /admin-ny, som ikke endres.
 *
 * FELLES-API (globale funksjoner, også under window.NA). Sidefilene i ny-admin/ er vanlige skript (ikke moduler):
 *
 *   registrerSide(id, {tittel, ikon, tegn(el, params), mobil})
 *       id: idag | kalender | kurs | medlemmer | varer | mer | medlemssiden | innstillinger (eller en ny).
 *       tegn(el, params): fyll el (tom <div>). params = URLSearchParams fra adressen (#kurs?okt=12 → params.get('okt')).
 *       Kan være async. Kalles hver gang siden åpnes. mobil:true = siden brukes også på mobil (≤760 px).
 *   api(sti, {metode, data})
 *       sti: 'kurs.php' (= /api/admin/kurs.php) eller absolutt '/api/ovn.php'. data gir POST med JSON.
 *       Svarer JSON. Kaster Error med .status og .data. 401 viser innloggingen.
 *   apneArk(html | Node, {bred}) → arkets innerste element (#ark-inn; onclick/onchange nullstilles ved hvert nytt ark).
 *       [data-lukk] lukker. Tekst som skrives i arket gjør det «endret» (ikke avkrysning; [data-ikke-endret] slipper).
 *   lukkArk(tving)  lukker; spør «Vil du forkaste?» når noe er skrevet, med mindre tving = true.
 *   toast(html)     kort beskjed nederst. Tekst fra data: NA.esc(tekst).
 *   hentHvert(ms, fn)  kjører fn nå, hvert ms når fanen er synlig, og når fanen får fokus igjen.
 *       Stoppes av seg selv når man bytter side. Returnerer stopp().
 *
 *   NA.gaTil(id, params) · NA.params() · NA.tilbakeKnapp(standardId) · NA.esc(t) · NA.kr(ore) · NA.erMobil()
 *   NA.meg ({navn, id}) · NA.oversikt(tving) (api/admin/oversikt.php, delt mellom topplinja og sidene, 5 s mellomlager)
 *   NA.oppdaterTopp() · NA.arkApen() · NA.arkEndret(bool) · NA.arkHode(tittelHtml) · NA.bekreft(tittel, tekst, ja) → Promise<bool>
 *   NA.maGjores(d) · NA.antallMaa(d) · NA.uttakLeire() · NA.skisse() · NA.hurtig()
 *   ny-admin/.htaccess: «no-cache» på .js/.css, så en ny utgave vises med en gang. Sidefilene hentes med fetch og kjøres.
 *
 * Felles klasser i ny-admin.css: .kort .kort-head .rad .tekst .knapper .knapp(.hoved .liten .rod) .merke(.rod .gul .gronn)
 *   .type .grid .faner .bryter .valgknapper .felt .sms .tom .ark-head .ark-fot .lukk .hgrupper .hgruppe .bare-stor .bare-mobil
 */
(function () {
  'use strict';
  const $ = s => document.querySelector(s);
  const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const kr = ore => Math.round((Number(ore) || 0) / 100).toLocaleString('nb-NO') + ' kr';
  const erMobil = () => innerWidth <= 760;

  /* Sidefilene (del A, B og C). Lastes etter tur; mangler en fil, står siden som «ikke bygd ennå». */
  const SIDEFILER = ['idag', 'kalender', 'kurs', 'medlemmer', 'medlemssiden', 'varer', 'innstillinger', 'mer'];
  const MYNT = '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-5px"><ellipse cx="12" cy="16" rx="8" ry="3.5" fill="#e0a800" stroke="currentColor" stroke-width="1.4"/><rect x="4" y="11" width="16" height="5" fill="#e0a800"/><path d="M4 11v5M20 11v5" stroke="currentColor" stroke-width="1.4"/><ellipse cx="12" cy="11" rx="8" ry="3.5" fill="#ffcf38" stroke="currentColor" stroke-width="1.4"/><ellipse cx="12" cy="11" rx="4.5" ry="1.8" fill="none" stroke="currentColor" stroke-width="1" opacity=".5"/></svg>';
  const MENY = [['idag', 'I dag', '☀'], ['kalender', 'Kalender', '▦'], ['kurs', 'Kurs', '◍'], ['medlemmer', 'Medlemmer', '☺'], ['varer', 'Varer', '◇'], ['kasse', 'Kasse', MYNT], ['mer', 'Mer', '…']];
  const BUNN = [['medlemssiden', 'Medlemssiden', '👁'], ['innstillinger', 'Innstillinger', '⚙']];
  const MOBIL = ['idag', 'kalender', 'kurs'];
  const HURTIG = [['skisse', 'Skisseverktøy', '✎'], ['uttak', 'Uttak leire', '◼']];
  const HURTIG_NOKKEL = 'na-hurtig';

  const sider = new Map();
  let toppPoll = null, liveTimer = null, klar = false;
  let rute = {id: 'idag', params: new URLSearchParams()};
  let forrige = null;
  let sidePollere = [];
  let tegnTeller = 0;
  const NA = window.NA = {esc, kr, erMobil, meg: null, sist: null};

  /* ── api ─────────────────────────────────────────────────────────────── */
  async function api(sti, {metode, data} = {}) {
    const url = /^\//.test(sti) ? sti : '/api/admin/' + sti;
    const m = metode || (data !== undefined ? 'POST' : 'GET');
    const r = await fetch(url, {credentials: 'same-origin', cache: 'no-store', method: m,
      ...(m !== 'GET' ? {headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data || {})} : {})});
    let d;
    try { d = await r.json(); } catch { throw Object.assign(Error('Serveren svarte ikke som forventet. Prøv igjen.'), {status: r.status}); }
    if (r.status === 401 && !/logg-inn\.php$/.test(url)) visInnlogging();
    if (!r.ok || d.ok === false) throw Object.assign(Error(d.feil || 'Kunne ikke hente opplysningene.'), {status: r.status, data: d});
    return d;
  }

  /* ── ark ─────────────────────────────────────────────────────────────── */
  let endret = false;
  const ark = () => $('#ark');
  function apneArk(innhold, {bred = false} = {}) {
    const inn = $('#ark-inn');
    inn.onclick = null; inn.onchange = null;
    if (typeof innhold === 'string') inn.innerHTML = innhold; else inn.replaceChildren(innhold);
    ark().classList.toggle('bred', !!bred);
    endret = false;
    if (!ark().open) ark().showModal();
    const forste = inn.querySelector('input:not([type=checkbox]),textarea,select');
    if (forste && !erMobil()) forste.focus();
    return inn;
  }
  function lukkArk(tving) {
    const d = ark();
    if (!d.open) return true;
    if (endret && !tving) {
      if ($('#forkast')) return false;
      const f = document.createElement('div');
      f.id = 'forkast'; f.className = 'forkast'; f.setAttribute('role', 'alert');
      f.innerHTML = '<b>Vil du forkaste det du har skrevet?</b><span class="valgknapper"><button class="knapp" data-na="fortsett">Fortsett å skrive</button><button class="knapp rod" data-na="forkast">Forkast</button></span>';
      $('#ark-inn').prepend(f);
      return false;
    }
    endret = false;
    d.close();
    $('#ark-inn').replaceChildren();
    return true;
  }
  function settOppArk() {
    const d = ark();
    d.addEventListener('click', e => {
      if (e.target === d) { const r = d.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) lukkArk(); }
      const b = e.target.closest('[data-lukk],[data-na]');
      if (!b) return;
      if (b.dataset.lukk !== undefined) lukkArk();
      else if (b.dataset.na === 'fortsett') $('#forkast')?.remove();
      else if (b.dataset.na === 'forkast') lukkArk(true);
    });
    d.addEventListener('cancel', e => { e.preventDefault(); lukkArk(); });
    d.addEventListener('input', e => { if (!e.target.closest('[data-ikke-endret]') && !e.target.matches('input[type=checkbox],input[type=radio]')) endret = true; });
  }
  const arkHode = tittel => `<div class="ark-head"><h2>${tittel}</h2><button class="lukk" data-lukk aria-label="Lukk">×</button></div>`;
  NA.arkHode = arkHode;
  /* «Er du sikker?» i arket. Svarer true/false. */
  NA.bekreft = (tittel, tekst, ja = 'Bekreft') => new Promise(svar => {
    let ferdig = false;
    const slutt = v => { if (ferdig) return; ferdig = true; ark().removeEventListener('close', nei); svar(v); };
    const nei = () => slutt(false);
    const inn = apneArk(`${arkHode(esc(tittel))}<p>${esc(tekst)}</p><div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="button" data-ja>${esc(ja)}</button></div>`);
    inn.querySelector('[data-ja]').onclick = () => { slutt(true); lukkArk(true); };
    ark().addEventListener('close', nei);
  });
  NA.arkApen = () => ark().open;
  NA.arkEndret = v => { endret = !!v; };

  /* ── toast ───────────────────────────────────────────────────────────── */
  function toast(html) {
    const t = document.createElement('div');
    t.className = 'toast'; t.innerHTML = html;
    $('#toast').prepend(t);
    setTimeout(() => t.remove(), 4800);
  }

  /* ── polling ─────────────────────────────────────────────────────────── */
  function hentHvert(ms, fn, {global = false} = {}) {
    let stoppet = false, opptatt = false, sist = 0;
    const kjor = async () => {
      if (stoppet || document.hidden || opptatt || Date.now() - sist < 2000) return;
      opptatt = true; sist = Date.now();
      try { await fn(); } catch (e) { console.warn('ny-admin: oppdatering feilet', e); } finally { opptatt = false; }
    };
    const t = setInterval(kjor, Math.max(3000, ms));
    const vis = () => { if (!document.hidden) kjor(); };
    document.addEventListener('visibilitychange', vis);
    window.addEventListener('focus', vis);
    const stopp = () => { stoppet = true; clearInterval(t); document.removeEventListener('visibilitychange', vis); window.removeEventListener('focus', vis); };
    if (!global) sidePollere.push(stopp);
    kjor();
    return stopp;
  }

  /* ── oversikt.php: delt mellom topplinja og I dag ────────────────────── */
  let oversiktLofte = null, oversiktTid = 0, sistOk = 0;
  NA.oversikt = function (tving) {
    if (!tving && oversiktLofte && Date.now() - oversiktTid < 5000) return oversiktLofte;
    oversiktTid = Date.now();
    oversiktLofte = api('oversikt.php').then(d => { NA.sist = d; sistOk = Date.now(); tegnTopp(d); tegnMeny(); return d; })
      .catch(e => { oversiktTid = 0; tegnLive(); throw e; });
    return oversiktLofte;
  };
  NA.oppdaterTopp = () => NA.oversikt(true).catch(() => {});
  /* Det som står i «Må gjøres» (I dag): samme liste som menyens tall. Påmeldinger er «Til info» og teller ikke. */
  NA.maGjores = d => (d?.maGjores || []).filter(s => s.teller !== false && s.type !== 'pamelding');
  NA.antallMaa = d => NA.maGjores(d).length + (Number(d?.koer?.medlemsvarer) || 0);

  /* ── ruting ──────────────────────────────────────────────────────────── */
  function lesRute() {
    const h = location.hash.replace(/^#/, '');
    const [id, q] = h.split('?');
    return {id: id || 'idag', params: new URLSearchParams(q || '')};
  }
  function gaTil(id, params) {
    const q = params ? new URLSearchParams(params).toString() : '';
    const h = '#' + id + (q ? '?' + q : '');
    if (location.hash === h) tegnSide(); else location.hash = h;
  }
  NA.gaTil = gaTil;
  NA.params = () => rute.params;
  NA.tilbakeKnapp = standardId => {
    const mal = forrige && (forrige.id !== rute.id || forrige.params.toString() !== rute.params.toString()) ? forrige : {id: standardId || 'idag', params: new URLSearchParams()};
    const navn = mal.id === 'idag' ? 'I dag' : (sider.get(mal.id)?.tittel || mal.id);
    const b = document.createElement('button');
    b.className = 'knapp liten'; b.type = 'button'; b.textContent = '← Tilbake til ' + navn;
    b.onclick = () => gaTil(mal.id, mal.params);
    return b;
  };

  function registrerSide(id, def) {
    sider.set(id, {tittel: def.tittel || id, ikon: def.ikon || '', tegn: def.tegn, mobil: !!def.mobil});
    if (NA.meg && klar && rute.id === id) tegnSide();
  }

  async function tegnSide() {
    const ny = lesRute();
    if (ny.id !== rute.id || ny.params.toString() !== rute.params.toString()) forrige = rute;
    rute = ny;
    sidePollere.forEach(s => s()); sidePollere = [];
    const nr = ++tegnTeller;
    tegnMeny();
    const arbeid = $('#arbeid');
    const side = sider.get(rute.id);
    const menyNavn = [...MENY, ...BUNN].find(m => m[0] === rute.id)?.[1];
    document.title = 'Lissom · ' + (side?.tittel || menyNavn || 'Admin');
    if (erMobil() && !(side ? side.mobil : MOBIL.includes(rute.id))) {
      arbeid.innerHTML = '<div class="kort" style="text-align:center;padding:32px 20px"><h2 style="margin-bottom:8px">Brukes på iPad eller PC</h2><p class="muted">Denne delen er ikke med på mobil. På mobilen har du I dag, Kalender og Kurs.</p><div style="margin-top:16px"><button class="knapp hoved" type="button" data-side="idag">Til I dag</button></div></div>';
      return;
    }
    if (!side) {
      arbeid.innerHTML = `<div class="head"><div><h1>${esc(menyNavn || rute.id)}</h1></div></div><section class="kort"><p class="muted">Denne delen er ikke bygd ennå i den nye adminen.</p><div style="margin-top:14px"><a class="knapp" href="/admin-ny">Åpne gammel admin</a></div></section>`;
      return;
    }
    const el = document.createElement('div');
    el.className = 'side side-' + rute.id;
    arbeid.replaceChildren(el);
    try {
      await side.tegn(el, rute.params);
    } catch (e) {
      if (nr !== tegnTeller) return;
      console.error(e);
      el.innerHTML = `<p class="feil">${esc(e.message || 'Noe gikk galt.')}</p>`;
    }
  }

  /* ── meny ────────────────────────────────────────────────────────────── */
  function hurtigValgt() {
    try { const v = JSON.parse(localStorage.getItem(HURTIG_NOKKEL) || 'null'); if (Array.isArray(v)) return HURTIG.filter(h => v.includes(h[0])); } catch {}
    return HURTIG;
  }
  NA.hurtig = hurtigValgt;
  function tegnMeny() {
    if (!NA.meg) return;
    const aktiv = rute.id;
    const n = NA.sist ? NA.antallMaa(NA.sist) : 0;
    const navKnapp = ([id, navn, ic]) => id === 'kasse'
      ? `<a class="nav" href="/kasse"><span class="ic">${ic}</span>${navn}</a>`
      : `<button class="nav" type="button" data-side="${id}" ${aktiv === id ? 'aria-current="page"' : ''}><span class="ic">${ic}</span>${navn}${id === 'idag' && n ? `<span class="tall">${n}</span>` : ''}</button>`;
    const meny = `<div class="brand">Lissom</div><div class="brand-note">Keramikk · admin</div>${MENY.map(navKnapp).join('')}
      <div class="hurtig-tittel">Hurtig</div>
      ${hurtigValgt().map(h => `<button class="nav hurtig" type="button" data-hurtig="${h[0]}"><span class="ic">${h[2]}</span>${h[1]}</button>`).join('')}
      <button class="nav hurtig tilpass" type="button" data-hurtig="tilpass"><span class="ic">＋</span>Tilpass</button>
      <div class="fot">${BUNN.map(navKnapp).join('')}
        <small>${esc(NA.meg.navn)} · innlogget</small>
        <button class="knapp liten logg-ut" type="button" data-loggut>Logg ut</button>
      </div>`;
    /* Bare når noe er endret: menyen tegnes ved hver oppdatering, og fokus skal ikke forsvinne. */
    if ($('#meny')._html !== meny) { $('#meny').innerHTML = meny; $('#meny')._html = meny; }
    const mobil = MENY.filter(m => MOBIL.includes(m[0])).map(([id, navn, ic]) =>
      `<button type="button" data-side="${id}" ${aktiv === id ? 'aria-current="page"' : ''}><span aria-hidden="true">${ic}</span>${navn}${id === 'idag' && n ? ` (${n})` : ''}</button>`).join('');
    if ($('#mobil')._html !== mobil) { $('#mobil').innerHTML = mobil; $('#mobil')._html = mobil; }
  }

  /* ── topplinje ───────────────────────────────────────────────────────── */
  let stemplet = null;
  function tegnToppSkall() {
    $('#topp').innerHTML = `<div class="sok-wrap"><input id="sok" type="search" class="sok" placeholder="⌕ Søk etter kurs, deltaker, medlem eller vare" autocomplete="off" aria-label="Søk"><div id="sokres" class="sokres" hidden></div></div>
      <span id="topptall"></span><span class="topp-fyll"></span>
      <button class="knapp stemple-topp" id="stemple-topp" type="button" hidden></button>
      <span class="live" id="live" title="Oppdateres av seg selv"><i></i><span id="live-tekst">Live</span></span>`;
  }
  function tegnLive() {
    const l = $('#live'); if (!l) return;
    const ok = sistOk && Date.now() - sistOk < 60000;
    l.classList.toggle('borte', !ok);
    $('#live-tekst').textContent = ok ? 'Live' : 'Frakoblet';
  }
  function tegnTopp(d) {
    if (!$('#topptall')) return;
    const o = d.omsetning || {};
    const idag = Number(o.idagOre) || 0, mnd = Number(o.manedOre) || 0, forr = Number(o.forrigeMndOre) || 0, uke = Number(o.forrigeUkedagOre) || 0;
    const diff = forr > 0 ? Math.round((mnd / forr - 1) * 100) : null;
    const ub = d.ubetalte || [];
    const ubSum = ub.reduce((s, r) => s + (Number(r.belopOre) || 0), 0);
    const inne = d.verkstedet || [];
    const megNavn = NA.meg?.navn;
    $('#topptall').innerHTML = `<button class="tall-pille" type="button" data-topp="omsetning" data-tips="Samme ukedag forrige uke: ${kr(uke)}"><small>I dag</small><b>${kr(idag)}</b></button>
      <button class="tall-pille" type="button" data-topp="omsetning" data-tips="${esc('Forrige måned til samme dato: ' + kr(forr) + (diff === null ? '' : ' · ' + (diff >= 0 ? '+' : '') + diff + ' %'))}"><small>Denne måneden</small><b>${kr(mnd)}${diff === null ? '' : ` <span class="${diff >= 0 ? 'opp' : 'ned'}">${diff >= 0 ? '▲' : '▼'} ${Math.abs(diff)} %</span>`}</b></button>
      <button class="tall-pille ${ub.length ? 'varsel' : ''}" type="button" data-topp="ubetalt" data-tips="${ub.length} ${ub.length === 1 ? 'har' : 'har'} ikke betalt"><small>Ikke betalt</small><b>${kr(ubSum)}</b></button>
      <button class="tall-pille inne-pille" type="button" data-topp="inne" data-tips="${esc(inne.map(x => x.navn + ' (inn ' + x.siden + ')').join(' · ') || 'Ingen er stemplet inn')}"><small>I verkstedet nå · ${inne.length}</small>
        <span class="inne-navn">${inne.length ? inne.slice(0, 4).map(x => `<span class="ini ${x.navn === megNavn ? 'ansatt' : ''}">${esc(String(x.navn).split(' ')[0])}</span>`).join('') + (inne.length > 4 ? `<span class="ini">+${inne.length - 4}</span>` : '') : '<span class="muted">Ingen</span>'}</span></button>`;
    tegnLive();
  }
  function tegnStemple() {
    const b = $('#stemple-topp'); if (!b) return;
    if (!stemplet) { b.hidden = true; return; }
    b.hidden = false;
    b.className = 'knapp stemple-topp ' + (stemplet.innstemplet ? '' : 'hoved');
    b.textContent = stemplet.innstemplet ? `Stemple ut · inne fra ${stemplet.siden || ''}` : 'Stemple inn';
  }
  async function hentStemple() {
    try { stemplet = await api('/api/stempling.php'); } catch { stemplet = null; }
    tegnStemple();
  }
  async function trykkStemple(b) {
    b.disabled = true;
    try {
      const d = await api('/api/stempling.php', {data: {handling: stemplet?.innstemplet ? 'ut' : 'inn'}});
      stemplet = d; tegnStemple();
      toast(d.innstemplet ? '<b>Stemplet inn</b> ' + esc(d.siden || '') + '.' : '<b>Stemplet ut.</b>');
      NA.oppdaterTopp();
    } catch (e) { toast('Fikk ikke registrert. ' + esc(e.message)); } finally { b.disabled = false; }
  }

  function arkOmsetning() {
    const o = NA.sist?.omsetning || {};
    const linjer = l => (l || []).map(x => `<div class="rad"><div class="tekst"><b>${esc(x.navn)}</b></div><span>${esc(x.verdi)}</span></div>`).join('') || '<p class="tom">Ingenting ennå.</p>';
    const mnd = Number(o.manedOre) || 0, forr = Number(o.forrigeMndOre) || 0;
    const diff = forr > 0 ? Math.round((mnd / forr - 1) * 100) : null;
    apneArk(`${arkHode('Omsetning')}
      <div class="grid"><div class="kort"><div class="type">I dag</div><div class="ovnstatus">${kr(o.idagOre)}</div><small>Samme ukedag forrige uke: ${kr(o.forrigeUkedagOre)}</small></div>
      <div class="kort"><div class="type">Denne måneden</div><div class="ovnstatus">${kr(mnd)}</div><small>Forrige måned til samme dato: ${kr(forr)}${diff === null ? '' : ' · ' + (diff >= 0 ? '+' : '') + diff + ' %'}</small></div></div>
      <div><h3>I dag</h3>${linjer(o.linjerIdag)}</div>
      <div><h3>Denne måneden</h3>${linjer(o.linjerMnd)}</div>`);
  }
  function arkUbetalt() {
    const ub = NA.sist?.ubetalte || [];
    const sum = ub.reduce((s, r) => s + (Number(r.belopOre) || 0), 0);
    apneArk(`${arkHode('Ikke betalt · ' + kr(sum))}
      ${ub.length ? ub.map(r => `<div class="rad"><div class="tekst"><b>${esc(r.navn)}</b><small>${esc([r.kurs, r.naar].filter(Boolean).join(' · '))}</small></div>
        <span class="merke rod">${esc(r.belop || kr(r.belopOre))}</span>
        ${r.slag === 'medlem' ? `<button class="knapp liten" type="button" data-medlem="${Number(r.id)}">Åpne medlemmet</button>` : (erMobil() ? '' : '<a class="knapp liten" href="/kasse">Ta betalt</a>')}</div>`).join('') : '<p class="tom">Alle har betalt.</p>'}
      ${ub.length && !erMobil() ? '<small>«Ta betalt» åpner kassa. Velg personen der.</small>' : ''}`);
  }
  function arkInne() {
    const inne = NA.sist?.verkstedet || [];
    apneArk(`${arkHode('I verkstedet nå · ' + inne.length)}
      ${inne.length ? inne.map(x => `<div class="rad"><div class="tekst"><b>${esc(x.navn)}</b><small>Stemplet inn ${esc(x.siden)}${x.ressurs ? ' · ' + esc(x.ressurs) : ''}${x.skjult ? ' · skjult for medlemmene' : ''}</small></div>${x.type ? `<span class="merke gronn">${esc(x.type)}</span>` : ''}</div>`).join('') : '<p class="tom">Ingen er stemplet inn.</p>'}
      <small>Oppdateres når noen stempler inn eller ut, på Min side eller iPaden.</small>`);
  }

  /* ── søk ─────────────────────────────────────────────────────────────── */
  let sokData = null, sokTid = 0;
  async function hentSokData() {
    if (sokData && Date.now() - sokTid < 60000) return sokData;
    sokTid = Date.now();
    const [p, k, m, v] = await Promise.all([
      api('pameldte.php').catch(() => ({})), api('kurs.php').catch(() => ({})),
      erMobil() ? {} : api('medlemmer.php').catch(() => ({})), erMobil() ? {} : api('produkter.php').catch(() => ({}))]);
    sokData = {deltakere: p.deltakere || [], kurs: k.kurs || [], medlemmer: m.medlemmer || [], varer: v.varer || []};
    return sokData;
  }
  function sokTreff(q, d) {
    const ord = q.toLocaleLowerCase('nb-NO').split(/\s+/).filter(Boolean);
    const passer = t => ord.every(o => String(t || '').toLocaleLowerCase('nb-NO').includes(o));
    const t = [];
    d.kurs.forEach(k => { if (passer(k.tittel)) t.push(['Kurs', k.tittel, 'kurs', {kurs: k.id}]); });
    d.deltakere.forEach(x => { if (passer(x.navn + ' ' + (x.epost || '') + ' ' + (x.tlf || ''))) t.push(['Deltaker', x.navn + ' · ' + x.kurs + ' · ' + x.dato, 'kurs', x.oktId ? {okt: x.oktId} : {kurs: x.kursId}]); });
    d.medlemmer.forEach(x => { if (passer(x.navn + ' ' + (x.epost || '') + ' ' + (x.telefon || ''))) t.push(['Medlem', x.navn, 'medlemmer', {person: x.id}]); });
    d.varer.forEach(x => { if (passer(x.tittel)) t.push(['Vare', x.tittel, 'varer', {vare: x.id}]); });
    return t.slice(0, 12);
  }
  let sokNr = 0;
  async function sok(q) {
    const box = $('#sokres');
    if (q.trim().length < 2) { box.hidden = true; return; }
    const nr = ++sokNr;
    box.hidden = false;
    if (!sokData) box.innerHTML = '<p class="tom" style="padding:10px">Søker …</p>';
    const d = await hentSokData();
    if (nr !== sokNr) return;
    const t = sokTreff(q, d);
    box.innerHTML = t.length ? t.map(([slag, navn, side, p]) => `<button type="button" data-gatil="${side}" data-p="${esc(new URLSearchParams(p).toString())}"><small>${slag}</small>${esc(navn)}</button>`).join('') : '<p class="tom" style="padding:10px">Ingen treff</p>';
  }

  /* ── hurtig: uttak leire, skisseverktøy, tilpass ─────────────────────── */
  /* Uttak leire = «Ta ut leire» som finnes (produkter.php handling=taUt): én pose fra lageret, uten betaling. */
  NA.uttakLeire = async function () {
    apneArk(arkHode('Uttak leire') + '<p class="laster">Henter leira …</p>');
    let d;
    try { d = await api('produkter.php'); } catch (e) { apneArk(arkHode('Uttak leire') + `<p class="feil">${esc(e.message)}</p>`); return; }
    const tegn = rader => apneArk(`${arkHode('Uttak leire')}
      ${rader.length ? rader.map(r => `<div class="rad"><div class="tekst"><b>${esc(r.vare)}</b><small>${Number(r.antall)} på lager</small></div><button class="knapp liten hoved" type="button" data-taut="${Number(r.id)}" ${r.antall > 0 ? '' : 'disabled'}>Ta ut 1</button></div>`).join('') : '<p class="tom">Ingen leire er merket som leire i varelista.</p>'}
      <small>Én pose fra lageret, uten betaling. Lageret oppdateres med en gang.</small>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Lukk</button></div>`);
    tegn(d.taUtLeire || []);
    $('#ark-inn').onclick = async e => {
      const b = e.target.closest('[data-taut]'); if (!b) return;
      b.disabled = true;
      try { const x = await api('produkter.php', {data: {handling: 'taUt', id: Number(b.dataset.taut)}}); toast(esc(x.beskjed || 'Tatt ut.')); const ny = await api('produkter.php'); tegn(ny.taUtLeire || []); }
      catch (err) { toast(esc(err.message)); b.disabled = false; }
    };
  };
  /* Skisseverktøyet finnes (skisser.html, egen modul) og åpnes i arket. */
  NA.skisse = function () {
    apneArk(`${arkHode('Skisseverktøy')}<iframe class="ramme-skisse" src="/skisser.html" title="Skisseverktøy"></iframe>`, {bred: true});
  };
  function arkTilpass() {
    const valgt = hurtigValgt().map(h => h[0]);
    apneArk(`${arkHode('Tilpass hurtig')}
      <p class="muted">Velg hvilke snarveier som står under Hurtig i menyen.</p>
      ${HURTIG.map(h => `<div class="bryter"><div><b>${h[1]}</b></div><button class="av" type="button" data-tilpass="${h[0]}" aria-pressed="${valgt.includes(h[0])}" aria-label="${h[1]}"></button></div>`).join('')}
      <div class="ark-fot"><button class="knapp hoved" type="button" data-lukk>Ferdig</button></div>`);
    $('#ark-inn').onclick = e => {
      const b = e.target.closest('[data-tilpass]'); if (!b) return;
      const paa = b.getAttribute('aria-pressed') !== 'true';
      b.setAttribute('aria-pressed', String(paa));
      const ny = [...$('#ark-inn').querySelectorAll('[data-tilpass]')].filter(x => x.getAttribute('aria-pressed') === 'true').map(x => x.dataset.tilpass);
      try { localStorage.setItem(HURTIG_NOKKEL, JSON.stringify(ny)); } catch {}
      tegnMeny();
    };
  }

  /* ── innlogging ──────────────────────────────────────────────────────── */
  function visInnlogging(tekst) {
    if (!NA.meg && $('#innlogging') && !tekst) return;
    NA.meg = null;
    if (toppPoll) { toppPoll(); toppPoll = null; }
    sidePollere.forEach(s => s()); sidePollere = [];
    $('#meny').innerHTML = ''; $('#mobil').innerHTML = ''; $('#topp').innerHTML = '';
    $('#meny')._html = $('#mobil')._html = '';
    if (ark().open) lukkArk(true);
    $('#arbeid').innerHTML = `<div class="head"><div><div class="eyebrow">Lissom · admin</div><h1>Logg inn</h1></div></div>
      <section class="kort" style="max-width:480px"><form id="innlogging" style="display:grid;gap:12px">
        ${tekst ? `<p class="feil">${esc(tekst)}</p>` : ''}
        <label class="felt"><small>Brukernavn</small><input name="brukernavn" autocomplete="username" required></label>
        <label class="felt"><small>Passord</small><input name="passord" type="password" autocomplete="current-password" required></label>
        <p class="feil" id="innlogging-feil" hidden></p>
        <div class="ark-fot"><button class="knapp hoved" type="submit">Logg inn</button></div></form></section>`;
    $('#innlogging').onsubmit = async e => {
      e.preventDefault();
      const f = e.target, b = f.querySelector('button'), feil = $('#innlogging-feil');
      b.disabled = true; feil.hidden = true;
      try {
        const r = await api('/api/logg-inn.php', {data: {brukernavn: f.brukernavn.value, passord: f.passord.value}});
        if (!r.erAdmin) throw Error('Denne kontoen har ikke tilgang til admin.');
        await start();
      } catch (err) { feil.textContent = err.message; feil.hidden = false; b.disabled = false; }
    };
  }

  /* ── sidefilene ──────────────────────────────────────────────────────── */
  /* Hentes med fetch (alltid ferskt, uten ?v=), og kjøres bare når svaret er JavaScript: en fil som ikke finnes
     ennå gir nettsidens HTML (side.php), og da hoppes den over. */
  let lastet = false;
  async function lastSidefiler() {
    if (lastet) return;
    lastet = true;
    const tekster = await Promise.all(SIDEFILER.map(async f => {
      try {
        const r = await fetch('/ny-admin/' + f + '.js', {credentials: 'same-origin', cache: 'no-cache'});
        if (!r.ok || !/javascript/i.test(r.headers.get('content-type') || '')) return null;
        return [f, await r.text()];
      } catch { return null; }
    }));
    for (const t of tekster) {
      if (!t) continue;
      try {
        const s = document.createElement('script');
        s.textContent = t[1] + '\n//# sourceURL=/ny-admin/' + t[0] + '.js';
        document.body.append(s);
      } catch (e) { console.error('ny-admin: ' + t[0] + '.js', e); }
    }
  }

  /* ── klikk ───────────────────────────────────────────────────────────── */
  document.addEventListener('click', e => {
    const b = e.target.closest('button,a');
    if (!e.target.closest('.sok-wrap') && $('#sokres')) $('#sokres').hidden = true;
    if (!b) return;
    const d = b.dataset;
    if (d.side) { if (ark().open && !lukkArk()) return; gaTil(d.side); window.scrollTo(0, 0); return; }
    if (d.gatil) { $('#sokres').hidden = true; $('#sok').value = ''; if (ark().open && !lukkArk()) return; gaTil(d.gatil, new URLSearchParams(d.p || '')); window.scrollTo(0, 0); return; }
    if (d.medlem) { lukkArk(true); gaTil('medlemmer', {person: d.medlem}); return; }
    if (d.topp === 'omsetning') return arkOmsetning();
    if (d.topp === 'ubetalt') return arkUbetalt();
    if (d.topp === 'inne') return arkInne();
    if (b.id === 'stemple-topp') return trykkStemple(b);
    if (d.hurtig === 'skisse') return NA.skisse();
    if (d.hurtig === 'uttak') return NA.uttakLeire();
    if (d.hurtig === 'tilpass') return arkTilpass();
    if (d.loggut !== undefined) {
      api('/api/logg-ut.php', {data: {}}).catch(() => {}).finally(() => visInnlogging());
    }
  });
  document.addEventListener('input', e => { if (e.target.id === 'sok') sok(e.target.value); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && $('#sokres') && !$('#sokres').hidden) { $('#sokres').hidden = true; }
    if (e.key === 'Enter' && e.target.id === 'sok') { const f = $('#sokres button'); if (f) f.click(); }
  });
  window.addEventListener('hashchange', () => {
    /* «Hopp til innhold» er en lenke til #arbeid: den er ikke en side. */
    if (location.hash === '#arbeid') { const q = rute.params.toString(); history.replaceState(history.state, '', '#' + rute.id + (q ? '?' + q : '')); $('#arbeid').focus(); return; }
    if (NA.meg) { if (ark().open) lukkArk(true); tegnSide(); }
  });
  let bredde = innerWidth;
  window.addEventListener('resize', () => { clearTimeout(window._naRz); window._naRz = setTimeout(() => { if ((bredde <= 760) !== (innerWidth <= 760) && NA.meg) { bredde = innerWidth; tegnSide(); } bredde = innerWidth; }, 200); });

  /* ── start ───────────────────────────────────────────────────────────── */
  async function start() {
    let meg;
    try { meg = await api('arbeidsrom.php'); }
    catch (e) { if (e.status !== 401) visInnlogging(e.status === 403 || e.status === 404 ? 'Denne kontoen har ikke tilgang til admin.' : e.message); return; }
    if (!meg.erAdmin) { visInnlogging('Denne kontoen har ikke tilgang til admin.'); return; }
    NA.meg = {navn: meg.navn};
    tegnToppSkall();
    tegnMeny();
    $('#arbeid').innerHTML = '<p class="laster">Henter …</p>';
    hentStemple();
    await lastSidefiler();
    klar = true;
    tegnSide();
    if (toppPoll) toppPoll();
    toppPoll = hentHvert(15000, () => NA.oversikt(), {global: true});
    if (!liveTimer) liveTimer = setInterval(tegnLive, 10000);
  }

  Object.assign(window, {registrerSide, api, apneArk, lukkArk, toast, hentHvert});
  Object.assign(NA, {registrerSide, api, apneArk, lukkArk, toast, hentHvert});
  document.addEventListener('DOMContentLoaded', () => { settOppArk(); start(); });
})();
