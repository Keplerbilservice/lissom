#!/usr/bin/env node
// PR scope guard: explicit paths in the PR description, one per line.
// A reviewer must independently verify that the declared scope matches the owner's request.
const fs = require('node:fs');
const cp = require('node:child_process');
const event = JSON.parse(fs.readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8'));
const pr = event.pull_request;
if (!pr) { console.log('Only checks pull requests.'); process.exit(0); }
const body = pr.body || '';
const match = body.match(/<!-- SCOPE-START -->([\s\S]*?)<!-- SCOPE-END -->/);
if (!match) { console.error('Missing <!-- SCOPE-START --> ... <!-- SCOPE-END --> in PR body.'); process.exit(1); }
const allowed = match[1].split(/\r?\n/).map(s => s.trim()).filter(Boolean);
if (!allowed.length || allowed.some(s => s.startsWith('#') || s.includes('*') || s.includes('..') || s.startsWith('/') || s.includes('\\'))) {
  console.error('Scope must contain exact repository-relative file paths, no globs or traversal.'); process.exit(1);
}
const base = pr.base.sha;
const head = pr.head.sha;
const diff = cp.execFileSync('git', ['diff', '--name-only', '-z', base, head], {encoding:'utf8'}).split('\0').filter(Boolean);
const unexpected = diff.filter(f => !allowed.includes(f));
console.log('Declared scope:', allowed.join(', '));
console.log('Changed files:', diff.join(', '));
if (unexpected.length) { console.error('OUT OF SCOPE:', unexpected.join(', ')); process.exit(1); }
console.log('All changed paths are declared. Human review is still required.');
