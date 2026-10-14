import test from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPair, exportJWK, createLocalJWKSet, SignJWT } from 'jose';
import { createResourceServer } from '../src/resource-server.mjs';

test('shared core keeps separate system audience, scope and tool catalog', async (t) => {
  const { privateKey,publicKey } = await generateKeyPair('RS256');
  const jwk = await exportJWK(publicKey); jwk.kid='fixture';
  let reads=0;
  const config = { name:'kepler-synthetic-fixture-not-an-implemented-adapter',resource:'https://127.0.0.1:444/mcp',
    issuer:'https://issuer.example.test',jwks:'https://issuer.example.test/jwks',scope:'kepler:jobs:read',subjects:{owner:'kepler-owner'} };
  const app = createResourceServer(config,{keySet:createLocalJWKSet({keys:[jwk]}),tools:[{
    name:'kepler_fixture_read',title:'Synthetic fixture',description:'Test only',inputSchema:{},
    read: async actor => { reads++; return {actor,synthetic:true}; },
  }]});
  const server = app.listen(0,'127.0.0.1'); await new Promise(resolve => server.once('listening',resolve));
  t.after(() => new Promise(resolve => server.close(resolve)));
  const request = async (audience,scope) => {
    const token = await new SignJWT({scope}).setProtectedHeader({alg:'RS256',kid:'fixture'}).setSubject('owner')
      .setIssuer(config.issuer).setAudience(audience).setIssuedAt().setExpirationTime('5m').sign(privateKey);
    return fetch(`http://127.0.0.1:${server.address().port}/mcp`,{method:'POST',headers:{
      Authorization:`Bearer ${token}`,'Content-Type':'application/json',Accept:'application/json, text/event-stream'},
      body:JSON.stringify({jsonrpc:'2.0',id:1,method:'tools/call',params:{name:'kepler_fixture_read',arguments:{}}})});
  };
  assert.equal((await request('https://127.0.0.1/mcp','lissom:courses:read')).status,401);
  assert.equal((await request(config.resource,'lissom:courses:read')).status,403);
  assert.equal(reads,0);
  const response=await request(config.resource,config.scope);
  assert.equal(response.status,200); assert.equal(reads,1);
  assert.equal((await response.json()).result.structuredContent.actor,'kepler-owner');
});
