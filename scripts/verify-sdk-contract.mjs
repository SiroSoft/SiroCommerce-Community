import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const backend = readFileSync(join(root, 'backend', 'generated', 'sirocommerce.ts'), 'utf8');
const client = readFileSync(join(root, 'packages', 'api-client', 'src', 'generated.ts'), 'utf8');
const names = ['ApiError', 'ApiEnvelope', 'PaginatedApiEnvelope', 'PaginationMeta', 'ApiRequestOptions'];

function declaration(source, name) {
  const start = source.indexOf(`export interface ${name}`);
  if (start < 0) return '';
  let depth = 0;
  let seenBrace = false;
  for (let i = start; i < source.length; i += 1) {
    if (source[i] === '{') { depth += 1; seenBrace = true; }
    if (source[i] === '}') depth -= 1;
    if ((seenBrace && depth === 0) || (!seenBrace && source[i] === ';')) return source.slice(start, i + 1);
  }
  return '';
}

const normalize = value => value.replace(/\/\/.*$/gm, '').replace(/\s+/g, ' ').trim();
const failures = names.filter(name => normalize(declaration(backend, name)) !== normalize(declaration(client, name)));
if (failures.length) {
  console.error(`VERIFY BLOCKED contract drift: ${failures.join(', ')}`);
  process.exit(1);
}
console.log('VERIFY PASS generated SDK contract parity');
