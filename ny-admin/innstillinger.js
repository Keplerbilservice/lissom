/* Ny admin, del C: Innstillinger (eierens GO 08.10.2026, prototypen lissom-enklere-admin-2).
   Faner: Meldinger · Varsler · Innlogging · Kurs · Medlemskap · Priser · Betaling · Brytere.

   Koblet til det som faktisk lagres i dag:
   - Meldinger/Varsler: malene i notification_templates (api/admin/maler.php: av/på og tekst;
     api/admin/meldinger.php: SMS/e-post per mal og antall sendt siste 30 dager). Varsler = malene
     til verkstedet selv (intern_…), Meldinger = alt som går til kunder og medlemmer.
   - Brytere: «Vis/…»-nøklene i content_blocks (api/admin/innhold.php), samme liste og
     standardverdier som admin-ny › Synlighet.
   - Medlemskap/Priser: planene og timepakken fra api/admin/medlemmer.php, frakten fra produkter.php.
   Det som ikke har lagring i dag, står i en «Finnes ikke ennå»-boks under hver fane. Ingen nye
   kundetekster: tekstene som vises er malene slik de står. */
(() => {
  'use strict';
  const NA = () => window.NyAdmin || window;
  const kall = (sti, data) => NA().api('/api/admin/' + sti, data === undefined ? { metode: 'GET' } : { metode: 'POST', data });
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const toast = h => NA().toast(h);
  const registrer = (id, opp) => { const f = NA().registrerSide; if (typeof f === 'function') f(id, opp); else document.addEventListener('DOMContentLoaded', () => registrer(id, opp), { once: true }); };

  const FANER = ['Meldinger', 'Varsler', 'Innlogging', 'Kurs', 'Medlemskap', 'Priser', 'Betaling', 'Brytere', 'Oppdateringer'];
  // Samme liste og standardverdier som «Synlighet» i admin-ny/innhold-og-kurs.js.
  const FLAGG = [['Banner/pa', 'Banneret under toppbildet', true], ['Vis/salgsuke', 'Salgskampanjen', true], ['Vis/kursvelger', 'Kursvelger i toppen', true],
    ['Vis/sok', 'Søk på nettsiden', true], ['Vis/referanser', 'Referanser på forsiden', true], ['Vis/anmeldelser', 'Google-anmeldelser', true],
    ['Vis/kursholdere', 'Kursholdere på nettsiden', true], ['Vis/admin2', 'Prøv nytt admin', false], ['Vis/autosvar', 'Automatiske svar på kommentarer', false],
    ['Vis/internkurs', 'Interne kurs', true], ['Vis/internbutikk', 'Internbutikken', true], ['Vis/medlemssalg', 'Selg egne arbeider', true],
    ['Vis/verving', 'Vervepremie', false], ['Vis/autogodkjenn', 'Auto-godkjenn nye varer', false], ['Vis/skisser', 'Skisser', true],
    ['Vis/skissermedlemmer', 'Skisser for medlemmer', false], ['Vis/skisserdeltakere', 'Skisser for kursdeltakere', false], ['Vis/gaven', 'Ta med en venn', true],
    ['Vis/medlemfrys', 'Frys medlemskap', true], ['Vis/glemtstempling', 'Glemt å stemple ut', true], ['Vis/handleliste', 'Handlelista', true],
    ['Vis/medlemsforslag', 'Del på Instagram og i galleriet', true], ['Vis/oppmotekurs', 'Betal ved oppmøte på kurs', true],
    ['Vis/oppmotebutikk', 'Betal ved oppmøte i butikk', true], ['Vis/oppmotemedlemskap', 'Betal medlemskap ved oppmøte', false],
    ['Vis/dugnadutvalgte', 'Dugnad bare for utvalgte medlemmer', false], ['Vis/dugnad', 'Dugnad', true], ['Vis/dugnadoverforing', 'Overfør dugnadstimer', true],
    ['Vis/tilleggbarn', 'Ta med barn', true], ['Vis/kalenderark', 'Kalenderen: økt-ark, farger og merker', false],
    ['Vis/kursstart3', 'Start kurset i tre steg, med QR-betaling', false], ['Vis/kalendergjenta', 'Kalenderen: gjenta kursdatoer og dupliser til neste uke', false],
    ['Vis/kalendermeny', 'Kalenderen: høyreklikk-menyer, dra og slipp, sideliste og dagsrapport', false], ['Vis/kasse', 'Kassa på iPad (/kasse)', false],
    ['Vis/magjoresmer', 'I dag: ovnen ferdig, kurs som fylles tregt, medlemmer ikke innom og mandagsoppsummering', true]];
  // Hver bryter står ett sted (designvokteren 08.10.2026): det som vises på Min side styres bare under Medlemssiden
  // (NA.minSideNokler fra medlemssiden.js), og Brytere-fanen har bare det som ikke står i en annen fane.
  const FANE_FLAGG = {
    Kurs: ['Vis/kursvelger', 'Vis/kursholdere', 'Vis/kursstart3', 'Vis/kalenderark', 'Vis/kalendergjenta', 'Vis/kalendermeny', 'Vis/skisserdeltakere'],
    Medlemskap: ['Vis/glemtstempling', 'Vis/dugnadutvalgte', 'Vis/dugnadoverforing', 'Vis/gaven'],
    Betaling: ['Vis/oppmotekurs', 'Vis/oppmotebutikk', 'Vis/oppmotemedlemskap', 'Vis/kasse'],
  };
  const GRUPPE = { system: 'Systemmeldinger', ordre: 'Ordrebekreftelser', kurs: 'Kursmeldinger', nyhetsbrev: 'Nyhetsbrev' };
  // Det som står i prototypen, men som ikke har noe sted å lagres i dag. Bare admin ser dette.
  const MANGLER = {
    Meldinger: ['Tidspunktet kan ikke velges her: påminnelsen går fast dagen før kl. 12 og anmeldelsen neste dag kl. 10 (satt i serverens planlagte jobber).',
      'Flere meldinger til samme person samme dag slås ikke sammen til én i dag.'],
    Varsler: ['Push på telefonen for det som må gjøres finnes ikke (verkstedet får e-post/SMS etter malene over).',
      '«Nye påmeldinger samlet kl. 18» finnes ikke: hver påmelding sendes for seg.', '«Stille mellom 21 og 07» finnes ikke.'],
    Innlogging: ['«Hold meg innlogget i 30 dager» og lista over innloggede enheter finnes ikke ennå (hører til innloggingen i skallet).'],
    Kurs: ['Standardtekstene for kurs redigeres fortsatt i gammel admin.'],
    Medlemskap: ['Planene (navn, timer, pris) endres fortsatt i gammel admin.'],
    Priser: ['Kursprisene står på hvert kurs og endres på kurssiden. Prisene her endres fortsatt i gammel admin.'],
    Betaling: ['Vipps-oppsettet og betalingsvarslene ligger fortsatt i gammel admin.'],
  };
  const LENKER = {
    Kurs: [['Standardtekster for kurs', '/admin-ny#kursstandard']],
    Medlemskap: [['Frys av medlemskap', '/admin-ny#frys'], ['Grupperabatter', '/admin-ny#rabatter']],
    Priser: [['Butikk og frakt', '/admin-ny#butikk']],
    Betaling: [['Vipps og betalingsvarsler', '/admin-ny#vippsoppsett'], ['Betalinger', '/admin-ny#betalinger']],
    Meldinger: [['Tekstmaler med e-postoppsett', '/admin-ny#maler']],
  };

  const S = { fane: 'Meldinger', el: null, maler: null, telling: null, innhold: null, medl: null, fraktOre: null, opptatt: false, ark: null };

  const bryterKnapp = (attr, verdi, navn, paa, av = false) => `<button class="av" ${attr}="${esc(verdi)}" aria-pressed="${paa}" aria-label="${esc(navn)}" ${av ? 'disabled' : ''}></button>`;
  const flaggPaa = (k, std) => { const v = (S.innhold || {})[k]; return v === undefined ? std : (std ? v !== 'nei' : v === 'ja'); };
  const minSide = () => NA().minSideNokler || [];
  const harSms = k => k === 'sms' || k === 'epost_sms';
  const harEpost = k => k === 'epost' || k === 'epost_sms';

  function manglerBoks(fane) {
    const m = MANGLER[fane], l = LENKER[fane];
    return (l ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">${l.map(([t, u]) => `<button class="knapp" data-inn-gaa="${esc(u)}">${esc(t)}</button>`).join('')}</div>` : '')
      + (m ? `<section class="kort" style="margin-top:16px"><div class="kort-head"><h2>Finnes ikke ennå</h2><small>Bare du ser dette</small></div>
        ${m.map(t => `<div class="rad"><div class="tekst"><small>${esc(t)}</small></div></div>`).join('')}</section>` : '');
  }

  function malRader(maler) {
    const t = (S.telling && S.telling.telling) || {};
    return maler.map(m => {
      const n = (t[m.navn]?.sms || 0) + (t[m.navn]?.epost || 0);
      // Bare kanalene malen faktisk kan sendes på (meldinger.php «kanaler»). Én mulig kanal = fast merke, ikke bryter.
      const mulig = S.telling?.kanaler?.[m.navn] || ['epost'];
      const kanalKnapp = (k, navn, paa) => mulig.length < 2
        ? (mulig.includes(k) ? `<span class="kanal-fast ${paa ? 'paa' : ''}">${paa ? '✓ ' : ''}${navn}</span>` : '<span></span>')
        : `<button class="kanal ${paa && m.aktiv ? 'paa' : ''}" data-inn-kanal="${esc(m.navn)}|${k}" aria-pressed="${paa}" ${m.aktiv ? '' : 'disabled'}>${paa ? '✓ ' : ''}${navn}</button>`;
      return `<div class="meld-rad ${m.aktiv ? '' : 'av-rad'}" style="display:grid;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid var(--line)">
        ${bryterKnapp('data-inn-malpaa', m.navn, m.tittel + ' av eller på', m.aktiv)}
        <span><b>${esc(m.tittel)}</b><small style="display:block">${m.aktiv ? `${esc(S.telling?.naar?.[m.navn] || '')}${S.telling?.naar?.[m.navn] ? ' · ' : ''}sendt ${n} ganger siste 30 dager` : 'Slått av – sendes ikke'}</small></span>
        ${kanalKnapp('sms', 'SMS', harSms(m.kanal))}
        ${kanalKnapp('epost', 'E-post', harEpost(m.kanal))}
        <button class="knapp liten" data-inn-tekst="${esc(m.navn)}">Tekst og tid</button></div>`;
    }).join('');
  }

  function fanMeldinger(intern) {
    if (!S.maler) return '<p class="muted">Henter …</p>';
    const M = S.maler.filter(m => m.navn.startsWith('intern_') === intern);
    const sum = (S.telling && S.telling.sum) || { sms: 0, epost: 0 };
    const topp = intern ? '' : `<div class="grid" style="margin-bottom:20px">
        <div class="kort"><div class="type">SMS siste 30 dager</div><div class="ovnstatus" style="font-size:34px">${sum.sms}</div><small>${S.telling?.smsMulig ? 'Sendt via Sveve' : 'SMS er ikke satt opp'}</small></div>
        <div class="kort"><div class="type">E-post siste 30 dager</div><div class="ovnstatus" style="font-size:34px">${sum.epost}</div><small>Alle maler samlet</small></div></div>`;
    const grupper = intern ? [['', M]] : Object.entries(M.reduce((a, m) => ((a[m.gruppe] = a[m.gruppe] || []).push(m), a), {}));
    return topp + grupper.map(([g, liste]) => `<section class="kort" style="margin-bottom:16px">
        <div class="kort-head"><h2>${intern ? 'Varsler til verkstedet' : esc(GRUPPE[g] || g)}</h2></div><p class="muted" style="margin:-6px 0 8px;font-size:15px">Trykk for å slå SMS eller e-post av og på.</p>
        <div class="meld-tabell">${malRader(liste)}</div></section>`).join('')
      + manglerBoks(intern ? 'Varsler' : 'Meldinger');
  }

  function fanFlagg(nokler) {
    if (!S.innhold) return '<p class="muted">Henter …</p>';
    const andre = Object.values(FANE_FLAGG).flat();
    const L = (nokler ? FLAGG.filter(f => nokler.includes(f[0])) : FLAGG.filter(f => !andre.includes(f[0]))).filter(f => !minSide().includes(f[0]));
    return `<section class="kort">${L.map(([k, navn, std]) => `<div class="bryter"><div><b>${esc(navn)}</b></div>${bryterKnapp('data-inn-flagg', k, navn, flaggPaa(k, std))}</div>`).join('')}</section>`;
  }

  function fanMedlemskap() {
    if (!S.medl) return '<p class="muted">Henter …</p>';
    const tp = S.medl.timepakke || {};
    return `<section class="kort" style="margin-bottom:16px"><div class="kort-head"><h2>Medlemskapene</h2></div>
      ${(S.medl.planer || []).map(p => `<div class="rad"><div class="tekst"><b>${esc(p.navn)}</b><small>${p.timer !== null ? p.timer + ' timer i måneden' : 'Fri bruk'}${p.engangs ? ' · én måned' : ' · løpende'}</small></div><span class="merke">${esc(p.pris)}</span></div>`).join('')}
      ${tp.timer ? `<div class="rad"><div class="tekst"><b>Timepakke</b><small>${tp.timer} timer</small></div><span class="merke">${(tp.prisOre / 100).toLocaleString('nb-NO')} kr</span></div>` : ''}
      </section>` + fanFlagg(FANE_FLAGG.Medlemskap);
  }

  function fanPriser() {
    if (!S.medl) return '<p class="muted">Henter …</p>';
    const tp = S.medl.timepakke || {};
    return `<section class="kort"><div class="kort-head"><h2>Priser som står i systemet</h2></div>
      ${(S.medl.planer || []).map(p => `<div class="rad"><div class="tekst"><b>${esc(p.navn)}</b><small>Medlemskap</small></div><span class="merke">${esc(p.pris)}</span></div>`).join('')}
      ${tp.timer ? `<div class="rad"><div class="tekst"><b>Timepakke, ${tp.timer} timer</b></div><span class="merke">${(tp.prisOre / 100).toLocaleString('nb-NO')} kr</span></div>` : ''}
      ${S.fraktOre !== null ? `<div class="rad"><div class="tekst"><b>Frakt</b><small>Nettbutikken, når kunden velger sending</small></div><span class="merke">${(S.fraktOre / 100).toLocaleString('nb-NO')} kr</span></div>` : ''}
      </section>`;
  }

  /* Databaseoppdateringer (eieren 09.10.2026: «jeg vil ha oppdatering under innstillinger»). Samme api/migrer.php og
     NA.kjorOppdateringer som saken i «Må gjøres». Flyttet hit fra Mer, så den ikke står to steder. */
  function fanOppdateringer() {
    const m = S.migr === undefined ? null : S.migr;
    if (S.migr === undefined) return '<section class="kort"><p class="laster">Henter …</p></section>';
    return `<section class="kort"><div class="kort-head"><h2>Databaseoppdateringer</h2></div>
      ${m === null ? '<p class="feil">Kunne ikke hente oppdateringene.</p>'
        : m.length ? `<p>${m.length} ${m.length === 1 ? 'oppdatering venter' : 'oppdateringer venter'}.</p>${m.map(f => `<div class="rad"><div class="tekst"><b>${esc(f)}</b></div></div>`).join('')}
          <div style="margin-top:14px"><button class="knapp hoved" type="button" data-inn-kjor>Kjør oppdateringer</button></div>`
        : '<p>Databasen er oppdatert. Ingenting å gjøre.</p>'}</section>`;
  }

  function innholdFane() {
    switch (S.fane) {
      case 'Meldinger': return fanMeldinger(false);
      case 'Varsler': return fanMeldinger(true);
      case 'Innlogging': return manglerBoks('Innlogging');
      case 'Kurs': return fanFlagg(FANE_FLAGG.Kurs) + manglerBoks('Kurs');
      case 'Medlemskap': return fanMedlemskap() + manglerBoks('Medlemskap');
      case 'Priser': return fanPriser() + manglerBoks('Priser');
      case 'Betaling': return fanFlagg(FANE_FLAGG.Betaling) + manglerBoks('Betaling');
      case 'Oppdateringer': return fanOppdateringer();
      default: return fanFlagg(null);
    }
  }

  function tegnFane() {
    const f = document.getElementById('inn-faner');
    if (f) f.innerHTML = FANER.map(n => `<button data-inn-fane="${n}" aria-pressed="${S.fane === n}">${n}</button>`).join('');
    const b = document.getElementById('inn-boks'); if (b) b.innerHTML = innholdFane();
  }

  /** Henter det fanen trenger. Det tunge (medlemslista) bare når Medlemskap eller Priser er åpen. */
  async function hent() {
    const jobber = [];
    if (S.fane === 'Meldinger' || S.fane === 'Varsler') {
      jobber.push(kall('maler.php').then(d => { S.maler = d.maler || []; }));
      jobber.push(kall('meldinger.php').then(d => { S.telling = d; }).catch(() => { S.telling = null; }));
    }
    if (['Kurs', 'Medlemskap', 'Betaling', 'Brytere'].includes(S.fane)) jobber.push(kall('innhold.php').then(d => { S.innhold = d.innhold || {}; }));
    if ((S.fane === 'Medlemskap' || S.fane === 'Priser') && !S.medl) jobber.push(kall('medlemmer.php').then(d => { S.medl = { planer: d.planer || [], timepakke: d.timepakke || {} }; }));
    if (S.fane === 'Oppdateringer') jobber.push(NA().oppdateringer(true).then(d => { S.migr = d ? d.mangler : null; }));
    if (S.fane === 'Priser' && S.fraktOre === null) jobber.push(kall('produkter.php').then(d => { S.fraktOre = Number(d.fraktOre || 0); }));
    try { await Promise.all(jobber); } catch (e) { toast(esc(e.message)); }
    if (!S.ark) tegnFane();
  }

  async function tegn(el) {
    S.el = el;
    el.innerHTML = `<div class="head"><div><div class="eyebrow">Alltid nederst til venstre</div><h1>Innstillinger</h1></div></div>
      <div class="faner" id="inn-faner"></div><div id="inn-boks"></div>`;
    tegnFane();
    await hent();
    const hh = NA().hentHvert;
    if (typeof hh === 'function') hh(15000, () => { if (el.isConnected && S.el === el && !S.opptatt && !S.ark) hent(); });
  }

  document.addEventListener('click', async e => {
    const k = e.target.closest('[data-inn-kjor]'); if (!k) return;
    k.disabled = true;
    if (await NA().kjorOppdateringer()) { NA().oppdaterTopp?.(); }
    S.migr = undefined; tegnFane(); hent();
  });

  // ── Tekst og tid ───────────────────────────────────────────────────────
  function arkTekst(navn) {
    const m = S.maler.find(x => x.navn === navn); if (!m) return;
    S.ark = navn;
    const naar = S.telling?.naar?.[m.navn];
    NA().apneArk(`<div class="ark-head"><h2>${esc(m.tittel)}</h2><button class="lukk" data-inn-gjor="lukk" aria-label="Lukk">×</button></div>
      <div><small>Når</small><p><b>${esc(naar || m.hvor)}</b></p>${naar ? `<small>${esc(m.hvor)}</small>` : ''}</div>
      ${harEpost(m.kanal) ? `<label style="display:grid;gap:5px"><small>Emne (e-post)</small><input id="inn-emne" value="${esc(m.emne)}" maxlength="191"
        style="padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit"></label>` : ''}
      <label style="display:grid;gap:5px"><small>Tekst${m.oppsett ? ' (SMS og ren tekst)' : ''}</small><textarea id="inn-tekst" style="width:100%;min-height:150px;padding:12px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit">${esc(m.tekst)}</textarea></label>
      ${m.felter && m.felter.length ? `<small>Fylles inn automatisk: ${m.felter.map(f => `<span title="${esc(f.hva)}">{${esc(f.felt)}}</span>`).join(' ')}</small>` : ''}
      ${m.oppsett ? `<div class="sms"><b>E-posten bruker oppsettet:</b><br>${esc(m.oppsett.overskrift)}${m.oppsett.avsnitt ? '<br>' + esc(m.oppsett.avsnitt).replace(/\n/g, '<br>') : ''}
        <br><button class="knapp liten" style="margin-top:8px" data-inn-gaa="/admin-ny#maler">Endre e-postoppsettet</button></div>` : ''}
      <p class="feil" id="inn-feil" role="alert" hidden style="color:var(--red)"></p>
      <div class="ark-fot"><button class="knapp" data-inn-gjor="lukk">Avbryt</button><button class="knapp hoved" data-inn-gjor="lagretekst" data-navn="${esc(m.navn)}">Lagre</button></div>`);
  }

  async function lagreMal(m, endring) {
    const d = await kall('maler.php', { handling: 'lagre', navn: m.navn, emne: m.emne, tekst: m.tekst, aktiv: m.aktiv ? 'ja' : 'nei', ...endring });
    if (Array.isArray(d.maler)) S.maler = d.maler;
    return d;
  }

  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-inn-fane],[data-inn-gaa],[data-inn-malpaa],[data-inn-kanal],[data-inn-tekst],[data-inn-flagg],[data-inn-gjor]'); if (!b) return;
    const ds = b.dataset;
    if (ds.innFane) { S.fane = ds.innFane; tegnFane(); hent(); return; }
    if (ds.innGaa) { location.href = ds.innGaa; return; }
    if (ds.innTekst) { arkTekst(ds.innTekst); return; }
    if (ds.innGjor === 'lukk') { NA().lukkArk(); return; }
    if (S.opptatt) return;
    S.opptatt = true; b.disabled = true;
    try {
      if (ds.innMalpaa) {
        const m = S.maler.find(x => x.navn === ds.innMalpaa);
        if (m) { const d = await lagreMal(m, { aktiv: m.aktiv ? 'nei' : 'ja' }); toast(esc(d.beskjed || 'Lagret.')); }
      } else if (ds.innKanal) {
        const [navn, k] = ds.innKanal.split('|'); const m = S.maler.find(x => x.navn === navn);
        if (m) {
          const sms = k === 'sms' ? !harSms(m.kanal) : harSms(m.kanal), epost = k === 'epost' ? !harEpost(m.kanal) : harEpost(m.kanal);
          const d = await kall('meldinger.php', { handling: 'kanal', navn, sms, epost });
          m.kanal = d.kanal; toast(esc(d.beskjed || 'Lagret.'));
        }
      } else if (ds.innFlagg) {
        const f = FLAGG.find(x => x[0] === ds.innFlagg); const ny = !flaggPaa(f[0], f[2]);
        await kall('innhold.php', { endringer: { [f[0]]: ny ? 'ja' : 'nei' } });
        S.innhold[f[0]] = ny ? 'ja' : 'nei';
        toast(`<b>${esc(f[1])}</b> er slått ${ny ? 'på' : 'av'}.`);
      } else if (ds.innGjor === 'lagretekst') {
        const m = S.maler.find(x => x.navn === ds.navn);
        const tekst = (document.getElementById('inn-tekst')?.value || '').trim();
        const emneEl = document.getElementById('inn-emne');
        try {
          const d = await lagreMal(m, { tekst, ...(emneEl ? { emne: emneEl.value.trim() } : {}) });
          S.ark = null; NA().lukkArk(true); toast(esc(d.beskjed || 'Lagret.'));
        } catch (err) { const f = document.getElementById('inn-feil'); if (f) { f.textContent = err.message; f.hidden = false; } return; }
      }
      tegnFane();
    } catch (err) { toast(esc(err.message)); } finally { S.opptatt = false; b.disabled = false; }
  });
  document.addEventListener('close', e => { if (e.target.tagName === 'DIALOG') S.ark = null; }, true);

  registrer('innstillinger', { tittel: 'Innstillinger', ikon: '⚙', tegn, mobil: false });
})();
