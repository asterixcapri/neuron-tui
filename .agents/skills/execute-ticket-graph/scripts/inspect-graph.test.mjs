#!/usr/bin/env node

import assert from "node:assert/strict";
import { mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { spawnSync } from "node:child_process";

const script = new URL("./inspect-graph.mjs", import.meta.url);
const directory = mkdtempSync(join(tmpdir(), "execute-ticket-graph-"));

function run(name, nodes, args = ["--rounds"]) {
  const path = join(directory, `${name}.json`);
  writeFileSync(path, JSON.stringify({ version: 1, effort: { id: name }, nodes }));
  return spawnSync(process.execPath, [script.pathname, path, ...args], { encoding: "utf8" });
}

const node = (id, state, blockedBy = []) => ({
  id,
  reference: `ticket-${id}`,
  title: `Ticket ${id}`,
  state,
  blockedBy,
});

const diamond = run("diamond", [
  node("01", "eligible"),
  node("02", "eligible", ["01"]),
  node("03", "eligible", ["01"]),
  node("04", "eligible", ["02", "03"]),
]);
assert.equal(diamond.status, 0, diamond.stderr);
assert.deepEqual(JSON.parse(diamond.stdout).rounds, [["01"], ["02", "03"], ["04"]]);

const failedSibling = run("failed-sibling", [
  node("01", "resolved"),
  node("02", "resolved", ["01"]),
  node("03", "failed", ["01"]),
  node("04", "eligible", ["02", "03"]),
]);
assert.equal(failedSibling.status, 0, failedSibling.stderr);
assert.deepEqual(JSON.parse(failedSibling.stdout).frontier, []);
assert.deepEqual(JSON.parse(failedSibling.stdout).remaining.map(({ id }) => id), ["03", "04"]);

const missing = run("missing", [node("01", "eligible", ["99"])]);
assert.equal(missing.status, 2);
assert.match(missing.stderr, /missing blocker 99/);

const cycle = run("cycle", [
  node("01", "eligible", ["02"]),
  node("02", "eligible", ["01"]),
]);
assert.equal(cycle.status, 2);
assert.match(cycle.stderr, /cycle detected/);

const capped = run(
  "capped",
  [node("01", "eligible"), node("02", "eligible"), node("03", "eligible")],
  ["--rounds", "--max-concurrency", "2"],
);
assert.equal(capped.status, 0, capped.stderr);
assert.deepEqual(JSON.parse(capped.stdout).rounds, [["01", "02"], ["03"]]);

process.stdout.write("inspect-graph tests passed\n");
