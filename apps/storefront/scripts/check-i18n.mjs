import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve('src/locales');
const locales = fs.readdirSync(root).filter((name) => fs.statSync(path.join(root, name)).isDirectory()).sort();
if (locales.length < 2) {
  console.error('i18n: at least two locales are required');
  process.exit(1);
}

const flatten = (value, prefix = '') => {
  const keys = [];
  for (const [key, child] of Object.entries(value)) {
    const full = prefix === '' ? key : `${prefix}.${key}`;
    if (child !== null && typeof child === 'object' && !Array.isArray(child)) {
      keys.push(...flatten(child, full));
    } else {
      keys.push(full);
    }
  }
  return keys;
};

const files = locales.map((locale) => {
  const file = path.join(root, locale, 'common.json');
  if (!fs.existsSync(file)) throw new Error(`${locale}: missing common.json`);
  const raw = fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, '');
  return { locale, keys: new Set(flatten(JSON.parse(raw))) };
});
const baseline = files[0];
let failed = false;
for (const current of files.slice(1)) {
  const missing = [...baseline.keys].filter((key) => !current.keys.has(key));
  const extra = [...current.keys].filter((key) => !baseline.keys.has(key));
  if (missing.length || extra.length) {
    failed = true;
    console.error(`${current.locale}: missing=${missing.join(',') || '-'} extra=${extra.join(',') || '-'}`);
  }
}
if (failed) process.exit(1);
console.log(`i18n parity OK (${locales.length} locales)`);
