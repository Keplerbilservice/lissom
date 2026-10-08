/* Ny admin — felles skall (eieren 08.10.2026, GO på «samlet forslag»). Ved siden av /admin-ny, som ikke endres.
 *
 * FELLES-API (globale funksjoner, også under window.NA). Sidefilene i ny-admin/ er vanlige skript (ikke moduler):
 *
 *   registrerSide(id, {tittel, ikon, tegn(el, params), mobil})   (kalender.js og kurs.js er ES-moduler, se MODULER)
 *       id: idag | kalender | kurs | medlemmer | varer | mer | semedlem (Medlemssiden) | innstillinger (eller en ny). «kasse» åpner /kasse.
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
 *   NA.gaTil(id, params) (= NA.gaaTil, også global; skriver #id?params, og ruteren tegner siden) · NA.params() · NA.tilbakeKnapp(standardId) · NA.esc(t) · NA.kr(ore) · NA.erMobil()
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
  /* Mobilvisningen (bunnmeny, «Brukes på iPad eller PC») bare på berøringsskjerm og smal skjerm. En PC med 200 % zoom
     eller et smalt vindu får hele adminen, som flyter om (designvokteren 08.10.2026). */
  const MOBIL_MQ = '(pointer:coarse) and (max-width:600px)';
  const erMobil = () => matchMedia(MOBIL_MQ).matches;
  const merkMobil = () => document.documentElement.classList.toggle('na-mobil', erMobil());
  merkMobil();

  /* Sidefilene (del A, B og C). Lastes etter tur; mangler en fil, står siden som «ikke bygd ennå».
     MODULER lastes som <script type="module" src> (kurs.js importerer fra kalender.js, så begge må ha samme adresse). */
  const SIDEFILER = ['idag', 'medlemmer', 'medlemssiden', 'varer', 'innstillinger', 'mer'];
  const MODULER = ['kalender', 'kurs'];
  const MYNT = '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-5px"><ellipse cx="12" cy="16" rx="8" ry="3.5" fill="#e0a800" stroke="currentColor" stroke-width="1.4"/><rect x="4" y="11" width="16" height="5" fill="#e0a800"/><path d="M4 11v5M20 11v5" stroke="currentColor" stroke-width="1.4"/><ellipse cx="12" cy="11" rx="8" ry="3.5" fill="#ffcf38" stroke="currentColor" stroke-width="1.4"/><ellipse cx="12" cy="11" rx="4.5" ry="1.8" fill="none" stroke="currentColor" stroke-width="1" opacity=".5"/></svg>';
  const MENY = [['idag', 'I dag', '☀'], ['kalender', 'Kalender', '▦'], ['kurs', 'Kurs', '◍'], ['medlemmer', 'Medlemmer', '☺'], ['varer', 'Varer', '◇'], ['kasse', 'Kasse', MYNT], ['mer', 'Mer', '…']];
  const BUNN = [['semedlem', 'Medlemssiden', '👁'], ['innstillinger', 'Innstillinger', '⚙']];
  const MOBIL = ['idag', 'kalender', 'kurs'];
  const HURTIG = [['skisse', 'Skisseverktøy', '✎'], ['uttak', 'Uttak leire', '◼']];
  const HURTIG_NOKKEL = 'na-hurtig';

  const sider = new Map();
  let toppPoll = null, liveTimer = null, klar = false;
  let rute = {id: 'idag', params: new URLSearchParams()};
  let forrige = null;
  let sidePollere = [];
  let tegnTeller = 0;
  /* Samme objekt under alle tre navnene sidefilene slår opp (A: NA, B: nyAdmin, C: NyAdmin). */
  const NA = window.NA = window.nyAdmin = window.NyAdmin = {esc, kr, erMobil, meg: null, sist: null};

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
  /* Arket legger en rad i nettleserens historikk, så Tilbake lukker arket (og spør «Vil du forkaste?» når noe er
     skrevet) i stedet for å forlate siden. arkHistorikk = raden er vår og ikke brukt ennå. */
  let arkHistorikk = false, hoppOverPop = false;
  const ark = () => $('#ark');
  function apneArk(innhold, {bred = false} = {}) {
    const inn = $('#ark-inn');
    inn.onclick = null; inn.onchange = null;
    if (typeof innhold === 'string') inn.innerHTML = innhold; else inn.replaceChildren(innhold);
    ark().classList.toggle('bred', !!bred);
    endret = false;
    /* Overskriften gir arket navnet (skjermleser) og tar fokus når innholdet byttes, så fokus aldri havner på body. */
    const h2 = inn.querySelector('h2');
    if (h2) { h2.id = h2.id || 'ark-tittel'; h2.tabIndex = -1; ark().setAttribute('aria-labelledby', h2.id); ark().removeAttribute('aria-label'); }
    if (!ark().open) {
      ark().showModal();
      document.documentElement.classList.add('ark-apent');
      if (!arkHistorikk) { try { history.pushState({naArk: 1}, '', location.href); arkHistorikk = true; } catch {} }
    }
    const forste = inn.querySelector('input:not([type=checkbox]):not([type=radio]),textarea,select');
    if (forste && !erMobil()) forste.focus();
    else (h2 || inn.querySelector('.lukk') || inn).focus?.({preventScroll: true});
    return inn;
  }
  function lukkArk(tving, {utenTilbake = false} = {}) {
    const d = ark();
    if (!d.open) return true;
    if (endret && !tving) {
      if ($('#forkast')) return false;
      const f = document.createElement('div');
      f.id = 'forkast'; f.className = 'forkast'; f.setAttribute('role', 'alert');
      f.innerHTML = '<b>Vil du forkaste det du har skrevet?</b><span class="valgknapper"><button class="knapp" data-na="fortsett">Fortsett å skrive</button><button class="knapp rod" data-na="forkast">Forkast</button></span>';
      $('#ark-inn').prepend(f);
      f.querySelector('button').focus();
      return false;
    }
    endret = false;
    d.close();
    $('#ark-inn').replaceChildren();
    document.documentElement.classList.remove('ark-apent');
    if (arkHistorikk) {
      arkHistorikk = false;
      if (!utenTilbake) { hoppOverPop = true; history.back(); }
    }
    return true;
  }
  /* Nettleserens Tilbake med åpent ark: lukk det, eller spør først når noe er skrevet. */
  window.addEventListener('popstate', () => {
    if (hoppOverPop) { hoppOverPop = false; return; }
    if (!ark().open || !arkHistorikk) return;
    arkHistorikk = false;
    if (endret) { try { history.pushState({naArk: 1}, '', location.href); arkHistorikk = true; } catch {} lukkArk(); }
    else lukkArk(true, {utenTilbake: true});
  });
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
  NA.gaTil = NA.gaaTil = gaTil;
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
    sider.set(id, {tittel: def.tittel || id, ikon: def.ikon || '', tegn: def.tegn, mobil: !!def.mobil, sok: def.sok || null});
    if (NA.meg && klar && rute.id === id) tegnSide();
  }

  async function tegnSide() {
    const ny = lesRute();
    if (ny.id !== rute.id || ny.params.toString() !== rute.params.toString()) forrige = rute;
    rute = ny;
    sidePollere.forEach(s => s()); sidePollere = [];
    if (forrige && forrige.id !== rute.id && $('#sok')) { $('#sok').value = ''; $('#sokres').hidden = true; }
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
      arbeid.innerHTML = `<div class="head"><div><h1>${esc(menyNavn || rute.id)}</h1></div></div><section class="kort"><p class="muted">Denne delen er ikke bygd ennå i den nye adminen.</p><div style="margin-top:14px"><a class="knapp" href="/admin-ny">Gammel admin</a></div></section>`;
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
    /* Bunnmenyen: på mobil bare I dag, Kalender og Kurs. På en smal PC-skjerm (zoom) alle sidene, så ingen blir borte. */
    const alle = !erMobil();
    const mobil = (alle ? [...MENY, ...BUNN] : MENY.filter(m => MOBIL.includes(m[0]))).map(([id, navn, ic]) => id === 'kasse'
      ? `<a href="/kasse"><span aria-hidden="true">${ic}</span>${navn}</a>`
      : `<button type="button" data-side="${id}" ${aktiv === id ? 'aria-current="page"' : ''}><span aria-hidden="true">${ic}</span>${navn}${id === 'idag' && n ? ` (${n})` : ''}</button>`).join('');
    $('#mobil').classList.toggle('alle', alle);
    if ($('#mobil')._html !== mobil) { $('#mobil').innerHTML = mobil; $('#mobil')._html = mobil; }
  }

  /* ── topplinje ───────────────────────────────────────────────────────── */
  let stemplet = null;
  function tegnToppSkall() {
    $('#topp').innerHTML = `<div class="sok-wrap"><input id="sok" type="search" class="sok" placeholder="⌕ Søk" autocomplete="off" aria-label="Søk etter kurs, deltaker, medlem eller vare"><div id="sokres" class="sokres" hidden></div></div>
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
    // Omsetningen er UTEN mva (vedtak «omsetning-uten-mva», eieren 30.09.2026), som på Oversikt i gammel admin.
    const eks = (e, b) => Number(o[e] ?? o[b]) || 0;
    const idag = eks('idagEksOre', 'idagOre'), mnd = eks('manedEksOre', 'manedOre'), forr = eks('forrigeMndEksOre', 'forrigeMndOre'), uke = eks('forrigeUkedagEksOre', 'forrigeUkedagOre');
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
    b.innerHTML = stemplet.innstemplet ? `Stemple ut<span class="lang"> · inne fra ${esc(stemplet.siden || '')}</span>` : 'Stemple inn';
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
    // Uten mva, som topplinja (vedtak «omsetning-uten-mva»). Linjene har eksOre fra Omsetning::mvaFor.
    const eks = (e, b) => Number(o[e] ?? o[b]) || 0;
    const linjer = l => (l || []).map(x => `<div class="rad"><div class="tekst"><b>${esc(x.navn)}</b></div><span>${typeof x.eksOre === 'number' ? kr(x.eksOre) : esc(x.verdi)}</span></div>`).join('') || '<p class="tom">Ingenting ennå.</p>';
    const mnd = eks('manedEksOre', 'manedOre'), forr = eks('forrigeMndEksOre', 'forrigeMndOre');
    const diff = forr > 0 ? Math.round((mnd / forr - 1) * 100) : null;
    apneArk(`${arkHode('Omsetning')}
      <div class="grid"><div class="kort"><div class="type">I dag</div><div class="ovnstatus">${kr(eks('idagEksOre', 'idagOre'))}</div><small>Samme ukedag forrige uke: ${kr(eks('forrigeUkedagEksOre', 'forrigeUkedagOre'))}</small></div>
      <div class="kort"><div class="type">Denne måneden</div><div class="ovnstatus">${kr(mnd)}</div><small>Forrige måned til samme dato: ${kr(forr)}${diff === null ? '' : ' · ' + (diff >= 0 ? '+' : '') + diff + ' %'}</small></div></div>
      <div><h3>I dag</h3>${linjer(o.linjerIdag)}</div>
      <div><h3>Denne måneden</h3>${linjer(o.linjerMnd)}</div>`);
  }
  /* Hele raden er trykkbar: et medlem åpner medlemmet under Medlemmer, en påmelding åpner kurset (der «Ta betalt» står). */
  function arkUbetalt() {
    const ub = NA.sist?.ubetalte || [];
    const sum = ub.reduce((s, r) => s + (Number(r.belopOre) || 0), 0);
    const mal = r => r.slag === 'medlem' ? (erMobil() ? '' : `data-medlem="${Number(r.id)}"`)
      : (r.oktId ? `data-gatil="kurs" data-p="${esc(new URLSearchParams({okt: r.oktId, booking: r.id}).toString())}"` : '');
    apneArk(`${arkHode('Ikke betalt · ' + kr(sum))}
      <div class="radliste">${ub.length ? ub.map(r => { const m = mal(r); const inn = `<span class="tekst"><b>${esc(r.navn)}</b><small>${esc([r.kurs, r.naar].filter(Boolean).join(' · '))}</small></span>
        <span class="merke rod">${esc(r.belop || kr(r.belopOre))}</span>`;
        return m ? `<button class="rad radknapp" type="button" ${m}>${inn}</button>` : `<div class="rad">${inn}</div>`; }).join('') : '<p class="tom">Alle har betalt.</p>'}</div>`);
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
    /* Én søkeboks per skjerm: står man på en side med egen liste (Medlemmer, Varer), filtrerer topplinjesøket den. */
    const side = sider.get(rute.id);
    if (side && typeof side.sok === 'function') { box.hidden = true; side.sok(q); return; }
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
  /* Uttak leire (eieren 08.10.2026): det som faktisk ligger i internbutikken. Leirevarene fra produkter.php
     (merket leire, i internbutikken, med lager, ikke kladd). Velg vare og antall; hver pose trekkes med den
     eksisterende «Ta ut» (produkter.php handling=taUt, én om gangen, uten betaling). Ingen faste leiretyper her. */
  const leireVarer = d => (d.varer || []).filter(v => v.leire && v.kunMedlemmer && v.lager !== null && v.status !== 'kladd');
  NA.uttakLeire = async function () {
    apneArk(arkHode('Uttak leire') + '<p class="laster">Henter leira …</p>');
    let varer;
    try { varer = leireVarer(await api('produkter.php')); } catch (e) { apneArk(arkHode('Uttak leire') + `<p class="feil">${esc(e.message)}</p>`); return; }
    let valgt = 0, antall = 1;
    const tegn = () => {
      const v = varer.find(x => x.id === valgt);
      if (v && antall > v.lager) antall = Math.max(1, v.lager);
      const inn = apneArk(`${arkHode('Uttak leire')}
      ${varer.length ? `<div class="hgruppe"><div class="type">Velg leire</div><div class="valgknapper">${varer.map(x => `<button class="knapp ${x.id === valgt ? 'hoved' : ''}" type="button" data-leire="${Number(x.id)}" aria-pressed="${x.id === valgt}" ${x.lager > 0 ? '' : 'disabled'}>${esc(x.tittel)} · ${Number(x.lager)} på lager</button>`).join('')}</div></div>
        <div class="hgruppe"><div class="type">Antall</div><div class="ant stor"><button type="button" data-ant="-1" aria-label="Færre" ${!v || antall <= 1 ? 'disabled' : ''}>−</button><b id="leire-ant" style="font-size:24px;min-width:2ch;text-align:center">${antall}</b><button type="button" data-ant="1" aria-label="Flere" ${!v || antall >= v.lager ? 'disabled' : ''}>+</button></div></div>`
        : '<p class="tom">Ingen leire i internbutikken. Merk varen som leire og internt under Varer.</p>'}
      <small>Fra lageret, uten betaling. Lageret oppdateres med en gang.</small>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Lukk</button>${varer.length ? `<button class="knapp hoved" type="button" data-taut ${v ? '' : 'disabled'}>Ta ut ${antall}</button>` : ''}</div>`);
      inn.onclick = async e => {
        const b = e.target.closest('button'); if (!b) return;
        if (b.dataset.leire) { valgt = Number(b.dataset.leire); antall = 1; return tegn(); }
        if (b.dataset.ant) { antall = Math.max(1, antall + Number(b.dataset.ant)); return tegn(); }
        if (b.dataset.taut === undefined || !v) return;
        b.disabled = true;
        let tatt = 0, feil = '';
        for (let i = 0; i < antall; i++) {
          try { await api('produkter.php', {data: {handling: 'taUt', id: v.id}}); tatt++; } catch (err) { feil = err.message; break; }
        }
        try { varer = leireVarer(await api('produkter.php')); } catch {}
        const naa = varer.find(x => x.id === v.id);
        toast(tatt ? `<b>Tatt ut ${tatt} × ${esc(v.tittel)}.</b>${naa ? ' Lageret er nå ' + Number(naa.lager) + '.' : ''}${feil ? ' ' + esc(feil) : ''}` : esc(feil || 'Fikk ikke tatt ut.'));
        antall = 1; tegn();
      };
    };
    tegn();
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
    const moduler = Promise.all(MODULER.map(f => new Promise(ferdig => {
      const s = document.createElement('script');
      s.type = 'module'; s.src = '/ny-admin/' + f + '.js';
      s.onload = ferdig; s.onerror = () => { console.error('ny-admin: ' + f + '.js lastet ikke'); ferdig(); };
      document.body.append(s);
    })));
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
    await moduler;
  }

  /* ── klikk ───────────────────────────────────────────────────────────── */
  document.addEventListener('click', e => {
    const b = e.target.closest('button,a');
    if (!e.target.closest('.sok-wrap') && $('#sokres')) $('#sokres').hidden = true;
    /* Brytere: hele raden er trykkflaten (designvokteren 08.10.2026), ikke bare den lille bryteren. */
    const bryterRad = !b && e.target.closest('.bryter');
    if (bryterRad && !e.target.closest('input,select,textarea,label')) { bryterRad.querySelector('button.av:not([disabled])')?.click(); return; }
    if (!b) return;
    const d = b.dataset;
    if (d.side) { if (ark().open && !lukkArk(false, {utenTilbake: true})) return; gaTil(d.side); window.scrollTo(0, 0); return; }
    if (d.gatil) { $('#sokres').hidden = true; $('#sok').value = ''; if (ark().open && !lukkArk(false, {utenTilbake: true})) return; gaTil(d.gatil, new URLSearchParams(d.p || '')); window.scrollTo(0, 0); return; }
    if (d.medlem) { lukkArk(true, {utenTilbake: true}); gaTil('medlemmer', {person: d.medlem}); return; }
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
    if (NA.meg) { if (ark().open) lukkArk(true, {utenTilbake: true}); tegnSide(); }
  });
  let bredde = innerWidth;
  let varMobil = erMobil();
  window.addEventListener('resize', () => { clearTimeout(window._naRz); window._naRz = setTimeout(() => { merkMobil(); if (varMobil !== erMobil() && NA.meg) { varMobil = erMobil(); tegnMeny(); tegnSide(); } bredde = innerWidth; }, 200); });

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
  Object.assign(window, {gaTil, gaaTil: gaTil});
  Object.assign(NA, {registrerSide, api, apneArk, lukkArk, toast, hentHvert});
  document.addEventListener('DOMContentLoaded', () => { settOppArk(); start(); });
})();
