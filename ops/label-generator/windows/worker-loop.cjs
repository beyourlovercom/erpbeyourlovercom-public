'use strict'

const path = require('path')
const { spawnSync } = require('child_process')

function arg(name, fallback = null) {
  const index = process.argv.indexOf(name)
  return index >= 0 ? process.argv[index + 1] : fallback
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function main() {
  const api = arg('--api')
  const runtime = arg('--runtime', __dirname)
  const outputRoot = arg('--output-root')
  const workerId = arg('--worker', process.env.COMPUTERNAME || 'company-win-label-01')
  const intervalMs = Math.max(1000, Math.min(60_000, Number(arg('--interval-ms', '5000'))))
  const maxIterations = Math.max(0, Number(arg('--max-iterations', '0')))
  const heartbeatMs = Math.max(1000, Math.min(120_000, Number(arg('--heartbeat-ms', '30000'))))

  if (!api || !outputRoot) {
    throw new Error('usage: worker-loop.cjs --api URL --runtime DIR --output-root DIR [--worker ID] [--interval-ms 5000] [--max-iterations N]')
  }
  if (!process.env.BYL_BLUE_LABEL_WORKER_TOKEN) {
    throw new Error('BYL_BLUE_LABEL_WORKER_TOKEN is required in the worker process environment.')
  }

  const dispatcher = path.join(runtime, 'dispatch-once.cjs')
  let iteration = 0

  while (maxIterations === 0 || iteration < maxIterations) {
    iteration += 1

    const result = spawnSync(process.execPath, [
      dispatcher,
      '--api', api,
      '--runtime', runtime,
      '--output-root', outputRoot,
      '--worker', workerId,
      '--heartbeat-ms', String(heartbeatMs),
    ], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      windowsHide: true,
      env: process.env,
    })

    const stdout = String(result.stdout || '').trim()
    const stderr = String(result.stderr || '').trim()

    if (stdout) process.stdout.write(`[worker-loop ${iteration}] ${stdout}\n`)
    if (stderr) process.stderr.write(`[worker-loop ${iteration}] ${stderr}\n`)

    const idle = result.status === 0 && stdout.includes('BLUE_LABEL_DISPATCH_IDLE')
    const succeeded = result.status === 0 && stdout.includes('BLUE_LABEL_DISPATCH_OK')

    if (!idle && !succeeded) {
      process.stderr.write(
        `[worker-loop ${iteration}] dispatcher exit=${result.status ?? 'null'}; waiting before next poll\n`,
      )
    }

    if (maxIterations !== 0 && iteration >= maxIterations) break
    await sleep(intervalMs)
  }

  process.stdout.write(`BLUE_LABEL_WORKER_LOOP_STOP iterations=${iteration}\n`)
}

main().catch((error) => {
  process.stderr.write(`BLUE_LABEL_WORKER_LOOP_ERROR ${error.stack || error.message || error}\n`)
  process.exit(1)
})
