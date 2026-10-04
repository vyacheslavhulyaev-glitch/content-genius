# GitHub Actions CI and production deployment

The workflows are configured in this repository. Their first real GitHub run and
SSH production deployment still require manual setup and verification after commit.
This milestone does not deploy, generate production keys, change server secrets,
seed accounts, or change the existing Docker/Caddy architecture.

## CI contract

`.github/workflows/ci.yml` is named **CI** and runs for every pull request targeting
`main` and every push to `main`, without path exclusions. It has two required jobs:

| Job | Checks |
| --- | --- |
| Backend | Ubuntu 24.04, PHP 8.4, Composer lock install/strict validation, test-only SQLite/array configuration, application key generation, PHPUnit and Pint |
| Frontend | Ubuntu 24.04, Node 24, `frontend/npm ci`, tests, ESLint and production build |

Backend also runs YAML lint through the existing Symfony dependency, Bash syntax
checks, Node deployment-safety tests, optional ShellCheck and `git diff --check`.
Frontend tests run sequentially to avoid contention between existing Vite test
servers sharing their dependency optimization cache. Composer caches downloaded
packages, not `vendor`; npm caches downloads using the frontend lock file.

CI has read-only repository permissions, no production secrets, and an empty
OpenAI key. Application tests use provider fakes. Nothing runs Generate/Regenerate
against production or supplies a real provider key. Action versions are pinned
to reviewed full commit SHAs, with release versions in comments.

## Verified-commit deployment

`.github/workflows/deploy.yml` is named **Deploy production**. A successful CI
`workflow_run` triggers it only for a `push` on this repository's `main` branch.
Pull requests, forks and failed CI cannot reach the deploy job.

Before accessing production secrets, the verification job uses the read-only
GitHub Actions API to confirm the exact `ci.yml` run, repository, branch, event,
completed/success status and SHA, and successful **Backend**/**Frontend** jobs
for that SHA. Checkout and the SSH deployment request then use this verified SHA,
never the possibly newer default branch SHA from the `workflow_run` context.
No PR artifacts or PR caches are consumed by deployment.

Actions sends only `contentgenius-deploy <verified-sha>` with SSH stdin disabled.
The dedicated key's forced command is the root-owned server gate, not a shell
supplied by Actions. The gate validates `SSH_ORIGINAL_COMMAND`, fetches the
expected repository's main branch, verifies commit existence and main ancestry,
then loads `scripts/deploy-production.sh` from that exact Git object. It never
evaluates the original command or executes client-provided script content.

Manual **Run workflow** is supported from `main`. Supply a full 40-character
`commit_sha` for a retry/redeploy, or leave it empty to use the selected main
revision. That SHA must already have a successful push CI run and both required
jobs. Manual dispatch does not bypass validation or implement rollback. If CI
has not completed, rerun deployment after it succeeds; no unverified revision
is admitted. CI runs that predate these workflows do not qualify.

## GitHub setup

Create the **production** Environment manually under repository Settings →
Environments. Configure these five secrets there (repository secrets are also
supported by the workflow, but keep the private key only in the production
Environment secret). Do not commit their values or put them in logs.

| Secret | Value to supply manually |
| --- | --- |
| `DEPLOY_HOST` | Actual SSH DNS name or IPv4 address, without a scheme |
| `DEPLOY_PORT` | Actual numeric SSH port; required, including when using port 22 |
| `DEPLOY_USER` | Existing server account with checkout and Docker access |
| `DEPLOY_SSH_KEY` | Complete dedicated, unencrypted OpenSSH private key |
| `DEPLOY_KNOWN_HOSTS` | Host-key line(s), independently fingerprint-verified for the exact host/port |

No production application/database/OpenAI credentials are needed in GitHub.
They remain in the existing protected `.env.production` on the server.

Environment protection is not assumed to exist. Before enabling the first real
deployment, configure allowed deployment branches to `main` and, if available
for your plan/repository, required reviewers. After the first CI run, configure
main branch protection to require **Backend** and **Frontend**. These settings
must be applied manually; no repository-settings API is used here.

Until the secrets and forced-command gate are configured, deployment cannot
succeed. Do not interpret a checked-in workflow or a successful local test as
successful production CD.

## Forced-command gate: one-time manual server setup

After the gate code has been reviewed and committed to main, use an existing
trusted administrative server session. Replace the SHA below with that reviewed
full main SHA. This installs a separate root-owned copy without advancing the
production checkout or deploying the application:

```sh
set -Eeuo pipefail
cd /opt/apps/contentgenius
GATE_SHA=REPLACE_WITH_FULL_REVIEWED_MAIN_SHA
[[ "$GATE_SHA" =~ ^[a-f0-9]{40}$ ]]
git fetch --no-tags origin refs/heads/main:refs/remotes/origin/main
git cat-file -e "$GATE_SHA^{commit}"
git merge-base --is-ancestor "$GATE_SHA" origin/main
gate_file=$(mktemp)
trap 'rm -f -- "$gate_file"' EXIT
git show "$GATE_SHA:scripts/contentgenius-deploy-gate.sh" > "$gate_file"
test -s "$gate_file"
bash -n "$gate_file"
sudo install -o root -g root -m 755 "$gate_file" /usr/local/sbin/contentgenius-deploy-gate
sudo chmod 755 /usr/local/sbin/contentgenius-deploy-gate
stat -c '%U:%G %a %n' /usr/local/sbin/contentgenius-deploy-gate
```

Expect `root:root 755 /usr/local/sbin/contentgenius-deploy-gate`. Its parent
directory must also be root-owned and not writable by the deploy user. The gate
runs as the existing deploy user; the forced command does not invoke sudo.
Root ownership prevents direct edits to the installed entry point. Later
gate updates require the same explicit review and manual root installation;
normal deployments do not replace it from the working tree.

Only `contentgenius-deploy ` followed by exactly 40 lowercase hex characters is
accepted. Empty commands, shells, SCP/SFTP, extra arguments/whitespace, newlines
and shell operators are rejected before Git access. The gate checks origin and
main membership, closes client stdin, writes the complete Git blob to a private
temporary file, checks Bash syntax, executes it with the SHA, and removes it on
exit. Failed fetch/object lookup/ancestry/script extraction prevents execution;
tag/blob object IDs are rejected because the requested object must be a commit.

Actions still enforces successful CI. The gate independently limits key access
to deployment code already committed to this repository's main branch; it does
not query CI or protect against malicious code merged into main. Protect main
and review deployment code because the deploy user retains Docker privileges.
The repository script rechecks SHA/branch/clean state and retains its lock,
backup, migration and health safeguards. Do not replace the forced command with
a deploy-user-writable gate or script path.

## Dedicated SSH key: manual setup only

Use a separate key for Actions, not Viacheslav's personal workstation key.
On Windows PowerShell, outside the repository:

```powershell
ssh-keygen -t ed25519 -f "$env:USERPROFILE\.ssh\contentgenius-actions" -C "contentgenius-github-actions"
Get-Content "$env:USERPROFILE\.ssh\contentgenius-actions.pub"
```

At the key-generation prompts, leave the passphrase empty for this unattended
workflow. If that filename already exists, choose a new dedicated filename
rather than overwriting a key. The public `.pub` file is the only file installed
on the server. Install the gate first, then paste the public key's single line
into the placeholder below using an existing trusted session as the deploy user:

```sh
umask 077
mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
printf '%s\n' 'restrict,command="/usr/local/sbin/contentgenius-deploy-gate" REPLACE_WITH_COMPLETE_PUBLIC_KEY_LINE' >> /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
```

Replace `REPLACE_WITH_COMPLETE_PUBLIC_KEY_LINE` with the complete
`ssh-ed25519 ... contentgenius-github-actions` public line before running it.
Appending retains existing authorized keys. If this dedicated Actions public key
already has a `restrict`-only or unrestricted entry, replace/remove that exact old
entry before installing the forced-command line. Do not leave a duplicate that
bypasses the gate, and do not change unrelated personal/admin keys. `restrict`
disables forwarding, PTY and user startup hooks; `command="..."` forces every
shell/command/subsystem request through the gate. Neither changes the user's
underlying Docker privileges.

Copy the private file into the GitHub Environment secret **DEPLOY_SSH_KEY**.
For example, copy it to the clipboard without printing it:

```powershell
Get-Content -Raw "$env:USERPROFILE\.ssh\contentgenius-actions" | Set-Clipboard
```

Paste only into the production Environment secret form. After saving the secret,
remove the temporary local private-key copy (use your chosen dedicated filename):

```powershell
Remove-Item -LiteralPath "$env:USERPROFILE\.ssh\contentgenius-actions"
```

The private key stays only in **DEPLOY_SSH_KEY**, never on the server or in the
checkout, issues, workflow YAML, documents or commits. Revoke access by removing
only its dedicated public-key line from `authorized_keys` and replacing/removing
the GitHub secret. The workflow creates a temporary runner key with mode 600 and
removes it on exit; the GitHub-hosted runner is ephemeral.

### Verify the SSH host key

First obtain the server's Ed25519 host-key fingerprint through an independently
trusted channel, such as the existing trusted session or Hetzner console:

```sh
sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
```

On your workstation, collect the advertised key:

```powershell
$deployHost = Read-Host 'Actual SSH host'
$deployPort = Read-Host 'Actual SSH port'
$scanFile = Join-Path $env:TEMP 'contentgenius-ssh-known-hosts'
ssh-keyscan -t ed25519 -p $deployPort $deployHost 2>$null | Set-Content -Encoding ascii $scanFile
ssh-keygen -lf $scanFile
```

Compare the SHA256 fingerprint with the independently trusted server value.
`ssh-keyscan` alone does not authenticate a host. **Only after they match**, copy
the complete verified line into **DEPLOY_KNOWN_HOSTS**:

```powershell
Get-Content -Raw $scanFile | Set-Clipboard
```

Use the exact same host and port in the other secrets. For a non-default port,
keep the scanner's `[host]:port` form. Investigate any mismatch; do not disable
host verification or accept an unverified replacement key. Actions uses
`StrictHostKeyChecking=yes`, explicit known_hosts, BatchMode and IdentitiesOnly.

## Server prerequisites and release sequence

The existing checkout must be `/opt/apps/contentgenius`, on a clean **main**
branch, with the expected GitHub origin. Tracked local edits cause deployment
to fail. Untracked collisions are preserved and Git fast-forward refuses to
overwrite them. The server user needs Git fetch access, Docker/Compose access,
Bash, `flock`, curl, the manually installed root-owned forced-command gate and
write access to the existing backups directory. Docker Compose must support
`up --wait --wait-timeout`. The existing PostgreSQL service,
protected environment, storage/DB volumes, `web` network and Caddy stack must
already be working. No new network or DB service is provisioned.

Actions invokes `contentgenius-deploy <verified-sha>`; the forced-command gate
loads the script from the verified main Git object.
`scripts/deploy-production.sh` then:

1. Uses `set -Eeuo pipefail` and `umask 022` for Git checkout/build operations,
   overriding the gate's inherited restrictive umask; acquires a server
   `flock` in `backups/.deployment.lock` before any release changes.
2. Checks checkout/branch/origin and existing `.env.production`; fetches `origin main`.
3. Requires the requested SHA to exist on origin main and the current server HEAD
   to be its ancestor; fast-forwards to **exactly** that SHA and verifies HEAD.
   Same-SHA retries are allowed. Older/divergent requests fail instead of silently
   downgrading a newer deployment. Ignored env/backups are retained; no `git clean`
   or hard reset is performed.
4. Runs `docker compose --env-file .env.production -f compose.prod.yml config --quiet`
   and builds only `web`. Build/config failure leaves the old web container running.
5. Stops only `web` to pause application HTTP writes, then takes one PostgreSQL
   custom-format `pg_dump` from the running `db` service. There is a brief HTTP
   interruption during backup/migration/recreation; Caddy itself is unchanged.
6. Requires a non-empty dump and successful `pg_restore --list`, then renames the
   `.partial` file to `backups/pre-deploy-<UTC-timestamp>-<full-sha>.dump`. Backup
   failures stop deployment before migrations. Partial files are never treated
   as verified backups. The backups directory is explicitly mode 700; the
   temporary dump is created with `install -m 600` before writing any data and
   the final dump is explicitly chmod 600. Existing `.env.production` permissions
   are unchanged. Credentials are read inside the existing DB container
   and are not printed. Backups are excluded from Git and Docker build context.
7. Explicitly runs a one-off `web` container with
   `php artisan migrate --force --no-interaction`, then recreates only web using
   `up -d --no-deps --force-recreate --wait --wait-timeout 180 web`. PostgreSQL is
   never stopped/recreated; demo accounts are never automatically seeded.
8. Runs Compose `ps`, checks the new web container's Docker health, and requests
   `https://contentgenius.hideas.dev/api/health` with bounded curl retries. It
   requires HTTP success and JSON `{"status":"ok"}`. No AI endpoint is called.

The backup is a **pre-deploy database safety point**, not scheduled backups,
retention management, off-server storage or an application-storage snapshot.
Preserve and protect these files manually; no automatic cleanup or DB restore
is introduced. There is no automatic rollback on failure. After web has been
stopped, a backup or migration failure leaves it stopped for investigation.
Do not blindly restart old code if a migration partially changed its schema.

### Concurrency

All automatic/manual deploy jobs share one GitHub concurrency group with
`cancel-in-progress: false`: a newer run cannot interrupt an active migration.
GitHub's default queue retains one pending deployment and can replace a pending
run with a newer one. The server's lock additionally serializes independent SSH
deployments. Its wait is bounded to 900 seconds; lock timeout fails before Git
or Docker changes. Stale-SHA checks prevent late runs from rolling a newer
checkout backward. Avoid manually cancelling an active deployment; runner or
SSH failures still require inspection of the actual server state.

## First run, retries and failures

1. Review and commit the workflow/script changes manually; configure the
   root-owned gate, forced-command public-key entry, production Environment,
   protection and all five secrets before approving any real deployment.
   Do not add credentials to the commit.
2. A push to main runs **CI**. After both jobs succeed, **Deploy production**
   verifies that exact SHA and proceeds through any configured environment approval.
3. Check Actions logs for the verified CI run/SHA, previous checkout, backup path
   and successful health verification. Confirm the actual server HEAD and web
   health if investigating; the workflow-run default branch SHA is not the target.
4. For a retry, open Actions → Deploy production → Run workflow → branch `main`.
   Supply the verified SHA (or leave it empty for main), then approve the
   production Environment if protection is configured. Failed/unverified commits
   are refused; an older SHA requires the manual rollback procedure below.
5. Inspect failed CI/deploy steps in Actions. Wrong secrets/host key fail SSH;
   missing/incorrect forced-command setup must be fixed in a trusted server session;
   dirty/old/divergent checkouts fail before build; backup failure prevents
   migration; migration/recreation/health failures leave a failed workflow.
   Inspect the server under the same lock before retrying. Never print resolved
   Compose environment or private keys while debugging.

## Manual, non-destructive rollback

Wait for any active deployment to finish and disable automatic deployment/reject
pending approvals while investigating. Identify the previous **known-good** SHA
from successful deployment logs and review whether that application version
can run against the current database schema. A previous checkout in a failed
run is not automatically a known-good release.

Use a separate trusted administrative SSH key/session; the Actions key cannot
open a shell or execute rollback commands. In a trusted server Bash session as
the existing deploy user, replace the SHA
placeholder before running:

```sh
set -Eeuo pipefail
cd /opt/apps/contentgenius
exec 9>backups/.deployment.lock
flock --exclusive 9
test -z "$(git status --porcelain --untracked-files=no)"
ROLLBACK_SHA=REPLACE_WITH_FULL_KNOWN_GOOD_SHA
[[ "$ROLLBACK_SHA" =~ ^[a-f0-9]{40}$ ]]
git fetch --no-tags origin refs/heads/main:refs/remotes/origin/main
git cat-file -e "$ROLLBACK_SHA^{commit}"
git merge-base --is-ancestor "$ROLLBACK_SHA" origin/main
git switch -C main "$ROLLBACK_SHA"
docker compose --env-file .env.production -f compose.prod.yml config --quiet
docker compose --env-file .env.production -f compose.prod.yml build web
docker compose --env-file .env.production -f compose.prod.yml up -d --no-deps --force-recreate --wait --wait-timeout 180 web
docker compose --env-file .env.production -f compose.prod.yml ps
curl --fail --silent --show-error https://contentgenius.hideas.dev/api/health
flock --unlock 9
exec 9>&-
```

This moves the server-local main ref, rebuilds/recreates web and preserves ignored
files and Docker volumes. It does not force-push or rewrite commits, run backward
migrations, or restore the database. **Laravel migrations may not be safely
reversible after production data changes.** If the old application is incompatible,
prefer a reviewed forward fix or a separate reviewed recovery using the verified
pre-deploy dump. Do not automate a DB restore or use `migrate:fresh`,
`migrate:refresh`, or `down --volumes`. Re-enable deployment only after resolving
the failure and selecting a verified forward release.

## Local verification

Run the existing application checks plus:

```sh
php vendor/bin/yaml-lint .github/workflows
bash -n scripts/deploy-production.sh
bash -n scripts/contentgenius-deploy-gate.sh
node --test scripts/tests/workflows.test.cjs scripts/tests/deploy-production.test.cjs scripts/tests/deploy-gate.test.cjs
```

The Node tests parse actual workflow YAML with the existing Symfony package,
execute CI verification against fake API responses, and execute the gate/deploy
scripts in disposable workspace directories with fake Git/Docker/curl/lock commands.
They never access production, fetch a remote repository, migrate a real database
or call OpenAI. The fixed production directory is replaced only in the disposable
test copy. Gate tests cover rejected commands/SHAs, main verification, trusted
Git-object execution, ignored client stdin, extraction failure and cleanup.
Deployment tests also cover inherited umask handling, explicit backup protection
and the Dockerfile's runtime permissions block. For a production image built from
a context with source files mode 600 and directories mode 700, set
`CONTENTGENIUS_PERMISSION_TEST_IMAGE` to its local tag before running these tests.
The optional Docker regression runs as `www-data`, with networking disabled, and
checks Composer reads, source ownership/write restrictions and absence of env files.
If ShellCheck is installed, also run
`shellcheck scripts/deploy-production.sh scripts/contentgenius-deploy-gate.sh`;
otherwise report its absence without installing tools just for this milestone.

References: [workflow_run](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#workflow_run),
[workflow run API](https://docs.github.com/en/rest/actions/workflow-runs#list-workflow-runs-for-a-workflow),
[concurrency](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency),
[OpenSSH ssh-keyscan](https://man.openbsd.org/ssh-keyscan),
[OpenSSH authorized_keys](https://man.openbsd.org/sshd.8#AUTHORIZED_KEYS_FILE_FORMAT),
[Git show](https://git-scm.com/docs/git-show),
[PostgreSQL pg_dump](https://www.postgresql.org/docs/17/app-pgdump.html).
