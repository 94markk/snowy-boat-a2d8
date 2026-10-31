// node mcp-audit.mjs <outDir> <url path>... — drives the project's chrome-devtools MCP server (from .mcp.json):
// sign in on the test site, then per page: Lighthouse (mobile) + a performance trace on Slow 4G with a 4x slower CPU.
import { spawn } from 'node:child_process';
import fs from 'node:fs';
const [outDir, ...paths] = process.argv.slice(2);
const BASE = 'http://localhost:8970';
const cfg = JSON.parse(fs.readFileSync('/home/user/snowy-boat-a2d8/.mcp.json', 'utf8')).mcpServers['chrome-devtools'];
const proc = spawn(cfg.command, cfg.args, { stdio: ['pipe', 'pipe', 'pipe'] });
let buf = '', id = 0; const waiting = new Map();
proc.stdout.on('data', (d) => { buf += d; let i; while ((i = buf.indexOf('\n')) >= 0) { const line = buf.slice(0, i); buf = buf.slice(i + 1); try { const m = JSON.parse(line); if (m.id && waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); } } catch (e) {} } });
proc.stderr.on('data', () => {});
const rpc = (method, params) => new Promise((ok) => { const n = ++id; waiting.set(n, ok); proc.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: n, method, params }) + '\n'); });
let PAGE = null;
const tool = async (name, args) => { const a = Object.assign(PAGE !== null && name !== 'list_pages' && name !== 'new_page' ? { pageId: PAGE } : {}, args || {}); const r = await rpc('tools/call', { name, arguments: a }); if (r.error) throw new Error(name + ': ' + JSON.stringify(r.error)); const text = (r.result.content || []).map((c) => c.text || '').join('\n'); if (r.result.isError) throw new Error(name + ': ' + text.slice(0, 300)); return text; };
await rpc('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'audit', version: '1' } });
proc.stdin.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');
const pages = await tool('list_pages');
const m = pages.match(/(\d+):/); PAGE = m ? Number(m[1]) : 1;
console.log('page id', PAGE);
await tool('navigate_page', { type: 'url', url: BASE + '/wp-login.php' });
await tool('evaluate_script', { function: "() => { document.getElementById('user_login').value='client1'; document.getElementById('user_pass').value='ClientPass!2026'; document.getElementById('loginform').submit(); return true; }" });
await new Promise((r) => setTimeout(r, 4000));
for (const p of paths) {
  const name = p.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'home';
  await tool('emulate', { viewport: '390x844x3,mobile,touch' });
  await tool('navigate_page', { type: 'url', url: BASE + p });
  const lh = await tool('lighthouse_audit', { device: 'mobile', mode: 'navigation', outputDirPath: outDir + '/' + name });
  fs.writeFileSync(`${outDir}/${name}-lighthouse.txt`, lh);
  await tool('emulate', { networkConditions: 'Fast 4G', cpuThrottlingRate: 1, viewport: '390x844x3,mobile,touch' });
  console.log('done', p);
}
proc.kill();
