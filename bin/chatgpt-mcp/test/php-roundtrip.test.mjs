import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {generateKeyPairSync,createHash} from 'node:crypto';
import {mkdtemp,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {fileURLToPath} from 'node:url';
import {Client} from '@modelcontextprotocol/sdk/client/index.js';
import {StreamableHTTPClientTransport} from '@modelcontextprotocol/sdk/client/streamableHttp.js';

test('PHP-only HTTP OAuth + consent + SQL sessions + real MCP client',async t => {
  const directory=await mkdtemp(path.join(tmpdir(),'lissom-mcp-php-'));
  const keyFile=path.join(directory,'fixture.key');
  const {privateKey}=generateKeyPairSync('rsa',{modulusLength:2048,privateKeyEncoding:{type:'pkcs8',format:'pem'},publicKeyEncoding:{type:'spki',format:'pem'}});
  await writeFile(keyFile,privateKey);
  const probe=net.createServer();probe.listen(0,'127.0.0.1');await new Promise(r=>probe.once('listening',r));
  const port=probe.address().port;await new Promise(r=>probe.close(r));
  const router=fileURLToPath(new URL('../../../tests/fixtures/chatgpt-php-router.php',import.meta.url));
  const php=spawn('php',['-S',`127.0.0.1:${port}`,router],{env:{...process.env,MCP_FIXTURE_DB:path.join(directory,'fixture.sqlite'),MCP_FIXTURE_KEY:keyFile},stdio:['ignore','ignore','pipe']});
  let client;
  t.after(async()=>{
    if(client)await client.close();
    if(php.exitCode===null){php.kill();await new Promise(resolve=>php.once('exit',resolve));}
    const absolute=path.resolve(directory);
    assert.equal(path.dirname(absolute),path.resolve(tmpdir()));
    assert.ok(path.basename(absolute).startsWith('lissom-mcp-php-'));
    await rm(absolute,{recursive:true,force:true});
  });
  await new Promise((resolve,reject)=>{
    const timer=setTimeout(()=>reject(new Error('PHP fixture startup timeout')),5000);
    php.once('error',e=>{clearTimeout(timer);reject(e);});
    php.stderr.on('data',chunk=>{if(String(chunk).includes('started')){clearTimeout(timer);resolve();}});
    php.once('exit',code=>{clearTimeout(timer);reject(new Error(`PHP exited ${code}`));});
  });
  const root=`http://127.0.0.1:${port}`,issuer='https://fixture.example',resource=issuer+'/integrasjon/mcp';
  const authMetadata=await (await fetch(root+'/.well-known/oauth-authorization-server')).json();
  assert.deepEqual(authMetadata.code_challenge_methods_supported,['S256']);
  const reg=await fetch(root+'/integrasjon/oauth/register',{method:'POST',body:JSON.stringify({redirect_uris:['https://chatgpt.com/connector/oauth/fixture'],token_endpoint_auth_method:'none',grant_types:['authorization_code','refresh_token']})});
  assert.equal(reg.status,201);const registration=await reg.json();
  const verifier='v'.repeat(64),challenge=createHash('sha256').update(verifier).digest('base64url');
  const query=new URLSearchParams({client_id:registration.client_id,redirect_uri:registration.redirect_uris[0],
    response_type:'code',scope:'lissom:courses:read',resource,state:'synthetic-client-state-123456',code_challenge:challenge,code_challenge_method:'S256'});
  const consent=await fetch(root+'/integrasjon/oauth/authorize?'+query,{headers:{'X-Fixture-Admin':'yes'}});
  assert.equal(consent.status,200);const html=await consent.text();
  const pending=/name="pending" value="([a-f0-9]+)"/.exec(html)[1],csrf=/name="csrf" value="([a-f0-9]+)"/.exec(html)[1];
  const approved=await fetch(root+'/integrasjon/oauth/authorize',{method:'POST',redirect:'manual',headers:{
    'X-Fixture-Admin':'yes',Origin:issuer,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({pending,csrf,approve:'yes'})});
  assert.equal(approved.status,302);const callback=new URL(approved.headers.get('location'));
  assert.equal(callback.searchParams.get('iss'),issuer);
  const exchange=new URLSearchParams({grant_type:'authorization_code',client_id:registration.client_id,redirect_uri:registration.redirect_uris[0],
    code:callback.searchParams.get('code'),code_verifier:verifier,resource});
  const tokenResponse=await fetch(root+'/integrasjon/oauth/token',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:exchange});
  assert.equal(tokenResponse.status,200);const tokens=await tokenResponse.json();
  assert.equal(typeof tokens.refresh_token,'string');
  const headers={'Content-Type':'application/json',Accept:'application/json, text/event-stream'};
  const noToken=await fetch(root+'/integrasjon/mcp',{method:'POST',headers,body:JSON.stringify({jsonrpc:'2.0',id:1,method:'tools/call',params:{name:'lissom_get_course',arguments:{id:7}}})});
  assert.equal(noToken.status,401);assert.match(noToken.headers.get('www-authenticate'),/resource_metadata/);
  client=new Client({name:'php-integration-fixture',version:'1.0.0'});
  await client.connect(new StreamableHTTPClientTransport(new URL(root+'/integrasjon/mcp'),{requestInit:{headers:{Authorization:`Bearer ${tokens.access_token}`}}}));
  const tools=await client.listTools();assert.equal(tools.tools.length,2);
  for(const tool of tools.tools){assert.equal(tool.annotations.readOnlyHint,true);assert.deepEqual(tool._meta.securitySchemes[0].scopes,['lissom:courses:read']);}
  const result=await client.callTool({name:'lissom_get_course',arguments:{id:7}});
  assert.equal(result.isError,false);assert.equal(result.structuredContent.courses[0].tittel,'Syntetisk HTTP-kurs');
  const list=await client.callTool({name:'lissom_list_courses',arguments:{}});assert.equal(list.structuredContent.courses.length,1);
  const missing=await client.callTool({name:'lissom_get_course',arguments:{id:888}});assert.deepEqual(missing.structuredContent.courses,[]);
  const replay=await fetch(root+'/integrasjon/oauth/token',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:exchange});assert.equal(replay.status,400);
});
