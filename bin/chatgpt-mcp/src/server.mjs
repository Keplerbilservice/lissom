import { createResourceServer } from './resource-server.mjs';
import { lissomTools } from './lissom-tools.mjs';
import { scope } from './config.mjs';

export function createApp(config, options = {}) {
  return createResourceServer({ ...config, name: 'lissom-courses-readonly', scope }, {
    keySet: options.keySet, tools: lissomTools(config, options.fetchImpl),
  });
}
