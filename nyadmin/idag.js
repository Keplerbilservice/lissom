/**
 * «I dag» — det som skjer nå. Første modul i det nye admin.
 *
 * Tallene kommer fra de samme API-ene som Oversikt i det gamle admin, med
 * uendret svarformat:
 *
 *   /api/admin/oversikt.php     omsetningen (Omsetning::perFormal, med mva),
 *                               dagens bestillinger, kurs, koer, lav aktivitet,
 *                               bestill mer, hvem som er inne
 *   /api/admin/foresporsler.php ubesvarte forespørsler
 *   /api/admin/marked.php       kommentarer som venter i Innboks (autosvar telles ikke)
 *   /api/admin/venteliste.php   ventelista
 *   /api/admin/soknader.php     medlemskapsforespørsler
 *   /api/admin/ferdigbrent.php  klar til henting, ikke meldt fra
 *   /api/admin/feilrapporter.php nye feil meldt inn
 *   /api/ovn.php                ovnen
 *
 * Mobil: summen først, så dagens kurs, «Må gjøres», ovnen og hvem som er inne.
 */
import { el, hent, send, bekreft, medAngre, melding, kr, rad, lasting, feilboks, ark } from './kjerne.js';
import { omsetningKort } from './omsetning.js';

/** Hurtigvalgene eieren kan velge mellom («Tilpass»). */
const HURTIG = {
  startkurs:    { navn: '▶ Start kurset', maal: '/admin/oversikt', klasse: 'f' },
  tabetalt:     { navn: 'Ta betalt', maal: '/admin/uttak', klasse: 'g' },
  nykursdato:   { navn: 'Ny kursdato', maal: '/admin/kalender' },
  dagsoppgjor:  { navn: 'Dagsoppgjør', maal: '/admin/okonomi' },
  nyttkurs:     { navn: 'Nytt kurs', maal: '/admin/kurs' },
  melding:      { navn: 'Melding til medlemmene', maal: '/admin/beskjeder' },
  tildeltakere: { navn: 'Til deltakere', maal: '/admin/beskjeder' },
  leggut:       { navn: 'Legg ut på SoMe', maal: '/admin/markedsforing' },
  kasse:        { navn: 'Kasse', maal: '/admin/uttak' },
  skisser:      { navn: 'Skisser', maal: '/skisser.html' },
};

const OVN = [
  ['raabrann', 'Råbrann satt'],
  ['glasurbrann', 'Glasurbrann satt'],
  ['tomt', 'Ovn tømt'],
];

const iDagOslo = () => new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Oslo' }).format(new Date());
const klokke = (sek) => new Date(sek * 1000).toLocaleTimeString('nb-NO', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Oslo' });

function betaltIdag(ov) {
  const oms = ov.omsetning || {};
  const ubetalte = (ov.ubetalte || []).length;
  const eksOre = typeof oms.idagEksOre === "number" ? oms.idagEksOre : oms.idagOre;
  // «Betalt i dag»: alt som er kjøpt i dag, også det som ikke er betalt ennå.
  const best = ov.dagensBestillinger || [];
  const liste = best.length
    ? best.slice(0, 8).map(b => rad([b.kl, b.navn, b.hva].filter(Boolean).join(" · "),
        el("span", {}, el("b", { tekst: String(b.belop || "").replace(/^kr\.\s/u, "kr ").replace(/,-$/, "") }), " ",
          el("span", { class: "tag" + (b.status === "Betalt" ? "" : " u"), tekst: b.status === "Betalt" ? "Betalt" : "Ubetalt" })),
        "/admin/oversikt"))
    : [el("p", { class: "tomt", tekst: "Ingenting er kjøpt i dag ennå." })];
  const kort = omsetningKort("Betalt i dag", oms.linjerIdag || [], eksOre, oms.idagOre,
    ubetalte ? el("div", {}, el("a", { class: "pille g", href: "/admin/uttak" }, ubetalte + " ubetalt · Ta betalt")) : null);
  kort.setAttribute("data-kort", "betalt");
  kort.append(el("div", { class: "g", style: "gap:6px" }, liste));
  return kort;
}

function hurtigvalg(valg, lagre) {
  const pl = el('div', { class: 'pl' },
    valg.filter(v => HURTIG[v]).map(v => el('a', { class: 'pille stor ' + (HURTIG[v].klasse || ''), href: HURTIG[v].maal }, HURTIG[v].navn)),
    el('button', { class: 'pille stor', type: 'button', onclick: () => tilpass(valg, lagre) }, '✎ Tilpass'));
  return el('section', { class: 'k' }, el('h2', { tekst: 'Hurtigvalg' }), pl);
}

function tilpass(valg, lagre) {
  const valgt = new Set(valg);
  const bokser = Object.entries(HURTIG).map(([k, v]) => {
    const boks = el('input', { type: 'checkbox', checked: valgt.has(k) ? true : null, 'data-valg': k });
    boks.addEventListener('change', () => { if (boks.checked) valgt.add(k); else valgt.delete(k); });
    return el('label', { class: 'sjekk' }, boks, el('span', { tekst: v.navn }));
  });
  const lagreK = el('button', { class: 'pille f', type: 'button', tekst: 'Lagre' });
  const { lukk } = ark([
    el('h2', { tekst: 'Tilpass hurtigvalg' }),
    el('p', { class: 'dempet', style: 'margin:0', tekst: 'Velg hva som skal stå øverst på I dag.' }),
    el('div', { class: 'g', style: 'gap:6px' }, bokser),
    el('div', { class: 'knapper' }, el('button', { class: 'pille', type: 'button', tekst: 'Avbryt', onclick: () => lukk(false) }), lagreK),
  ]);
  lagreK.onclick = async () => {
    const ny = Object.keys(HURTIG).filter(k => valgt.has(k));
    try { await lagre(ny); lukk(true); } catch (e) { melding(e.message); }
  };
}

function kursIdag(ov) {
  const dag = iDagOslo();
  const kurs = (ov.kommende || []).filter(k => String(k.startTid || '').slice(0, 10) === dag);
  const innhold = kurs.length
    ? kurs.map(k => el('div', { class: 'g', style: 'gap:8px' },
        rad(k.klokke + ' · ' + k.tittel, el('span', { class: 'dempet', tekst: (k.pameldte || 0) + ' påmeldt' }), '/admin/pameldte'),
        el('div', { class: 'pl' },
          el('a', { class: 'pille f stor', href: '/admin/oversikt' }, '▶ Start kurset'),
          el('a', { class: 'pille g stor', href: '/admin/uttak' }, 'Ta betalt'))))
    : [el('p', { class: 'tomt', tekst: 'Ingen kurs i dag.' })];
  return el('section', { class: 'k', 'data-kort': 'kurs' }, el('h2', { tekst: 'Kurs i dag' }), innhold);
}

function maaGjores(t) {
  const tall = (n) => el('span', { class: 'tall' + (n > 0 ? ' haster' : ''), tekst: String(n) });
  const rader = [
    ['Nye påmeldinger', t.pameldinger, '/admin/nye-pameldinger'],
    ['Venter på svar', t.svar, t.forespUbesvart > 0 || !t.innboks ? '/admin/ubesvarte' : '/admin/markedsforing'],
    ['Til godkjenning', t.godkjenning, '/admin/godkjenning'],
    ['Venteliste', t.venteliste, '/admin/venteliste'],
    ['Klar til henting', t.henting, '/admin/ferdigbrent'],
    ['Lav aktivitet', t.lav, '/admin/medlemmer/alle'],
    ['Bestill mer', t.bestill, '/admin/butikk'],
    ['Feil meldt inn', t.feil, '/admin/feilmeldinger'],
  ];
  return el('section', { class: 'k', 'data-kort': 'maagjores' },
    el('h2', { tekst: 'Må gjøres' }),
    el('div', { class: 'g', style: 'gap:6px' }, rader.map(([n, v, m]) => {
      const r = rad(n, tall(v), m);
      r.setAttribute('data-rad', n);
      return r;
    })));
}

function ovnen(status, oppdater) {
  const aktiv = status && status.slag;
  const knapper = OVN.map(([slag, navn]) => {
    const paa = aktiv === slag;
    const b = el('button', { type: 'button', class: paa ? 'paa' : '', 'data-ovn': slag },
      navn, el('span', { tekst: paa ? 'siden ' + klokke(status.naar) + (status.av ? ' · ' + status.av : '') : 'trykk for å sette' }));
    b.onclick = async () => {
      if (!(await bekreft(navn + '. Alle i verkstedet ser det.', navn))) return;
      medAngre(navn, async () => {
        const d = await send('/api/ovn.php', { handling: slag });
        melding(navn + '.');
        oppdater(d.tomt);
      });
    };
    return b;
  });
  return el('section', { class: 'k', 'data-kort': 'ovn' }, el('h2', { tekst: 'Ovnen' }), el('div', { class: 'ovn' }, knapper));
}

function inne(ov) {
  const liste = ov.verkstedet || [];
  const navn = liste.map(p => p.navn || p.fornavn || '').filter(Boolean);
  return el('section', { class: 'k', 'data-kort': 'inne' },
    el('h2', { tekst: 'I verkstedet nå' }),
    navn.length
      ? rad(navn.length + ' inne: ' + navn.join(' · '), null, null)
      : el('p', { class: 'tomt', tekst: 'Ingen er stemplet inn.' }));
}

export default {
  id: 'idag',
  navn: 'I dag',
  ikon: '⌂',
  adresse: 'i-dag',
  async tegn(main, { oppsett }) {
    main.replaceChildren(lasting());
    const [ov, fr, mk, vl, sk, fb, fe, ovn] = await Promise.all([
      hent('/api/admin/oversikt.php'), hent('/api/admin/foresporsler.php'), hent('/api/admin/marked.php'),
      hent('/api/admin/venteliste.php'), hent('/api/admin/soknader.php?status=venter'), hent('/api/admin/ferdigbrent.php'),
      hent('/api/admin/feilrapporter.php'), hent('/api/ovn.php'),
    ]);
    if (ov.status !== 200) {
      main.replaceChildren(feilboks('Fikk ikke hentet dagens tall.', () => this.tegn(main, { oppsett })));
      return;
    }
    const d = ov.d;
    const koer = d.koer || {};
    const forespUbesvart = (fr.d.foresporsler || []).filter(f => f.status === 'Ubesvart').length;
    const t = {
      pameldinger: (d.nyeste || []).length,
      forespUbesvart,
      innboks: Number(mk.d.innboksVenter || 0),
      svar: forespUbesvart + Number(mk.d.innboksVenter || 0),
      godkjenning: (koer.medlemsvarer || 0) + (koer.medlemsforslag || 0) + (koer.dugnad || 0) + ((sk.d.soknader || []).length),
      venteliste: (vl.d.venteliste || []).length,
      henting: (fb.d.okter || []).filter(o => !o.meldt).length,
      lav: Number((d.lavAktivitet || {}).antall || 0),
      bestill: (d.bestillMer || []).length,
      feil: (fe.d.rapporter || []).filter(r => r.status === 'ny').length,
    };

    let valg = oppsett.hurtigvalg || [];
    const hurtigBoks = el('div', {});
    const lagre = async (ny) => {
      const r = await send('/api/admin/admin2.php', { handling: 'hurtigvalg', valg: ny });
      valg = r.hurtigvalg; oppsett.hurtigvalg = valg;
      hurtigBoks.replaceChildren(hurtigvalg(valg, lagre));
      melding('Hurtigvalgene er lagret.');
    };
    hurtigBoks.append(hurtigvalg(valg, lagre));

    const ovnBoks = el('div', {});
    const tegnOvn = (s) => ovnBoks.replaceChildren(ovnen(s, tegnOvn));
    tegnOvn(ovn.d.tomt || null);

    const dato = new Date().toLocaleDateString('nb-NO', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'Europe/Oslo' });
    main.replaceChildren(
      el('div', { class: 'hode' }, el('div', {}, el('div', { class: 'eb', tekst: dato }), el('h1', { tekst: 'I dag' }))),
      // PC: hurtigvalgene oeverst over kortene. Mobil: summen foerst, og
      // hurtigvalgene nederst (se .idag-hurtig i admin2.html).
      el('div', { class: 'idag' },
        el('div', { class: 'idag-hurtig' }, hurtigBoks),
        el('div', { class: 'g to' },
          el('div', { class: 'g' }, betaltIdag(d), kursIdag(d)),
          el('div', { class: 'g' }, maaGjores(t), ovnBoks, inne(d)))));
  },
};
