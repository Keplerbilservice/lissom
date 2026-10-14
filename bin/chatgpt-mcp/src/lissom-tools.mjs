import { createHmac, randomBytes, randomUUID } from 'node:crypto';
import { z } from 'zod';

const course = z.object({ id: z.number().int().positive(), tittel: z.string().max(4096),
  slug: z.string().max(4096), status: z.string().max(64), bilde: z.string().max(4096).nullable() }).strip();
const payload = z.object({ courses: z.array(course).max(50) }).strip();

export function lissomTools(config, fetchImpl = fetch) {
  const read = async (actor, operation, args) => {
    const raw = JSON.stringify({ actor, requestId: randomUUID(), operation, ...args });
    const timestamp = String(Math.floor(Date.now() / 1000));
    const nonce = randomBytes(16).toString('hex');
    const signature = createHmac('sha256', config.bridgeSecret)
      .update(`lissom-courses-read-v1\n${timestamp}\n${nonce}\n${raw}`).digest('hex');
    const response = await fetchImpl(config.bridgeUrl, {
      method: 'POST', redirect: 'error', signal: AbortSignal.timeout(5000),
      headers: { 'Content-Type': 'application/json', 'X-Lissom-Timestamp': timestamp,
        'X-Lissom-Nonce': nonce, 'X-Lissom-Signature': signature }, body: raw,
    });
    if (!response.ok) throw new Error('Bridge unavailable');
    const reader = response.body.getReader();
    let size = 0; const chunks = [];
    try {
      while (true) {
        const { done, value } = await reader.read(); if (done) break;
        size += value.length;
        if (size > 262144) { await reader.cancel(); throw new Error('Bridge response too large'); }
        chunks.push(Buffer.from(value));
      }
    } finally { reader.releaseLock(); }
    const parsed = payload.parse(JSON.parse(Buffer.concat(chunks).toString('utf8')));
    if (operation === 'get' && (parsed.courses.length > 1 || parsed.courses.some(c => c.id !== args.id))) {
      throw new Error('Unexpected course');
    }
    if (operation === 'list' && (parsed.courses.length > args.limit
        || parsed.courses.some((c, i) => c.id <= (i ? parsed.courses[i-1].id : args.afterId)))) {
      throw new Error('Unexpected page');
    }
    return parsed;
  };
  return [
    { name: 'lissom_list_courses', title: 'List Lissom-kurs', description: 'List kurs med ID, tittel, slug, status og bilde. Endrer ingen kurs.',
      inputSchema: { afterId: z.number().int().min(0).max(Number.MAX_SAFE_INTEGER).default(0),
        limit: z.number().int().min(1).max(50).default(20) }, read: (actor,args) => read(actor,'list',args) },
    { name: 'lissom_get_course', title: 'Hent ett Lissom-kurs', description: 'Les ett kurs med ID, tittel, slug, status og bilde. Endrer ingen kurs.',
      inputSchema: { id: z.number().int().positive().max(Number.MAX_SAFE_INTEGER) }, read: (actor,args) => read(actor,'get',args) },
  ];
}
