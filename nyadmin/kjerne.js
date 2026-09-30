/**
 * Felles byggeklosser for det nye admin (/admin2): elementer, kall mot
 * serveren, «Er du sikker?», Angre og tilstandene lasting / tomt / feil.
 *
 * Eieren, 30. september 2026: «Er du sikker?» foer betaling og viktige
 * trykk. Handlinger som kan angres venter i tillegg noen sekunder med
 * «Angre» foer de sendes — ingenting er gjort foer tida er ute.
 */

/** Lager et element. Tekst legges alltid inn som tekst, aldri som HTML. */
export function el(tag, attr, ...barn) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attr || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') e.className = v;
    else if (k === 'tekst') e.textContent = v;
    else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v);
    else e.setAttribute(k, v === true ? '' : String(v));
  }
  for (const b of barn.flat(Infinity)) {
    if (b === null || b === undefined || b === false) continue;
    e.append(b.nodeType ? b : document.createTextNode(String(b)));
  }
  return e;
}

/** GET. Gir { status, d }, og kaster aldri. */
export async function hent(sti) {
  try {
    const r = await fetch(sti, { credentials: 'same-origin', cache: 'no-store' });
    let d = null;
    try { d = await r.json(); } catch (e) { /* ikke json */ }
    return { status: r.status, d: d || {} };
  } catch (e) {
    return { status: 0, d: {} };
  }
}

/** POST med JSON. Kaster med serverens melding naar det ikke gikk. */
export async function send(sti, kropp) {
  const r = await fetch(sti, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(kropp || {}),
  });
  let d = null;
  try { d = await r.json(); } catch (e) { /* ikke json */ }
  if (!r.ok || !d || d.ok === false) throw new Error((d && d.feil) || 'Det gikk ikke. Prøv igjen.');
  return d;
}

/** «kr 4 590» fra øre. */
export const kr = (ore) => 'kr ' + String(Math.round((Number(ore) || 0) / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

/** Lukker det øverste arket. Esc og trykk utenfor gjør det samme. */
function aapneArk(innhold, ved) {
  const bak = el('div', { class: 'bak', role: 'presentation' });
  const ark = el('div', { class: 'ark', role: 'dialog', 'aria-modal': 'true' }, innhold);
  bak.append(ark);
  const lukk = (svar) => {
    document.removeEventListener('keydown', tast);
    bak.remove();
    if (ved) ved(svar);
  };
  const tast = (ev) => { if (ev.key === 'Escape') lukk(false); };
  bak.addEventListener('click', (ev) => { if (ev.target === bak) lukk(false); });
  document.addEventListener('keydown', tast);
  document.body.append(bak);
  return { ark, lukk };
}

/**
 * «Er du sikker?» — gir true når brukeren sa ja.
 * @param {string} hva  én linje om hva som skjer, f.eks. «Ovn tømt»
 * @param {string} ja   teksten på ja-knappen
 */
export function bekreft(hva, ja = 'Ja') {
  return new Promise((svar) => {
    const nei = el('button', { class: 'pille', type: 'button', tekst: 'Avbryt' });
    const jaK = el('button', { class: 'pille f', type: 'button', tekst: ja, 'data-bekreft': 'ja' });
    const { lukk } = aapneArk([
      el('h2', { tekst: 'Er du sikker?' }),
      el('p', { class: 'dempet', style: 'margin:0', tekst: hva }),
      el('div', { class: 'knapper' }, nei, jaK),
    ], svar);
    nei.onclick = () => lukk(false);
    jaK.onclick = () => lukk(true);
    setTimeout(() => jaK.focus(), 0);
  });
}

/** Et ark med eget innhold. Gir { ark, lukk }. */
export const ark = aapneArk;

let aktivToast = null;
/**
 * Viser «Angre» i noen sekunder, og gjør handlingen først når tida er ute.
 * Trykker man Angre, skjer ingenting.
 */
export function medAngre(tekst, gjor, sek = 6) {
  if (aktivToast) aktivToast.fullfor();
  const angre = el('button', { type: 'button', tekst: 'Angre', 'data-angre': 'ja' });
  const t = el('div', { class: 'toast', role: 'status' }, el('span', { tekst: tekst }), angre);
  document.body.append(t);
  let ferdig = false;
  const tid = setTimeout(() => fullfor(), sek * 1000);
  function fullfor() {
    if (ferdig) return;
    ferdig = true; clearTimeout(tid); t.remove(); aktivToast = null;
    Promise.resolve().then(gjor).catch((e) => melding(e.message || 'Det gikk ikke.'));
  }
  angre.onclick = () => { ferdig = true; clearTimeout(tid); t.remove(); aktivToast = null; melding('Angret. Ingenting er endret.'); };
  aktivToast = { fullfor };
}

/** En kort beskjed nederst som forsvinner av seg selv. */
export function melding(tekst) {
  const t = el('div', { class: 'toast', role: 'status' }, el('span', { tekst: tekst }));
  document.body.append(t);
  setTimeout(() => t.remove(), 3500);
}

export const lasting = () => el('p', { class: 'lasting', tekst: 'Laster …' });

export function feilboks(tekst, igjen) {
  return el('div', { class: 'feilboks', role: 'alert' },
    el('b', { tekst: tekst || 'Fikk ikke hentet dataene.' }),
    igjen ? el('div', {}, el('button', { class: 'pille', type: 'button', tekst: 'Prøv igjen', onclick: igjen })) : null);
}

/** En rad der hele raden trykkes. Uten mål er den bare tekst. */
export function rad(tekst, hoyre, maal) {
  const inn = [el('span', { tekst: tekst }), hoyre];
  if (typeof maal === 'string') return el('a', { class: 'rad', href: maal }, inn);
  if (typeof maal === 'function') return el('button', { class: 'rad', type: 'button', onclick: maal }, inn);
  return el('div', { class: 'rad tom' }, inn);
}
