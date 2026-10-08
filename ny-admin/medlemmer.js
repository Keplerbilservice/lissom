/* Ny admin, del C: Medlemmer (eierens GO 08.10.2026, prototypen lissom-enklere-admin-2).
   Liste med status, «Gi gave» øverst og per medlem, «Gaver som gjelder nå» med Trekk, og
   «Send beskjed». Ekte data: api/admin/medlemmer.php (lista), gaver.php (gavene) og
   beskjed.php (utsendingen). Ingen egne kopier av noe av det. */
(() => {
  'use strict';
  const NA = () => window.NyAdmin || window;
  const kall = (sti, data) => NA().api('/api/admin/' + sti, data === undefined ? { metode: 'GET' } : { metode: 'POST', data });
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const toast = h => NA().toast(h);
  const registrer = (id, opp) => { const f = NA().registrerSide; if (typeof f === 'function') f(id, opp); else document.addEventListener('DOMContentLoaded', () => registrer(id, opp), { once: true }); };

  const S = { medlemmer: [], gaver: [], sok: '', el: null, tikk: 0, opptatt: false };
  // Gaveskjemaet. «belop» er tomt til Monica skriver det: ingen ferdige beløp (ingen hardkodede priser).
  const GV = { type: 'timer', til: 'alle', medlemId: 0, timer: 2, belop: '', hilsen: '' };

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
    boks.innerHTML = L.length ? L.map(m => `<div class="rad"><div class="tekst"><b>${esc(m.navn)}</b>
        <small>${esc(m.medlemskap || '')}${m.timerIgjen ? ` · ${esc(m.timerIgjen)} igjen denne måneden` : ''}${m.erInne ? ' · i verkstedet nå' : ''}</small></div>
        ${merke(m)}<button class="knapp liten" data-mlm="gave" data-id="${m.id}">🎁 Gi gave</button></div>`).join('')
      : '<p class="muted">Ingen treff.</p>';
    const n = liste().length;
    const ey = document.getElementById('mlm-antall'); if (ey) ey.textContent = `${n} medlemmer`;
    const bk = document.getElementById('mlm-beskjedknapp'); if (bk) bk.textContent = `✉ Send beskjed til ${n}`;
  }

  async function hentGaver() {
    try { S.gaver = (await kall('gaver.php')).gaver || []; } catch (e) { S.gaver = []; toast(esc(e.message)); }
    tegnGaver();
  }
  async function hentMedlemmer() {
    try { S.medlemmer = (await kall('medlemmer.php')).medlemmer || []; } catch (e) { toast(esc(e.message)); }
    tegnListe();
  }

  async function tegn(el) {
    S.el = el;
    el.innerHTML = `<div class="head"><div><div class="eyebrow" id="mlm-antall">Henter …</div><h1>Medlemmer</h1></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="knapp" data-mlm="gave">🎁 Gi gave</button>
      <button class="knapp hoved" data-mlm="beskjed" id="mlm-beskjedknapp">✉ Send beskjed</button></div></div>
      <section class="kort" style="margin-bottom:20px"><div class="kort-head"><h2>Gaver som gjelder nå</h2><small>Medlemmene ser dem på Min side</small></div>
      <div id="mlm-gaver"><p class="muted">Henter …</p></div></section>
      <div style="margin-bottom:16px"><input id="mlm-sok" type="search" placeholder="Søk i medlemmer" aria-label="Søk i medlemmer" value="${esc(S.sok)}"
        style="width:100%;max-width:420px;min-height:48px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--paper);font:inherit"></div>
      <section class="kort" id="mlm-liste"><p class="muted">Henter …</p></section>`;
    el.querySelector('#mlm-sok').addEventListener('input', e => { S.sok = e.target.value; tegnListe(); });
    await Promise.all([hentGaver(), hentMedlemmer()]);
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
        .map(([k, t]) => `<button class="knapp ${GV.type === k ? 'hoved' : ''}" data-mlm="gvtype" data-v="${k}">${t}</button>`).join('')}</div></div>
      ${GV.type === 'timer' ? `<div><small>Antall timer</small><div class="valgknapper" style="margin-top:6px">${[1, 2, 3, 5]
        .map(n => `<button class="knapp ${GV.timer === n ? 'hoved' : ''}" data-mlm="gvtimer" data-v="${n}">${n}</button>`).join('')}</div></div>` : ''}
      ${GV.type === 'gavekort' ? `<label style="display:grid;gap:5px"><small>Beløp i kroner</small><input id="mlm-gv-belop" inputmode="numeric" value="${esc(GV.belop)}"
        style="padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit;max-width:200px"></label>` : ''}
      <div><small>Til</small><div class="valgknapper" style="margin-top:6px">
        <button class="knapp ${GV.til === 'alle' ? 'hoved' : ''}" data-mlm="gvtil" data-v="alle">Alle medlemmer</button>
        <button class="knapp ${GV.til === 'en' ? 'hoved' : ''}" data-mlm="gvtil" data-v="en">${GV.til === 'en' && valgt ? esc(valgt.navn) : 'Ett medlem …'}</button></div>
        ${GV.til === 'en' ? `<select id="mlm-gv-medlem" aria-label="Velg medlem" style="margin-top:8px;padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit;width:100%">
          <option value="">Velg medlem</option>${liste().map(m => `<option value="${m.id}" ${m.id === GV.medlemId ? 'selected' : ''}>${esc(m.navn)}</option>`).join('')}</select>` : ''}
        <small>«Alle medlemmer» gjelder også dem som blir medlem senere, til du trekker gaven.</small></div>
      <label style="display:grid;gap:5px"><small>Hilsen (valgfritt)</small><input id="mlm-gv-hilsen" value="${esc(GV.hilsen)}" maxlength="255"
        style="padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit"></label>
      <div class="sms"><b>Slik ser medlemmet det på Min side:</b><br><span id="mlm-gv-forh">🎁 ${esc(gaveTittel(forh))}${GV.hilsen ? ` – «${esc(GV.hilsen)}»` : ''}</span></div>
      <p class="feil" id="mlm-gv-feil" role="alert" hidden style="color:var(--red)"></p>
      <div class="ark-fot"><button class="knapp" data-mlm="lukk">Avbryt</button><button class="knapp hoved" data-mlm="lagregave">Gi gaven</button></div>`);
  }
  function lesGaveFelter() {
    const b = document.getElementById('mlm-gv-belop'); if (b) GV.belop = b.value.replace(/\D+/g, '');
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
    if (GV.type === 'gavekort' && !(Number(GV.belop) > 0)) return vis('Skriv beløpet.');
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
      <div class="ark-fot"><button class="knapp" data-mlm="lukk">Avbryt</button><button class="knapp hoved rod" data-mlm="bekrefttrekk" data-id="${g.id}">Trekk gaven</button></div>`);
  }

  // ── Send beskjed ───────────────────────────────────────────────────────
  let kanal = 'epost';
  function arkBeskjed() {
    const n = liste().length;
    NA().apneArk(`<div class="ark-head"><h2>✉ Send beskjed til ${n}</h2><button class="lukk" data-mlm="lukk" aria-label="Lukk">×</button></div>
      <div><small>Send som</small><div class="valgknapper" style="margin-top:6px">
        <button class="knapp ${kanal === 'epost' ? 'hoved' : ''}" data-mlm="kanal" data-v="epost">E-post</button>
        <button class="knapp ${kanal === 'begge' ? 'hoved' : ''}" data-mlm="kanal" data-v="begge">E-post og SMS</button></div></div>
      <p class="muted">Beskjeden legges også ut på Min side.</p>
      <label style="display:grid;gap:5px"><small>Overskrift (valgfritt)</small><input id="mlm-b-emne" maxlength="191" placeholder="Beskjed fra Lissom"
        style="padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit"></label>
      <label style="display:grid;gap:5px"><small>Tekst</small><textarea id="mlm-b-tekst" style="width:100%;min-height:120px;padding:12px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit"></textarea></label>
      <p class="feil" id="mlm-b-feil" role="alert" hidden style="color:var(--red)"></p>
      <div class="ark-fot"><button class="knapp" data-mlm="lukk">Avbryt</button><button class="knapp hoved" data-mlm="sendbeskjed">Send til ${n}</button></div>`);
    setTimeout(() => document.getElementById('mlm-b-tekst')?.focus(), 0);
  }
  async function sendBeskjed(knapp) {
    if (S.opptatt) return;
    const tekst = (document.getElementById('mlm-b-tekst')?.value || '').trim();
    const emne = (document.getElementById('mlm-b-emne')?.value || '').trim();
    const feil = document.getElementById('mlm-b-feil');
    if (tekst.length < 3) { feil.textContent = 'Skriv en melding først.'; feil.hidden = false; return; }
    S.opptatt = true; knapp.disabled = true;
    try {
      const d = await kall('beskjed.php', { til: 'medlemmer', tekst, ...(emne ? { emne } : {}), ogsaaSms: kanal === 'begge' ? 'ja' : 'nei' });
      NA().lukkArk(true);
      toast(esc(d.beskjed || 'Sendt.'));
    } catch (e) { feil.textContent = e.message; feil.hidden = false; } finally { S.opptatt = false; knapp.disabled = false; }
  }

  // Én lytter for hele siden og arkene (arket tegnes av skallet, utenfor sideelementet).
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-mlm]'); if (!b) return;
    const h = b.dataset.mlm, id = Number(b.dataset.id) || 0;
    if (h === 'lukk') return NA().lukkArk();
    if (h === 'gave') { Object.assign(GV, { type: 'timer', timer: 2, belop: '', hilsen: '', til: id ? 'en' : 'alle', medlemId: id }); return arkGave(); }
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
    if (h === 'beskjed') return arkBeskjed();
    if (h === 'kanal') { kanal = b.dataset.v; document.querySelectorAll('[data-mlm="kanal"]').forEach(x => x.classList.toggle('hoved', x === b)); return; }
    if (h === 'sendbeskjed') return sendBeskjed(b);
  });
  document.addEventListener('input', e => { if (e.target.id === 'mlm-gv-belop' || e.target.id === 'mlm-gv-hilsen') oppdaterForh(); });
  document.addEventListener('change', e => { if (e.target.id === 'mlm-gv-medlem') lesGaveFelter(); });

  registrer('medlemmer', { tittel: 'Medlemmer', ikon: '☺', tegn, mobil: false });
})();
