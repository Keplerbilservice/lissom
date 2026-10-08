/* Ny admin, del C: Medlemssiden (eierens GO 08.10.2026, prototypen lissom-enklere-admin-2).
   Det medlemmene ser på Min side, styrt herfra:
   - brytere per del av Min side: de samme «Vis/…»-nøklene som admin-ny › Medlemmene
     (api/admin/innhold.php), samme standardverdier;
   - kampanjer til medlemmer av/på: api/admin/kampanjer.php handling=bryter;
   - «Legg ut internt»: api/admin/beskjed.php handling=tavle (bare Min side, ingen e-post/SMS);
   - forhåndsvisning per rolle: /min-side?forhandsvis=medlem|deltaker («se som medlem»). */
(() => {
  'use strict';
  const NA = () => window.NyAdmin || window;
  const kall = (sti, data) => NA().api('/api/admin/' + sti, data === undefined ? { metode: 'GET' } : { metode: 'POST', data });
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const toast = h => NA().toast(h);
  const registrer = (id, opp) => { const f = NA().registrerSide; if (typeof f === 'function') f(id, opp); else document.addEventListener('DOMContentLoaded', () => registrer(id, opp), { once: true }); };

  // Samme liste og standardverdier som MINSIDE_MODULER i admin-ny/innhold-og-kurs.js
  // (navn, nøkkel, på når nøkkelen mangler). «Kampanje til medlemmer» styres under Kampanjer.
  const MODULER = [['I verkstedet nå', 'Vis/minsideinne'], ['Spør o store krukkemester', 'Vis/minsidefaq'], ['Ovnen', 'Vis/minsideovn'],
    ['Ta med barn', 'Vis/tilleggbarn'], ['Stemple inn og timene dine', 'Vis/minsidestempel'], ['Dugnad', 'Vis/dugnad'],
    ['Skisser', 'Vis/skissermedlemmer', false], ['Dreieskivene denne uka', 'Vis/minsideskiver'], ['Medlemskapet ditt', 'Vis/minsideabonnement'],
    ['Nyttig info', 'Vis/minsidenyttig'], ['Frys av medlemskap', 'Vis/medlemfrys'], ['Handleliste', 'Vis/handleliste'],
    ['Internbutikk', 'Vis/internbutikk'], ['Kjøpshistorikk', 'Vis/minsidehistorikk'], ['Selg mine produkter', 'Vis/medlemssalg'],
    ['Beskjeder', 'Vis/minsidebeskjeder'], ['Del på Instagram og i galleriet', 'Vis/medlemsforslag'], ['Kurs for medlemmer', 'Vis/internkurs'],
    ['Verv en venn', 'Vis/verving', false], ['Mine påmeldinger', 'Vis/minsidepameldinger'], ['Kursbevis', 'Vis/minsidekursbevis'],
    ['Medlemschat', 'Vis/minsidechat'], ['Ordensregler og HMS', 'Vis/minsidehms'], ['Ny Min side (fliser)', 'Vis/minsideny', false]];
  // Min side-bryterne står bare her (Innstillinger viser dem ikke). Nøklene leses av innstillinger.js.
  if (window.NA) window.NA.minSideNokler = MODULER.map(m => m[1]);
  const ROLLER = [['medlem', 'Se som medlem'], ['deltaker', 'Se som kursdeltaker'], ['prove', 'Se som Prøv Lissom']];
  const S = { innhold: {}, kampanjer: [], harMedlemmer: true, rolle: 'medlem', el: null, opptatt: false };

  const paa = (nokkel, std = true) => { const v = S.innhold[nokkel]; return v === undefined ? std : (std ? v !== 'nei' : v === 'ja'); };
  const bryter = (attr, verdi, navn, paaNaa) => `<button class="av" ${attr}="${esc(verdi)}" aria-pressed="${paaNaa}" aria-label="${esc(navn)}"></button>`;

  function tegnBrytere() {
    const boks = document.getElementById('mss-brytere'); if (!boks) return;
    boks.innerHTML = MODULER.map(([navn, k, std = true]) => `<div class="bryter"><div><b>${esc(navn)}</b></div>${bryter('data-mss-modul', k, navn, paa(k, std))}</div>`).join('');
  }
  function tegnKampanjer() {
    const boks = document.getElementById('mss-kamp'); if (!boks) return;
    if (!S.harMedlemmer) { boks.innerHTML = '<p class="muted">Kampanjer til medlemmer krever oppdatering 260. Kjør oppdateringene først.</p>'; return; }
    const K = S.kampanjer.filter(k => k.publikum === 'medlemmer');
    boks.innerHTML = K.length ? K.map(k => `<div class="bryter"><div><b>${esc(k.navn || k.tittel)}</b><br><small>${esc(k.tittel)}${k.tekst ? ' · ' + esc(k.tekst) : ''}</small></div>
      ${bryter('data-mss-kamp', k.id, k.navn || k.tittel, !!k.paa)}</div>`).join('')
      : '<p class="muted">Ingen kampanjer til medlemmer ennå.</p>';
  }
  function tegnRoller() {
    const boks = document.getElementById('mss-roller'); if (!boks) return;
    boks.innerHTML = ROLLER.map(([r, t]) => r === 'prove'
      ? `<button class="knapp" disabled title="Forhåndsvisning for Prøv Lissom finnes ikke ennå">${t}</button>`
      : `<button class="knapp ${S.rolle === r ? 'hoved' : ''}" data-mss-rolle="${r}">${t}</button>`).join('');
    const tittel = document.getElementById('mss-baand'); if (tittel) tittel.textContent = `Slik ser ${S.rolle === 'deltaker' ? 'kursdeltakeren' : 'medlemmet'} siden nå`;
  }
  function lastForhandsvisning() {
    const f = document.getElementById('mss-ramme'); if (f) f.src = `/min-side?forhandsvis=${S.rolle}`;
  }

  async function hent() {
    const [inn, km] = await Promise.all([kall('innhold.php').catch(e => { toast(esc(e.message)); return null; }), kall('kampanjer.php').catch(() => null)]);
    if (inn) S.innhold = inn.innhold || {};
    if (km) { S.kampanjer = km.kampanjer || []; S.harMedlemmer = km.harMedlemmer !== false; }
    tegnBrytere(); tegnKampanjer();
  }

  async function tegn(el) {
    S.el = el;
    el.innerHTML = `<div class="head"><div><div class="eyebrow">Det medlemmene ser, styrt herfra</div><h1>Medlemssiden</h1></div>
      <span class="roller" id="mss-roller" style="gap:8px"></span></div>
      <div class="ms-grid"><div style="display:grid;gap:18px;min-width:0">
        <section class="kort"><div class="kort-head"><h2>Hva vises på Min side</h2><small>Endringer vises med en gang</small></div><div id="mss-brytere"><p class="muted">Henter …</p></div></section>
        <section class="kort"><div class="kort-head"><h2>Kampanjer og banner</h2><button class="knapp liten hoved" data-mss="nykampanje">＋ Ny kampanje</button></div><div id="mss-kamp"><p class="muted">Henter …</p></div></section>
        <section class="kort"><div class="kort-head"><h2>Legg ut internt</h2></div>
          <label class="felt" style="margin-bottom:8px"><small>Overskrift (valgfritt)</small><input id="mss-emne" maxlength="191"></label>
          <label class="felt"><small>Beskjed til alle medlemmer, vises på Min side</small><textarea id="mss-intern" style="min-height:70px"></textarea></label>
          <div style="display:flex;justify-content:flex-end;margin-top:8px"><button class="knapp hoved" data-mss="legguti">Legg ut</button></div></section>
      </div>
      <div class="kort ms-forhånd" style="padding:0;overflow:hidden">
        <div class="medlem-bånd"><span id="mss-baand">Slik ser medlemmet siden nå</span><small style="color:var(--ink)">Forhåndsvisning – med dine egne navn og tall</small></div>
        <iframe id="mss-ramme" title="Min side slik den vises" style="width:100%;height:820px;border:0;display:block;background:#fff"></iframe>
      </div></div>`;
    tegnRoller(); lastForhandsvisning();
    await hent();
    const hh = NA().hentHvert;
    if (typeof hh === 'function') hh(15000, () => { if (el.isConnected && S.el === el && !S.opptatt) hent(); });
  }

  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-mss],[data-mss-modul],[data-mss-kamp],[data-mss-rolle]'); if (!b || S.opptatt) return;
    if (b.dataset.mssRolle) { S.rolle = b.dataset.mssRolle; tegnRoller(); lastForhandsvisning(); return; }
    if (b.dataset.mssModul) {
      const k = b.dataset.mssModul, std = (MODULER.find(m => m[1] === k) || [])[2] !== false, ny = !paa(k, std);
      S.opptatt = true; b.disabled = true;
      try { await kall('innhold.php', { endringer: { [k]: ny ? 'ja' : 'nei' } }); S.innhold[k] = ny ? 'ja' : 'nei'; b.setAttribute('aria-pressed', String(ny)); lastForhandsvisning();
        toast(`<b>${esc((MODULER.find(m => m[1] === k) || [k])[0])}</b> er slått ${ny ? 'på' : 'av'} på Min side.`); }
      catch (err) { toast(esc(err.message)); } finally { S.opptatt = false; b.disabled = false; }
      return;
    }
    if (b.dataset.mssKamp) {
      const id = Number(b.dataset.mssKamp), k = S.kampanjer.find(x => x.id === id); if (!k) return;
      S.opptatt = true; b.disabled = true;
      try { const d = await kall('kampanjer.php', { handling: 'bryter', id, paa: !k.paa }); S.kampanjer = d.kampanjer || S.kampanjer; tegnKampanjer(); toast(esc(d.beskjed || 'Lagret.')); lastForhandsvisning(); }
      catch (err) { toast(esc(err.message)); } finally { S.opptatt = false; b.disabled = false; }
      return;
    }
    // Kampanjeskjemaet finnes i admin-ny (bilde, tekst, pris, mal) og lages ikke på nytt her.
    if (b.dataset.mss === 'nykampanje') { location.href = '/admin-ny#kampanjer'; return; }
    if (b.dataset.mss === 'legguti') {
      const t = document.getElementById('mss-intern'), em = document.getElementById('mss-emne');
      const tekst = (t?.value || '').trim(), emne = (em?.value || '').trim();
      if (tekst.length < 3) { toast('Skriv beskjeden først.'); return; }
      S.opptatt = true; b.disabled = true;
      try { const d = await kall('beskjed.php', { handling: 'tavle', tekst, ...(emne ? { emne } : {}) }); t.value = ''; em.value = ''; toast(esc(d.beskjed || 'Lagt ut på Min side.')); lastForhandsvisning(); }
      catch (err) { toast(esc(err.message)); } finally { S.opptatt = false; b.disabled = false; }
    }
  });

  registrer('semedlem', { tittel: 'Medlemssiden', ikon: '👁', tegn, mobil: false });
})();
