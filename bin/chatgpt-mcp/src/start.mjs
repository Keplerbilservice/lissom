import { configuration } from './config.mjs';
import { createApp } from './server.mjs';

// Loopback only: the separately approved TLS proxy exposes /mcp and metadata.
const config = configuration();
const port = Number(process.env.LISSOM_MCP_PORT ?? 8781);
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('Invalid port');
const server = createApp(config).listen(port, '127.0.0.1', () => console.log(`Lissom MCP listening on loopback port ${port}`));
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => server.close());
