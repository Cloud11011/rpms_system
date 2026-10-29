// Release gate for a reviewed local commit (REL-01). Replaces `git add -A`: only files named in the allowlist are staged,
// each must match the git blob id that was reviewed, and the staged content is scanned for private data before the
// operator commits by hand. It never commits, pushes or changes the working tree, except `prepare-sanitized`, which
// moves unredacted originals to a private folder outside the repository and installs the reviewed sanitized copies.
//
//   node maintenance/release/commit-gate.cjs check   [--allowlist=<file>] [--deny-terms=<private file>]
//   node maintenance/release/commit-gate.cjs prepare-sanitized --from=<review package reports dir> --originals-to=<private dir>
//        [--windows-acl-confirmed]   (Windows only: after restricting the folder's ACL as the refusal message explains)
//   node maintenance/release/commit-gate.cjs stage   [--allowlist=<file>] [--deny-terms=<private file>]
//
// Allowlist (tab-separated): `baseline <commit>`, `branch <name>`, then `<blob id> <path> [original=<blob id>]`.
// `original=` marks a report whose unredacted local original must never be committed; `self` pins the allowlist itself.
// --deny-terms names a private file outside the repository with one sensitive term per line (for example real names or
// the production database name); a staged file containing any of them is refused.
'use strict';
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process');
const [command, ...rest] = process.argv.slice(2);
const opt = Object.fromEntries(rest.map(a => a.match(/^--([a-z-]+)=(.*)$/s)).filter(Boolean).map(m => [m[1], m[2]]));
const flags = new Set(rest.filter(a => /^--[a-z-]+$/.test(a)));
const git = (args, input) => cp.execFileSync('git', args, { cwd: root, input, maxBuffer: 1 << 30, env: { ...process.env, GIT_LITERAL_PATHSPECS: '1' } });
const gitText = (args, input) => git(args, input).toString('utf8');
const fail = message => { console.error('REFUSED: ' + message); process.exit(1); };
const root = (() => { try { return cp.execFileSync('git', ['rev-parse', '--show-toplevel'], { cwd: __dirname, encoding: 'utf8' }).trim(); } catch (_) { fail('Run inside the repository worktree.'); } })();
const allowlistPath = path.resolve(root, opt.allowlist || 'maintenance/release/7r-commit-allowlist.tsv');
const rel = p => path.relative(root, p).split(path.sep).join('/');
const insideRepo = p => { const r = path.relative(fs.realpathSync(root), p); return r === '' || (!r.startsWith('..') && !path.isAbsolute(r)); };

// Paths that never belong in a commit: binaries, dumps, logs, secrets, runtime data and production-derived evidence.
const FORBIDDEN_PATHS = [
  [/\.(png|jpe?g|gif|webp|bmp|pdf|docx?|xlsx?|pptx?|zip|gz|tgz|7z|rar|tar|sql|sqlite3?|db|bak|log|pem|key|crt|p12|pfx|jks)$/i, 'binary, dump, log or key file'],
  [/(^|\/)config\.local\.php$|(^|\/)\.env(\.|$)/i, 'deployment secrets'],
  [/^(storage|backups|sessions|uploads|vendor|node_modules|tools)\//, 'runtime data or third-party code'],
  [/(^|\/)evidence\/cleanup\/|^PRISM_V14_7R_EVIDENCE\/cleanup\//, 'cleanup evidence (may derive from the production export)'],
  [/\/migration\/(r7-prod-|orig-f944220-|prod-restore-)/, 'evidence derived from the production export'],
];
// Content that never belongs in a commit. Placeholders such as /home/<account>/ and *@example.test are allowed.
const ALLOWED_EMAIL_DOMAINS = /@(example\.(test|com|org|net)|<?redacted-institution-domain>?)$/i;
const CONTENT_RULES = [
  ['Hostinger account prefix or database name', /\bu\d{9}_[A-Za-z0-9_]+/g],
  ['local Windows user-profile path', /\b[A-Za-z]:(?:\\{1,2}|\/)Users(?:\\{1,2}|\/)(?!Public\b|Default\b|<)[^\\/\s"'<>]+/gi],
  ['local user-profile path', /\/(?:c|mnt\/c)\/Users\/(?!Public\b|<)[^/\s"'<>]+|\/Users\/(?!Shared\b|<)[A-Za-z0-9._-]+\/|\/home\/(?!<)[a-z][a-z0-9._-]*\//gi],
  ['private key', /-----BEGIN [A-Z ]*PRIVATE KEY-----/g],
  ['API key or token', /\bsk-or-v1-[A-Za-z0-9]{20,}|\bsk-(?:proj-|ant-)?[A-Za-z0-9_-]{32,}|\bAKIA[0-9A-Z]{16}\b|\bgh[pousr]_[A-Za-z0-9]{36,}|\bAIza[0-9A-Za-z_-]{35}|\bxox[abprs]-[A-Za-z0-9-]{10,}|\bya29\.[A-Za-z0-9_-]{20,}|\b1\/\/0[A-Za-z0-9_-]{20,}/g],
  ['JSON Web Token', /\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/g],
  ['password hash', /\$2[aby]\$\d{2}\$[./A-Za-z0-9]{53}|\$argon2(?:id|i|d)\$v=\d+\$m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+/]{10,}\$[A-Za-z0-9+/]{20,}/g],
];

function readAllowlist() {
  if (!fs.existsSync(allowlistPath)) fail('Allowlist not found: ' + rel(allowlistPath));
  const header = {}, entries = [], seen = new Set();
  fs.readFileSync(allowlistPath, 'utf8').split(/\r?\n/).forEach((line, i) => {
    if (!line.trim() || line.startsWith('#')) return;
    const cols = line.split('\t');
    if (cols[0] === 'baseline' || cols[0] === 'branch') { header[cols[0]] = cols[1]; return; }
    const [oid, file, extra = ''] = cols;
    if (!/^([0-9a-f]{40}|self)$/.test(oid) || !file || cols.length > 3 || (extra && !/^original=[0-9a-f]{40}$/.test(extra))) fail(`Allowlist line ${i + 1} is malformed.`);
    if (file.startsWith('/') || file.includes('\\') || file.split('/').some(s => s === '' || s === '.' || s === '..')) fail(`Allowlist line ${i + 1}: unsafe path ${file}`);
    if (seen.has(file)) fail('Duplicate allowlist path ' + file);
    seen.add(file); entries.push({ oid, file, original: extra ? extra.slice(9) : null });
  });
  if (!header.baseline || !header.branch || !entries.length) fail('The allowlist needs baseline, branch and at least one file.');
  return { header, entries };
}

function denyTerms() {
  if (!opt['deny-terms']) return [];
  const file = fs.realpathSync(path.resolve(opt['deny-terms']));
  if (insideRepo(file)) fail('Keep the deny-terms file outside the repository.');
  return fs.readFileSync(file, 'utf8').split(/\r?\n/).map(t => t.trim()).filter(t => t && !t.startsWith('#')).map(t => t.toLowerCase());
}

function scan(file, buffer, terms) {
  const problems = [];
  for (const [re, why] of FORBIDDEN_PATHS) if (re.test(file)) problems.push('path: ' + why);
  if (buffer.includes(0)) problems.push('binary content');
  const text = buffer.toString('utf8');
  for (const [why, re] of CONTENT_RULES) { const m = text.match(re); if (m) problems.push(`${why} (${m.length}×)`); }
  const emails = (text.match(/[A-Za-z0-9._%+-]+@<?[A-Za-z0-9.-]+\.[A-Za-z]{2,}>?/g) || []).filter(e => !ALLOWED_EMAIL_DOMAINS.test(e));
  if (emails.length) problems.push(`email address outside the synthetic domains (${emails.length}×)`);
  const lower = text.toLowerCase(), hits = terms.filter(t => lower.includes(t));
  if (hits.length) problems.push(`private deny-term (${hits.length} term(s))`);
  return problems; // Matched values are never printed, only their kind.
}

function context() {
  const { header, entries } = readAllowlist();
  const branch = gitText(['rev-parse', '--abbrev-ref', 'HEAD']).trim(), head = gitText(['rev-parse', 'HEAD']).trim();
  if (branch !== header.branch) fail(`Branch is ${branch}; the allowlist was reviewed for ${header.branch}.`);
  if (!head.startsWith(header.baseline)) fail(`HEAD is ${head.slice(0, 12)}; the allowlist was reviewed against ${header.baseline}.`);
  return { entries, terms: denyTerms() };
}

function check({ entries, terms }) {
  const errors = [], files = entries.map(e => e.file);
  for (const e of entries) {
    const abs = path.join(root, e.file);
    let st; try { st = fs.lstatSync(abs); } catch (_) { errors.push(`${e.file}: missing`); continue; }
    if (!st.isFile()) { errors.push(`${e.file}: not a regular file (links and folders are never staged)`); continue; }
  }
  if (errors.length) return errors;
  const oids = gitText(['hash-object', '--stdin-paths'], files.join('\n') + '\n').trim().split('\n');
  entries.forEach((e, i) => {
    if (e.original && oids[i] === e.original) errors.push(`${e.file}: this is the UNREDACTED original; run prepare-sanitized first`);
    else if (e.oid !== 'self' && oids[i] !== e.oid) errors.push(`${e.file}: content differs from the reviewed version (${oids[i].slice(0, 12)} ≠ ${e.oid.slice(0, 12)})`);
    for (const p of scan(e.file, fs.readFileSync(path.join(root, e.file)), terms)) errors.push(`${e.file}: ${p}`);
  });
  const listed = new Set(files);
  const modified = gitText(['ls-files', '-m', '-d', '-z']).split('\0').filter(Boolean);
  for (const f of new Set(modified)) if (!listed.has(f)) errors.push(`${f}: tracked change is not in the allowlist (a partial commit would be inconsistent)`);
  return errors;
}

function report(entries) {
  const listed = new Set(entries.map(e => e.file));
  const left = gitText(['ls-files', '-o', '--exclude-standard', '-z']).split('\0').filter(f => f && !listed.has(f));
  const groups = {};
  for (const f of left) { const why = (FORBIDDEN_PATHS.find(([re]) => re.test(f)) || [null, 'not reviewed'])[1]; groups[why] = (groups[why] || 0) + 1; }
  console.log(`Allowlisted: ${entries.length} files. Left out (stays local, untracked): ${left.length}.`);
  for (const [why, n] of Object.entries(groups).sort((a, b) => b[1] - a[1])) console.log(`  ${String(n).padStart(5)}  ${why}`);
}

// REL-02: the folder that receives unredacted originals. It is a real folder (no link or junction anywhere in its path)
// outside every git working tree, because an untracked file there could be committed from that checkout. POSIX: it
// belongs to this account with no group or other access (chmod 700); a missing folder is created that way, and an
// existing one is never loosened or repaired here. Windows: NTFS ACLs are not inspected, so the operator creates and
// restricts the folder first and confirms it with --windows-acl-confirmed; the gate does not create it there.
const POSIX = process.platform !== 'win32';
const WINDOWS_ACL_STEPS = [
  'On Windows the gate cannot verify NTFS permissions. Before running prepare-sanitized:',
  '  1. Create the folder outside every repository and outside OneDrive, Dropbox or any other synced or shared folder.',
  '  2. Remove inherited access and grant only your account full control:',
  '       cmd.exe:    icacls "<folder>" /inheritance:r /grant:r "%USERDOMAIN%\\%USERNAME%:(OI)(CI)F"',
  '       PowerShell: icacls "<folder>" /inheritance:r /grant:r "${env:USERDOMAIN}\\${env:USERNAME}:(OI)(CI)F"',
  '  3. Check with icacls "<folder>": it must list only your account, as (OI)(CI)(F). Files created inside inherit that.',
  '  4. Run prepare-sanitized again with --windows-acl-confirmed.',
];
const samePath = (a, b) => (POSIX ? a === b : a.toLowerCase() === b.toLowerCase());
const exists = p => { try { fs.lstatSync(p); return true; } catch (_) { return false; } };
const insideGitWorkTree = dir => { for (let d = dir; ; d = path.dirname(d)) { if (exists(path.join(d, '.git'))) return true; if (path.dirname(d) === d) return false; } };

function privateFolder(dir) {
  const st = fs.lstatSync(dir);
  if (st.isSymbolicLink() || !st.isDirectory()) fail('The folder for unredacted originals must be a real folder, not a file, link or junction.');
  if (!POSIX) return;
  if ((st.mode & 0o077) !== 0) fail(`The folder for unredacted originals must not be accessible to group or others (chmod 700); it is ${(st.mode & 0o777).toString(8)}. It was not changed.`);
  if (st.uid !== process.geteuid()) fail('The folder for unredacted originals must belong to this account.');
}

function originalsFolder(input) {
  const keep = path.resolve(input), present = exists(keep), checked = present ? keep : path.dirname(keep);
  let real = null; try { real = fs.realpathSync.native(checked); } catch (_) {}
  if (!real || !samePath(real, checked)) fail('Give the folder for unredacted originals as its real absolute path' + (present ? '' : ' inside an existing folder') + ', with no link or junction in it.');
  if (insideGitWorkTree(real)) fail('Keep the unredacted originals outside the repository and outside every other git working tree (an untracked file there could be committed from that checkout).');
  if (!POSIX && (!present || !flags.has('--windows-acl-confirmed'))) {
    WINDOWS_ACL_STEPS.forEach(l => console.error(l));
    fail(present ? 'Restrict the folder as above, then confirm with --windows-acl-confirmed.' : 'Create and restrict the folder as above first; on Windows the gate does not create it.');
  }
  if (!present) { fs.mkdirSync(keep, { mode: 0o700 }); fs.chmodSync(keep, 0o700); }
  privateFolder(keep);
  return keep;
}

// Exclusive creation (never follows or replaces an existing file or link), mode 0600 before any content on POSIX, flushed,
// then read back. A partial copy is removed.
function writePrivate(file, data, label) {
  let fd;
  try { fd = fs.openSync(file, 'wx', 0o600); }
  catch (e) { fail(e.code === 'EEXIST' ? `${label}: a file or link already exists at its private destination; nothing was overwritten or changed. Use a new private folder.` : `${label}: the private copy could not be created (${e.code}); nothing was changed.`); }
  try {
    if (fs.lstatSync(file).isSymbolicLink()) throw new Error('the destination became a link');
    if (POSIX) {
      fs.fchmodSync(fd, 0o600);
      const st = fs.fstatSync(fd);
      if ((st.mode & 0o777) !== 0o600 || st.uid !== process.geteuid()) throw new Error('the copy could not be made private');
    }
    for (let at = 0; at < data.length;) at += fs.writeSync(fd, data, at, data.length - at);
    fs.fsyncSync(fd);
  } catch (e) {
    fs.closeSync(fd); fs.rmSync(file, { force: true });
    fail(`${label}: the private copy could not be written safely (${e.code || e.message}); it was removed.`);
  }
  fs.closeSync(fd);
  if (!fs.readFileSync(file).equals(data)) fail(`${label}: the private copy does not match the original.`);
}

function preserveOriginal(keep, file, data) {
  let dir = keep;
  for (const part of path.posix.dirname(file).split('/').filter(p => p !== '.')) {
    dir = path.join(dir, part);
    if (!exists(dir)) { fs.mkdirSync(dir, { mode: 0o700 }); if (POSIX) fs.chmodSync(dir, 0o700); }
    privateFolder(dir);
  }
  writePrivate(path.join(keep, file), data, file);
}

if (command === 'check') {
  const ctx = context(), errors = check(ctx);
  report(ctx.entries);
  if (errors.length) { errors.forEach(e => console.error('  ✗ ' + e)); fail(`${errors.length} problem(s); nothing was staged.`); }
  console.log('CHECK PASS: every allowlisted file matches its reviewed blob id and passes the privacy scan. Nothing was staged.');
} else if (command === 'stage') {
  const ctx = context();
  if (gitText(['diff', '--cached', '--name-only']).trim()) fail('The index already has staged changes. Start from a clean index (git reset -q) so only the allowlist is staged.');
  const errors = check(ctx);
  if (errors.length) { errors.forEach(e => console.error('  ✗ ' + e)); fail(`${errors.length} problem(s); nothing was staged.`); }
  const files = ctx.entries.map(e => e.file), pins = new Map(ctx.entries.map(e => [e.file, e]));
  const undo = message => { git(['reset', '-q']); fail(message + ' The index was reset; nothing is staged.'); };
  let staged, unchanged;
  try {
    git(['add', '--pathspec-from-file=-', '--pathspec-file-nul', '--'], files.join('\0') + '\0');
    staged = gitText(['diff', '--cached', '--name-only', '-z']).split('\0').filter(Boolean);
    const stagedSet = new Set(staged);
    if (staged.some(f => !pins.has(f))) undo('Staged files outside the allowlist: ' + staged.filter(f => !pins.has(f)).join(', '));
    unchanged = files.filter(f => !stagedSet.has(f));
    // The whole index, without path arguments (hundreds of paths exceed the Windows command-line limit).
    const index = new Map(gitText(['ls-files', '-s', '-z']).split('\0').filter(Boolean).map(l => { const [meta, f] = l.split('\t'); return [f, meta.split(' ')]; }));
    const problems = [];
    for (const f of staged) {
      const [mode, oid] = index.get(f) || [];
      const e = pins.get(f);
      if (!oid) { problems.push(`${f}: missing from the index`); continue; }
      if (mode !== '100644' && mode !== '100755') problems.push(`${f}: staged as mode ${mode} (only regular files)`);
      if (e.original && oid === e.original) problems.push(`${f}: the UNREDACTED original was staged`);
      if (e.oid !== 'self' && oid !== e.oid) problems.push(`${f}: staged blob ${oid.slice(0, 12)} ≠ reviewed ${e.oid.slice(0, 12)}`);
      for (const p of scan(f, git(['cat-file', 'blob', oid]), ctx.terms)) problems.push(`${f}: staged content: ${p}`);
    }
    if (problems.length) { problems.forEach(p => console.error('  ✗ ' + p)); undo(`${problems.length} problem(s) in the staged content.`); }
  } catch (e) {
    undo('Staging verification failed (' + (e.code || e.message || e).toString().split('\n')[0] + ').');
  }
  report(ctx.entries);
  console.log(`STAGED: ${staged.length} files (${unchanged.length} allowlisted files already match HEAD). Staged content passed the privacy scan.`);
  console.log(['Before committing, review by hand:',
    '  git diff --cached --stat | tail -5            # count and size look right',
    '  git diff --cached --name-only | less          # no config.local.php, dumps, screenshots, PDFs or logs',
    '  git diff --cached -- PRISM_V14_7R_DATABASE_COMPATIBILITY.md PRISM_V14_7R_PDF_COMPLIANCE_MATRIX.md   # redaction placeholders present',
    'Then commit locally only. Do not push until the hPanel auto-deploy branch is confirmed. To abandon: git reset -q'].join('\n'));
} else if (command === 'prepare-sanitized') {
  const { entries } = readAllowlist();
  if (!opt.from || !opt['originals-to']) fail('Give --from=<review package reports dir> and --originals-to=<private dir outside the repository>.');
  const from = fs.realpathSync(path.resolve(opt.from));
  // Verify every original and its sanitized copy before anything is written or replaced. The bytes that are kept are the
  // bytes whose blob id was verified.
  const plan = [];
  for (const e of entries.filter(x => x.original)) {
    const target = path.join(root, e.file), source = path.join(from, e.file);
    let st; try { st = fs.lstatSync(target); } catch (_) { fail(`${e.file} is missing; nothing was changed.`); }
    if (!st.isFile()) fail(`${e.file} is not a regular file; nothing was changed.`);
    const data = fs.readFileSync(target), now = gitText(['hash-object', '--stdin', '--path=' + e.file], data).trim();
    if (now === e.oid) { console.log(`already sanitized: ${e.file}`); continue; }
    if (now !== e.original) fail(`${e.file} changed after review (${now.slice(0, 12)}); re-sanitize it by hand. Nothing was changed.`);
    const [src] = gitText(['hash-object', '--path=' + e.file, '--', source]).trim().split('\n');
    if (src !== e.oid) fail(`${rel(source)} is not the reviewed sanitized copy of ${e.file}. Nothing was changed.`);
    plan.push({ e, target, source, data });
  }
  if (plan.length) {
    const keep = originalsFolder(opt['originals-to']);
    // Every original is kept privately before any worktree file is replaced.
    for (const p of plan) preserveOriginal(keep, p.e.file, p.data);
    const privacy = POSIX ? 'folders 0700 and files 0600, owned by this account (verified)' : 'Windows ACL restricted and confirmed by the operator (not verified by the gate)';
    writePrivate(path.join(keep, 'MANIFEST-' + Date.now() + '.tsv'), Buffer.from(['# original blob\tsanitized blob\tpath', '# privacy: ' + privacy,
      ...plan.map(p => `${p.e.original}\t${p.e.oid}\t${p.e.file}`)].join('\n') + '\n'), 'manifest');
    for (const p of plan) { fs.copyFileSync(p.source, p.target); console.log(`sanitized: ${p.e.file} (original kept privately)`); }
    console.log('Originals kept: ' + privacy + '.');
  }
  console.log('Done. Run `check` next.');
} else {
  console.error('Usage: node maintenance/release/commit-gate.cjs check | stage | prepare-sanitized --from=<dir> --originals-to=<dir> [--windows-acl-confirmed]');
  process.exit(2);
}
