export const scope = 'lissom:courses:read';

export function configuration(env = process.env) {
  const url = (name) => {
    const value = new URL(env[name] ?? '');
    if (value.protocol !== 'https:' || value.username || value.password || value.hash || value.search) {
      throw new Error(`${name} must be a HTTPS URL without credentials, query or fragment`);
    }
    return env[name]; // Preserve the issuer/resource identifier exactly, including a trailing slash.
  };
  const resource = url('LISSOM_MCP_RESOURCE');
  const issuer = url('LISSOM_MCP_ISSUER');
  const jwks = url('LISSOM_MCP_JWKS');
  const bridgeUrl = url('LISSOM_MCP_BRIDGE_URL');
  if (new URL(resource).pathname !== '/mcp') throw new Error('Resource must end in /mcp');
  const subjects = JSON.parse(env.LISSOM_MCP_SUBJECTS ?? '{}');
  if (!subjects || Array.isArray(subjects) || typeof subjects !== 'object' || !Object.keys(subjects).length
      || Object.entries(subjects).some(([sub, actor]) => !sub || typeof actor !== 'string' || !/^[a-zA-Z0-9_-]{1,64}$/.test(actor))) {
    throw new Error('Explicit OAuth subject-to-actor mapping required');
  }
  const bridgeSecret = env.LISSOM_MCP_BRIDGE_SECRET ?? '';
  if (Buffer.byteLength(bridgeSecret) < 32) throw new Error('Bridge secret must be at least 32 bytes');
  return { resource, issuer, jwks, bridgeUrl, subjects, bridgeSecret };
}
