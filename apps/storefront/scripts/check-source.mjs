import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import ts from "typescript";

const root = path.resolve("src");
const extensions = new Set([".ts", ".tsx"]);
const violations = [];

async function filesIn(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const filePath = path.join(directory, entry.name);
    if (entry.isDirectory()) files.push(...(await filesIn(filePath)));
    else if (extensions.has(path.extname(entry.name))) files.push(filePath);
  }

  return files;
}

for (const filePath of await filesIn(root)) {
  const source = await readFile(filePath, "utf8");
  const relativePath = path.relative(process.cwd(), filePath);
  const result = ts.transpileModule(source, {
    fileName: filePath,
    compilerOptions: {
      jsx: ts.JsxEmit.ReactJSX,
      target: ts.ScriptTarget.ES2022,
      module: ts.ModuleKind.ESNext,
    },
    reportDiagnostics: true,
  });

  for (const diagnostic of result.diagnostics ?? []) {
    const position = diagnostic.start === undefined
      ? ""
      : `:${ts.getLineAndCharacterOfPosition(ts.createSourceFile(filePath, source, ts.ScriptTarget.Latest), diagnostic.start).line + 1}`;
    violations.push(`${relativePath}${position}: ${ts.flattenDiagnosticMessageText(diagnostic.messageText, " ")}`);
  }

  source.split(/\r?\n/).forEach((line, index) => {
    if (/[ \t]+$/.test(line)) violations.push(`${relativePath}:${index + 1}: trailing whitespace`);
  });

  for (const [pattern, message] of [
    [/\bdebugger\b/, "debugger statements are not allowed"],
    [/@ts-(?:ignore|nocheck|expect-error)/, "TypeScript suppression comments are not allowed"],
    [/\bconsole\.log\s*\(/, "console.log is not allowed; use an application logger or handle the error"],
  ]) {
    const match = source.match(pattern);
    if (match) {
      const line = source.slice(0, match.index).split(/\r?\n/).length;
      violations.push(`${relativePath}:${line}: ${message}`);
    }
  }
}

if (violations.length > 0) {
  console.error(violations.join("\n"));
  process.exitCode = 1;
} else {
  console.log(`Source boundary check passed (${(await filesIn(root)).length} files).`);
}
