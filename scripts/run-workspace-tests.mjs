import { execFileSync, spawnSync } from 'node:child_process';

const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const timeoutMs = Number(process.env.VERIFY_TIMEOUT_MS || 120_000);
function cleanup(pid) {
  if (!pid || process.platform !== 'win32') return true;
  try { execFileSync('taskkill', ['/PID', String(pid), '/T', '/F'], { stdio: 'ignore' }); } catch {}
  try {
    const output = execFileSync('tasklist', ['/FI', `PID eq ${pid}`, '/FO', 'CSV', '/NH'], { encoding: 'utf8' });
    return !output.trim() || output.includes('INFO: No tasks');
  } catch { return true; }
}
const result = spawnSync(npm, ['run', 'test', '--workspaces', '--if-present'], {
  stdio: 'inherit',
  shell: process.platform === 'win32',
  timeout: timeoutMs,
  killSignal: 'SIGTERM',
  windowsHide: true,
});

if (result.error?.code === 'ETIMEDOUT' || result.signal) {
  console.error(`TEST FAIL timeout after ${timeoutMs}ms; process cleanup ${cleanup(result.pid) ? 'PASS' : 'FAIL'}`);
  process.exit(1);
}
const cleaned = cleanup(result.pid);
if (result.status === 0 && cleaned) console.log(`TEST PASS bounded ${timeoutMs}ms; process cleanup PASS`);
else {
  console.error(`TEST FAIL exit ${result.status ?? 'unknown'}; process cleanup ${cleaned ? 'PASS' : 'FAIL'}`);
  process.exit(1);
}
