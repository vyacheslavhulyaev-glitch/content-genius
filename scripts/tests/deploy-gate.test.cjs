const assert = require('node:assert/strict')
const { spawnSync, execFileSync } = require('node:child_process')
const { readFileSync, writeFileSync, mkdtempSync, mkdirSync, readdirSync, rmSync, existsSync } = require('node:fs')
const { resolve, join, sep } = require('node:path')
const { test } = require('node:test')

const root = resolve(__dirname, '../..')
const bash = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash'
const sha = 'a'.repeat(40)
const source = readFileSync(join(root, 'scripts/contentgenius-deploy-gate.sh'), 'utf8')

function fixture(t, { command = `contentgenius-deploy ${sha}`, input = '', args = [], ...options } = {}) {
  const directory = mkdtempSync(join(__dirname, '.tmp-gate-'))
  t.after(() => {
    assert.ok(resolve(directory).startsWith(`${resolve(__dirname)}${sep}`))
    rmSync(directory, { recursive: true, force: true })
  })
  const posix = execFileSync(bash, ['-c', 'pwd -P'], { cwd: directory, encoding: 'utf8' }).trim()
  mkdirSync(join(directory, 'bin'))
  mkdirSync(join(directory, 'scripts'))
  writeFileSync(join(directory, 'commands.log'), '')
  writeFileSync(join(directory, 'stdin.log'), '')
  writeFileSync(join(directory, 'scripts/deploy-production.sh'), 'touch "$MOCK_DIRECTORY/untrusted-executed"\n')
  writeFileSync(join(directory, 'verified-script.sh'), '#!/bin/bash\nset -eu\nprintf "deploy %s\\n" "$1" >> "$MOCK_LOG"\ncat >> "$MOCK_DIRECTORY/stdin.log"\nexit "${MOCK_DEPLOY_EXIT:-0}"\n')
  // Substitute paths only in a disposable test copy; all Git/network operations are mocked.
  assert.equal(source.split('readonly APP_DIR=/opt/apps/contentgenius').length, 2)
  assert.equal(source.split('readonly PATH=/usr/bin:/bin').length, 2)
  writeFileSync(join(directory, 'gate.sh'), source
    .replace('readonly APP_DIR=/opt/apps/contentgenius', `readonly APP_DIR='${posix}'`)
    .replace('readonly PATH=/usr/bin:/bin', `readonly PATH='${posix}/bin:/usr/bin:/bin'`)
    .replace('/tmp/contentgenius-deploy.XXXXXXXXXX', `${posix}/contentgenius-deploy.XXXXXXXXXX`))
  writeFileSync(join(directory, 'bin/git'), `#!/bin/bash
set -eu
printf 'git %s\\n' "$*" >> "$MOCK_LOG"
case "$*" in
  'rev-parse --show-toplevel') pwd -P ;;
  'remote get-url origin') printf '%s\\n' "\${MOCK_ORIGIN:-https://github.com/vyacheslavhulyaev-glitch/content-genius.git}" ;;
  'fetch --no-tags origin refs/heads/main:refs/remotes/origin/main') [[ "\${MOCK_FAIL:-}" != fetch ]] ;;
  'cat-file -e '*) [[ "\${MOCK_FAIL:-}" != missing-sha ]] ;;
  'cat-file -t '*) printf '%s\\n' "\${MOCK_OBJECT_TYPE:-commit}" ;;
  'merge-base --is-ancestor '*) [[ "\${MOCK_FAIL:-}" != foreign-sha ]] ;;
  'show --no-ext-diff --no-textconv '*)
    case "\${MOCK_FAIL:-}" in
      show) printf 'touch "$MOCK_DIRECTORY/untrusted-executed"\\n'; exit 1 ;;
      empty-script) : ;;
      invalid-script) printf 'if then\\n' ;;
      *) cat "$MOCK_DIRECTORY/verified-script.sh" ;;
    esac ;;
  *) printf 'Unexpected git command: %s\\n' "$*" >&2; exit 90 ;;
esac
`, { mode: 0o755 })
  const result = spawnSync(bash, ['gate.sh', ...args], { cwd: directory,
    env: { ...process.env, SSH_ORIGINAL_COMMAND: command, MOCK_DIRECTORY: posix, MOCK_LOG: `${posix}/commands.log`, ...options },
    input, encoding: 'utf8' })
  assert.equal(existsSync(join(directory, 'untrusted-executed')), false)
  assert.equal(readFileSync(join(directory, 'stdin.log'), 'utf8'), '')
  assert.equal(readdirSync(directory).some(name => name.startsWith('contentgenius-deploy.')), false)
  return { result, log: readFileSync(join(directory, 'commands.log'), 'utf8') }
}

test('gate rejects shells, arbitrary commands, command injection, subsystems and streamed shell content', t => {
  for (const command of ['', 'id', 'bash', `bash -s -- '${sha}'`, 'sftp', 'scp -t /tmp/file',
    `contentgenius-deploy ${sha}; id`, `contentgenius-deploy ${sha} && id`,
    `contentgenius-deploy ${sha}\nid`, 'contentgenius-deploy $(id)']) {
    const { result, log } = fixture(t, { command, input: 'touch "$MOCK_DIRECTORY/untrusted-executed"\n' })
    assert.notEqual(result.status, 0)
    assert.match(result.stderr, /Rejected SSH command/)
    assert.equal(log, '')
  }
})

test('gate accepts exactly one lowercase full SHA with one separator and no extra arguments', t => {
  for (const command of [`contentgenius-deploy ${'a'.repeat(39)}`, `contentgenius-deploy ${'a'.repeat(41)}`,
    `contentgenius-deploy ${'A'.repeat(40)}`, `contentgenius-deploy ${'g'.repeat(40)}`,
    'contentgenius-deploy main', `contentgenius-deploy  ${sha}`, `contentgenius-deploy\t${sha}`,
    ` contentgenius-deploy ${sha}`, `contentgenius-deploy ${sha} `, `contentgenius-deploy ${sha}\n`,
    `contentgenius-deploy ${sha} extra`, `other-deploy ${sha}`]) {
    const { result, log } = fixture(t, { command })
    assert.notEqual(result.status, 0)
    assert.equal(log, '')
  }
  const { result, log } = fixture(t, { args: ['unexpected'] })
  assert.notEqual(result.status, 0)
  assert.equal(log, '')
})

test('valid gate request executes only the exact main Git object and ignores client stdin and working-tree script', t => {
  const { result, log } = fixture(t, { input: 'touch "$MOCK_DIRECTORY/untrusted-executed"\n' })
  assert.equal(result.status, 0, result.stderr)
  const order = ['remote get-url origin', 'fetch --no-tags origin', `cat-file -e ${sha}^{commit}`,
    `merge-base --is-ancestor ${sha} origin/main`, `show --no-ext-diff --no-textconv ${sha}:scripts/deploy-production.sh`, `deploy ${sha}`]
  const positions = order.map(value => { const index = log.indexOf(value); assert.ok(index >= 0, value); return index })
  assert.deepEqual(positions, [...positions].sort((a, b) => a - b))
})

test('wrong repository, failed fetch, missing/non-commit object and commit outside main cannot execute deployment code', t => {
  for (const options of [{ MOCK_ORIGIN: 'https://example.test/unrelated' },
    { MOCK_OBJECT_TYPE: 'tag' }, { MOCK_OBJECT_TYPE: 'blob' },
    ...['fetch', 'missing-sha', 'foreign-sha'].map(MOCK_FAIL => ({ MOCK_FAIL }))]) {
    const { result, log } = fixture(t, options)
    assert.notEqual(result.status, 0)
    assert.doesNotMatch(log, /git show|\ndeploy /)
  }
})

test('failed Git show, empty or invalid script cannot execute partial content and temporary files are cleaned', t => {
  for (const MOCK_FAIL of ['show', 'empty-script', 'invalid-script']) {
    const { result, log } = fixture(t, { MOCK_FAIL })
    assert.notEqual(result.status, 0)
    assert.doesNotMatch(log, /\ndeploy /)
  }
})

test('gate preserves deployment failure exit status', t => {
  const { result } = fixture(t, { MOCK_DEPLOY_EXIT: '23' })
  assert.equal(result.status, 23)
})

test('gate setup documents root ownership and both authorized-key restrictions', () => {
  const docs = readFileSync(join(root, 'docs/github-actions-deploy.md'), 'utf8')
  assert.match(source, /^#!\/bin\/bash\n/)
  assert.match(source, /readonly PATH=\/usr\/bin:\/bin/)
  assert.doesNotMatch(source, /\beval\b|bash\s+-s|source\s+/)
  assert.match(docs, /sudo install -o root -g root -m 755/)
  assert.match(docs, /restrict,command="\/usr\/local\/sbin\/contentgenius-deploy-gate" REPLACE_WITH_COMPLETE_PUBLIC_KEY_LINE/)
})
