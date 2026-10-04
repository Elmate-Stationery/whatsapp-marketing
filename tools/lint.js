#!/usr/bin/env node
// Syntax-checks every PHP file (php -l) and JS file (node --check) in the plugin. A single PHP syntax error takes a
// WordPress site down, so run this before uploading: `npm run lint` (also runs automatically before `npm version`).
// PHP binary: $PHP_BIN, else `php` on PATH, else XAMPP's C:/xampp/php/php.exe.
'use strict';
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const skip = new Set(['.git', 'node_modules']);
function files(dir, out = []) {
  for (const name of fs.readdirSync(dir)) {
    if (skip.has(name)) continue;
    const full = path.join(dir, name);
    if (fs.statSync(full).isDirectory()) files(full, out); else if (/\.(php|js)$/.test(name)) out.push(full);
  }
  return out;
}
function phpBinary() {
  for (const bin of [process.env.PHP_BIN, 'php', 'C:/xampp/php/php.exe'].filter(Boolean)) {
    try { execFileSync(bin, ['-v'], { stdio: 'ignore' }); return bin; } catch (e) { /* try next */ }
  }
  return null;
}

const php = phpBinary();
if (!php) { console.error('lint: no PHP found. Set PHP_BIN to your php executable.'); process.exit(1); }
let errors = 0, count = 0;
for (const file of files(root)) {
  count++;
  const [cmd, args] = file.endsWith('.php') ? [php, ['-l', file]] : [process.execPath, ['--check', file]];
  try { execFileSync(cmd, args, { stdio: 'pipe' }); }
  catch (e) { errors++; console.error(`\n✗ ${path.relative(root, file)}\n${String(e.stdout || '') + String(e.stderr || '')}`.trim()); }
}
if (errors) { console.error(`\nlint: ${errors} file(s) with syntax errors. Do not upload until fixed.`); process.exit(1); }
console.log(`lint: ${count} files OK`);
