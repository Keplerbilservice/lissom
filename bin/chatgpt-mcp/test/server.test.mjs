import test from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPair, SignJWT, createLocalJWKSet, exportJWK } from 'jose';
import { createHmac } from 'node:crypto';
import http from 'node:http';
import { createApp } from '../src/server.mjs';
import { configuration, scope } from '../src/config.mjs';

const { privateKey, publicKey } = await generateKeyPair('RS256');
const jwk = await exportJWK(publicKey); jwk.kid = 'fixture';
const config = { resource: 'https://127.0.0.1/mcp', issuer: 'https://issuer.example.test',
  jwks: 'https://issuer.example.test/jwks', bridgeUrl: 'https://backend.example.test/api/integrasjon/chatgpt-kurser.php',
  bridgeSecret: 'synthetic-test-key-never-use-in-production', subjects: { owner: 'owner' } };
const calls = [];
let fail = false;
const app = createApp(config, { keySet: createLocalJWKSet({ keys: [jwk] }), fetchImpl: async (url, request) => {
  calls.push({ url, ...request });
  if (fail) return new Response('private upstream diagnostic', { status: 500 });
  const args = JSON.parse(request.body);
  return Response.json({ courses: [{ id: args.id ?? 1, tittel: 'Syntetisk kurs', slug: 'syntetisk', status: 'aktiv',
    bilde: null, epost: 'must-not-leak@example.test', notat: 'PRIVATE' }] });
} });
const server = app.listen(0, '127.0.0.1');
await new Promise(resolve => server.once('listening', resolve));
const url = `http://127.0.0.1:${server.address().port}`;
const headers = { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' };
const token = (claims = {}, key = privateKey) => new SignJWT({ scope, ...claims })
  .setProtectedHeader({ alg: 'RS256', kid: 'fixture' }).setSubject(claims.sub ?? 'owner')
  .setIssuer(claims.iss ?? config.issuer).setAudience(claims.aud ?? config.resource)
  .setIssuedAt(claims.iat ?? Math.floor(Date.now()/1000)).setExpirationTime(claims.exp ?? Math.floor(Date.now()/1000)+300).sign(key);
const rpc = (method, params = {}, bearer, extra = {}) => fetch(`${url}/mcp`, {
  method: 'POST', headers: { ...headers, ...(bearer ? { Authorization: `Bearer ${bearer}` } : {}), ...extra },
  body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }),
});
const params = { name: 'lissom_get_course', arguments: { id: 1 } };

test.after(() => new Promise(resolve => server.close(resolve)));
test('configuration fails closed and preserves exact issuer identifier', () => {
  assert.throws(() => configuration({}));
  const env = { LISSOM_MCP_RESOURCE: config.resource, LISSOM_MCP_ISSUER: config.issuer,
    LISSOM_MCP_JWKS: config.jwks, LISSOM_MCP_BRIDGE_URL: config.bridgeUrl,
    LISSOM_MCP_BRIDGE_SECRET: config.bridgeSecret, LISSOM_MCP_SUBJECTS: JSON.stringify(config.subjects) };
  assert.equal(configuration(env).issuer, config.issuer);
  assert.throws(() => configuration({ ...env, LISSOM_MCP_BRIDGE_URL: 'http://backend.example.test' }));
  assert.throws(() => configuration({ ...env, LISSOM_MCP_SUBJECTS: '{}' }));
});
test('public discovery exposes only two read-only tools', async () => {
  const response = await rpc('tools/list'); assert.equal(response.status, 200);
  const data = await response.json();
  assert.deepEqual(data.result.tools.map(t => t.name), ['lissom_list_courses', 'lissom_get_course']);
  for (const tool of data.result.tools) {
    assert.equal(tool.annotations.readOnlyHint, true);
    assert.deepEqual(tool._meta.securitySchemes, [{ type: 'oauth2', scopes: [scope] }]);
  }
  assert.equal(calls.length, 0);
});
test('missing authentication advertises OAuth metadata', async () => {
  const response = await rpc('tools/call', params); assert.equal(response.status, 401);
  assert.match(response.headers.get('www-authenticate'), /resource_metadata=/);
  const metadata = await fetch(`${url}/.well-known/oauth-protected-resource`);
  assert.deepEqual((await metadata.json()).authorization_servers, [config.issuer]);
});
test('invalid issuer, audience, expiry, future issuance and long lifetime denied', async () => {
  const now = Math.floor(Date.now()/1000);
  for (const claims of [{ iss: 'https://other.test' }, { aud: 'other' }, { exp: now-1 }, { iat: now+60 }, { nbf: now+60 }, { exp: now+1800 }]) {
    const response = await rpc('tools/call', params, await token(claims)); assert.equal(response.status, 401);
  }
  assert.equal((await rpc('tools/call', params, 'unsigned.token.value')).status, 401);
  const other = await generateKeyPair('RS256');
  assert.equal((await rpc('tools/call', params, await token({},other.privateKey))).status,401);
  assert.equal(calls.length,0);
});
test('scope and explicit subject mapping required', async () => {
  for (const claims of [{ scope: 'other' }, { sub: 'stranger' }, { sub: '__proto__' }]) {
    assert.equal((await rpc('tools/call', params, await token(claims))).status,403);
  }
  assert.equal(calls.length,0);
});
test('authorized call signs internal request and strips sensitive fields', async () => {
  const bearer = await token();
  const response = await rpc('tools/call',params,bearer,{ Cookie: 'browser-session=never-forward' });
  const data = await response.json(); assert.equal(response.status,200);
  assert.equal(data.result.structuredContent.courses[0].id,1);
  assert.doesNotMatch(JSON.stringify(data),/PRIVATE|epost|must-not-leak/);
  const request = calls.at(-1);
  assert.equal(request.url,config.bridgeUrl); assert.equal(request.redirect,'error');
  assert.equal(JSON.parse(request.body).actor,'owner');
  assert.equal(request.headers['X-Lissom-Signature'], createHmac('sha256', config.bridgeSecret)
    .update(`lissom-courses-read-v1\n${request.headers['X-Lissom-Timestamp']}\n${request.headers['X-Lissom-Nonce']}\n${request.body}`).digest('hex'));
  assert.doesNotMatch(JSON.stringify(request.headers),/Bearer|Cookie|browser-session/i);
});
test('invalid input and unavailable upstream fail without leaking diagnostics', async () => {
  const bearer = await token(); const count = calls.length;
  const bad = await rpc('tools/call',{ ...params,arguments:{id:0} },bearer);
  assert.equal((await bad.json()).result.isError,true); assert.equal(calls.length,count);
  const unknown = await rpc('tools/call',{name:'execute_sql',arguments:{}},bearer);
  assert.equal((await unknown.json()).result.isError,true); assert.equal(calls.length,count);
  fail = true;
  const response = await rpc('tools/call',params,bearer); const data = await response.json();
  assert.equal(data.result.isError,true); assert.doesNotMatch(JSON.stringify(data),/private upstream diagnostic/);
  fail = false;
});
test('foreign Origin/Host, batch and oversized requests denied', async () => {
  assert.equal((await rpc('tools/list',{},undefined,{ Origin:'https://other.test' })).status,403);
  const badHost = await new Promise((resolve,reject) => {
    const request = http.request(`${url}/mcp`, { method:'POST',headers:{...headers,Host:'evil.test'} }, response => {
      response.resume(); response.on('end', () => resolve(response.statusCode));
    }); request.on('error',reject); request.end('{}');
  });
  assert.equal(badHost,403);
  const batch = await fetch(`${url}/mcp`,{method:'POST',headers,body:JSON.stringify([{jsonrpc:'2.0',id:1,method:'tools/call',params}])});
  assert.equal(batch.status,400);
  const large = await fetch(`${url}/mcp`,{method:'POST',headers,body:JSON.stringify({data:'x'.repeat(9000)})});
  assert.equal(large.status,413);
});
