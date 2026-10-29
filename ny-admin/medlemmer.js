/* Ny admin, del C: Medlemmer (eierens GO 08.10.2026, prototypen lissom-enklere-admin-2).
   Liste med status, «Gi gave» øverst og per medlem, «Gaver som gjelder nå» med Trekk, og
   «Send beskjed». Ekte data: api/admin/medlemmer.php (lista), gaver.php (gavene) og
   beskjed.php (utsendingen, i det felles «Send beskjed»-arket fra kalender.js). Ingen egne kopier av noe av det.
   #medlemmer?person=ID (søket, «Ikke betalt», «Må gjøres») markerer og åpner medlemmet. Søket i topplinja
   filtrerer lista her (ett søk per skjerm). */
(() => {
  'use strict';
  const NA = () => window.NyAdmin || window;
  const kall = (sti, data) => NA().api('/api/admin/' + sti, data === undefined ? { metode: 'GET' } : { metode: 'POST', data });
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const toast = h => NA().toast(h);
  const registrer = (id, opp) => { const f = NA().registrerSide; if (typeof f === 'function') f(id, opp); else document.addEventListener('DOMContentLoaded', () => registrer(id, opp), { once: true }); };

  const S = { medlemmer: [], gaver: [], sok: '', el: null, tikk: 0, opptatt: false, person: 0, planer: [], sortering: '', typeFilter: '', ble: null, oppf: null };

  /* ── Sortering, type og «sist innom» (eieren 12.10.2026, visningen «Medlemmer og oppfølging») ──
     Medlemskapene og rekkefølgen kommer fra membership_plans (planer, sortert på «sortering»). */
  const SORTER = [['lengst', 'Medlem lengst'], ['kortest', 'Nyeste medlem'], ['type', 'Type medlemskap'], ['navn', 'Navn A–Å'], ['sist', 'Lengst siden innom']];
  const sorteringNokkel = () => 'lissom-nyadmin-medlemmer-sortering-' + (NA().meg?.id || '');
  const lesSortering = () => { try { const v = localStorage.getItem(sorteringNokkel()); return SORTER.some(x => x[0] === v) ? v : 'lengst'; } catch { return 'lengst'; } };
  const husk = v => { try { localStorage.setItem(sorteringNokkel(), v); } catch {} };
  const idag = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; };
  const somDato = iso => { const [y, m, d] = String(iso || '').slice(0, 10).split('-').map(Number); return y ? new Date(y, m - 1, d) : null; };
  function varighet(fra) {
    const d = somDato(fra), I = idag(); if (!d) return '';
    let mnd = (I.getFullYear() - d.getFullYear()) * 12 + I.getMonth() - d.getMonth();
    if (I.getDate() < d.getDate()) mnd--;
    if (mnd < 1) { const dager = Math.max(0, Math.round((I - d) / 864e5)); return dager + (dager === 1 ? ' dag' : ' dager'); }
    const aar = Math.floor(mnd / 12), rest = mnd % 12;
    return [aar ? aar + ' år' : '', rest ? rest + ' mnd' : ''].filter(Boolean).join(' ');
  }
  const maanedAar = fra => { const d = somDato(fra); return d ? d.toLocaleDateString('nb-NO', { month: 'short', year: 'numeric' }) : ''; };
  const planIndeks = navn => { const i = S.planer.findIndex(p => p.navn === navn); return i < 0 ? 999 : i; };
  // Prøveplanen gul, det minste løpende medlemskapet nøytralt, de andre grønne (som i visningen).
  function typeKlasse(navn) {
    const p = S.planer.find(x => x.navn === navn); if (!p) return '';
    if (p.engangs) return 'gul';
    return S.planer.filter(x => !x.engangs)[0]?.navn === navn ? '' : 'gronn';
  }
  // Dager siden innom; aldri innom = dager siden medlemskapet startet.
  const dagerBorte = m => m.dagerSidenSist ?? (somDato(m.medlemFra) ? Math.max(0, Math.round((idag() - somDato(m.medlemFra)) / 864e5)) : 0);
  /* «Sist innom» (eieren 12.10.2026): bare etter mer enn 7 dager. 8–13 grå, 14–21 gul, over 21 rød.
     Står under navnet bak « · » (eieren 12.10.2026, «Alle merkene på linje»), ikke som eget merke. */
  function sistTekst(m) {
    const d = dagerBorte(m); if (d <= 7) return '';
    const k = d > 21 ? 'rod' : d >= 14 ? 'gul' : '';
    return `<span class="sist ${k}">${m.dagerSidenSist == null ? 'Aldri innom' : d + ' d siden sist'}</span>`;
  }
  // «X av Y t igjen»: timer igjen av månedens timer (medlemmer.php timerIgjen/timer). Uten timetak: ingenting.
  const tall = t => String(Math.round(Number(t) * 10) / 10).replace('.', ',');
  const igjenTekst = m => m.timer == null || m.timerIgjen == null ? '' : `${esc(m.timerIgjen)} av ${tall(m.timer)} t igjen`;
  // «Aktiv»/«Prøveperiode» står først som før (vedtaket medlemmer-betalt-merke), så medlemskapet, «sist innom» og betalingen.
  const statusMerke = m => m.status === 'prove' ? '<span class="merke gul">Prøveperiode</span>' : '<span class="merke gronn">Aktiv</span>';
  // knapp = id: merket blir en trykkbar pille som åpner betalingen for personen (lista).
  function betalingMerke(m, knapp = 0) {
    const [k, t] = m.betalingUte ? ['rod', m.betalingTekst || 'Ikke betalt']
      : m.betaling === 'betalt' ? ['gronn', 'Betalt']
      : m.betaling === 'fri' ? ['', 'Betaler ikke']
      : m.betaling === 'fryst' ? ['', 'Fryst'] : [];
    if (!t) return '';
    return knapp ? `<button type="button" class="merke trykk ${k}" data-mlm="betaling" data-id="${knapp}">${esc(t)}</button>` : `<span class="merke ${k}">${esc(t)}</span>`;
  }
  function sorter(L) {
    const fra = m => String(m.medlemFra || '9999');
    const f = {
      lengst: (a, b) => fra(a).localeCompare(fra(b)),
      kortest: (a, b) => fra(b).localeCompare(fra(a)),
      type: (a, b) => planIndeks(a.medlemskap) - planIndeks(b.medlemskap) || fra(a).localeCompare(fra(b)),
      navn: (a, b) => String(a.navn).localeCompare(String(b.navn), 'nb'),
      sist: (a, b) => dagerBorte(b) - dagerBorte(a),
    }[S.sortering] || (() => 0);
    return [...L].sort((a, b) => f(a, b) || String(a.navn).localeCompare(String(b.navn), 'nb'));
  }
  function tegnVelger() {
    const boks = document.getElementById('mlm-velger'); if (!boks) return;
    const alle = liste();
    const typer = S.planer.map(p => p.navn).filter(n => alle.some(m => m.medlemskap === n));
    const piller = [['', `Alle (${alle.length})`], ...typer.map(n => [n, `${n} (${alle.filter(m => m.medlemskap === n).length})`])];
    boks.innerHTML = `<label style="display:grid;gap:5px;max-width:280px"><small>Sorter etter</small><select id="mlm-sorter" style="padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit;min-height:44px">${SORTER.map(([v, t]) => `<option value="${v}" ${S.sortering === v ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
      <div class="faner" style="margin:12px 0 4px">${piller.map(([v, t]) => `<button type="button" data-mlm="typefilter" data-v="${esc(v)}" aria-pressed="${S.typeFilter === v}">${esc(t)}</button>`).join('')}</div>`;
  }
  function tegnBle() {
    const boks = document.getElementById('mlm-ble'); if (!boks) return;
    const b = S.ble;
    if (!b || !b.av) { boks.hidden = true; return; }
    const pil = b.forrige == null || b.forrige === b.prosent ? '' : b.prosent > b.forrige ? `<span class="merke gronn">↑ fra ${b.forrige} %</span>` : `<span class="merke gul">↓ fra ${b.forrige} %</span>`;
    boks.hidden = false;
    boks.innerHTML = `<div class="rad"><div class="tekst"><b>${b.ble} av ${b.av} ble medlem</b><small>Prøv Lissom siste 3 måneder · ${b.prosent} %</small></div>${pil}</div>`;
  }
  async function hentBle() {
    try { S.ble = (await kall('oppfolging.php')).ble || null; } catch { S.ble = null; }
    tegnBle();
  }
  // Gaveskjemaet. Ingenting er valgt på forhånd (eierens regel), og «belop» er tomt til Monica skriver det.
  const GV = { type: '', til: '', medlemId: 0, timer: 0, belop: '', hilsen: '' };

  /** Bare medlemmene: aktive og på prøve, uten admin-brukerne (samme utvalg som «Send beskjed» når). */
  const erMedlem = m => !m.rolleAdmin && (m.status === 'aktiv' || m.status === 'prove');
  const liste = () => S.medlemmer.filter(erMedlem);

  /* Status og betaling hver for seg (eieren 12.10.2026: «vil jeg også se om det er betalt eller ikke, nå står det bare aktivt»). */
  function merke(m) {
    const status = m.status === 'prove' ? '<span class="merke gul">Prøveperiode</span>' : '<span class="merke gronn">Aktiv</span>';
    const betaling = m.betalingUte ? `<span class="merke rod">${esc(m.betalingTekst || 'Ikke betalt')}</span>`
      : m.betaling === 'betalt' ? '<span class="merke gronn">Betalt</span>'
      : m.betaling === 'fri' ? '<span class="merke">Betaler ikke</span>'
      : m.betaling === 'fryst' ? '<span class="merke">Fryst</span>' : '';
    return `<span class="merker">${status}${betaling}</span>`;
  }

  function gaveTittel(g) {
    if (g.type === 'timer') return `${g.timer} ekstra time${Number(g.timer) === 1 ? '' : 'r'}`;
    if (g.type === 'gavekort') return `Gavekort på ${Number(g.belop || 0).toLocaleString('nb-NO')} kr`;
    return 'Ta med en venn';
  }

  function tegnGaver() {
    const boks = document.getElementById('mlm-gaver');
    if (!boks) return;
    const naa = S.gaver.filter(g => !g.trukket && !g.utloept);
    boks.innerHTML = naa.length ? naa.map(g => `<div class="rad"><div class="tekst"><b>${esc(g.tittel)}</b>
        <small>${esc(g.mottaker)}${g.hilsen ? ` · «${esc(g.hilsen)}»` : ''} · gjelder ut ${esc(g.gyldigTil)} · ${g.brukt} har løst inn${g.innloestAv ? ` (${esc(g.innloestAv)})` : ''}</small></div>
        <button class="knapp liten rod" data-mlm="trekk" data-id="${g.id}">Trekk gaven</button></div>`).join('')
      : '<p class="muted">Ingen gaver nå.</p>';
  }

  function tegnListe() {
    const boks = document.getElementById('mlm-liste');
    if (!boks) return;
    if (!document.getElementById('mlm-rader')) boks.innerHTML = '<div id="mlm-velger"></div><div id="mlm-rader"></div>';
    const q = S.sok.trim().toLowerCase();
    const L = sorter(liste().filter(m => (!S.typeFilter || m.medlemskap === S.typeFilter)
      && (!q || [m.navn, m.epost, m.telefon, m.medlemskap].some(v => String(v || '').toLowerCase().includes(q)))));
    tegnVelger();
    /* Rutenett med faste kolonner (eieren 12.10.2026, «Alle merkene på linje»): Navn · Status · Medlemskap · Betaling.
       Hele raden åpner medlemmet. Medlemskapet åpner «Endre» (bytte av medlemskap), betalingen åpner betalingen
       for personen (samme sted som «Betaling og detaljer» i arket). Medlemstiden er en boble over navnet. */
    const rader = L.length ? `<div class="mlm-tabell"><div class="mlm-rad mlm-hode" aria-hidden="true"><span>Navn</span><span>Status</span><span>Medlemskap</span><span>Betaling</span></div>
      ${L.map(m => {
        const under = [igjenTekst(m), sistTekst(m)].filter(Boolean).join(' · ');
        return `<div role="button" tabindex="0" class="rad radknapp mlm-rad ${m.id === S.person ? 'markert' : ''}" data-mlm="medlem" data-id="${m.id}"><span class="tekst">
          <span class="navn" data-mlm-navn>${esc(m.navn)}${m.medlemFra ? `<span class="boble" role="tooltip">Medlem i ${esc(varighet(m.medlemFra))} · siden ${esc(maanedAar(m.medlemFra))}</span>` : ''}</span>
          ${under ? `<small class="igjen">${under}</small>` : ''}</span>
          <span class="merker"><span>${statusMerke(m)}</span>
          <span>${m.medlemskap ? `<button type="button" class="merke trykk ${typeKlasse(m.medlemskap)}" data-mlm="endre" data-id="${m.id}">${esc(m.medlemskap)}</button>` : ''}</span>
          <span>${betalingMerke(m, m.id)}</span></span></div>`;
      }).join('')}</div>`
      : '<p class="muted">Ingen treff.</p>';
    const r = document.getElementById('mlm-rader'); if (r) r.innerHTML = rader;
    const n = liste().length;
    const ey = document.getElementById('mlm-antall'); if (ey) ey.textContent = `${n} medlemmer`;
  }
  // Medlemmet fra adressen (person=): markeres i lista og åpnes. Er det ikke aktivt medlem, finnes det i gammel admin.
  function visPerson() {
    if (!S.person || !S.medlemmer.length) return;
    const id = S.person;
    const r = document.querySelector(`[data-mlm="medlem"][data-id="${id}"]`);
    if (r) r.scrollIntoView({ block: 'center' });
    arkMedlem(id);
    S.person = 0;
  }
  function arkMedlem(id) {
    const m = S.medlemmer.find(x => x.id === id);
    if (!m) {
      NA().apneArk(`<div class="ark-head"><h2>Medlemmet</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
        <p class="muted">Står ikke blant de aktive medlemmene. Betaling og historikk finnes i gammel admin.</p>
        <div class="ark-fot"><a class="knapp hoved" href="/admin-ny#folk?person=${id}">Til personen i gammel admin</a></div>`);
      return;
    }
    NA().apneArk(`<div class="ark-head"><h2>${esc(m.navn)}</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <p>${merke(m)} <span class="muted">${esc(m.medlemskap || '')}${m.timerIgjen ? ` · ${esc(m.timerIgjen)} igjen denne måneden` : ''}</span></p>
      <p class="muted">${esc([m.epost, m.telefon].filter(Boolean).join(' · ') || 'Ingen kontaktinfo')}</p>
      ${m.betalingUte ? `<p class="feil">${esc(m.betalingTekst || 'Ikke betalt')}</p>` : ''}
      ${typeof m.dugnadUtenGodkjenning === 'boolean' ? `<div class="bryter"><div><b>Dugnad uten godkjenning</b><br><small>Medlemmet kan stemple inn dugnad uten at den godkjennes først.</small></div>
        <button class="av" type="button" data-mlm="dugnadfri" data-id="${m.id}" aria-pressed="${m.dugnadUtenGodkjenning}" aria-label="Dugnad uten godkjenning"></button></div>` : ''}
      <div id="mlm-oppf" data-id="${m.id}"></div>
      <div class="valgknapper"><button class="knapp" data-mlm="endre" data-id="${m.id}">Endre</button>
        <button class="knapp" data-mlm="gave" data-id="${m.id}">🎁 Gi gave</button>
        <button class="knapp" data-mlm="beskjeden" data-id="${m.id}" ${m.epost || m.telefon ? '' : 'disabled'}>✉ Send beskjed</button>
        <button class="knapp" data-mlm="stempling" data-id="${m.id}">Verkstedtid</button>
        <button class="knapp" data-mlm="juster" data-id="${m.id}">Juster timer</button>
        <a class="knapp" href="/admin-ny#folk?person=${m.id}">Betaling og detaljer (gammel admin)</a></div>
      <div id="mlm-hist" data-id="${m.id}"><p class="muted">Henter historikk …</p></div>`);
    hentOppf(m);
    hentHist(m);
  }
  /* Historikken fra gammel admin rett i medlemskortet (eieren 20.10.2026, visningen XFi6a5DL34Ya7jr4YVjzHs):
     timene denne måneden med «Juster timer», betalinger, kurs, ventelister og endringsloggen. Samme kall som
     gammel admin (medlemmer.php?person=). */
  const tt = min => String(Math.round(Math.abs(min) / 60 * 10) / 10).replace('.', ',') + ' t';
  async function hentHist(m) {
    let d; try { d = await kall('medlemmer.php?person=' + m.id); } catch (e) {
      const b = document.getElementById('mlm-hist'); if (b && Number(b.dataset.id) === m.id) b.innerHTML = '<p class="feil">' + esc(e.message) + '</p>'; return;
    }
    const boks = document.getElementById('mlm-hist'); if (!boks || Number(boks.dataset.id) !== m.id) return;
    const del = (tittel, innhold) => `<h3 style="margin-top:18px">${tittel}</h3>${innhold}`;
    const rad = (b, small, hoyre = '', klasse = '') => `<div class="rad"${klasse ? ` style="color:var(--${klasse})"` : ''}><div class="tekst"><b>${esc(b)}</b>${small ? `<small>${esc(small)}</small>` : ''}</div>${hoyre ? `<div class="knapper"><b>${esc(hoyre)}</b></div>` : ''}</div>`;
    const ingen = '<p class="muted">Ingen.</p>';
    const t = d.timerMnd || {};
    const mnd = new Date().toLocaleDateString('nb-NO', { month: 'long', timeZone: 'Europe/Oslo' });
    let timer = '';
    if (t.plan == null) timer = '<p class="muted">Fri tilgang – ingen timegrense.</p>';
    else {
      timer = rad('Medlemskapet', '', t.plan + ' t')
        + (t.justeringer || []).map(j => rad(j.minutter < 0 ? 'Trukket av verkstedet' : 'Lagt til av verkstedet',
          [j.naar, j.grunn, j.av].filter(Boolean).join(' · '), (j.minutter < 0 ? '−' : '+') + tt(j.minutter), j.minutter < 0 ? 'danger' : 'sage-600')).join('')
        + rad('Brukt (stemplet)', '', tt(t.bruktMin || 0))
        + (t.tak == null ? '' : rad('Igjen', '', tt(Math.max(0, t.tak * 60 - (t.bruktMin || 0)))));
    }
    boks.innerHTML = del('Timer i ' + esc(mnd), timer)
      + del('Betalingshistorikk', (d.betalinger || []).map(p => rad((p.kurs || 'Betaling') + (p.annullert ? ' (annullert)' : ''), [p.maate, p.naar, p.kommentar].filter(Boolean).join(' · '), p.belop)).join('') || ingen)
      + del('Kurshistorikk', (d.historikk || []).map(k => rad(k.tittel, [k.naar, k.status].filter(Boolean).join(' · '), k.sum)).join('') || ingen)
      + del('Ventelister', (d.ventelister || []).map(v => rad(v.kurs || v.tittel || 'Venteliste', v.siden ? 'Siden ' + v.siden : '')).join('') || ingen)
      + del('Endringslogg', (d.logg || []).map(l => rad(l.hva, [l.av, l.naar].filter(Boolean).join(' · '))).join('') || ingen);
  }
  function arkJuster(id) {
    const m = S.medlemmer.find(x => x.id === id) || { navn: 'Medlemmet' };
    NA().apneArk(`<div class="ark-head"><h2>${esc(m.navn)} · Juster timer</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <label class="felt"><small>Timer – skriv minus for å trekke, f.eks. -2. Skriv 1,5 for å legge til.</small><input id="mlm-ju-timer" type="text" inputmode="decimal" placeholder="-2"></label>
      <label class="felt"><small>Grunn (bare synlig i admin)</small><input id="mlm-ju-grunn" type="text" maxlength="255"></label>
      <p class="muted">Gjelder denne måneden.</p>
      <p id="mlm-ju-feil" class="feil" role="alert" hidden></p>
      <div class="ark-fot"><button class="knapp" data-mlm="medlem" data-id="${id}">Avbryt</button><button class="knapp hoved" data-mlm="justerlagre" data-id="${id}">Lagre</button></div>`);
    document.getElementById('mlm-ju-timer')?.focus();
  }
  /* Norske datoer i Verkstedtid (eieren 20.10.2026, visningen KParQyDBGMo9sZZznKd6fg): «Torsdag 1. · 17:48–20:16»,
     uten år. Måneden står bare med når økta ikke er i inneværende måned. Datoen velges som dag i inneværende måned. */
  const UKEDAG = ['Søndag', 'Mandag', 'Tirsdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lørdag'];
  const MND = ['jan.', 'feb.', 'mars', 'apr.', 'mai', 'juni', 'juli', 'aug.', 'sep.', 'okt.', 'nov.', 'des.'];
  const isoDag = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  function dagTekst(iso) {
    const d = somDato(iso); if (!d) return '';
    const n = idag();
    return UKEDAG[d.getDay()] + ' ' + d.getDate() + '.' + (d.getMonth() === n.getMonth() && d.getFullYear() === n.getFullYear() ? '' : ' ' + MND[d.getMonth()]);
  }
  const oktTekst = (inn, ut) => dagTekst(inn) + ' · ' + String(inn || '').slice(11, 16) + '–' + (ut ? String(ut).slice(11, 16) : 'pågår');
  const minTekst = min => { const t = Math.floor(min / 60), m = min % 60; return (t ? t + ' t' : '') + (t && m ? ' ' : '') + (m || !t ? m + ' min' : ''); };
  function dagValg(valgt) {
    const n = idag(), dager = [];
    for (let d = n.getDate(); d >= 1; d--) dager.push(new Date(n.getFullYear(), n.getMonth(), d));
    const v = valgt || isoDag(n);
    const ekstra = dager.some(d => isoDag(d) === v) ? '' : `<option value="${esc(v)}" selected>${esc(dagTekst(v))}</option>`;
    return ekstra + dager.map((d, i) => `<option value="${isoDag(d)}"${isoDag(d) === v ? ' selected' : ''}>${esc(dagTekst(isoDag(d)))}${i === 0 ? ' (i dag)' : ''}</option>`).join('');
  }
  async function arkStempling(id, sakId = 0, oktId = 0) {
    NA().apneArk('<div class="ark-head"><h2>Verkstedtid</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div><p>Henter økter …</p>', { bred: true });
    let d;
    try { d = await kall('stempling-rett.php?medlemId=' + Number(id) + '&sakId=' + Number(sakId) + '&oktId=' + Math.max(0, Number(oktId))); }
    catch (err) { NA().apneArk('<div class="ark-head"><h2>Verkstedtid</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div><p class="feil">' + esc(err.message) + '</p>'); return; }
    const sak = d.saker.find(x => x.id === Number(sakId)) || (oktId > 0
      ? d.saker.find(x => x.oktId === Number(oktId) && (x.status === 'apen' || x.status === 'trenger_info')) : null);
    const okt = d.okter.find(x => x.id === (sak ? sak.oktId : Number(oktId))) || (!sakId && !oktId ? d.okter.find(x => x.ut) : null);
    const inn = sak?.inn || okt?.inn || '', ut = sak?.ut || okt?.ut || '';
    const dato = inn.slice(0, 10), fra = inn.slice(11, 16), til = ut.slice(11, 16);
    const innhold = NA().apneArk(`<div class="ark-head"><h2>${esc(d.medlem.navn)} · Verkstedtid</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <p class="muted">Velg en økt eller en sak. Medlemmet kan foreslå tid; bare admin kan endre timeforbruket.</p>
      <h3>Åpne forslag</h3>${d.saker.filter(x => x.status === 'apen' || x.status === 'trenger_info').map(x => `<div class="rad"><div class="tekst"><b>${esc(oktTekst(x.inn, x.ut))}</b><small>${esc(x.forklaring)} · ${esc(x.status)}</small></div><div class="knapper"><button class="knapp liten" data-mlm="stemplingsak" data-id="${id}" data-sak="${x.id}">Åpne</button></div></div>`).join('') || '<p class="muted">Ingen åpne forslag.</p>'}
      <h3 style="margin-top:18px">Økter</h3>${d.okter.map(x => `<div class="rad"><div class="tekst"><b>${esc(oktTekst(x.inn, x.ut))}</b><small>${x.minutter == null ? 'Pågår' : minTekst(x.minutter) + ' telt'}${x.auto ? ' · automatisk lukket' : ''}</small></div><div class="knapper"><button class="knapp liten" data-mlm="stemplingokt" data-id="${id}" data-okt="${x.id}" ${x.ut ? '' : 'disabled'}>Rett</button></div></div>`).join('') || '<p class="muted">Ingen økter registrert.</p>'}
      <div class="ark-fot"><button class="knapp" data-mlm="stemplingokt" data-id="${id}" data-okt="-1">Legg inn manglende økt</button></div>
      <h3 style="margin-top:18px">${sak ? 'Behandle forslag' : okt ? 'Rett valgt økt' : 'Legg inn økt'}</h3>
      ${okt ? `<p>Registrert: ${esc(oktTekst(okt.inn, okt.ut))} · ${minTekst(okt.minutter || 0)} telt.</p>` : ''}
      ${sak ? `<p class="sms">Medlemmet foreslår ${esc(oktTekst(sak.inn, sak.ut))}. ${esc(sak.forklaring)}</p>` : ''}
      <div class="valgknapper"><label class="felt"><small>Dag i ${esc(new Date().toLocaleDateString('nb-NO', { month: 'long' }))}</small><select id="mlm-st-dato">${dagValg(dato)}</select></label><label class="felt"><small>Riktig inn</small><input id="mlm-st-inn" type="text" inputmode="numeric" maxlength="5" placeholder="11:00" value="${esc(fra)}"></label><label class="felt"><small>Riktig ut</small><input id="mlm-st-ut" type="text" inputmode="numeric" maxlength="5" placeholder="16:00" value="${esc(til)}"></label></div>
      <label class="felt"><small>Begrunnelse</small><textarea id="mlm-st-grunn" placeholder="Hva ble avklart med medlemmet?"></textarea></label>
      <p id="mlm-st-feil" class="feil" role="alert" hidden></p>
      <div class="ark-fot"><button class="knapp hoved" data-mlm="stemplinglagre" data-id="${id}" data-okt="${okt?.id || 0}" data-sak="${sak?.id || 0}" data-gammelmin="${okt?.minutter ?? 0}">Lagre riktig tid</button>${sak ? `<button class="knapp" data-mlm="stemplinginfo" data-id="${id}" data-sak="${sak.id}">Be om info</button><button class="knapp" data-mlm="stemplingavslaa" data-id="${id}" data-sak="${sak.id}">Avslå</button>` : ''}</div>`, { bred: true });
    innhold.querySelector('#mlm-st-dato')?.focus();
  }
  NA().arkStempling = arkStempling;
  /* Medlemskortet: påminnelse-SMS per medlem og notatene fra «Snakket med» (eieren 12.10.2026). */
  async function hentOppf(m) {
    let d; try { d = await kall('oppfolging.php?medlem=' + m.id); } catch { return; }
    const boks = document.getElementById('mlm-oppf'); if (!boks || Number(boks.dataset.id) !== m.id) return;
    S.oppf = d;
    tegnOppf(m);
  }
  function tegnOppf(m) {
    const boks = document.getElementById('mlm-oppf'); const d = S.oppf; if (!boks || !d) return;
    const fornavn = String(m.navn || '').split(' ')[0];
    const p = d.paaminnelse || {};
    const status = m.paaminnelseSms ? 'På' + (p.antall ? ` · sendt ${p.antall} gang${p.antall === 1 ? '' : 'er'}${p.sist ? ` (${esc(p.sist)})` : ''}` : '')
      : `Av · ${esc(fornavn)} får ingen påminnelse-SMS`;
    boks.innerHTML = (typeof m.paaminnelseSms === 'boolean' ? `<div class="bryter"><div><b>Påminnelse-SMS når hen ikke kommer</b><br><small>${status}</small></div>
        <button class="av" type="button" data-mlm="paaminnelse" data-id="${m.id}" aria-pressed="${m.paaminnelseSms}" aria-label="Påminnelse-SMS for ${esc(m.navn)}"></button></div>` : '')
      + (d.notater || []).map(n => `<div class="rad"><div class="tekst"><b>Notat</b><small>${n.notat ? `«${esc(n.notat)}»` : 'Snakket med'} – ${esc(n.av || 'admin')} ${esc(n.dato)}</small></div></div>`).join('');
  }

  /* Endre personopplysninger og medlemskap i ett skjema (eieren 12.10.2026, valgt «Ett skjema»).
     Samme kall som gammel admin: medlemmer.php «kontakt» (navn, e-post, telefon, datoer, timer) og «bytt-plan».
     Datoene og timetallet tømmes når feltet er tomt, så skjemaet fylles med det som står. Byttes medlemskapet,
     kjøres «bytt-plan» sist: sluttdatoen hører til planen (engangsplan = én måned). */
  function arkEndre(id) {
    const m = S.medlemmer.find(x => x.id === id); if (!m) return;
    const planer = S.planer.some(p => p.navn === m.medlemskap) || !m.medlemskap ? S.planer : [{ navn: m.medlemskap, pris: '' }, ...S.planer];
    const felt = (navn, id2, verdi, type = 'text', ekstra = '') => `<label class="felt"><small>${navn}</small><input id="${id2}" type="${type}" value="${esc(verdi || '')}" ${ekstra}></label>`;
    NA().apneArk(`<div class="ark-head"><h2>Endre ${esc(m.navn)}</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <div style="display:grid;gap:12px">
        ${felt('Navn', 'mlm-e-navn', m.navn, 'text', 'autocomplete="off"')}
        ${felt('E-post', 'mlm-e-epost', m.epost, 'email', 'autocomplete="off"')}
        ${felt('Telefon', 'mlm-e-telefon', m.telefon, 'tel', 'autocomplete="off"')}
        <label class="felt"><small>Medlemskap</small><select id="mlm-e-plan">${planer.map(p => `<option value="${esc(p.navn)}" ${p.navn === m.medlemskap ? 'selected' : ''}>${esc(p.navn)}${p.pris ? ' · ' + esc(p.pris) : ''}</option>`).join('')}</select></label>
        ${felt('Medlem fra', 'mlm-e-fra', String(m.startDato || '').slice(0, 10), 'date')}
        ${felt('Medlem til (tom = løper)', 'mlm-e-til', String(m.sluttDato || '').slice(0, 10), 'date')}
        ${felt('Timer per måned (tom = følger medlemskapet)', 'mlm-e-timer', m.timerEgen ?? '', 'text', 'inputmode="numeric"')}
        <p class="feil" id="mlm-e-feil" hidden></p>
      </div>
      <div class="ark-fot"><button class="knapp" data-mlm="lukk">Avbryt</button><button class="knapp hoved" data-mlm="lagreendre" data-id="${m.id}">Lagre</button></div>`);
  }
  async function lagreEndre(b, id) {
    const m = S.medlemmer.find(x => x.id === id); if (!m) return;
    const v = x => (document.getElementById(x)?.value || '').trim();
    const feil = document.getElementById('mlm-e-feil');
    const plan = v('mlm-e-plan');
    b.disabled = true;
    try {
      const r = await kall('medlemmer.php', { handling: 'kontakt', medlemId: id, navn: v('mlm-e-navn'), epost: v('mlm-e-epost'), telefon: v('mlm-e-telefon'),
        startDato: v('mlm-e-fra'), sluttDato: v('mlm-e-til'), timer: v('mlm-e-timer') });
      let beskjed = r.beskjed || 'Lagret.';
      if (plan && plan !== m.medlemskap) beskjed = (await kall('medlemmer.php', { handling: 'bytt-plan', medlemId: id, type: plan })).beskjed || beskjed;
      NA().lukkArk(true); toast(esc(beskjed));
      await hentMedlemmer();
    } catch (e) { if (feil) { feil.textContent = e.message; feil.hidden = false; } b.disabled = false; }
  }

  /* Brukere (eieren 22.10.2026): innlogging, roller, passord og kasse-PIN, samme api/admin/brukere.php som gammel admin. */
  const RB = { liste: [], minst: 12, kanKasse: false };
  const ROLLER = () => [['medlem', 'Medlem'], ['kursholder', 'Kursholder'], ['regnskap', 'Regnskap'], ...(RB.kanKasse ? [['kasse', 'Kasse (iPad)']] : []), ['admin', 'Administrator']];
  const ROLLENAVN = { admin: 'Administrator', regnskap: 'Regnskap', kasse: 'Kasse', medlem: 'Medlem' };
  async function arkBrukere() {
    NA().apneArk(`<div class="ark-head"><h2>Brukere</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div><p class="muted" data-mlm-brukere>Henter …</p>`);
    let d;
    try { d = await kall('brukere.php'); } catch (e) { const p = document.querySelector('[data-mlm-brukere]'); if (p) { p.className = 'feil'; p.textContent = e.message; } return; }
    if (!document.querySelector('[data-mlm-brukere]')) return;
    Object.assign(RB, { liste: d.brukere || [], minst: d.minstLengde || 12, kanKasse: !!d.kanKasse });
    NA().apneArk(`<div class="ark-head"><h2>Brukere</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <p class="muted">Innlogging, roller og passord.</p>
      ${RB.liste.map(r => `<div class="rad"><div class="tekst"><b>${esc(r.navn || r.brukernavn)}</b><small>${esc([r.brukernavn, r.epost, r.sist].filter(Boolean).join(' · '))}</small>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">${r.kursholder && r.rolle === 'medlem' ? '' : `<span class="merke">${esc(ROLLENAVN[r.rolle] || r.rolle)}</span>`}${r.kursholder ? '<span class="merke">Kursholder</span>' : ''}${r.erDeg ? '<span class="merke">Deg</span>' : ''}${r.fraNodluke ? '<span class="merke">Tilgang via oppsett</span>' : ''}${r.harKassePin ? '<span class="merke gronn">Kasse-PIN</span>' : ''}</div></div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end"><button class="knapp" data-mlm="bruker" data-id="${r.id}">Endre</button>
        ${RB.kanKasse && r.rolle !== 'kasse' ? `<button class="knapp" data-mlm="kassepin" data-id="${r.id}">${r.harKassePin ? 'Ny kasse-PIN' : 'Kasse-PIN'}</button>` : ''}
        ${RB.kanKasse && r.harKassePin ? `<button class="knapp" data-mlm="fjernpin" data-id="${r.id}">Fjern kasse-PIN</button>` : ''}
        ${!r.erDeg && !r.fraNodluke ? `<button class="knapp" data-mlm="brukerslett" data-id="${r.id}">Fjern tilgang</button>` : ''}</div></div>`).join('') || '<p class="muted">Ingen brukere.</p>'}
      <div class="ark-fot"><button class="knapp" data-mlm="lukk">Lukk</button><button class="knapp hoved" data-mlm="bruker" data-id="0">Ny bruker</button></div>`);
  }
  function arkBruker(id, verdier = null, feiltekst = '') {
    const r = verdier || RB.liste.find(x => x.id === id) || {};
    const rolle = verdier ? verdier.rolle : (r.rolle || 'medlem');
    const felt = (navn, id2, verdi, type = 'text', ekstra = '') => `<label class="felt"><small>${navn}</small><input id="${id2}" type="${type}" value="${esc(verdi || '')}" autocomplete="off" ${ekstra}></label>`;
    NA().apneArk(`<div class="ark-head"><h2>${id ? 'Endre ' + esc(r.navn || r.brukernavn) : 'Ny bruker'}</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <div style="display:grid;gap:12px">
        ${felt('Navn', 'mlm-b-navn', r.navn)}
        ${felt('Brukernavn', 'mlm-b-brukernavn', r.brukernavn)}
        ${felt('E-post', 'mlm-b-epost', r.epost, 'email')}
        ${id ? '' : felt('Telefon', 'mlm-b-telefon', r.telefon, 'tel')}
        <label class="felt"><small>Tilgang</small><select id="mlm-b-rolle">${ROLLER().map(([v, t]) => `<option value="${v}" ${v === rolle ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
        <label id="mlm-b-holder-rad" style="display:${rolle === 'admin' || rolle === 'medlem' ? 'flex' : 'none'};align-items:center;gap:8px"><input id="mlm-b-holder" type="checkbox" ${(verdier ? verdier.kursholder === 'ja' : !!r.kursholder) ? 'checked' : ''}>Kursholder</label>
        ${felt(id ? 'Nytt passord (tomt = beholdes)' : 'Passord', 'mlm-b-passord', '', 'password', 'autocomplete="new-password"')}
        <small class="muted">Passordet må være minst ${RB.minst} tegn.</small>
        <p class="feil" id="mlm-b-feil" ${feiltekst ? '' : 'hidden'}>${esc(feiltekst)}</p>
      </div>
      <div class="ark-fot"><button class="knapp" data-mlm="brukere">Tilbake</button><button class="knapp hoved" data-mlm="lagrebruker" data-id="${id}">Lagre bruker</button></div>`);
  }
  async function lagreBruker(b, id) {
    const v = x => (document.getElementById(x)?.value || '').trim();
    const data = { handling: id ? 'endre' : 'opprett', id, navn: v('mlm-b-navn'), brukernavn: v('mlm-b-brukernavn'), epost: v('mlm-b-epost'), rolle: v('mlm-b-rolle'), passord: document.getElementById('mlm-b-passord')?.value || '' };
    if (data.rolle === 'admin' || data.rolle === 'medlem') data.kursholder = document.getElementById('mlm-b-holder')?.checked ? 'ja' : 'nei';
    if (['regnskap', 'kasse'].includes(data.rolle) && RB.liste.some(r => r.id === id && r.kursholder)) data.kursholder = 'behold';
    if (!id) data.telefon = v('mlm-b-telefon');
    const vis = t => arkBruker(id, data, t);
    if (data.passord && data.passord.length < RB.minst) return vis('Passordet må være minst ' + RB.minst + ' tegn.');
    if ((data.rolle === 'kursholder' || data.kursholder === 'ja') && !data.epost) return vis('Skriv e-posten til kursholderen.');
    const tekst = { admin: 'Denne personen får tilgang til hele administrasjonen.', regnskap: 'Denne personen får tilgang til økonomi og betalinger.', kasse: 'Denne kontoen kommer bare til kassa på iPaden.', kursholder: 'Denne personen får medlemstilgang og blir lagt til under Kursholdere.' }[data.rolle] || 'Denne personen får vanlig medlemstilgang.';
    if (!await NA().bekreft('Lagre tilgang?', tekst, 'Lagre bruker')) return vis('');
    try { await kall('brukere.php', data); toast('Brukeren er lagret.'); arkBrukere(); }
    catch (e) { vis(e.message); }
  }
  function arkKassePin(id) {
    const r = RB.liste.find(x => x.id === id); if (!r) return;
    NA().apneArk(`<div class="ark-head"><h2>Kasse-PIN · ${esc(r.navn || r.brukernavn)}</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <div style="display:grid;gap:12px"><label class="felt"><small>PIN (fire sifre)</small><input id="mlm-b-pin" type="text" inputmode="numeric" maxlength="4" autocomplete="off"></label><p class="feil" id="mlm-b-feil" hidden></p></div>
      <div class="ark-fot"><button class="knapp" data-mlm="brukere">Tilbake</button><button class="knapp hoved" data-mlm="lagrepin" data-id="${id}">Lagre PIN</button></div>`);
  }

  async function hentGaver() {
    try { S.gaver = (await kall('gaver.php')).gaver || []; } catch (e) { S.gaver = []; toast(esc(e.message)); }
    tegnGaver();
  }
  async function hentMedlemmer() {
    try { const d = await kall('medlemmer.php'); S.medlemmer = d.medlemmer || []; S.planer = d.planer || []; } catch (e) { toast(esc(e.message)); }
    tegnListe();
  }
  /* «Må gjøres › Betaling» på mobil (eieren 09.10.2026): samme medlemsark som på PC, uten å bytte side
     (Medlemmer er ikke en mobilside). Lista hentes først om den ikke er hentet. */
  NA().arkMedlem = async id => { if (!S.medlemmer.length) await hentMedlemmer(); arkMedlem(Number(id)); };

  async function tegn(el, params) {
    S.el = el;
    S.person = Number(params?.get?.('person')) || 0;
    S.sok = '';
    S.sortering = lesSortering();
    el.innerHTML = `<div class="head"><div><div class="eyebrow" id="mlm-antall">Henter …</div><h1>Folk</h1></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="knapp" data-mlm="brukere">🔑 Brukere</button><button class="knapp" data-mlm="gave">🎁 Gi gave</button>
      <button class="knapp hoved" data-mlm="beskjed" id="mlm-beskjedknapp">✉ Send beskjed</button></div></div>
      <section class="kort" style="margin-bottom:20px"><div class="kort-head"><h2>Gaver som gjelder nå</h2><small>Medlemmene ser dem på Min side</small></div>
      <div id="mlm-gaver"><p class="muted">Henter …</p></div></section>
      <section class="kort" id="mlm-ble" style="margin-bottom:20px" hidden></section>
      <section class="kort" id="mlm-liste"><p class="muted">Henter …</p></section>`;
    await Promise.all([hentGaver(), hentMedlemmer(), hentBle()]);
    visPerson();
    // Fortløpende: gavene hvert 15. sekund, den tunge medlemslista hvert minutt.
    const hh = NA().hentHvert;
    if (typeof hh === 'function') hh(15000, () => {
      if (!el.isConnected || S.el !== el) return;
      hentGaver();
      if (++S.tikk % 4 === 0) hentMedlemmer();
    });
  }

  // ── Gi gave ────────────────────────────────────────────────────────────
  function arkGave() {
    const valgt = S.medlemmer.find(m => m.id === GV.medlemId);
    const forh = { type: GV.type, timer: GV.timer, belop: GV.belop };
    NA().apneArk(`<div class="ark-head"><h2>🎁 Gi gave</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <div><small>Hva</small><div class="valgknapper" style="margin-top:6px">${[['timer', 'Ekstra timer'], ['gavekort', 'Gavekort'], ['venn', 'Ta med en venn']]
        .map(([k, t]) => `<button class="knapp" data-mlm="gvtype" data-v="${k}" aria-pressed="${GV.type === k}">${t}</button>`).join('')}</div></div>
      ${GV.type === 'timer' ? `<div><small>Antall timer</small><div class="valgknapper" style="margin-top:6px">${[1, 2, 3, 5]
        .map(n => `<button class="knapp" data-mlm="gvtimer" data-v="${n}" aria-pressed="${GV.timer === n}">${n}</button>`).join('')}</div></div>` : ''}
      ${GV.type === 'gavekort' ? `<label style="display:grid;gap:5px"><small>Beløp i kroner</small><input id="mlm-gv-belop" inputmode="numeric" value="${esc(GV.belop)}"
        style="padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit;max-width:200px"></label>` : ''}
      <div><small>Til</small><div class="valgknapper" style="margin-top:6px">
        <button class="knapp" data-mlm="gvtil" data-v="alle" aria-pressed="${GV.til === 'alle'}">Alle medlemmer</button>
        <button class="knapp" data-mlm="gvtil" data-v="en" aria-pressed="${GV.til === 'en'}">${GV.til === 'en' && valgt ? esc(valgt.navn) : 'Ett medlem …'}</button></div>
        ${GV.til === 'en' ? `<select id="mlm-gv-medlem" aria-label="Velg medlem" style="margin-top:8px;padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit;width:100%">
          <option value="">Velg medlem</option>${liste().map(m => `<option value="${m.id}" ${m.id === GV.medlemId ? 'selected' : ''}>${esc(m.navn)}</option>`).join('')}</select>` : ''}
        <small>«Alle medlemmer» gjelder også dem som blir medlem senere, til du trekker gaven.</small></div>
      <label style="display:grid;gap:5px"><small>Hilsen (valgfritt)</small><input id="mlm-gv-hilsen" value="${esc(GV.hilsen)}" maxlength="255"
        style="padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit"></label>
      ${GV.type ? `<div class="sms"><b>Slik ser medlemmet det på Min side:</b><br><span id="mlm-gv-forh">🎁 ${esc(gaveTittel(forh))}${GV.hilsen ? ` – «${esc(GV.hilsen)}»` : ''}</span></div>` : ''}
      <p class="feil" id="mlm-gv-feil" role="alert" hidden style="color:var(--red)"></p>
      <div class="ark-fot"><button class="knapp" data-mlm="lukk">Avbryt</button><button class="knapp hoved" id="mlm-gv-ok" data-mlm="lagregave" ${gaveKlar() ? '' : 'disabled'}>Gi gaven</button></div>`);
  }
  // «Gi gaven» er låst til hva og hvem er valgt.
  function gaveKlar() {
    if (!GV.type || !GV.til) return false;
    if (GV.type === 'timer' && !GV.timer) return false;
    if (GV.type === 'gavekort' && !(/^\d+$/.test(GV.belop) && Number(GV.belop) > 0)) return false;
    return GV.til !== 'en' || !!GV.medlemId;
  }
  const sjekkGave = () => { lesGaveFelter(); const b = document.getElementById('mlm-gv-ok'); if (b) b.disabled = !gaveKlar(); };
  function lesGaveFelter() {
    const b = document.getElementById('mlm-gv-belop'); if (b) GV.belop = b.value.trim();
    const h = document.getElementById('mlm-gv-hilsen'); if (h) GV.hilsen = h.value;
    const m = document.getElementById('mlm-gv-medlem'); if (m) GV.medlemId = Number(m.value) || 0;
  }
  function oppdaterForh() {
    lesGaveFelter();
    const f = document.getElementById('mlm-gv-forh');
    if (f) f.textContent = `🎁 ${gaveTittel({ type: GV.type, timer: GV.timer, belop: GV.belop })}${GV.hilsen ? ` – «${GV.hilsen}»` : ''}`;
  }
  async function lagreGave(knapp) {
    if (S.opptatt) return;
    lesGaveFelter();
    const feil = document.getElementById('mlm-gv-feil');
    const vis = t => { if (feil) { feil.textContent = t; feil.hidden = false; } };
    if (!gaveKlar()) return vis('Velg hva og hvem gaven gjelder.');
    if (GV.type === 'gavekort' && !/^\d+$/.test(GV.belop)) return vis('Skriv beløpet i hele kroner.');
    if (GV.til === 'en' && !GV.medlemId) return vis('Velg hvem gaven skal gå til.');
    S.opptatt = true; knapp.disabled = true;
    try {
      const d = await kall('gaver.php', {
        handling: 'lagre',
        type: { timer: 'Ekstra timer', gavekort: 'Gavekort', venn: 'Ta med en venn' }[GV.type],
        mottaker: GV.til === 'en' ? 'Ett medlem' : 'Alle medlemmer',
        medlemId: GV.til === 'en' ? GV.medlemId : 0,
        timer: GV.timer, belop: Number(GV.belop) || 0, hilsen: GV.hilsen.trim(),
      });
      NA().lukkArk(true);
      toast(`<b>Gaven er gitt.</b> ${esc(d.beskjed || '')}`);
      hentGaver();
    } catch (e) { vis(e.message); } finally { S.opptatt = false; knapp.disabled = false; }
  }

  // ── Trekk ──────────────────────────────────────────────────────────────
  function arkTrekk(id) {
    const g = S.gaver.find(x => x.id === id); if (!g) return;
    NA().apneArk(`<div class="ark-head"><h2>Trekk gaven?</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <p><b>${esc(g.tittel)}</b> til ${esc(g.mottaker)}. Den forsvinner fra Min side. ${g.brukt} har løst den inn, og det står igjen.</p>
      <div class="ark-fot"><button class="knapp" data-mlm="lukk">Avbryt</button><button class="knapp rod" data-mlm="bekrefttrekk" data-id="${g.id}">Trekk gaven</button></div>`);
  }

  // ── Send beskjed: det felles arket (kalender.js, NA.arkBeskjed) ───────
  const beskjed = mal => { const f = NA().arkBeskjed; if (typeof f === 'function') f(mal); else toast('Fikk ikke åpnet beskjeden. Last siden på nytt.'); };

  // Én lytter for hele siden og arkene (arket tegnes av skallet, utenfor sideelementet).
  document.addEventListener('click', async e => {
    if (!e.target.closest('[data-mlm-navn]')) document.querySelectorAll('[data-mlm-navn].vis').forEach(x => x.classList.remove('vis'));
    const b = e.target.closest('[data-mlm]'); if (!b) return;
    const h = b.dataset.mlm, id = Number(b.dataset.id) || 0;
    if (h === 'lukk') return NA().lukkArk();
    // Navnet på berøringsskjerm: trykk viser medlemstiden (boblen) i stedet for å åpne medlemmet.
    const navn = e.target.closest('[data-mlm-navn]');
    if (h === 'medlem' && navn && window.matchMedia?.('(hover: none)').matches) {
      const vis = !navn.classList.contains('vis');
      document.querySelectorAll('[data-mlm-navn].vis').forEach(x => x.classList.remove('vis'));
      navn.classList.toggle('vis', vis);
      return;
    }
    if (h === 'betaling') { location.href = '/admin-ny#folk?person=' + id; return; }
    if (h === 'medlem') return arkMedlem(id);
    if (h === 'stempling') return arkStempling(id);
    if (h === 'juster') return arkJuster(id);
    if (h === 'justerlagre') {
      if (S.opptatt) return;
      const feil = document.getElementById('mlm-ju-feil');
      S.opptatt = true; b.disabled = true;
      try {
        const r = await kall('medlemmer.php', { handling: 'juster', medlemId: id, timer: document.getElementById('mlm-ju-timer')?.value || '', grunn: document.getElementById('mlm-ju-grunn')?.value || '' });
        toast(esc(r.beskjed || 'Lagret.'));
        await hentMedlemmer();
        arkMedlem(id);
      } catch (err) { if (feil) { feil.textContent = err.message; feil.hidden = false; } }
      finally { S.opptatt = false; b.disabled = false; }
      return;
    }
    if (h === 'stemplingsak') return arkStempling(id, Number(b.dataset.sak));
    if (h === 'stemplingokt') return arkStempling(id, 0, Number(b.dataset.okt));
    if (h === 'stemplinglagre' || h === 'stemplinginfo' || h === 'stemplingavslaa') {
      if (S.opptatt) return;
      const felt = x => document.getElementById('mlm-st-' + x)?.value?.trim() || '';
      const data = h === 'stemplinglagre' ? { handling: 'rett', medlemId: id, oktId: Math.max(0, Number(b.dataset.okt)), sakId: Number(b.dataset.sak), dato: felt('dato'), inn: felt('inn'), ut: felt('ut'), grunn: felt('grunn') }
        : { handling: h === 'stemplinginfo' ? 'trenger_info' : 'avslaatt', sakId: Number(b.dataset.sak), notat: felt('grunn') };
      S.opptatt = true; b.disabled = true;
      try {
        if (h === 'stemplinglagre') {
          if (data.grunn.length < 5) throw new Error('Skriv hvorfor tiden rettes (minst fem tegn).');
          const forh = await kall('stempling-rett.php', { handling: 'beregn', dato: data.dato, inn: data.inn, ut: data.ut });
          const gammel = Number(b.dataset.gammelmin) || 0;
          const tekst = `${data.dato} kl. ${data.inn}–${data.ut}. Telt tid: ${gammel} → ${forh.minutter} minutter. ${data.oktId ? 'Den valgte økten endres.' : 'En ny økt legges inn.'}${data.sakId ? ' Medlemmet får beskjed.' : ''}`;
          if (!await NA().bekreft('Rett verkstedtid?', tekst, 'Lagre rettingen')) return;
        }
        const svar = await kall('stempling-rett.php', data); toast(esc(svar.beskjed || 'Lagret.')); await arkStempling(id);
      }
      catch (err) { const el = document.getElementById('mlm-st-feil'); if (el) { el.textContent = err.message; el.hidden = false; } }
      finally { S.opptatt = false; b.disabled = false; }
      return;
    }
    if (h === 'endre') return arkEndre(id);
    if (h === 'lagreendre') return lagreEndre(b, id);
    if (h === 'gave') { Object.assign(GV, { type: '', timer: 0, belop: '', hilsen: '', til: id ? 'en' : '', medlemId: id }); return arkGave(); }
    if (h === 'gvtype') { lesGaveFelter(); GV.type = b.dataset.v; return arkGave(); }
    if (h === 'gvtimer') { lesGaveFelter(); GV.timer = Number(b.dataset.v); return arkGave(); }
    if (h === 'gvtil') { lesGaveFelter(); GV.til = b.dataset.v; return arkGave(); }
    if (h === 'lagregave') return lagreGave(b);
    if (h === 'trekk') return arkTrekk(id);
    if (h === 'bekrefttrekk') {
      if (S.opptatt) return; S.opptatt = true; b.disabled = true;
      try { const d = await kall('gaver.php', { handling: 'trekk', id }); NA().lukkArk(true); toast(esc(d.beskjed || 'Gaven er trukket.')); hentGaver(); }
      catch (err) { toast(esc(err.message)); } finally { S.opptatt = false; b.disabled = false; }
      return;
    }
    // «Dugnad uten godkjenning» (eieren 11.10.2026, migrasjon 272): lagres med en gang, per medlem.
    if (h === 'dugnadfri') {
      const m = S.medlemmer.find(x => x.id === id); if (!m || S.opptatt) return;
      const paa = b.getAttribute('aria-pressed') !== 'true';
      S.opptatt = true; b.disabled = true;
      try { await kall('medlemmer.php', { handling: 'dugnad-uten-godkjenning', medlemId: id, paa: paa ? 'ja' : 'nei' }); m.dugnadUtenGodkjenning = paa; b.setAttribute('aria-pressed', String(paa)); }
      catch (err) { toast(esc(err.message)); } finally { S.opptatt = false; b.disabled = false; }
      return;
    }
    if (h === 'typefilter') { S.typeFilter = b.dataset.v || ''; return tegnListe(); }
    // Påminnelse-SMS per medlem (migrasjon 273): lagres med en gang.
    if (h === 'paaminnelse') {
      const m = S.medlemmer.find(x => x.id === id); if (!m || S.opptatt) return;
      const paa = b.getAttribute('aria-pressed') !== 'true';
      S.opptatt = true; b.disabled = true;
      try { await kall('oppfolging.php', { handling: 'paaminnelse', medlemId: id, paa: paa ? 'ja' : 'nei' }); m.paaminnelseSms = paa; tegnOppf(m); }
      catch (err) { toast(esc(err.message)); } finally { S.opptatt = false; b.disabled = false; }
      return;
    }
    if (h === 'brukere') return arkBrukere();
    if (h === 'bruker') return arkBruker(id);
    if (h === 'lagrebruker') return lagreBruker(b, id);
    if (h === 'kassepin') return arkKassePin(id);
    if (h === 'lagrepin') {
      const pin = (document.getElementById('mlm-b-pin')?.value || '').trim();
      const f = document.getElementById('mlm-b-feil');
      if (!/^\d{4}$/.test(pin)) { if (f) { f.textContent = 'PIN-en må være fire sifre.'; f.hidden = false; } return; }
      b.disabled = true;
      try { await kall('brukere.php', { handling: 'kassepin', id, pin }); toast('PIN-en er lagret.'); return arkBrukere(); } catch (e) { if (f) { f.textContent = e.message; f.hidden = false; } b.disabled = false; return; }
    }
    if (h === 'fjernpin' || h === 'brukerslett') {
      const r = RB.liste.find(x => x.id === id); if (!r) return;
      const n = r.navn || r.brukernavn;
      const ok = h === 'fjernpin'
        ? await NA().bekreft('Fjern kasse-PIN?', 'Fjern kasse-PIN-en til ' + n + '. Står personen i kassa nå, låses den.', 'Fjern kasse-PIN')
        : await NA().bekreft('Fjern tilgang?', 'Fjern innloggingen til ' + n + '. Kjøpshistorikk beholdes når den finnes.', 'Fjern tilgang');
      if (!ok) return arkBrukere();
      try { const s = await kall('brukere.php', h === 'fjernpin' ? { handling: 'kassepin', id, pin: '' } : { handling: 'slett', id }); toast(esc(s.beskjed || 'Fjernet.')); } catch (e) { toast(esc(e.message)); }
      return arkBrukere();
    }
    if (h === 'beskjed') return beskjed({ til: 'medlemmer' });
    if (h === 'beskjeden') { const m = S.medlemmer.find(x => x.id === id); if (m) beskjed({ til: 'en', navn: m.navn, epost: m.epost || '', telefon: m.telefon || '' }); }
  });
  // Raden er role=button: Enter og mellomrom åpner medlemmet, som et trykk.
  document.addEventListener('keydown', e => {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches?.('.mlm-rad[data-mlm="medlem"]')) { e.preventDefault(); arkMedlem(Number(e.target.dataset.id)); }
  });
  document.addEventListener('input', e => { if (e.target.id === 'mlm-gv-belop' || e.target.id === 'mlm-gv-hilsen') { oppdaterForh(); sjekkGave(); } });
  document.addEventListener('change', e => {
    if (e.target.id === 'mlm-gv-medlem') sjekkGave();
    if (e.target.id === 'mlm-b-rolle') document.getElementById('mlm-b-holder-rad').style.display = ['admin', 'medlem'].includes(e.target.value) ? 'flex' : 'none';
    if (e.target.id === 'mlm-sorter') { S.sortering = e.target.value; husk(S.sortering); tegnListe(); }
  });

  registrer('medlemmer', { tittel: 'Folk', ikon: '☺', tegn, mobil: false, sok: q => { S.sok = q; tegnListe(); } });
})();
