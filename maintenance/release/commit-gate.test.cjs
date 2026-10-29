// REL-01 / REL-02 regression for maintenance/release/commit-gate.cjs. Builds a throwaway git repository under the system
// temporary directory; the real repository and its index are never touched.  Run: node maintenance/release/commit-gate.test.cjs
// POSIX folder and file modes are tested on POSIX hosts; on Windows the ACL confirmation path is tested instead.
'use strict';
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), cp = require('node:child_process'), assert = require('node:assert/strict');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'prism-commit-gate-')), repo = path.join(tmp, 'repo'), pkg = path.join(tmp, 'package'), keep = path.join(tmp, 'private-originals');
const env = { ...process.env, GIT_AUTHOR_NAME: 'Synthetic', GIT_AUTHOR_EMAIL: 'synthetic@example.test', GIT_COMMITTER_NAME: 'Synthetic', GIT_COMMITTER_EMAIL: 'synthetic@example.test', GIT_CONFIG_NOSYSTEM: '1' };
const git = (...args) => cp.execFileSync('git', ['-c', 'core.autocrlf=false', ...args], { cwd: repo, env, encoding: 'utf8' }).trim();
const write = (file, text, base = repo) => { fs.mkdirSync(path.dirname(path.join(base, file)), { recursive: true }); fs.writeFileSync(path.join(base, file), text); };
const gate = (...args) => { const r = cp.spawnSync(process.execPath, [path.join(repo, 'maintenance/release/commit-gate.cjs'), ...args], { cwd: repo, env, encoding: 'utf8' }); return { exit: r.status, out: r.stdout + r.stderr }; };
const staged = () => git('diff', '--cached', '--name-only').split('\n').filter(Boolean).sort();
const checks = []; const check = (ok, label) => { assert.ok(ok, label); checks.push(label); console.log(label); };
// Hostinger-shaped synthetic name, assembled so the gate never sees it in this file.
const HOSTINGER_NAME = 'u' + '123456789' + '_prismdb';
const UNREDACTED = 'Requirements by Jane Panelist; export ' + HOSTINGER_NAME + '.sql\n', SANITIZED = 'Requirements by Reviewer A; export <HOSTINGER_DB_NAME_REDACTED>.sql\n';
try {
  fs.mkdirSync(repo); git('init', '-q', '-b', 'remediation/test');
  write('app.php', '<?php // baseline\n'); write('notes.md', 'baseline\n'); git('add', '-A'); git('commit', '-q', '-m', 'baseline');
  const baseline = git('rev-parse', 'HEAD');
  fs.mkdirSync(path.join(repo, 'maintenance/release'), { recursive: true });
  fs.copyFileSync(path.join(__dirname, 'commit-gate.cjs'), path.join(repo, 'maintenance/release/commit-gate.cjs'));
  // Reviewed changes, plus everything `git add -A` would also have swept in.
  write('app.php', '<?php // reviewed change; contact admin@example.test\n');
  write('report.md', UNREDACTED); write('report.md', SANITIZED, pkg);
  write('screenshot.png', '\x89PNG fake'); write('config.local.php', "<?php define('DB_PASS','x');\n"); write('evidence/cleanup/preview.json', '{"admins":[1,13,46]}\n');
  write('unreviewed-notes.md', 'draft\n');
  const oid = (file, base = repo) => git('hash-object', '--path=' + file, '--', path.join(base, file));
  const allowlist = lines => write('maintenance/release/7r-commit-allowlist.tsv', ['# test allowlist', 'baseline\t' + baseline.slice(0, 12), 'branch\tremediation/test', 'self\tmaintenance/release/7r-commit-allowlist.tsv', ...lines].join('\n') + '\n');
  const good = [`${oid('app.php')}\tapp.php`, `${oid('report.md', pkg)}\treport.md\toriginal=${oid('report.md')}`];
  allowlist(good);

  let r = gate('check');
  check(r.exit === 1 && /UNREDACTED original/.test(r.out) && /Hostinger account prefix/.test(r.out) && !r.out.includes(HOSTINGER_NAME), 'check refuses the unredacted original and never prints the sensitive value');
  r = gate('stage');
  check(r.exit === 1 && staged().length === 0, 'stage refuses while an unredacted original is present; nothing is staged');
  r = gate('prepare-sanitized', '--from=' + pkg, '--originals-to=' + path.join(repo, 'private'));
  check(r.exit === 1 && fs.readFileSync(path.join(repo, 'report.md'), 'utf8') === UNREDACTED && !fs.existsSync(path.join(repo, 'private')), 'prepare-sanitized refuses to keep originals inside the repository and creates nothing there');
  write('report.md', 'tampered sanitized copy\n', path.join(tmp, 'bad-package'));
  r = gate('prepare-sanitized', '--from=' + path.join(tmp, 'bad-package'), '--originals-to=' + keep);
  check(r.exit === 1 && fs.readFileSync(path.join(repo, 'report.md'), 'utf8') === UNREDACTED, 'prepare-sanitized refuses a copy that is not the reviewed sanitized version');

  // --- REL-02: the private folder for unredacted originals is verified before anything is written or replaced.
  const win = process.platform === 'win32', ack = win ? ['--windows-acl-confirmed'] : [];
  const prep = (dir, ...extra) => gate('prepare-sanitized', '--from=' + pkg, '--originals-to=' + dir, ...extra);
  const unchanged = () => fs.readFileSync(path.join(repo, 'report.md'), 'utf8') === UNREDACTED;
  const mode = p => fs.statSync(p).mode & 0o777;
  // The documented setup: chmod 700 on POSIX; on Windows the operator's icacls step (the gate cannot verify ACLs).
  const restrict = dir => {
    fs.mkdirSync(dir);
    if (!win) fs.chmodSync(dir, 0o700);
    else cp.execFileSync('icacls', [dir, '/inheritance:r', '/grant:r', (process.env.USERDOMAIN ? process.env.USERDOMAIN + '\\' : '') + os.userInfo().username + ':(OI)(CI)F'], { stdio: 'ignore' });
    return dir;
  };
  const other = path.join(tmp, 'other-checkout');
  cp.execFileSync('git', ['init', '-q', other], { env });
  r = prep(path.join(other, 'originals'), ...ack);
  check(r.exit === 1 && /outside every other git working tree/.test(r.out) && unchanged() && !fs.existsSync(path.join(other, 'originals')),
    'prepare-sanitized refuses a folder inside another git working tree, where an untracked original could be committed');
  const real = restrict(path.join(tmp, 'real-private')), linked = path.join(tmp, 'linked-private');
  fs.symlinkSync(real, linked, win ? 'junction' : 'dir');
  r = prep(linked, ...ack);
  check(r.exit === 1 && /link or junction/.test(r.out) && unchanged() && fs.readdirSync(real).length === 0, 'prepare-sanitized refuses a linked folder (a junction on Windows) and writes nothing through it');
  fs.unlinkSync(linked);
  if (win) {
    const plain = path.join(tmp, 'unconfirmed'); fs.mkdirSync(plain);
    r = prep(plain);
    check(r.exit === 1 && /icacls/.test(r.out) && /--windows-acl-confirmed/.test(r.out) && unchanged() && fs.readdirSync(plain).length === 0,
      'Windows: without --windows-acl-confirmed the gate prints the icacls steps and keeps nothing');
    r = prep(path.join(tmp, 'not-created'), ...ack);
    check(r.exit === 1 && /does not create it/.test(r.out) && !fs.existsSync(path.join(tmp, 'not-created')) && unchanged(),
      'Windows: a missing folder is refused, because the gate cannot restrict the ACL of a folder it creates');
  } else {
    // The reported case: an existing 0755 folder and a 0644 original.
    const loose = path.join(tmp, 'loose-private'); fs.mkdirSync(loose); fs.chmodSync(loose, 0o755);
    fs.chmodSync(path.join(repo, 'report.md'), 0o644);
    r = prep(loose);
    check(r.exit === 1 && /chmod 700/.test(r.out) && mode(loose) === 0o755 && fs.readdirSync(loose).length === 0 && unchanged() && mode(path.join(repo, 'report.md')) === 0o644,
      'POSIX: an existing 0755 folder is refused and left unchanged and empty; the 0644 original stays in place, unsanitized');
    if (process.geteuid() === 0) {
      const foreign = path.join(tmp, 'foreign-private'); fs.mkdirSync(foreign, { mode: 0o700 }); fs.chownSync(foreign, 65534, 65534);
      r = prep(foreign);
      check(r.exit === 1 && /belong to this account/.test(r.out) && unchanged() && fs.readdirSync(foreign).length === 0, 'POSIX: a private folder owned by another account is refused');
    }
    const linkPlanted = restrict(path.join(tmp, 'link-planted'));
    write('victim.txt', 'victim\n', tmp);
    fs.symlinkSync(path.join(tmp, 'victim.txt'), path.join(linkPlanted, 'report.md'));
    r = prep(linkPlanted);
    check(r.exit === 1 && fs.readFileSync(path.join(tmp, 'victim.txt'), 'utf8') === 'victim\n' && unchanged(), 'POSIX: a link planted at the private destination is never followed');
  }
  const planted = restrict(path.join(tmp, 'planted-private'));
  write('report.md', 'planted\n', planted);
  r = prep(planted, ...ack);
  check(r.exit === 1 && /already exists/.test(r.out) && fs.readFileSync(path.join(planted, 'report.md'), 'utf8') === 'planted\n' && unchanged(),
    'Exclusive creation: an existing file at the private destination is never overwritten, and no worktree file is replaced');
  if (win) restrict(keep); // POSIX: the gate creates the missing folder itself, at 0700
  const originalMode = mode(path.join(repo, 'report.md'));
  r = prep(keep, ...ack);
  check(r.exit === 0 && fs.readFileSync(path.join(repo, 'report.md'), 'utf8') === SANITIZED && fs.readFileSync(path.join(keep, 'report.md'), 'utf8') === UNREDACTED,
    'prepare-sanitized installs the reviewed sanitized copy and keeps the original byte-for-byte outside the repository');
  const manifest = fs.readdirSync(keep).find(f => f.startsWith('MANIFEST-'));
  if (win) {
    check(/confirmed by the operator \(not verified by the gate\)/.test(fs.readFileSync(path.join(keep, manifest), 'utf8')) && /not verified by the gate/.test(r.out),
      'Windows: the manifest and output say the ACL was confirmed by the operator, not verified by the gate');
  } else {
    check(originalMode === 0o644 && mode(keep) === 0o700 && mode(path.join(keep, 'report.md')) === 0o600 && mode(path.join(keep, manifest)) === 0o600
      && fs.statSync(path.join(keep, 'report.md')).uid === process.geteuid(), 'POSIX: the gate creates the folder at 0700 and keeps the 0644 original at 0600 (manifest 0600), owned by this account');
  }

  r = gate('check');
  check(r.exit === 0 && /CHECK PASS/.test(r.out) && staged().length === 0, 'check passes on the sanitized tree and stages nothing');
  git('add', '--', 'unreviewed-notes.md');
  r = gate('stage');
  check(r.exit === 1 && /already has staged changes/.test(r.out), 'stage refuses an index that already holds staged changes');
  git('reset', '-q');
  r = gate('stage');
  const s = staged();
  check(r.exit === 0 && JSON.stringify(s) === JSON.stringify(['app.php', 'maintenance/release/7r-commit-allowlist.tsv', 'report.md']), 'stage adds exactly the allowlist: ' + JSON.stringify(s));
  check(!s.some(f => /screenshot|config\.local|cleanup|unreviewed|commit-gate\.cjs/.test(f)), 'Screenshots, config.local.php, cleanup evidence and unreviewed files are left out (unlike git add -A)');
  check(git('show', ':report.md') === SANITIZED.trim() && git('rev-parse', 'HEAD') === baseline, 'The staged report is the sanitized blob, and nothing was committed');
  git('reset', '-q');
  // Hundreds of files must not hit the Windows command-line limit (the real 7R commit has more than 500).
  const bulk = Array.from({ length: 600 }, (_, i) => `evidence/runs/r${String(i).padStart(3, '0')}/receipt.json`);
  bulk.forEach((f, i) => write(f, `{"run":${i},"exit":0}\n`));
  const bulkOids = cp.execFileSync('git', ['-c', 'core.autocrlf=false', 'hash-object', '--stdin-paths'], { cwd: repo, env, input: bulk.join('\n') + '\n', encoding: 'utf8' }).trim().split('\n');
  allowlist([...good, ...bulk.map((f, i) => `${bulkOids[i]}\t${f}`)]);
  r = gate('stage');
  check(r.exit === 0 && staged().length === 603, 'stage verifies more than 600 files without exceeding the command-line limit: ' + staged().length);
  git('reset', '-q'); fs.rmSync(path.join(repo, 'evidence/runs'), { recursive: true }); allowlist(good);

  write('app.php', '<?php // edited after review\n');
  r = gate('check');
  check(r.exit === 1 && /differs from the reviewed version/.test(r.out), 'A file edited after review is refused until its pin is deliberately updated');
  write('app.php', '<?php // reviewed change; contact admin@example.test\n');
  write('deny.txt', '# private terms\nreviewer a\n', tmp);
  r = gate('stage', '--deny-terms=' + path.join(tmp, 'deny.txt'));
  check(r.exit === 1 && /private deny-term/.test(r.out) && staged().length === 0 && !r.out.toLowerCase().includes('reviewer a'), 'A private deny-term in the content is refused without printing the term; nothing stays staged');
  write('deny.txt', 'x\n');
  r = gate('check', '--deny-terms=' + path.join(repo, 'deny.txt'));
  check(r.exit === 1 && /outside the repository/.test(r.out), 'The deny-terms file must live outside the repository');
  fs.rmSync(path.join(repo, 'deny.txt'));

  allowlist([...good, `${oid('app.php')}\t../escape.php`]);
  check(gate('check').exit === 1, 'An allowlist path that leaves the repository is refused');
  allowlist([...good, `${oid('screenshot.png')}\tscreenshot.png`]);
  r = gate('check');
  check(r.exit === 1 && /binary, dump, log or key file/.test(r.out), 'A forbidden file type is refused even when allowlisted');
  allowlist([good[1]]);
  r = gate('check');
  check(r.exit === 1 && /app\.php: tracked change is not in the allowlist/.test(r.out), 'A tracked change missing from the allowlist is refused (no inconsistent partial commit)');
  allowlist(good);
  git('checkout', '-q', '-b', 'other-branch');
  check(gate('check').exit === 1, 'The gate refuses a branch other than the reviewed one');
  git('checkout', '-q', 'remediation/test');
  write('app.php', '<?php // reviewed change; contact ' + ['real.person', 'gmail.com'].join('@') + '\n'); // assembled so this file holds no address
  allowlist([`${oid('app.php')}\tapp.php`, good[1]]);
  r = gate('check');
  check(r.exit === 1 && /email address outside the synthetic domains/.test(r.out) && !r.out.includes('gmail'), 'A real email address is refused without being printed');
  console.log(JSON.stringify({ status: 'PASS', assertions: checks.length }));
} finally {
  fs.rmSync(tmp, { recursive: true, force: true });
}
