import express from 'express';
import { createRemoteJWKSet, jwtVerify } from 'jose';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';

// Shared OAuth/MCP resource server; each system has an isolated scope and catalog.
export function createResourceServer(config, { keySet = createRemoteJWKSet(new URL(config.jwks)), tools } = {}) {
  const scope = config.scope;
  if (typeof scope !== 'string' || !/^[a-zA-Z0-9:_-]+$/.test(scope) || !Array.isArray(tools) || !tools.length) {
    throw new Error('Explicit scope and read-only tool catalog required');
  }
  const app = express();
  app.disable('x-powered-by');
  const publicUrl = new URL(config.resource);
  const metadata = `${publicUrl.origin}/.well-known/oauth-protected-resource`;
  const challenge = (error) => `Bearer resource_metadata="${metadata}", scope="${scope}"${error ? `, error="${error}"` : ''}`;
  app.use((req, res, next) => {
    res.set('Cache-Control', 'no-store');
    // No wildcard CORS and no trust in forwarded Host headers.
    if (req.hostname !== publicUrl.hostname || (req.get('Origin') && req.get('Origin') !== publicUrl.origin)) {
      return res.status(403).json({ error: 'Forbidden origin or host' });
    }
    next();
  });
  app.get('/.well-known/oauth-protected-resource', (_req, res) => res.json({
    resource: config.resource, authorization_servers: [config.issuer], scopes_supported: [scope],
    bearer_methods_supported: ['header'],
  }));
  // Limit unauthenticated discovery too. Single-process limit; edge limits are also required at deployment.
  const windows = new Map();
  app.use('/mcp', (req, res, next) => {
    const minute = Math.floor(Date.now() / 60000);
    const entry = windows.get(req.socket.remoteAddress) ?? { minute, count: 0 };
    if (entry.minute !== minute) { entry.minute = minute; entry.count = 0; }
    entry.count++;
    windows.set(req.socket.remoteAddress, entry);
    for (const [ip, value] of windows) if (value.minute < minute) windows.delete(ip);
    if (entry.count > 120) return res.status(429).set('Retry-After', '60').json({ error: 'Rate limit' });
    next();
  });
  app.use(express.json({ limit: '8kb' }));

  app.post('/mcp', async (req, res) => {
    if (!req.body || Array.isArray(req.body) || typeof req.body !== 'object') {
      return res.status(400).json({ error: 'One JSON-RPC request required' });
    }
    let actor;
    const isCall = req.body?.method === 'tools/call';
    if (isCall) {
      const token = /^Bearer ([^\s]+)$/.exec(req.get('Authorization') ?? '')?.[1];
      if (!token) return res.status(401).set('WWW-Authenticate', challenge()).json({ error: 'Authorization required' });
      let claims;
      try {
        ({ payload: claims } = await jwtVerify(token, keySet, {
          issuer: config.issuer, audience: config.resource, algorithms: ['RS256', 'ES256'],
          requiredClaims: ['sub', 'iat', 'exp'], maxTokenAge: '15m', clockTolerance: 0,
        }));
        if (typeof claims.sub !== 'string' || !claims.sub || claims.exp - claims.iat > 900
            || claims.iat > Math.floor(Date.now() / 1000)) throw new Error('Invalid lifetime');
      } catch {
        return res.status(401).set('WWW-Authenticate', challenge('invalid_token')).json({ error: 'Invalid token' });
      }
      actor = Object.hasOwn(config.subjects, claims.sub) ? config.subjects[claims.sub] : undefined;
      if (!actor || typeof claims.scope !== 'string' || !claims.scope.split(' ').includes(scope)) {
        return res.status(403).set('WWW-Authenticate', challenge('insufficient_scope')).json({ error: 'Forbidden' });
      }
    }
    const server = new McpServer({ name: config.name, version: '0.1.0' });
    const annotations = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false };
    const securitySchemes = [{ type: 'oauth2', scopes: [scope] }];
    for (const tool of tools) server.registerTool(tool.name, {
      title: tool.title, description: tool.description, inputSchema: tool.inputSchema, annotations,
      _meta: { securitySchemes },
    }, async (args) => {
      if (!actor) return { isError: true, content: [{ type: 'text', text: 'Innlogging kreves.' }],
        _meta: { 'mcp/www_authenticate': [challenge()] } };
      try {
        const data = await tool.read(actor, args);
        return { content: [{ type: 'text', text: JSON.stringify(data) }], structuredContent: data };
      } catch {
        return { isError: true, content: [{ type: 'text', text: 'Opplysningene kunne ikke leses. Prøv igjen senere.' }] };
      }
    });
    const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined, enableJsonResponse: true });
    res.on('close', () => { void transport.close(); void server.close(); });
    try {
      await server.connect(transport);
      await transport.handleRequest(req, res, req.body);
    } catch {
      if (!res.headersSent) res.status(500).json({ error: 'MCP request failed' });
      await transport.close(); await server.close();
    }
  });
  app.all('/mcp', (_req, res) => res.status(405).set('Allow', 'POST').json({ error: 'Method not allowed' }));
  app.use((err, _req, res, _next) => res.status(err.status === 413 ? 413 : 400).json({ error: 'Invalid request' }));
  return app;
}
