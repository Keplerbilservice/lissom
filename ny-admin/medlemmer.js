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

  const S = { medlemmer: [], gaver: [], sok: '', el: null, tikk: 0, opptatt: false, person: 0 };
  // Gaveskjemaet. Ingenting er valgt på forhånd (eierens regel), og «belop» er tomt til Monica skriver det.
  const GV = { type: '', til: '', medlemId: 0, timer: 0, belop: '', hilsen: '' };

  /** Bare medlemmene: aktive og på prøve, uten admin-brukerne (samme utvalg som «Send beskjed» når). */
  const erMedlem = m => !m.rolleAdmin && (m.status === 'aktiv' || m.status === 'prove');
  const liste = () => S.medlemmer.filter(erMedlem);

  function merke(m) {
    if (m.status === 'prove') return '<span class="merke gul">Prøveperiode</span>';
    if (m.betalingUte) return `<span class="merke rod">${esc(m.betalingTekst || 'Ikke betalt')}</span>`;
    return '<span class="merke gronn">Aktiv</span>';
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
    const q = S.sok.trim().toLowerCase();
    const L = liste().filter(m => !q || [m.navn, m.epost, m.telefon, m.medlemskap].some(v => String(v || '').toLowerCase().includes(q)));
    // Hele raden er trykkbar og åpner medlemmet (gave, beskjed, betaling).
    boks.innerHTML = L.length ? L.map(m => `<button type="button" class="rad radknapp ${m.id === S.person ? 'markert' : ''}" data-mlm="medlem" data-id="${m.id}"><span class="tekst"><b>${esc(m.navn)}</b>
        <small>${esc(m.medlemskap || '')}${m.timerIgjen ? ` · ${esc(m.timerIgjen)} igjen denne måneden` : ''}${m.erInne ? ' · i verkstedet nå' : ''}</small></span>
        ${merke(m)}</button>`).join('')
      : '<p class="muted">Ingen treff.</p>';
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
      <div class="valgknapper"><button class="knapp" data-mlm="gave" data-id="${m.id}">🎁 Gi gave</button>
        <button class="knapp" data-mlm="beskjeden" data-id="${m.id}" ${m.epost || m.telefon ? '' : 'disabled'}>✉ Send beskjed</button>
        <a class="knapp" href="/admin-ny#folk?person=${m.id}">Betaling og detaljer (gammel admin)</a></div>`);
  }

  async function hentGaver() {
    try { S.gaver = (await kall('gaver.php')).gaver || []; } catch (e) { S.gaver = []; toast(esc(e.message)); }
    tegnGaver();
  }
  async function hentMedlemmer() {
    try { S.medlemmer = (await kall('medlemmer.php')).medlemmer || []; } catch (e) { toast(esc(e.message)); }
    tegnListe();
  }
  /* «Må gjøres › Betaling» på mobil (eieren 09.10.2026): samme medlemsark som på PC, uten å bytte side
     (Medlemmer er ikke en mobilside). Lista hentes først om den ikke er hentet. */
  NA().arkMedlem = async id => { if (!S.medlemmer.length) await hentMedlemmer(); arkMedlem(Number(id)); };

  async function tegn(el, params) {
    S.el = el;
    S.person = Number(params?.get?.('person')) || 0;
    S.sok = '';
    el.innerHTML = `<div class="head"><div><div class="eyebrow" id="mlm-antall">Henter …</div><h1>Medlemmer</h1></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="knapp" data-mlm="gave">🎁 Gi gave</button>
      <button class="knapp hoved" data-mlm="beskjed" id="mlm-beskjedknapp">✉ Send beskjed</button></div></div>
      <section class="kort" style="margin-bottom:20px"><div class="kort-head"><h2>Gaver som gjelder nå</h2><small>Medlemmene ser dem på Min side</small></div>
      <div id="mlm-gaver"><p class="muted">Henter …</p></div></section>
      <section class="kort" id="mlm-liste"><p class="muted">Henter …</p></section>`;
    await Promise.all([hentGaver(), hentMedlemmer()]);
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
    const b = e.target.closest('[data-mlm]'); if (!b) return;
    const h = b.dataset.mlm, id = Number(b.dataset.id) || 0;
    if (h === 'lukk') return NA().lukkArk();
    if (h === 'medlem') return arkMedlem(id);
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
    if (h === 'beskjed') return beskjed({ til: 'medlemmer' });
    if (h === 'beskjeden') { const m = S.medlemmer.find(x => x.id === id); if (m) beskjed({ til: 'en', navn: m.navn, epost: m.epost || '', telefon: m.telefon || '' }); }
  });
  document.addEventListener('input', e => { if (e.target.id === 'mlm-gv-belop' || e.target.id === 'mlm-gv-hilsen') { oppdaterForh(); sjekkGave(); } });
  document.addEventListener('change', e => { if (e.target.id === 'mlm-gv-medlem') sjekkGave(); });

  registrer('medlemmer', { tittel: 'Medlemmer', ikon: '☺', tegn, mobil: false, sok: q => { S.sok = q; tegnListe(); } });
})();
