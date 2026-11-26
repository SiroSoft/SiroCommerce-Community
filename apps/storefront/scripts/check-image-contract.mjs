import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import { pathToFileURL } from "node:url";

const ROOT = path.resolve("src");
const EXTENSIONS = new Set([".ts", ".tsx"]);
const REQUIRED = ["width", "height", "alt"];

function lineNumberAt(source, index) {
  return source.slice(0, index).split(/\r?\n/).length;
}

function hasAttribute(tag, name) {
  return new RegExp(`(?:^|\\s)${name}\\s*=`, "i").test(tag);
}

/**
 * Pure inspection of a source string. Returns one entry per `<img>` that is
 * missing a required attribute. Exported so unit tests do not need the CLI.
 */
export function inspectImageTags(source, file = "<inline>") {
  const issues = [];
  const pattern = /<img\b[^>]*>/gi;
  let match;
  while ((match = pattern.exec(source)) !== null) {
    const tag = match[0];
    const missing = REQUIRED.filter((name) => !hasAttribute(tag, name));
    if (missing.length > 0) {
      issues.push({ file, line: lineNumberAt(source, match.index), tag: tag.replace(/\s+/g, " "), missing });
    }
  }
  return issues;
}

async function filesIn(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const filePath = path.join(directory, entry.name);
    // Test fixtures deliberately include malformed tags to exercise this check.
    if (entry.isDirectory()) {
      if (entry.name !== "__tests__") files.push(...(await filesIn(filePath)));
    } else if (EXTENSIONS.has(path.extname(entry.name)) && !/\.test\.[cm]?[jt]sx?$/.test(entry.name)) {
      files.push(filePath);
    }
  }
  return files;
}

export async function collectImageIssues(root = ROOT) {
  const files = await filesIn(root);
  const issues = [];
  for (const filePath of files) {
    const source = await readFile(filePath, "utf8");
    issues.push(...inspectImageTags(source, path.relative(process.cwd(), filePath)));
  }
  return { files: files.length, issues };
}

async function main() {
  const { files, issues } = await collectImageIssues();
  if (issues.length > 0) {
    for (const issue of issues) console.warn(`${issue.file}:${issue.line}: <img> missing ${issue.missing.join(", ")}`);
    process.exitCode = 1;
    return;
  }
  console.log(`Image contract check passed (${files} files).`);
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
  await main();
}
