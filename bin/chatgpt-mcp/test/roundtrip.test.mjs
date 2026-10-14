import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import net from 'node:net';
import { fileURLToPath } from 'node:url';
import { generateKeyPair, SignJWT, exportJWK, createLocalJWKSet } from 'jose';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { createApp } from '../src/server.mjs';
import { scope } from '../src/config.mjs';

test('real MCP client -> OAuth verifier -> signed HTTP -> PHP reader -> SQLite', async (t) => {
  const probe = net.createServer(); probe.listen(0,'127.0.0.1');
  await new Promise(resolve => probe.once('listening',resolve)); const port = probe.address().port;
  await new Promise(resolve => probe.close(resolve));
  const router = fileURLToPath(new URL('../../../tests/fixtures/chatgpt-kurser-router.php',import.meta.url));
  const php = spawn('php',['-S',`127.0.0.1:${port}`,router],{stdio:['ignore','ignore','pipe']});
  t.after(() => php.kill());
  await new Promise((resolve,reject) => {
    const timeout = setTimeout(() => reject(new Error('PHP fixture startup timed out')),5000);
    php.once('error',error => { clearTimeout(timeout); reject(error); });
    php.stderr.on('data',chunk => { if (String(chunk).includes('started')) { clearTimeout(timeout); resolve(); } });
    php.once('exit',code => { clearTimeout(timeout); reject(new Error(`PHP exited ${code}`)); });
  });
  const {publicKey,privateKey} = await generateKeyPair('RS256');
  const jwk = await exportJWK(publicKey); jwk.kid = 'fixture';
  const config = {resource:'https://127.0.0.1/mcp',issuer:'https://issuer.example.test',jwks:'https://issuer.example.test/jwks',
    subjects:{owner:'owner'},bridgeUrl:`http://127.0.0.1:${port}/fixture`,bridgeSecret:'synthetic-test-key-never-use-in-production'};
  const server = createApp(config,{keySet:createLocalJWKSet({keys:[jwk]})}).listen(0,'127.0.0.1');
  await new Promise(resolve => server.once('listening',resolve));
  t.after(() => new Promise(resolve => server.close(resolve)));
  const token = await new SignJWT({scope}).setProtectedHeader({alg:'RS256',kid:'fixture'}).setSubject('owner')
    .setIssuer(config.issuer).setAudience(config.resource).setIssuedAt().setExpirationTime('5m').sign(privateKey);
  const client = new Client({name:'isolated-integration-test',version:'1.0.0'});
  const transport = new StreamableHTTPClientTransport(new URL(`http://127.0.0.1:${server.address().port}/mcp`),
    {requestInit:{headers:{Authorization:`Bearer ${token}`}}});
  t.after(() => client.close());
  await client.connect(transport);
  assert.equal((await client.listTools()).tools.length,2);
  const result = await client.callTool({name:'lissom_get_course',arguments:{id:7}});
  assert.equal(result.isError,undefined);
  assert.equal(result.structuredContent.courses[0].tittel,'Syntetisk HTTP-kurs');
  const list = await client.callTool({name:'lissom_list_courses',arguments:{}});
  assert.equal(list.structuredContent.courses.length,1);
  const missing = await client.callTool({name:'lissom_get_course',arguments:{id:123}});
  assert.deepEqual(missing.structuredContent.courses,[]);
});
