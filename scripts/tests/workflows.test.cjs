const assert = require('node:assert/strict')
const { execFileSync } = require('node:child_process')
const { resolve } = require('node:path')
const { test } = require('node:test')

const root = resolve(__dirname, '../..')
function workflow(name) {
  return JSON.parse(execFileSync('php', ['-r',
    'require "vendor/autoload.php"; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parseFile($argv[1]), JSON_THROW_ON_ERROR);',
    `.github/workflows/${name}.yml`], { cwd: root, encoding: 'utf8' }))
}
const ci = workflow('ci')
const deploy = workflow('deploy')
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor
const verify = new AsyncFunction('github', 'context', 'core', deploy.jobs.verify.steps[0].with.script)
const sha = 'a'.repeat(40)
const repository = { owner: 'vyacheslavhulyaev-glitch', repo: 'content-genius' }

async function check({ manual = false, input = sha, run = {}, jobs, runs, context = {} } = {}) {
  const expectedRun = { id: 22, workflow_id: 4, head_sha: sha, head_branch: 'main', event: 'push', status: 'completed',
    conclusion: 'success', head_repository: { full_name: `${repository.owner}/${repository.repo}` }, ...run }
  const output = {}
  const github = {
    rest: { actions: {
      getWorkflow: async options => { assert.equal(options.workflow_id, 'ci.yml'); return { data: { id: 4 } } },
      getWorkflowRun: async options => { assert.equal(options.run_id, 22); return { data: expectedRun } },
      listWorkflowRuns: async options => {
        assert.equal(options.head_sha, sha)
        assert.equal(options.branch, 'main')
        assert.equal(options.event, 'push')
        assert.equal(options.status, 'success')
        return { data: { workflow_runs: runs ?? [expectedRun] } }
      },
      listJobsForWorkflowRun: () => {},
    } },
    paginate: async (endpoint, options) => {
      assert.equal(endpoint, github.rest.actions.listJobsForWorkflowRun)
      assert.equal(options.run_id, 22)
      assert.equal(options.filter, 'latest')
      return jobs ?? ['Backend', 'Frontend'].map(name => ({ name, head_sha: sha, status: 'completed', conclusion: 'success' }))
    },
  }
  await verify(github, { repo: repository, ref: 'refs/heads/main', sha: manual ? sha : 'b'.repeat(40),
    eventName: manual ? 'workflow_dispatch' : 'workflow_run',
    payload: { workflow_run: { id: 22, head_sha: sha }, inputs: { commit_sha: input } }, ...context },
  { setOutput: (key, value) => { output[key] = value }, info: () => {} })
  return output
}

test('workflow YAML parses, validates main only, and grants minimal permissions', () => {
  assert.deepEqual(ci.on, { pull_request: { branches: ['main'] }, push: { branches: ['main'] } })
  assert.deepEqual(ci.permissions, { contents: 'read' })
  assert.deepEqual(deploy.permissions, { contents: 'read', actions: 'read' })
  assert.deepEqual(deploy.on.workflow_run, { workflows: [ci.name], types: ['completed'], branches: ['main'] })
  assert.equal(deploy.jobs.deploy.needs, 'verify')
  assert.equal(deploy.jobs.deploy.environment.name, 'production')
  assert.equal(deploy.jobs.deploy.concurrency['cancel-in-progress'], false)
  assert.equal(deploy.jobs.deploy.steps[0].with.ref, '${{ needs.verify.outputs.sha }}')
  assert.equal(ci.jobs.backend.env.OPENAI_API_KEY, '')
  assert.deepEqual(Object.values(ci.jobs).map(job => job.name), ['Backend', 'Frontend'])
  assert.match(deploy.jobs.verify.if, /workflow_run\.event == 'push'/)
  assert.match(deploy.jobs.verify.if, /head_repository\.full_name == github\.repository/)
  for (const config of [ci, deploy]) {
    for (const job of Object.values(config.jobs)) {
      assert.equal(job['runs-on'], 'ubuntu-24.04')
      for (const step of job.steps) {
        if (step.uses) assert.match(step.uses, /@[a-f0-9]{40}$/)
      }
    }
  }
})

test('automatic deployment verifies CI head SHA rather than the current default branch SHA', async () => {
  assert.deepEqual(await check(), { sha })
})

test('manual redeploy requires exact successful push CI, including when input is empty', async () => {
  assert.deepEqual(await check({ manual: true }), { sha })
  assert.deepEqual(await check({ manual: true, input: '' }), { sha })
  await assert.rejects(check({ manual: true, runs: [] }), /no successful push CI/)
  await assert.rejects(check({ manual: true, input: 'main' }), /full commit SHA/)
  await assert.rejects(check({ manual: true, context: { ref: 'refs/heads/feature' } }), /must run from main/)
})

test('failed, incomplete, PR, fork, wrong workflow and mismatched SHA runs cannot deploy', async () => {
  for (const run of [{ conclusion: 'failure' }, { status: 'in_progress' }, { event: 'pull_request' },
    { head_branch: 'feature' }, { head_repository: { full_name: 'fork/content-genius' } }, { workflow_id: 5 }, { head_sha: 'b'.repeat(40) }]) {
    await assert.rejects(check({ run }), /no successful push CI/)
  }
})

test('missing, skipped, failed or wrong-SHA required jobs cannot deploy', async () => {
  for (const override of [null, { conclusion: 'skipped' }, { conclusion: 'failure' }, { status: 'in_progress' }, { head_sha: 'b'.repeat(40) }]) {
    const jobs = [{ name: 'Frontend', head_sha: sha, status: 'completed', conclusion: 'success' }]
    if (override !== null) jobs.push({ name: 'Backend', head_sha: sha, status: 'completed', conclusion: 'success', ...override })
    await assert.rejects(check({ jobs }), /Required CI job did not succeed/)
  }
})

test('runner SSH step requests only forced-command deployment with strict trusted hosts and no streamed script', () => {
  const script = deploy.jobs.deploy.steps[1].run
  const bash = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash'
  execFileSync(bash, ['-n'], { input: script })
  assert.match(script, /StrictHostKeyChecking=yes/)
  assert.match(script, /DEPLOY_KNOWN_HOSTS/)
  assert.match(script, /chmod 600/)
  assert.match(script, /trap .* EXIT/)
  assert.match(script, /\^\[a-f0-9\]\{40\}\$/)
  assert.match(script, /ssh -nT /)
  assert.match(script, /"\$DEPLOY_HOST" "contentgenius-deploy \$EXPECTED_SHA"/)
  assert.doesNotMatch(script, /StrictHostKeyChecking=no|ssh-keyscan|set -x|bash\s+-s|<\s*scripts\/|\|\s*ssh\b/)
  assert.ok(ci.jobs.backend.steps.some(step => step.run?.includes('scripts/tests/deploy-gate.test.cjs')))
})
