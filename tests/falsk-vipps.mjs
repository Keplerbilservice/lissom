/**
 * En falsk Vipps, til ende-til-ende-testen.
 *
 * Testmiljoet har ingen ekte noekler, saa hele pengekjeden stopper paa det
 * foerste kallet. Denne svarer som Vipps ville gjort, og skriver ned NOYAKTIG
 * hva vi ba om — beloep, intervall, forfall, adresser. Da kan tests/pengekjede.php
 * lese hva kunden faktisk ville sett, uten aa flytte en krone.
 *
 * Start:   node tests/falsk-vipps.mjs
 * Loggen:  tests/.falsk-vipps.jsonl   (en linje per kall)
 *
 * Tilstanden styres utenfra, saa testen kan si «naa godkjente hun i appen»:
 *   tests/.avtale-status    ACTIVE | PENDING | STOPPED | EXPIRED
 *   tests/.trekk-status     CHARGED | FAILED | PENDING
 *   tests/.betaling-status  AUTHORIZED | CAPTURED | ABORTED | EXPIRED
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const HER   = path.dirname(new URL(import.meta.url).pathname);
const LOGG  = path.join(HER, '.falsk-vipps.jsonl');
const PORT  = Number(process.env.FALSK_VIPPS_PORT || 8125);

const avtaler = new Map();
const betalinger = new Map();
const trekk = new Map();

/** Leser en styrefil, eller gir standarden. */
const styrt = (navn, standard) => {
  try {
    const v = fs.readFileSync(path.join(HER, navn), 'utf8').trim();
    return v || standard;
  } catch { return standard; }
};

const svar = (res, kode, kropp) => {
  const s = JSON.stringify(kropp);
  res.writeHead(kode, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(s) });
  res.end(s);
};

http.createServer((req, res) => {
  let raa = '';
  req.on('data', c => { raa += c; });
  req.on('end', () => {
    const u = new URL(req.url, 'http://x');
    let kropp = null;
    try { kropp = raa ? JSON.parse(raa) : null; } catch { kropp = raa; }
    fs.appendFileSync(LOGG, JSON.stringify({
      tid: new Date().toISOString(), metode: req.method, sti: u.pathname, kropp,
    }) + '\n');

    const p = u.pathname;

    if (p === '/accesstoken/get') {
      return svar(res, 200, {
        access_token: 'falskt-token',
        expires_on: String(Math.floor(Date.now() / 1000) + 3600),
      });
    }

    // ── ePayment: kurs, varer, gavekort, engangs medlemskap ─────────────
    if (p === '/epayment/v1/payments' && req.method === 'POST') {
      const ref = kropp?.reference || 'ukjent';
      betalinger.set(ref, kropp);
      return svar(res, 201, { reference: ref, redirectUrl: 'https://falsk.vipps/betal/' + ref });
    }
    if (p.startsWith('/epayment/v1/payments/') && p.endsWith('/cancel')) {
      return svar(res, 200, { state: 'TERMINATED' });
    }
    if (p.startsWith('/epayment/v1/payments/') && p.endsWith('/capture')) {
      return svar(res, 200, { state: 'CAPTURED' });
    }
    if (p.startsWith('/epayment/v1/payments/') && p.endsWith('/refund')) {
      return svar(res, 200, { state: 'REFUNDED' });
    }
    if (p.startsWith('/epayment/v1/payments/') && req.method === 'GET') {
      const ref = p.split('/')[4];
      const b = betalinger.get(ref);
      const belop = b?.amount?.value ?? 0;
      return svar(res, 200, {
        reference: ref,
        state: styrt('.betaling-status', 'AUTHORIZED'),
        aggregate: { authorizedAmount: { value: belop, currency: 'NOK' } },
        amount: b?.amount ?? { value: belop, currency: 'NOK' },
      });
    }

    // ── Recurring: avtalen og maanedstrekkene ───────────────────────────
    if (p === '/recurring/v3/agreements' && req.method === 'POST') {
      const id = 'agr_' + Date.now().toString(36) + '_' + (avtaler.size + 1);
      avtaler.set(id, { status: 'PENDING', kropp });
      return svar(res, 201, { agreementId: id, vippsConfirmationUrl: 'https://falsk.vipps/godkjenn/' + id });
    }
    if (p === '/recurring/v3/agreements' && req.method === 'GET') {
      return svar(res, 200, []);
    }
    if (p.includes('/charges/') && req.method === 'GET') {
      const id = p.split('/')[4];
      const tid = p.split('/')[6];
      return svar(res, 200, {
        id: tid, amount: trekk.get(tid)?.amount ?? 0,
        status: styrt('.trekk-status', 'CHARGED'),
        agreementId: id,
      });
    }
    if (p.endsWith('/charges') && req.method === 'POST') {
      const tid = 'chg_' + Date.now().toString(36) + '_' + (trekk.size + 1);
      trekk.set(tid, kropp);
      // «201 uten chargeId» er tilfellet der trekket trolig finnes hos Vipps,
      // men vi ikke har noe aa foelge det opp med. Styres med .trekk-uten-id.
      if (styrt('.trekk-uten-id', '') === 'ja') { return svar(res, 201, {}); }
      return svar(res, 201, { chargeId: tid });
    }
    if (p.startsWith('/recurring/v3/agreements/') && req.method === 'GET') {
      const id = p.split('/')[4];
      const a = avtaler.get(id);
      return svar(res, 200, Object.assign(
        { id, status: styrt('.avtale-status', a?.status || 'PENDING') },
        a?.kropp || {}
      ));
    }
    if (p.startsWith('/recurring/v3/agreements/')
        && (req.method === 'PATCH' || req.method === 'PUT')) {
      const id = p.split('/')[4];
      if (avtaler.has(id)) { avtaler.get(id).status = kropp?.status || 'STOPPED'; }
      return svar(res, 200, {});
    }

    // ── Webhooks ────────────────────────────────────────────────────────
    if (p.startsWith('/webhooks/v1/webhooks')) {
      if (req.method === 'GET')  return svar(res, 200, { webhooks: [] });
      if (req.method === 'POST') return svar(res, 201, { id: 'wh_1', secret: 'falsk-hemmelighet' });
      return svar(res, 204, {});
    }

    svar(res, 404, { feil: 'Falsk Vipps kjenner ikke ' + p });
  });
}).listen(PORT, '127.0.0.1', () => console.log('falsk Vipps på ' + PORT));
