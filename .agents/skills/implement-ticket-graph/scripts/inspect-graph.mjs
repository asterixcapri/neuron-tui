#!/usr/bin/env node

import { readFileSync } from "node:fs";

const STATES = new Set(["eligible", "claimed", "resolved", "failed", "ineligible"]);

function usage() {
  return [
    "Usage: node inspect-graph.mjs <snapshot.json> [options]",
    "",
    "Options:",
    "  --max-concurrency <n>  Cap each simulated round (default: 2)",
    "  --rounds                Simulate rounds until completion or a stall",
    "  --format <json|text>    Output format (default: json)",
  ].join("\n");
}

function parseArgs(argv) {
  const options = { path: undefined, maxConcurrency: 2, rounds: false, format: "json" };

  for (let index = 0; index < argv.length; index += 1) {
    const value = argv[index];
    if (value === "--help" || value === "-h") {
      process.stdout.write(`${usage()}\n`);
      process.exit(0);
    }
    if (value === "--rounds") {
      options.rounds = true;
      continue;
    }
    if (value === "--max-concurrency") {
      const parsed = Number(argv[index + 1]);
      if (!Number.isInteger(parsed) || parsed < 1) {
        throw new Error("--max-concurrency must be a positive integer");
      }
      options.maxConcurrency = parsed;
      index += 1;
      continue;
    }
    if (value === "--format") {
      const format = argv[index + 1];
      if (format !== "json" && format !== "text") {
        throw new Error("--format must be json or text");
      }
      options.format = format;
      index += 1;
      continue;
    }
    if (value.startsWith("-")) {
      throw new Error(`unknown option: ${value}`);
    }
    if (options.path) {
      throw new Error("provide exactly one snapshot path");
    }
    options.path = value;
  }

  if (!options.path) {
    throw new Error("snapshot path is required");
  }
  return options;
}

function validateSnapshot(snapshot) {
  const errors = [];
  if (!snapshot || typeof snapshot !== "object" || Array.isArray(snapshot)) {
    return ["snapshot must be a JSON object"];
  }
  if (snapshot.version !== 1) {
    errors.push("version must be 1");
  }
  if (!Array.isArray(snapshot.nodes)) {
    errors.push("nodes must be an array");
    return errors;
  }

  const ids = new Set();
  for (const [index, node] of snapshot.nodes.entries()) {
    const at = `nodes[${index}]`;
    if (!node || typeof node !== "object" || Array.isArray(node)) {
      errors.push(`${at} must be an object`);
      continue;
    }
    for (const field of ["id", "reference", "title"]) {
      if (typeof node[field] !== "string" || node[field].trim() === "") {
        errors.push(`${at}.${field} must be a non-empty string`);
      }
    }
    if (typeof node.id === "string") {
      if (ids.has(node.id)) errors.push(`duplicate ticket id: ${node.id}`);
      ids.add(node.id);
    }
    if (!STATES.has(node.state)) {
      errors.push(`${at}.state must be one of ${[...STATES].join(", ")}`);
    }
    if (!Array.isArray(node.blockedBy)) {
      errors.push(`${at}.blockedBy must be an array`);
    } else if (node.blockedBy.some((id) => typeof id !== "string" || id.trim() === "")) {
      errors.push(`${at}.blockedBy must contain only non-empty string ids`);
    }
  }

  for (const node of snapshot.nodes) {
    if (!node || !Array.isArray(node.blockedBy) || typeof node.id !== "string") continue;
    const seen = new Set();
    for (const blocker of node.blockedBy) {
      if (seen.has(blocker)) errors.push(`ticket ${node.id} repeats blocker ${blocker}`);
      seen.add(blocker);
      if (blocker === node.id) errors.push(`ticket ${node.id} blocks itself`);
      if (!ids.has(blocker)) errors.push(`ticket ${node.id} has missing blocker ${blocker}`);
    }
  }

  if (errors.length === 0) {
    const byId = new Map(snapshot.nodes.map((node) => [node.id, node]));
    const visiting = new Set();
    const visited = new Set();
    const stack = [];

    function visit(id) {
      if (visiting.has(id)) {
        const start = stack.indexOf(id);
        errors.push(`cycle detected: ${[...stack.slice(start), id].join(" -> ")}`);
        return;
      }
      if (visited.has(id)) return;
      visiting.add(id);
      stack.push(id);
      for (const blocker of byId.get(id).blockedBy) visit(blocker);
      stack.pop();
      visiting.delete(id);
      visited.add(id);
    }

    for (const node of snapshot.nodes) visit(node.id);
  }

  return [...new Set(errors)];
}

function frontier(nodes, resolved) {
  return nodes.filter(
    (node) =>
      node.state === "eligible" &&
      !resolved.has(node.id) &&
      node.blockedBy.every((blocker) => resolved.has(blocker)),
  );
}

function inspect(snapshot, options) {
  const initiallyResolved = new Set(
    snapshot.nodes.filter((node) => node.state === "resolved").map((node) => node.id),
  );
  const currentFrontier = frontier(snapshot.nodes, initiallyResolved).slice(
    0,
    options.maxConcurrency,
  );
  const result = {
    valid: true,
    complete: initiallyResolved.size === snapshot.nodes.length,
    maxConcurrency: options.maxConcurrency,
    frontier: currentFrontier.map((node) => node.id),
  };

  if (options.rounds) {
    const simulatedResolved = new Set(initiallyResolved);
    const rounds = [];
    while (true) {
      const next = frontier(snapshot.nodes, simulatedResolved).slice(0, options.maxConcurrency);
      if (next.length === 0) break;
      rounds.push(next.map((node) => node.id));
      for (const node of next) simulatedResolved.add(node.id);
    }
    const remaining = snapshot.nodes.filter((node) => !simulatedResolved.has(node.id));
    result.rounds = rounds;
    result.completeAfterSimulation = remaining.length === 0;
    result.stalled = remaining.length > 0;
    result.remaining = remaining.map((node) => ({
      id: node.id,
      state: node.state,
      unresolvedBlockers: node.blockedBy.filter((id) => !simulatedResolved.has(id)),
    }));
  } else {
    result.stalled = !result.complete && currentFrontier.length === 0;
  }
  return result;
}

function renderText(result) {
  const lines = [
    `valid: ${result.valid}`,
    `complete: ${result.complete}`,
    `frontier: ${result.frontier.length ? result.frontier.join(", ") : "none"}`,
  ];
  if (result.rounds) {
    for (const [index, round] of result.rounds.entries()) {
      lines.push(`round ${index + 1}: ${round.join(", ")}`);
    }
    lines.push(`complete after simulation: ${result.completeAfterSimulation}`);
    if (result.remaining.length) {
      for (const node of result.remaining) {
        const blockers = node.unresolvedBlockers.length
          ? `; unresolved blockers: ${node.unresolvedBlockers.join(", ")}`
          : "";
        lines.push(`remaining: ${node.id} (${node.state}${blockers})`);
      }
    }
  }
  return `${lines.join("\n")}\n`;
}

try {
  const options = parseArgs(process.argv.slice(2));
  const snapshot = JSON.parse(readFileSync(options.path, "utf8"));
  const errors = validateSnapshot(snapshot);
  if (errors.length) {
    process.stderr.write(`${JSON.stringify({ valid: false, errors }, null, 2)}\n`);
    process.exit(2);
  }
  const result = inspect(snapshot, options);
  process.stdout.write(
    options.format === "text" ? renderText(result) : `${JSON.stringify(result, null, 2)}\n`,
  );
} catch (error) {
  process.stderr.write(`${error.message}\n\n${usage()}\n`);
  process.exit(2);
}
