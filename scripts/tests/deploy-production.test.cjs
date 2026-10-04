const assert = require('node:assert/strict')
const { spawnSync, execFileSync } = require('node:child_process')
const { readFileSync, writeFileSync, mkdtempSync, mkdirSync, readdirSync, rmSync, statSync } = require('node:fs')
const { resolve, join, sep } = require('node:path')
const { test } = require('node:test')

const root = resolve(__dirname, '../..')
const bash = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash'
const sha = 'a'.repeat(40)
const previous = 'b'.repeat(40)
const source = readFileSync(join(root, 'scripts/deploy-production.sh'), 'utf8')

function fixture(t, options = {}) {
  const directory = mkdtempSync(join(__dirname, '.tmp-deploy-'))
  t.after(() => {
    assert.ok(resolve(directory).startsWith(`${resolve(__dirname)}${sep}`))
    rmSync(directory, { recursive: true, force: true })
  })
  const posix = execFileSync(bash, ['-c', 'pwd -P'], { cwd: directory, encoding: 'utf8' }).trim()
  mkdirSync(join(directory, 'bin'))
  writeFileSync(join(directory, 'commands.log'), '')
  writeFileSync(join(directory, '.env.production'), 'PRESERVE_FAKE_ENV=true\n')
  mkdirSync(join(directory, 'backups'))
  writeFileSync(join(directory, 'backups/existing.dump'), 'PRESERVE_FAKE_BACKUP')
  // Only substitute the fixed application directory in a disposable copy; never execute against production.
  assert.equal(source.split('readonly APP_DIR=/opt/apps/contentgenius').length, 2)
  writeFileSync(join(directory, 'deploy.sh'), source.replace('readonly APP_DIR=/opt/apps/contentgenius', `readonly APP_DIR='${posix}'`))
  const commands = {
    git: `#!/usr/bin/env bash
set -eu
printf 'git %s\\n' "$*" >> "$MOCK_LOG"
case "$*" in
  'rev-parse --show-toplevel') pwd -P ;;
  'symbolic-ref --quiet --short HEAD') printf '%s\\n' "\${MOCK_BRANCH:-main}" ;;
  'status --porcelain --untracked-files=no') printf '%s' "\${MOCK_DIRTY:-}" ;;
  'remote get-url origin') printf '%s\\n' "\${MOCK_ORIGIN:-https://github.com/vyacheslavhulyaev-glitch/content-genius.git}" ;;
  'rev-parse HEAD') if [[ -f "$MOCK_CHECKED_OUT" ]]; then printf '%s\\n' "\${MOCK_FINAL_SHA:-$MOCK_SHA}"; else printf '%s\\n' "$MOCK_PREVIOUS"; fi ;;
  fetch*) [[ "\${MOCK_FAIL:-}" != fetch ]] ;;
  cat-file*) [[ "\${MOCK_FAIL:-}" != missing-sha ]] ;;
  'merge-base --is-ancestor HEAD '*) [[ "\${MOCK_FAIL:-}" != stale ]] ;;
  'merge-base --is-ancestor '*) [[ "\${MOCK_FAIL:-}" != foreign-sha ]] ;;
  'merge --ff-only '*) printf 'checkout-umask %s\\n' "$(umask)" >> "$MOCK_LOG"; touch "$MOCK_CHECKED_OUT" ;;
  *) printf 'Unexpected git command: %s\\n' "$*" >&2; exit 90 ;;
esac
`,
    docker: `#!/usr/bin/env bash
set -eu
printf 'docker %s\\n' "$*" >> "$MOCK_LOG"
case "$*" in
  *' config --quiet') [[ "\${MOCK_FAIL:-}" != config ]] ;;
  *' build web') printf 'build-umask %s\\n' "$(umask)" >> "$MOCK_LOG"; [[ "\${MOCK_FAIL:-}" != build ]] ;;
  *' stop web') : ;;
  *' exec -T db sh -c '*)
    if [[ "\${MOCK_FAIL:-}" == dump ]]; then printf 'PGDMP-partial'; exit 1; fi
    if [[ "\${MOCK_FAIL:-}" != empty-dump ]]; then printf 'PGDMP-fake-archive'; fi ;;
  *' exec -T db pg_restore --list') cat >/dev/null; [[ "\${MOCK_FAIL:-}" != invalid-archive ]] ;;
  *' run --rm --no-deps web php artisan migrate --force --no-interaction') [[ "\${MOCK_FAIL:-}" != migrate ]] ;;
  *' up -d --no-deps --force-recreate --wait --wait-timeout 180 web') [[ "\${MOCK_FAIL:-}" != recreate ]] ;;
  *' ps -q web') printf 'fake-web-id\\n' ;;
  *' ps') : ;;
  'inspect --format {{.State.Health.Status}} fake-web-id') printf '%s\\n' "\${MOCK_HEALTH:-healthy}" ;;
  *) printf 'Unexpected docker command: %s\\n' "$*" >&2; exit 90 ;;
esac
`,
    flock: `#!/usr/bin/env bash
set -eu
printf 'flock %s\\n' "$*" >> "$MOCK_LOG"
[[ "\${MOCK_FAIL:-}" != lock ]]
`,
    curl: `#!/usr/bin/env bash
set -eu
printf 'curl %s\\n' "$*" >> "$MOCK_LOG"
[[ "\${MOCK_FAIL:-}" != public-health ]]
printf '%s' "\${MOCK_PUBLIC_HEALTH:-{\\"status\\":\\"ok\\"}}"
`,
    chmod: '#!/usr/bin/env bash\nprintf "chmod %s\\n" "$*" >> "$MOCK_LOG"\nexec /usr/bin/chmod "$@"\n',
    install: '#!/usr/bin/env bash\nprintf "install %s\\n" "$*" >> "$MOCK_LOG"\nexec /usr/bin/install "$@"\n',
  }
  for (const [name, contents] of Object.entries(commands)) writeFileSync(join(directory, 'bin', name), contents, { mode: 0o755 })
  const env = { ...process.env, MOCK_LOG: `${posix}/commands.log`,
    MOCK_CHECKED_OUT: `${posix}/checked-out`, MOCK_SHA: sha, MOCK_PREVIOUS: previous, ...options }
  const result = spawnSync(bash, ['-c', 'umask 077; export PATH="$PWD/bin:$PATH"; exec bash ./deploy.sh "$MOCK_SHA"'], { cwd: directory, env, encoding: 'utf8' })
  const log = readFileSync(join(directory, 'commands.log'), 'utf8')
  assert.equal(readFileSync(join(directory, '.env.production'), 'utf8'), 'PRESERVE_FAKE_ENV=true\n')
  assert.equal(readFileSync(join(directory, 'backups/existing.dump'), 'utf8'), 'PRESERVE_FAKE_BACKUP')
  return { result, log, directory }
}

test('verified SHA deployment orders lock, fetch, exact checkout, build, backup, migration, web recreation and health', t => {
  const { result, log, directory } = fixture(t)
  assert.equal(result.status, 0, result.stderr)
  const order = ['flock --exclusive --wait 900 9', 'git fetch --no-tags', `git merge --ff-only ${sha}`, 'config --quiet',
    'build web', 'stop web', 'pg_dump', 'pg_restore --list', 'artisan migrate --force', 'up -d --no-deps', 'inspect --format', 'curl ']
  const positions = order.map(value => { const index = log.indexOf(value); assert.ok(index >= 0, value); return index })
  assert.deepEqual(positions, [...positions].sort((a, b) => a - b))
  const backups = readdirSync(join(directory, 'backups')).filter(name => name.startsWith('pre-deploy-') && name.endsWith('.dump'))
  assert.equal(backups.length, 1)
  assert.ok(backups[0].includes(sha))
  assert.match(result.stdout, new RegExp(`succeeded for ${sha}`))
  assert.doesNotMatch(log, /db:seed|migrate:fresh|migrate:refresh|clean|reset|down|up .* db|stop db/)
})

test('checkout and build override inherited restrictive umask while dump files have explicit private permissions', t => {
  const { result, log, directory } = fixture(t)
  assert.equal(result.status, 0, result.stderr)
  assert.match(log, /checkout-umask 0022/)
  assert.match(log, /build-umask 0022/)
  assert.doesNotMatch(source, /^\s*umask\s+0?77\b/m)
  const backup = readdirSync(join(directory, 'backups')).find(name => name.startsWith('pre-deploy-') && name.endsWith('.dump'))
  const protectPartial = `install -m 600 /dev/null backups/${backup}.partial`
  const protectFinal = `chmod 600 backups/${backup}`
  assert.ok(log.indexOf(protectPartial) >= 0 && log.indexOf(protectPartial) < log.indexOf('pg_dump'))
  assert.ok(log.indexOf(protectFinal) > log.indexOf('pg_restore --list'))
  assert.ok(log.indexOf(protectFinal) < log.indexOf('artisan migrate'))
  assert.match(log, /chmod 700 backups/)
  if (process.platform !== 'win32') {
    assert.equal(statSync(join(directory, 'backups')).mode & 0o777, 0o700)
    assert.equal(statSync(join(directory, 'backups', backup)).mode & 0o777, 0o600)
  }
})

test('invalid input exits before touching repository or Docker', () => {
  for (const input of ['main', 'a'.repeat(39), 'abc; echo injected']) {
    const result = spawnSync(bash, ['-s', '--', input], { input: source, encoding: 'utf8' })
    assert.equal(result.status, 1)
    assert.match(result.stderr, /Usage:/)
  }
})

test('dirty checkout, wrong branch/origin and unavailable lock stop before checkout or Docker', t => {
  for (const options of [{ MOCK_DIRTY: ' M tracked-file' }, { MOCK_BRANCH: 'feature' }, { MOCK_ORIGIN: 'https://example.test/unrelated' }, { MOCK_FAIL: 'lock' }]) {
    const { result, log } = fixture(t, options)
    assert.notEqual(result.status, 0)
    assert.doesNotMatch(log, /git merge --ff-only|docker /)
  }
})

test('fetch failure, absent/foreign/stale SHA and wrong resulting HEAD stop before build', t => {
  for (const options of ['fetch', 'missing-sha', 'foreign-sha', 'stale'].map(MOCK_FAIL => ({ MOCK_FAIL })).concat([{ MOCK_FINAL_SHA: previous }])) {
    const { result, log } = fixture(t, options)
    assert.notEqual(result.status, 0)
    assert.doesNotMatch(log, /docker /)
  }
})

test('config and build failures leave the existing HTTP container running', t => {
  for (const MOCK_FAIL of ['config', 'build']) {
    const { result, log } = fixture(t, { MOCK_FAIL })
    assert.notEqual(result.status, 0)
    assert.doesNotMatch(log, /stop web|pg_dump|artisan migrate|up -d/)
  }
})

test('failed, empty or invalid backups prevent migrations and cannot become verified dumps', t => {
  for (const MOCK_FAIL of ['dump', 'empty-dump', 'invalid-archive']) {
    const { result, log, directory } = fixture(t, { MOCK_FAIL })
    assert.notEqual(result.status, 0)
    assert.doesNotMatch(log, /artisan migrate|up -d/)
    assert.equal(readdirSync(join(directory, 'backups')).filter(name => name.startsWith('pre-deploy-') && name.endsWith('.dump')).length, 0)
    const partial = readdirSync(join(directory, 'backups')).find(name => name.endsWith('.partial'))
    assert.ok(partial)
    assert.match(log, /install -m 600 \/dev\/null backups\/pre-deploy-/)
    if (process.platform !== 'win32') assert.equal(statSync(join(directory, 'backups', partial)).mode & 0o777, 0o600)
  }
})

test('production stage normalizes restrictive source modes and limits writable ownership to storage/cache', t => {
  const dockerfile = readFileSync(join(root, 'Dockerfile'), 'utf8')
  const production = dockerfile.split('\nFROM php-runtime AS production\n')[1]
  const instructions = production.match(/^RUN ([\s\S]*?)\nUSER www-data/m)[1].replace(/\\\n/g, ' ')
  const directory = mkdtempSync(join(__dirname, '.tmp-permissions-'))
  t.after(() => {
    assert.ok(resolve(directory).startsWith(`${resolve(__dirname)}${sep}`))
    rmSync(directory, { recursive: true, force: true })
  })
  mkdirSync(join(directory, 'app'), { mode: 0o700 })
  mkdirSync(join(directory, 'bin'))
  writeFileSync(join(directory, 'composer.json'), '{}', { mode: 0o600 })
  writeFileSync(join(directory, 'app/Example.php'), '<?php', { mode: 0o600 })
  writeFileSync(join(directory, 'artisan'), '#!/usr/bin/env php', { mode: 0o700 })
  // Execute the actual RUN permissions block. Ownership is asserted through a spy;
  // effective ownership/access is additionally verified in the optional real image test.
  writeFileSync(join(directory, 'bin/chown'), '#!/bin/bash\nprintf "%s\\n" "$*" >> chown.log\n', { mode: 0o755 })
  const result = spawnSync(bash, ['-s'], { cwd: directory, encoding: 'utf8',
    input: `set -eu\nexport PATH="$PWD/bin:$PATH"\n${instructions.replaceAll('/var/www/html', '"$PWD"')}\n` })
  assert.equal(result.status, 0, result.stderr)
  const ownership = readFileSync(join(directory, 'chown.log'), 'utf8').trim().split('\n')
  assert.equal(ownership.length, 2)
  assert.match(ownership[0], /^-R root:root /)
  assert.equal(ownership[1], '-R www-data:www-data storage bootstrap/cache')
  if (process.platform !== 'win32') {
    assert.equal(statSync(join(directory, 'composer.json')).mode & 0o777, 0o644)
    assert.equal(statSync(join(directory, 'app/Example.php')).mode & 0o777, 0o644)
    for (const path of ['.', 'app', 'artisan']) assert.equal(statSync(join(directory, path)).mode & 0o777, 0o755)
    for (const path of ['storage', 'bootstrap/cache']) assert.equal(statSync(join(directory, path)).mode & 0o777, 0o770)
  }
  assert.match(dockerfile, /COPY --chmod=644 docker\/apache\.conf/)
  assert.match(dockerfile, /COPY --chmod=644 docker\/php\.ini/)
  assert.match(readFileSync(join(root, '.dockerignore'), 'utf8'), /^\.env\.\*$/m)
})

test('real production image is readable but source is not writable as www-data', {
  skip: !process.env.CONTENTGENIUS_PERMISSION_TEST_IMAGE && 'Set CONTENTGENIUS_PERMISSION_TEST_IMAGE to a locally built restrictive-context image',
}, () => {
  const checks = `set -eu
test "$(id -un)" = www-data
test -r /var/www/html/composer.json
php -r 'json_decode(file_get_contents("/var/www/html/composer.json"), true, 512, JSON_THROW_ON_ERROR); require "/var/www/html/vendor/autoload.php";'
test -r /etc/apache2/sites-available/000-default.conf
test -r /usr/local/etc/php/conf.d/contentgenius.ini
test -w /var/www/html/storage
test -w /var/www/html/bootstrap/cache
test -z "$(find /var/www/html -path /var/www/html/storage -prune -o -path /var/www/html/bootstrap/cache -prune -o -writable -print)"
test -z "$(find /var/www/html -path /var/www/html/storage -prune -o -path /var/www/html/bootstrap/cache -prune -o \\( ! -user root -o ! -group root \\) -print)"
test -z "$(find /var/www/html -name '.env*' -print)"
printf 'Production image permissions verified as www-data\\n'
`
  const output = execFileSync('docker', ['run', '--rm', '--network', 'none', '--user', 'www-data',
    '--entrypoint', '/bin/sh', process.env.CONTENTGENIUS_PERMISSION_TEST_IMAGE, '-c', checks], { encoding: 'utf8' })
  assert.match(output, /Production image permissions verified as www-data/)
})

test('migration failure prevents admission of the new web container and never rolls back the DB', t => {
  const { result, log } = fixture(t, { MOCK_FAIL: 'migrate' })
  assert.notEqual(result.status, 0)
  assert.doesNotMatch(log, /up -d|rollback|pg_restore .*--clean|down/)
})

test('container recreation, container health and public HTTP/JSON failures fail deployment visibly', t => {
  for (const options of [{ MOCK_FAIL: 'recreate' }, { MOCK_HEALTH: 'unhealthy' }, { MOCK_FAIL: 'public-health' }, { MOCK_PUBLIC_HEALTH: '{"status":"error"}' }]) {
    const { result } = fixture(t, options)
    assert.notEqual(result.status, 0)
    assert.doesNotMatch(result.stdout, /Deployment and public health verification succeeded/)
  }
})
