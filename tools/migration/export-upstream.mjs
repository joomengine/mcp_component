// One-time migration evidence exporter. Not included in Composer or Joomla archives.
// The installed PHP component never loads this file or requires Node.js.
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { writeFile } from 'node:fs/promises';

const [source, destination] = process.argv.slice(2);
if (!source || !destination) {
  throw new Error('Usage: node export-upstream.mjs SOURCE_DIRECTORY OUTPUT_JSON');
}
const root = resolve(source);
const load = (path) => import(pathToFileURL(`${root}/${path}`).href);
const { createServer } = await load('dist/mcp/create-server.js');
const { publicCatalog } = await load('dist/catalog/core.js');
const { joomlaCrudBases } = await load('dist/catalog/crud-bases.js');
const { crudWriteFields, sensitiveCrudWriteFieldsByBaseId } = await load('dist/catalog/crud-write-fields.js');
const { ToolsetSchema } = await load('dist/config/schema.js');
const { Client } = await load('node_modules/@modelcontextprotocol/sdk/dist/esm/client/index.js');
const { InMemoryTransport } = await load('node_modules/@modelcontextprotocol/sdk/dist/esm/inMemory.js');
const configuration = {
  defaultSite: 'migration',
  sites: new Map([['migration', { id: 'migration', toolsets: new Set(ToolsetSchema.options) }]]),
};
const server = createServer(configuration);
const client = new Client({ name: 'immutable-contract-export', version: '1.0.0' });
const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
try {
  await server.connect(serverTransport);
  await client.connect(clientTransport);
  const tools = await client.listTools();
  const resources = await client.listResources();
  const result = {
    source: {
      repository: 'joomengine/joomla-mcp',
      commit: '2cff50f4f6b440da3c684f9995a77efad32e1a36',
      method: 'Original compiled catalogues and actual MCP tools/list and resources/list over linked in-memory transports; no Joomla operation invoked.',
    },
    catalog: publicCatalog(),
    crud: joomlaCrudBases,
    fields: Object.fromEntries(joomlaCrudBases.map((base) => [base.id, crudWriteFields(base.id)])),
    sensitiveFields: sensitiveCrudWriteFieldsByBaseId,
    tools: tools.tools,
    resources: resources.resources,
    toolsets: ToolsetSchema.options,
  };
  await writeFile(destination, `${JSON.stringify(result, null, 2)}\n`, { flag: 'wx' });
  console.log(JSON.stringify({ tools: result.tools.length, resources: result.resources.length, apiActions: result.catalog.api.semanticActions, companionDescriptors: result.catalog.cli.companion.semanticActions, cliTargets: result.catalog.cli.targets.length }));
} finally {
  await client.close();
  await server.close();
}
