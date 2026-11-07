// Minimal MCP client for chrome-devtools-mcp, so the web-perf skill's tools (performance
// traces, insights, network list, accessibility snapshot) can be used from scripts when the
// MCP server is not connected to the session. Every tool result is saved to OUT (default
// work/devtools) and printed shortened.
//
// Chrome: CHROME_PATH, else /opt/pw-browsers/chromium when it exists (cloud sessions), else
// the Chrome that chrome-devtools-mcp finds on the machine.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const LAB = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const OUT = process.env.OUT || path.join(LAB, 'work', 'devtools');

export async function connect(extraArgs = []) {
  fs.mkdirSync(OUT, { recursive: true });
  const chrome = process.env.CHROME_PATH || (fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : '');
  const args = [
    path.join(LAB, 'node_modules/chrome-devtools-mcp/build/src/bin/chrome-devtools-mcp.js'),
    '--headless', '--isolated', '--usageStatistics=false', '--chromeArg=--no-sandbox',
    ...(chrome ? [`--executablePath=${chrome}`] : []),
    ...extraArgs,
  ];
  const transport = new StdioClientTransport({ command: process.execPath, args, env: process.env, stderr: 'pipe' });
  const client = new Client({ name: 'perf-lab', version: '1.0.0' });
  await client.connect(transport);
  let n = 0;
  const call = async (name, a = {}, { print = 4000, tag = '' } = {}) => {
    const t0 = Date.now();
    const res = await client.callTool({ name, arguments: a }, undefined, { timeout: 240000 });
    const text = (res.content || []).map((c) => (c.type === 'text' ? c.text : `[${c.type}]`)).join('\n');
    const file = path.join(OUT, `${String(++n).padStart(2, '0')}-${name}${tag ? '-' + tag : ''}.txt`);
    fs.writeFileSync(file, text);
    console.log(`\n### ${name} -> ${res.isError ? 'ERROR' : 'ok'} in ${Date.now() - t0} ms, ${text.length} chars, saved ${file}`);
    if (print) console.log(text.length > print ? text.slice(0, print) + `\n...[shortened, full output in ${file}]` : text);
    return text;
  };
  return { client, call, close: () => client.close() };
}
