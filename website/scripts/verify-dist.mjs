import { lstat, readdir, readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const WEBSITE_BASE = "/campus-placement-engine/";
const DIST_ROOT = fileURLToPath(new URL("../dist/", import.meta.url));
const MAX_FILES = 256;
const MAX_FILE_BYTES = 10 * 1024 * 1024;
const MAX_TOTAL_BYTES = 25 * 1024 * 1024;

const requiredFiles = new Set([
  ".nojekyll",
  "favicon.svg",
  "index.html",
  "og-outcomes.png",
  "demo/board-desktop.png",
  "demo/board-mobile.png",
  "demo/campus-placement-engine-demo.vtt",
  "demo/campus-placement-engine-demo.webm",
]);

const allowedRootFiles = new Set([
  ...requiredFiles,
  "404.html",
  "robots.txt",
  "site.webmanifest",
  "sitemap.xml",
]);

const allowedExtensions = new Set([
  ".avif",
  ".css",
  ".html",
  ".ico",
  ".jpeg",
  ".jpg",
  ".js",
  ".json",
  ".png",
  ".svg",
  ".txt",
  ".vtt",
  ".webm",
  ".webmanifest",
  ".webp",
  ".woff",
  ".woff2",
  ".xml",
]);

const textExtensions = new Set([
  ".css",
  ".html",
  ".js",
  ".json",
  ".svg",
  ".txt",
  ".vtt",
  ".webmanifest",
  ".xml",
]);

const forbiddenPathSegments = new Set([
  ".env",
  ".git",
  ".legacy-private",
  "data",
  "node_modules",
  "tests",
]);

const forbiddenContent = [
  ["private key", /-----BEGIN [A-Z ]*PRIVATE KEY-----/],
  ["AWS access key", /\bAKIA[0-9A-Z]{16}\b/],
  ["GitHub token", /\b(?:gh[opsru]_[A-Za-z0-9_]{30,}|github_pat_[A-Za-z0-9_]{20,})\b/],
  ["OpenAI-style API key", /\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}\b/],
  ["Slack token", /\bxox[baprs]-[A-Za-z0-9-]{20,}\b/],
  ["long bearer token", /Bearer\s+(?!\.\.\.|replace|example|token|test)[A-Za-z0-9._~+/=-]{24,}/i],
  ["source map reference", /sourceMappingURL\s*=/],
  ["private archive reference", /\.legacy-private/i],
  ["ChatGPT Sites runtime reference", /chatgpt\.site|site-creator|codex-preview|vinext|wrangler/i],
  ["persistent browser storage", /\b(?:localStorage|sessionStorage|indexedDB)\b/],
];

const failures = [];
const files = [];
let totalBytes = 0;

function fail(message) {
  failures.push(message);
}

function relativePath(absolutePath) {
  return path.relative(DIST_ROOT, absolutePath).split(path.sep).join("/");
}

async function collectFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  entries.sort((left, right) => left.name.localeCompare(right.name));

  for (const entry of entries) {
    const absolutePath = path.join(directory, entry.name);
    const relative = relativePath(absolutePath);
    const segments = relative.split("/");

    if (segments.some((segment) => forbiddenPathSegments.has(segment))) {
      fail(`forbidden path in artifact: ${relative}`);
      continue;
    }

    const stats = await lstat(absolutePath);
    if (stats.isSymbolicLink()) {
      fail(`symbolic link in artifact: ${relative}`);
      continue;
    }
    if (stats.isDirectory()) {
      await collectFiles(absolutePath);
      continue;
    }
    if (!stats.isFile()) {
      fail(`unsupported filesystem entry in artifact: ${relative}`);
      continue;
    }
    if (stats.nlink > 1) {
      fail(`hard-linked file in artifact: ${relative}`);
    }

    files.push({ absolutePath, relative, size: stats.size });
    totalBytes += stats.size;
  }
}

function validatePath(relative) {
  if (relative.includes("\\") || relative.includes("\0") || relative.split("/").includes("..")) {
    fail(`unsafe artifact path: ${relative}`);
    return;
  }

  const firstSegment = relative.split("/")[0];
  if (firstSegment !== "assets" && firstSegment !== "demo" && !allowedRootFiles.has(relative)) {
    fail(`unexpected file outside assets/: ${relative}`);
  }
  if (firstSegment === "demo" && !requiredFiles.has(relative)) {
    fail(`unexpected demo artifact: ${relative}`);
  }

  if (relative.startsWith(".") && relative !== ".nojekyll") {
    fail(`unexpected hidden file in artifact: ${relative}`);
  }

  const extension = path.extname(relative).toLowerCase();
  if (relative !== ".nojekyll" && !allowedExtensions.has(extension)) {
    fail(`unsupported artifact file type: ${relative}`);
  }
  if (extension === ".map") {
    fail(`source maps must not be published: ${relative}`);
  }
}

function validateExternalUrl(rawUrl, relative) {
  const cleaned = rawUrl.replace(/[),.;]+$/, "");
  let url;
  try {
    url = new URL(cleaned);
  } catch {
    fail(`invalid external URL in ${relative}: ${cleaned}`);
    return;
  }

  const allowed =
    (url.origin === "https://agrim.github.io" && url.pathname.startsWith(WEBSITE_BASE)) ||
    (url.origin === "https://github.com" && url.pathname.startsWith("/agrim/campus-placement-engine")) ||
    (url.origin === "https://react.dev" && url.pathname.startsWith("/errors/")) ||
    (url.origin === "https://reactjs.org" && url.pathname.startsWith("/docs/error-decoder")) ||
    url.origin === "http://www.w3.org";

  if (!allowed) {
    fail(`unapproved external URL in ${relative}: ${cleaned}`);
  }
}

function localTargetFromReference(reference, containingFile) {
  const withoutFragment = reference.split("#", 1)[0].split("?", 1)[0];
  let decoded;
  try {
    decoded = decodeURIComponent(withoutFragment);
  } catch {
    fail(`invalid encoded asset reference in ${relativePath(containingFile)}: ${reference}`);
    return null;
  }

  if (decoded.startsWith(WEBSITE_BASE)) {
    return path.join(DIST_ROOT, decoded.slice(WEBSITE_BASE.length));
  }
  if (decoded.startsWith("/")) {
    fail(`root-relative URL misses ${WEBSITE_BASE} in ${relativePath(containingFile)}: ${reference}`);
    return null;
  }
  return path.resolve(path.dirname(containingFile), decoded);
}

async function requireLocalReference(reference, containingFile, knownFiles) {
  if (
    reference === "" ||
    reference.startsWith("#") ||
    reference.startsWith("data:") ||
    reference.startsWith("mailto:") ||
    reference.startsWith("tel:") ||
    /^https?:\/\//i.test(reference)
  ) {
    return;
  }

  const target = localTargetFromReference(reference, containingFile);
  if (target === null) return;
  const targetRelative = relativePath(target);
  if (targetRelative.startsWith("../") || path.isAbsolute(targetRelative)) {
    fail(`asset reference escapes dist/ in ${relativePath(containingFile)}: ${reference}`);
    return;
  }
  if (!knownFiles.has(targetRelative)) {
    fail(`missing local asset referenced by ${relativePath(containingFile)}: ${reference}`);
  }
}

try {
  const rootStats = await lstat(DIST_ROOT);
  if (!rootStats.isDirectory() || rootStats.isSymbolicLink()) {
    throw new Error("website/dist must be a real directory");
  }
  await collectFiles(DIST_ROOT);
} catch (error) {
  const detail = error instanceof Error ? error.message : String(error);
  console.error(`Artifact verification failed: ${detail}`);
  process.exit(1);
}

if (files.length === 0) fail("artifact is empty");
if (files.length > MAX_FILES) fail(`artifact has ${files.length} files; maximum is ${MAX_FILES}`);
if (totalBytes > MAX_TOTAL_BYTES) fail(`artifact is ${totalBytes} bytes; maximum is ${MAX_TOTAL_BYTES}`);

const knownFiles = new Set(files.map((file) => file.relative));
for (const required of requiredFiles) {
  if (!knownFiles.has(required)) fail(`required artifact file is missing: ${required}`);
}

for (const file of files) {
  validatePath(file.relative);
  if (file.size > MAX_FILE_BYTES) {
    fail(`${file.relative} is ${file.size} bytes; maximum per file is ${MAX_FILE_BYTES}`);
  }
  if (requiredFiles.has(file.relative) && file.relative !== ".nojekyll" && file.size === 0) {
    fail(`required artifact file is empty: ${file.relative}`);
  }
}

const textByPath = new Map();
for (const file of files) {
  if (!textExtensions.has(path.extname(file.relative).toLowerCase())) continue;
  const text = await readFile(file.absolutePath, "utf8");
  textByPath.set(file.relative, text);

  for (const [label, pattern] of forbiddenContent) {
    if (pattern.test(text)) fail(`${label} found in ${file.relative}`);
  }

  for (const match of text.matchAll(/https?:\/\/[^\s"'`<>\\]+/g)) {
    validateExternalUrl(match[0], file.relative);
  }
}

const indexPath = path.join(DIST_ROOT, "index.html");
const indexHtml = textByPath.get("index.html") ?? "";
if (!/<html\s[^>]*lang=["']en["']/i.test(indexHtml)) fail("index.html must declare lang=\"en\"");
if (!/<meta\s[^>]*name=["']description["']/i.test(indexHtml)) fail("index.html is missing its description metadata");
if (!/<meta\s[^>]*name=["']viewport["']/i.test(indexHtml)) fail("index.html is missing responsive viewport metadata");
if (!/<title>[^<]*Campus Placement Engine[^<]*<\/title>/i.test(indexHtml)) fail("index.html is missing the product title");
if (/\/(?:src|website)\//i.test(indexHtml)) fail("index.html references source files instead of built assets");
if (!indexHtml.includes(`https://agrim.github.io${WEBSITE_BASE}og-outcomes.png`)) {
  fail("index.html does not reference the canonical social preview image");
}

for (const match of indexHtml.matchAll(/\b(?:src|href)=["']([^"']+)["']/gi)) {
  await requireLocalReference(match[1], indexPath, knownFiles);
}

for (const [relative, text] of textByPath) {
  if (!relative.endsWith(".css")) continue;
  const cssPath = path.join(DIST_ROOT, relative);
  for (const match of text.matchAll(/url\(\s*["']?([^"')\s]+)["']?\s*\)/gi)) {
    await requireLocalReference(match[1], cssPath, knownFiles);
  }
}

const allText = [...textByPath.values()].join("\n");
for (const [label, pattern] of [
  ["product identity", /Campus Placement Engine/],
  ["outcome-led headline", /Maximise job/],
  ["fictional-data disclosure", /fictional data/i],
  ["browser-only disclosure", /Nothing is uploaded or stored/i],
]) {
  if (!pattern.test(allText)) fail(`required ${label} is missing from the artifact`);
}

if (![...knownFiles].some((file) => file.startsWith("assets/") && file.endsWith(".js"))) {
  fail("artifact has no compiled JavaScript asset");
}
if (![...knownFiles].some((file) => file.startsWith("assets/") && file.endsWith(".css"))) {
  fail("artifact has no compiled CSS asset");
}

for (const pngPath of ["og-outcomes.png", "demo/board-desktop.png", "demo/board-mobile.png"]) {
  if (!knownFiles.has(pngPath)) continue;
  const png = await readFile(path.join(DIST_ROOT, pngPath));
  const pngSignature = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  if (png.length < pngSignature.length || !png.subarray(0, pngSignature.length).equals(pngSignature)) {
    fail(`${pngPath} does not have a valid PNG signature`);
  }
}

if (knownFiles.has("demo/campus-placement-engine-demo.webm")) {
  const webm = await readFile(path.join(DIST_ROOT, "demo/campus-placement-engine-demo.webm"));
  const ebmlSignature = Buffer.from([0x1a, 0x45, 0xdf, 0xa3]);
  if (webm.length < ebmlSignature.length || !webm.subarray(0, ebmlSignature.length).equals(ebmlSignature)) {
    fail("demo walkthrough does not have a valid WebM/EBML signature");
  }
}

if (!(textByPath.get("demo/campus-placement-engine-demo.vtt") ?? "").startsWith("WEBVTT\n")) {
  fail("demo walkthrough captions do not have a valid WebVTT header");
}

if (failures.length > 0) {
  for (const failure of failures) console.error(`FAIL: ${failure}`);
  console.error(`Artifact verification failed with ${failures.length} issue(s).`);
  process.exit(1);
}

console.log(`OK: verified ${files.length} GitHub Pages files (${totalBytes} bytes).`);
console.log(`OK: all local assets resolve beneath ${WEBSITE_BASE}.`);
console.log("OK: no forbidden paths, source maps, persistent storage, runtime remnants, or obvious secrets found.");
