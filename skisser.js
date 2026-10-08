/**
 * Skisser — tegneflaten. Se skisser.html og app/lib/skisser.php.
 *
 * To visninger: lista over tavler, og selve tavla. Samme side for admin og
 * for Min side; serveren avgjør hva du ser og hva du kan endre. Admin går
 * mot api/admin/skisser.php, alle andre mot api/skisser.php.
 *
 * Tegning: fingeren eller musa tegner. Har en penn (Apple Pencil) vært brukt,
 * tegner bare pennen, og fingeren flytter og zoomer i stedet — så håndflaten
 * ikke setter streker. Trykket på pennen styrer tykkelsen.
 */
(function () {
  'use strict';

  const F = window.fabric;
  const app = document.getElementById('app');

  // Tolv farger i Lissom-drakten (eieren 08.10.2026: «flere farger»), og en egen fargevelger etter dem.
  const FARGER = ['#4D1D12', '#765C50', '#A2502B', '#D9A47E', '#AD3425', '#E0A800',
                  '#FFCF38', '#2F6B3B', '#8FB08A', '#1F5A8A', '#7FA3C6', '#111111'];
  const TYKKELSER = [2, 5, 12];
  const NOTATFARGE = '#FFF3B8';

  let API = '/api/skisser.php';
  let erAdmin = false;

  // ── Hjelpere ────────────────────────────────────────────────────────
  const el = (tag, attr, ...barn) => {
    const e = document.createElement(tag);
    for (const [k, v] of Object.entries(attr || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') e.className = v;
      else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
      else if (k === 'tekst') e.textContent = v;
      else e.setAttribute(k, v === true ? '' : String(v));
    }
    for (const b of barn.flat(Infinity)) if (b !== null && b !== undefined && b !== false) e.append(b.nodeType ? b : document.createTextNode(String(b)));
    return e;
  };

  async function hent(sti) {
    const r = await fetch(sti, { credentials: 'same-origin', cache: 'no-store' });
    let d = null;
    try { d = await r.json(); } catch (e) { /* ikke json */ }
    return { status: r.status, d: d || {} };
  }

  async function send(kropp) {
    const r = await fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(kropp),
    });
    let d = null;
    try { d = await r.json(); } catch (e) { /* ikke json */ }
    if (!r.ok || !d || d.ok === false) throw new Error((d && d.feil) || 'Det gikk ikke. Prøv igjen.');
    return d;
  }

  const norskTid = (iso) => {
    const d = new Date(String(iso).replace(' ', 'T') + (String(iso).includes('T') ? '' : 'Z'));
    if (isNaN(d)) return String(iso);
    return d.toLocaleString('nb-NO', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
  };

  // ── Oppstart: hvem er du? ───────────────────────────────────────────
  async function start() {
    // Admin først. Er du ikke admin, svarer serveren 404, og da er det Min side.
    const a = await hent('/api/admin/skisser.php');
    if (a.status === 200 && a.d.ok) {
      API = '/api/admin/skisser.php';
      erAdmin = true;
      return visListe(a.d);
    }
    if (a.status === 403 && a.d.feil) return visMelding(a.d.feil, '/admin');
    const m = await hent('/api/skisser.php');
    if (m.status === 200 && m.d.ok) return visListe(m.d);
    if (m.status === 401) return visMelding('Du må være logget inn.', '/min-side');
    return visMelding(m.d.feil || 'Skisser er ikke slått på.', '/min-side');
  }

  function topp(tittel, tilbake, ...hoyre) {
    return el('div', { class: 'topp' },
      el('a', { class: 'logo', href: tilbake, 'aria-label': 'Tilbake' }, 'lissom'),
      el('h1', {}, tittel),
      ...hoyre);
  }

  function visMelding(tekst, tilbake) {
    app.replaceChildren(
      topp('Skisser', tilbake),
      el('div', { class: 'innhold' }, el('div', { class: 'melding' }, el('p', { tekst }))));
  }

  // ── Lista ───────────────────────────────────────────────────────────
  function visListe(d) {
    const tilbake = erAdmin ? '/admin' : '/min-side';
    const tavler = d.tavler || [];
    const nyKnapp = el('button', { class: 'pille fylt', type: 'button', onclick: nyTavle, 'data-test': 'ny-tavle' }, '+ Ny tavle');
    const kort = tavler.map(t => el('button', { class: 'tavle', type: 'button', onclick: () => aapne(t.id), 'data-tavle': t.id },
      el('b', { tekst: t.tittel }),
      el('small', { tekst: (t.egen ? '' : (t.eierNavn ? t.eierNavn + ' · ' : '')) + norskTid(t.endret) }),
      (erAdmin && (t.deltMedlemmer || t.deltDeltakere))
        ? el('span', { class: 'merke', tekst: [t.deltMedlemmer ? 'Medlemmer' : '', t.deltDeltakere ? 'Kursdeltakere' : ''].filter(Boolean).join(' · ') })
        : null,
      (!t.kanEndre) ? el('span', { class: 'merke', tekst: 'Delt' }) : null,
    ));
    app.replaceChildren(
      topp('Skisser', tilbake),
      el('div', { class: 'innhold' },
        el('div', { class: 'overskrift' }, el('h2', { tekst: 'Skisser' }), nyKnapp),
        kort.length ? el('div', { class: 'rutenett' }, kort) : el('p', { class: 'tom', tekst: 'Ingen tavler ennå.' })));
  }

  async function nyTavle() {
    try {
      const d = await send({ handling: 'ny', tittel: 'Ny tavle' });
      aapne(d.id);
    } catch (e) { alert(e.message); }
  }

  async function tilListe() {
    const r = await hent(API);
    if (r.d.ok) visListe(r.d); else visMelding(r.d.feil || 'Det gikk ikke.', erAdmin ? '/admin' : '/min-side');
  }

  // ── Tavla ───────────────────────────────────────────────────────────
  let T = null;      // tavla fra serveren
  let C = null;      // Fabric-lerretet
  let nr = 1;        // siden som vises
  let verktoy = 'penn';
  let farge = FARGER[0];
  let tykkelse = TYKKELSER[1];
  let pennBrukt = false;
  let lagreTimer = null;
  let lagrer = false;
  let skitten = false;
  let angre = [], gjor = [], iAngre = false;
  let statusEl = null;
  let sideEl = null;

  async function aapne(id) {
    const r = await hent(API + '?id=' + encodeURIComponent(id));
    if (!r.d.ok) return alert(r.d.feil || 'Fant ikke tavla.');
    T = r.d.tavle;
    nr = 1;
    tegnRedigerer();
    await lastSide();
  }

  function sideData(n) {
    const s = (T.sider || []).find(x => x.nr === n);
    return s ? s.data : '{}';
  }

  function tegnRedigerer() {
    const kanEndre = !!T.kanEndre;
    const knapp = (navn, tekst, handling, test) => el('button', {
      class: 'pille', type: 'button', 'aria-pressed': verktoy === navn ? 'true' : 'false',
      'data-verktoy': navn, 'data-test': test || null, onclick: handling || (() => velgVerktoy(navn)),
    }, tekst);

    const tittel = kanEndre
      ? el('input', { value: T.tittel, 'aria-label': 'Navn på tavla', maxlength: 120, onchange: async (e) => {
          T.tittel = e.target.value.trim() || 'Ny tavle';
          try { await send({ handling: 'navn', id: T.id, tittel: T.tittel }); } catch (er) { alert(er.message); }
        } })
      : T.tittel;

    const verk = el('div', { class: 'verktoy', role: 'toolbar' },
      kanEndre ? [
        knapp('penn', '✎ Penn'),
        knapp('viske', '⌫ Viskelær'),
        knapp('notat', '▢ Notat'),
        knapp('tekst', 'T Tekst'),
        el('button', { class: 'pille', type: 'button', onclick: velgBilde, 'data-test': 'bilde' }, '▣ Bilde'),
        knapp('flytt', '✥ Flytt'),
        el('span', { class: 'skille' }),
        FARGER.map(f => el('button', { class: 'farge', type: 'button', style: 'background:' + f, 'aria-label': 'Farge', 'aria-pressed': f === farge ? 'true' : 'false', onclick: () => { farge = f; oppdaterPensel(); merk(); } })),
        el('input', { class: 'farge-egen', type: 'color', value: farge, 'aria-label': 'Velg egen farge', title: 'Velg egen farge',
          style: 'width:34px;height:34px;padding:0;border:0;background:none;cursor:pointer;flex:none',
          oninput: (e) => { farge = e.target.value; oppdaterPensel(); merk(); } }),
        el('span', { class: 'skille' }),
        TYKKELSER.map(t => el('button', { class: 'tykk', type: 'button', 'aria-label': 'Tykkelse', 'aria-pressed': t === tykkelse ? 'true' : 'false', onclick: () => { tykkelse = t; oppdaterPensel(); merk(); } },
          el('span', { style: `width:${Math.min(22, t + 3)}px;height:${Math.min(22, t + 3)}px` }))),
        el('span', { class: 'skille' }),
        el('button', { class: 'pille', type: 'button', onclick: () => angreSteg(), 'data-test': 'angre' }, '↶ Angre'),
        el('button', { class: 'pille', type: 'button', onclick: () => gjorSteg() }, '↷ Gjør om'),
      ] : [knapp('flytt', '✥ Flytt')],
      el('span', { class: 'skille' }),
      el('button', { class: 'pille', type: 'button', onclick: () => zoom(1.25), 'aria-label': 'Zoom inn' }, '+'),
      el('button', { class: 'pille', type: 'button', onclick: () => zoom(0.8), 'aria-label': 'Zoom ut' }, '−'),
      el('button', { class: 'pille', type: 'button', onclick: lastNed, 'data-test': 'png' }, '⤓ Last ned'),
    );

    const flate = el('div', { class: 'flate', id: 'flate' }, el('canvas', { id: 'lerret' }));
    statusEl = el('span', { class: 'status', 'data-test': 'status' });
    sideEl = el('span', { class: 'sidenr' });
    const bunn = el('div', { class: 'bunn' },
      el('button', { class: 'pille', type: 'button', onclick: () => byttSide(nr - 1), 'aria-label': 'Forrige side' }, '‹'),
      sideEl,
      el('button', { class: 'pille', type: 'button', onclick: () => byttSide(nr + 1), 'aria-label': 'Neste side' }, '›'),
      kanEndre ? el('button', { class: 'pille', type: 'button', onclick: nySide, 'data-test': 'ny-side' }, '+ Side') : null,
      kanEndre ? el('button', { class: 'pille', type: 'button', onclick: visVersjoner }, 'Tidligere versjoner') : null,
      (erAdmin && kanEndre && T.egen) ? el('button', { class: 'pille gul', type: 'button', onclick: visDeling, 'data-test': 'del' }, 'Del') : null,
      kanEndre ? el('button', { class: 'pille', type: 'button', onclick: slettSpor }, 'Slett') : null,
      statusEl,
    );

    const rot = el('div', { class: 'redigerer' },
      topp(tittel, erAdmin ? '/admin' : '/min-side',
        el('button', { class: 'pille gul', type: 'button', onclick: lukk, 'data-test': 'ferdig' }, 'Ferdig')),
      verk, flate, bunn);
    app.replaceChildren(rot);
    lagLerret(flate, kanEndre);
  }

  function merk() {
    document.querySelectorAll('.verktoy [data-verktoy]').forEach(b => b.setAttribute('aria-pressed', b.dataset.verktoy === verktoy ? 'true' : 'false'));
    document.querySelectorAll('.verktoy .farge').forEach(b => b.setAttribute('aria-pressed', b.style.background && b.style.backgroundColor === hexTilRgb(farge) ? 'true' : 'false'));
    document.querySelectorAll('.verktoy .tykk').forEach((b, i) => b.setAttribute('aria-pressed', TYKKELSER[i] === tykkelse ? 'true' : 'false'));
  }
  const hexTilRgb = (h) => { const n = parseInt(h.slice(1), 16); return `rgb(${n >> 16 & 255}, ${n >> 8 & 255}, ${n & 255})`; };

  function status(t) { if (statusEl) statusEl.textContent = t; }

  function lagLerret(flate, kanEndre) {
    if (C) { C.dispose(); C = null; }
    const b = flate.clientWidth, h = flate.clientHeight;
    C = new F.Canvas('lerret', { width: b, height: h, backgroundColor: null, preserveObjectStacking: true, selection: kanEndre, allowTouchScrolling: false });
    C.freeDrawingBrush = new F.PencilBrush(C);
    oppdaterPensel();

    window.addEventListener('resize', tilpass);

    // Endringer: angre-stakken og autolagring.
    const endret = () => { if (iAngre || C._laster) return; angre.push(JSON.stringify(C.toJSON())); if (angre.length > 60) angre.shift(); gjor = []; planLagring(); };
    C.on('object:added', endret);
    C.on('object:modified', endret);
    C.on('object:removed', endret);
    C.on('text:changed', endret);
    // Et notat du ikke skrev noe i, blir ikke liggende igjen.
    C.on('text:editing:exited', (o) => { const t = o && o.target; if (t && !String(t.text || '').trim()) C.remove(t); });

    // Et notat eller en tekst settes der du trykker.
    C.on('mouse:down', (o) => {
      if (!kanEndre) return;
      const p = C.getScenePoint(o.e);
      // Verktøyet går over til «Flytt» uten å slippe notatet, så du kan skrive med én gang.
      if (verktoy === 'notat' && !o.target) { verktoy = 'flytt'; leggNotat(p, true); oppdaterPensel(); merk(); }
      else if (verktoy === 'tekst' && !o.target) { verktoy = 'flytt'; leggNotat(p, false); oppdaterPensel(); merk(); }
      else if (verktoy === 'viske' && o.target) { C.remove(o.target); }
    });
    C.on('mouse:move', (o) => {
      if (verktoy === 'viske' && kanEndre && o.target && trykket) C.remove(o.target);
    });

    // Musehjul zoomer rundt pekeren.
    C.on('mouse:wheel', (o) => {
      const e = o.e; e.preventDefault(); e.stopPropagation();
      let z = C.getZoom() * Math.pow(0.999, e.deltaY);
      z = Math.min(8, Math.max(0.2, z));
      C.zoomToPoint(new F.Point(e.offsetX, e.offsetY), z);
    });

    kobleBerøring(C.upperCanvasEl, kanEndre);
  }

  let trykket = false;

  // Penn, finger og to fingre. Fabric tar imot punktene; her bestemmes det
  // hva hvert trykk skal bety før Fabric ser det.
  function kobleBerøring(upper, kanEndre) {
    const punkter = new Map();
    let start = null;

    const avTegning = () => { C.isDrawingMode = false; };
    const paaTegning = () => { C.isDrawingMode = kanEndre && verktoy === 'penn'; };

    upper.addEventListener('pointerdown', (e) => {
      trykket = true;
      if (e.pointerType === 'pen') {
        pennBrukt = true;
        // Trykket styrer tykkelsen på streken som begynner nå.
        if (verktoy === 'penn' && C.freeDrawingBrush) C.freeDrawingBrush.width = Math.max(1, tykkelse * (0.4 + (e.pressure || 0.5) * 1.2));
      }
      punkter.set(e.pointerId, { x: e.clientX, y: e.clientY, type: e.pointerType });
      const fingre = [...punkter.values()].filter(p => p.type === 'touch');
      const treff = C.findTarget(e);
      // To fingre flytter og zoomer alltid. Har pennen vært brukt, tegner
      // ikke fingeren — den flytter. I «Flytt» og på en tavle du bare ser på,
      // flytter et trykk på tom flate hele tavla; et trykk på noe flytter det.
      const panorer = fingre.length >= 2
        || (e.pointerType === 'touch' && pennBrukt && verktoy === 'penn')
        || (!treff && (verktoy === 'flytt' || !kanEndre));
      if (panorer) {
        avTegning();
        start = { vpt: C.viewportTransform.slice(), z: C.getZoom(), punkter: new Map([...punkter].map(([k, v]) => [k, { ...v }])) };
        // Påbegynt strek fra første finger kastes.
        C.contextTop && C.clearContext(C.contextTop);
        e.stopImmediatePropagation();
      }
    }, { capture: true });

    upper.addEventListener('pointermove', (e) => {
      if (!punkter.has(e.pointerId)) return;
      punkter.get(e.pointerId).x = e.clientX; punkter.get(e.pointerId).y = e.clientY;
      if (!start) return;
      const naa = [...punkter.values()], foer = [...start.punkter.values()];
      if (!naa.length || !foer.length) return;
      const midt = (l) => ({ x: l.reduce((s, p) => s + p.x, 0) / l.length, y: l.reduce((s, p) => s + p.y, 0) / l.length });
      const avst = (l) => l.length < 2 ? 0 : Math.hypot(l[0].x - l[1].x, l[0].y - l[1].y);
      const m0 = midt(foer), m1 = midt(naa);
      const v = start.vpt.slice();
      v[4] += m1.x - m0.x; v[5] += m1.y - m0.y;
      C.setViewportTransform(v);
      if (naa.length >= 2 && foer.length >= 2 && avst(foer) > 0) {
        const z = Math.min(8, Math.max(0.2, start.z * avst(naa) / avst(foer)));
        const r = upper.getBoundingClientRect();
        C.zoomToPoint(new F.Point(m1.x - r.left, m1.y - r.top), z);
      }
      e.stopImmediatePropagation();
    }, { capture: true });

    const slipp = (e) => {
      punkter.delete(e.pointerId);
      if (!punkter.size) {
        trykket = false;
        start = null;
        paaTegning();
        oppdaterPensel();
      } else if (start) {
        start = { vpt: C.viewportTransform.slice(), z: C.getZoom(), punkter: new Map([...punkter].map(([k, v]) => [k, { ...v }])) };
      }
    };
    upper.addEventListener('pointerup', slipp, { capture: true });
    upper.addEventListener('pointercancel', slipp, { capture: true });
  }

  function tilpass() {
    const f = document.getElementById('flate');
    if (!f || !C) return;
    C.setDimensions({ width: f.clientWidth, height: f.clientHeight });
  }

  function oppdaterPensel() {
    if (!C) return;
    C.isDrawingMode = !!T.kanEndre && verktoy === 'penn';
    const b = C.freeDrawingBrush;
    if (b) { b.color = farge; b.width = tykkelse; }
    C.defaultCursor = verktoy === 'flytt' ? 'grab' : (verktoy === 'viske' ? 'not-allowed' : 'crosshair');
    C.forEachObject(o => { o.selectable = !!T.kanEndre && verktoy === 'flytt'; o.evented = verktoy === 'flytt' || verktoy === 'viske'; });
  }

  function velgVerktoy(v) {
    verktoy = v;
    if (C) C.discardActiveObject();
    oppdaterPensel();
    merk();
    C && C.requestRenderAll();
  }

  function leggNotat(p, somNotat) {
    const t = new F.Textbox(somNotat ? '' : '', {
      left: p.x, top: p.y, width: somNotat ? 180 : 240, fontSize: somNotat ? 18 : 22,
      fontFamily: 'Alegreya Sans', fill: somNotat ? '#4D1D12' : farge,
      backgroundColor: somNotat ? NOTATFARGE : '', padding: somNotat ? 12 : 0,
      splitByGrapheme: false,
    });
    C.add(t);
    C.setActiveObject(t);
    t.enterEditing();
    C.requestRenderAll();
  }

  function velgBilde() {
    const inp = el('input', { type: 'file', accept: 'image/*', class: 'skjult', 'data-test': 'bildefil' });
    inp.addEventListener('change', async () => {
      const fil = inp.files && inp.files[0];
      inp.remove();
      if (!fil) return;
      status('Laster opp …');
      const fd = new FormData();
      fd.append('handling', 'bilde'); fd.append('id', String(T.id)); fd.append('bilde', fil);
      try {
        const r = await fetch(API, { method: 'POST', credentials: 'same-origin', body: fd });
        const d = await r.json().catch(() => ({}));
        if (!r.ok || !d.ok) throw new Error(d.feil || 'Bildet kom ikke fram.');
        const img = await F.FabricImage.fromURL('/' + d.url.replace(/^\//, ''));
        const vpt = C.viewportTransform, z = C.getZoom();
        const maks = Math.min(C.width, C.height) * 0.6 / z;
        const s = Math.min(1, maks / Math.max(img.width, img.height));
        img.set({ left: (-vpt[4] + C.width / 2) / z - img.width * s / 2, top: (-vpt[5] + C.height / 2) / z - img.height * s / 2, scaleX: s, scaleY: s });
        C.add(img);
        velgVerktoy('flytt');
        C.setActiveObject(img);
        status('');
      } catch (e) { status(''); alert(e.message); }
    });
    document.body.append(inp);
    inp.click();
  }

  function zoom(f) {
    const z = Math.min(8, Math.max(0.2, C.getZoom() * f));
    C.zoomToPoint(new F.Point(C.width / 2, C.height / 2), z);
  }

  async function lastSide() {
    angre = []; gjor = [];
    C._laster = true;
    let json = {};
    try { json = JSON.parse(sideData(nr) || '{}'); } catch (e) { json = {}; }
    C.clear();
    C.backgroundColor = null;
    if (json && json.objects && json.objects.length) await C.loadFromJSON(json);
    C.setViewportTransform([1, 0, 0, 1, 0, 0]);
    C._laster = false;
    angre.push(JSON.stringify(C.toJSON()));
    oppdaterPensel();
    C.requestRenderAll();
    const antall = Math.max(1, (T.sider || []).length);
    sideEl.textContent = nr + ' / ' + antall;
    status('');
  }

  function planLagring() {
    skitten = true;
    status('Ikke lagret ennå');
    clearTimeout(lagreTimer);
    lagreTimer = setTimeout(lagre, 1200);
  }

  async function lagre() {
    if (!T || !T.kanEndre || !skitten) return;
    if (lagrer) { planLagring(); return; }
    lagrer = true; skitten = false;
    const data = JSON.stringify(C.toJSON());
    try {
      await send({ handling: 'lagre', id: T.id, nr, data });
      const s = (T.sider || []).find(x => x.nr === nr);
      if (s) s.data = data; else (T.sider = T.sider || []).push({ nr, data });
      status(skitten ? 'Ikke lagret ennå' : 'Lagret');
    } catch (e) {
      skitten = true;
      status('Ikke lagret — ' + e.message);
    } finally { lagrer = false; }
  }

  async function byttSide(n) {
    const antall = Math.max(1, (T.sider || []).length);
    if (n < 1 || n > antall || n === nr) return;
    clearTimeout(lagreTimer); await lagre();
    nr = n;
    await lastSide();
  }

  async function nySide() {
    const antall = Math.max(1, (T.sider || []).length);
    if (antall >= 20) return alert('En tavle kan ha opptil 20 sider.');
    clearTimeout(lagreTimer); await lagre();
    try {
      await send({ handling: 'lagre', id: T.id, nr: antall + 1, data: '{}' });
      T.sider.push({ nr: antall + 1, data: '{}' });
      nr = antall + 1;
      await lastSide();
    } catch (e) { alert(e.message); }
  }

  function angreSteg() {
    if (angre.length < 2) return;
    iAngre = true;
    gjor.push(angre.pop());
    C.loadFromJSON(JSON.parse(angre[angre.length - 1])).then(() => { iAngre = false; oppdaterPensel(); C.requestRenderAll(); planLagring(); });
  }
  function gjorSteg() {
    if (!gjor.length) return;
    iAngre = true;
    const s = gjor.pop(); angre.push(s);
    C.loadFromJSON(JSON.parse(s)).then(() => { iAngre = false; oppdaterPensel(); C.requestRenderAll(); planLagring(); });
  }

  // PNG av alt på siden, ikke bare det som vises akkurat nå.
  function lastNed() {
    const vpt = C.viewportTransform.slice();
    // Uten zoom og flytting er skjermen og scenen det samme, og utsnittet
    // kan regnes rett fra hvert objekt.
    C.setViewportTransform([1, 0, 0, 1, 0, 0]);
    const obj = C.getObjects();
    let url;
    if (obj.length) {
      const r = obj.map(o => o.getBoundingRect());
      const x0 = Math.min(...r.map(b => b.left)), y0 = Math.min(...r.map(b => b.top));
      const x1 = Math.max(...r.map(b => b.left + b.width)), y1 = Math.max(...r.map(b => b.top + b.height));
      const kant = 24;
      const gammel = C.backgroundColor;
      C.backgroundColor = '#ffffff';
      url = C.toDataURL({ format: 'png', multiplier: 2, left: x0 - kant, top: y0 - kant, width: x1 - x0 + 2 * kant, height: y1 - y0 + 2 * kant });
      C.backgroundColor = gammel;
    } else {
      url = C.toDataURL({ format: 'png', multiplier: 2 });
    }
    C.setViewportTransform(vpt);
    const a = el('a', { href: url, download: (T.tittel || 'skisse').replace(/[^wæøåÆØÅ -]+/g, '') + ' ' + nr + '.png' });
    document.body.append(a); a.click(); a.remove();
    window.__skissePng = url;
  }

  function ark(tittel, ...innhold) {
    const bakgrunn = el('div', { class: 'ark', onclick: (e) => { if (e.target === bakgrunn) bakgrunn.remove(); } },
      el('div', {}, el('h3', { tekst: tittel }), ...innhold,
        el('button', { class: 'pille', type: 'button', onclick: () => bakgrunn.remove() }, 'Lukk')));
    const esc = (e) => { if (e.key === 'Escape') { bakgrunn.remove(); document.removeEventListener('keydown', esc); } };
    document.addEventListener('keydown', esc);
    document.body.append(bakgrunn);
    return bakgrunn;
  }

  function visDeling() {
    const bryter = (navn, paa, vekslet) => {
      const b = el('button', { class: 'bryter', type: 'button', role: 'switch', 'aria-checked': paa ? 'true' : 'false', 'aria-label': navn });
      b.addEventListener('click', async () => {
        const ny = b.getAttribute('aria-checked') !== 'true';
        b.setAttribute('aria-checked', ny ? 'true' : 'false');
        try { await vekslet(ny); } catch (e) { b.setAttribute('aria-checked', ny ? 'false' : 'true'); alert(e.message); }
      });
      return el('div', { class: 'rad' }, el('span', { tekst: navn }), b);
    };
    const lagreDeling = async () => {
      const d = await send({ handling: 'del', id: T.id, medlemmer: !!T.deltMedlemmer, deltakere: !!T.deltDeltakere });
      Object.assign(T, { deltMedlemmer: d.tavle.deltMedlemmer, deltDeltakere: d.tavle.deltDeltakere });
    };
    ark('Del',
      bryter('Del med medlemmer', T.deltMedlemmer, async (v) => { T.deltMedlemmer = v; await lagreDeling(); }),
      bryter('Del med kursdeltakere', T.deltDeltakere, async (v) => { T.deltDeltakere = v; await lagreDeling(); }));
  }

  async function visVersjoner() {
    clearTimeout(lagreTimer); await lagre();
    const r = await hent(API + '?id=' + T.id + '&versjoner=1&nr=' + nr);
    const liste = (r.d.versjoner || []);
    const a = ark('Tidligere versjoner',
      liste.length ? liste.map(v => el('button', { class: 'versjon', type: 'button', onclick: async () => {
        a.remove();
        C._laster = true;
        await C.loadFromJSON(JSON.parse(v.data));
        C._laster = false;
        oppdaterPensel(); C.requestRenderAll();
        angre.push(JSON.stringify(C.toJSON()));
        planLagring();
      } }, norskTid(v.naar))) : el('p', { class: 'tom', tekst: 'Ingen tidligere versjoner ennå.' }));
  }

  function slettSpor() {
    const antall = Math.max(1, (T.sider || []).length);
    const a = ark('Slett',
      antall > 1 ? el('button', { class: 'pille', type: 'button', onclick: async () => {
        a.remove();
        try { const d = await send({ handling: 'slett-side', id: T.id, nr }); T = d.tavle; nr = Math.min(nr, T.sider.length); await lastSide(); } catch (e) { alert(e.message); }
      } }, 'Slett denne siden') : null,
      el('button', { class: 'pille fylt', type: 'button', onclick: async () => {
        a.remove();
        try { await send({ handling: 'slett', id: T.id }); T = null; await tilListe(); } catch (e) { alert(e.message); }
      } }, 'Slett hele tavla'));
  }

  async function lukk() {
    clearTimeout(lagreTimer);
    await lagre();
    window.removeEventListener('resize', tilpass);
    if (C) { C.dispose(); C = null; }
    await tilListe();
  }

  // Lagre før siden lukkes.
  window.addEventListener('pagehide', () => {
    if (!T || !T.kanEndre || !skitten || !C) return;
    try {
      navigator.sendBeacon && navigator.sendBeacon(API, new Blob([JSON.stringify({ handling: 'lagre', id: T.id, nr, data: JSON.stringify(C.toJSON()) })], { type: 'application/json' }));
    } catch (e) { /* får ikke gjort noe med det */ }
  });

  // For testene: tegn en strek som om noen gjorde det.
  window.__skisse = {
    lerret: () => C,
    tavle: () => T,
    lagreNaa: async () => { clearTimeout(lagreTimer); skitten = true; await lagre(); },
  };

  start();
})();
