import { spawnSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const cli = path.resolve(appRoot, "../../node_modules/@playwright/test/cli.js");
const baseUrl = process.env.E2E_BASE_URL ?? "https://ecommerce.sirophp.com";

console.log(`[e2e:live] axe-only run against ${baseUrl} (visual baselines skipped)`);

const result = spawnSync(
  process.execPath,
  [cli, "test", "-c", "playwright.quality.config.ts", ...process.argv.slice(2)],
  {
    cwd: appRoot,
    stdio: "inherit",
    env: { ...process.env, E2E_LIVE: "1", E2E_BASE_URL: baseUrl },
  },
);

process.exit(result.status ?? 1);
