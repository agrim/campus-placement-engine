import assert from "node:assert/strict";
import { cp, mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { test } from "node:test";

const websiteRoot = fileURLToPath(new URL("../", import.meta.url));

async function verifyCopiedArtifact(transform = (html) => html) {
  const fixture = await mkdtemp(path.join(tmpdir(), "cpe-website-artifact-test-"));
  try {
    await mkdir(path.join(fixture, "scripts"));
    await cp(path.join(websiteRoot, "scripts/verify-dist.mjs"), path.join(fixture, "scripts/verify-dist.mjs"));
    await cp(path.join(websiteRoot, "dist"), path.join(fixture, "dist"), { recursive: true });
    const indexPath = path.join(fixture, "dist/index.html");
    await writeFile(indexPath, transform(await readFile(indexPath, "utf8")));
    return spawnSync(process.execPath, [path.join(fixture, "scripts/verify-dist.mjs")], { encoding: "utf8" });
  } finally {
    await rm(fixture, { recursive: true, force: true });
  }
}

test("accepts the built website artifact", async () => {
  const result = await verifyCopiedArtifact();
  assert.equal(result.status, 0, result.stderr);
});

test("rejects a missing production security policy", async () => {
  const result = await verifyCopiedArtifact((html) => html.replace(/<meta http-equiv="Content-Security-Policy"[^>]*>/, ""));
  assert.equal(result.status, 1);
  assert.match(result.stderr, /missing its Content Security Policy/);
});

test("rejects a relaxed first script directive hidden by a strict duplicate", async () => {
  const result = await verifyCopiedArtifact((html) => html.replace(
    "script-src &#39;self&#39;",
    "script-src &#39;self&#39; &#39;unsafe-inline&#39;; script-src &#39;self&#39;",
  ));
  assert.equal(result.status, 1);
  assert.match(result.stderr, /duplicate script-src security policy directive/);
});

test("rejects a weakened network restriction", async () => {
  const result = await verifyCopiedArtifact((html) => html.replace("connect-src &#39;none&#39;", "connect-src &#39;self&#39;"));
  assert.equal(result.status, 1);
  assert.match(result.stderr, /unexpected connect-src security policy/);
});

test("rejects a mixed-case relaxed duplicate directive", async () => {
  const result = await verifyCopiedArtifact((html) => html.replace(
    "script-src &#39;self&#39;",
    "SCRIPT-SRC &#39;unsafe-inline&#39;; script-src &#39;self&#39;",
  ));
  assert.equal(result.status, 1);
  assert.match(result.stderr, /duplicate script-src security policy directive/);
});

test("rejects a policy that appears after resource elements", async () => {
  const result = await verifyCopiedArtifact((html) => {
    const policy = html.match(/<meta http-equiv="Content-Security-Policy"[^>]*>/)?.[0];
    assert.ok(policy);
    return html.replace(policy, "").replace("</head>", `${policy}</head>`);
  });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /security policy must precede resources/);
});
