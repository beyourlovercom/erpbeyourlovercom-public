'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')
const { spawn } = require('child_process')

function arg(name, fallback = null) {
  const index = process.argv.indexOf(name)
  return index >= 0 ? process.argv[index + 1] : fallback
}

async function request(base, token, workerId, endpoint, options = {}) {
  const response = await fetch(base + endpoint, {
    method: options.method || 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'X-BYL-Worker-ID': workerId,
      Accept: 'application/json',
      ...(options.headers || {}),
    },
    body: options.body,
  })

  const text = await response.text()
  let json = null
  if (text !== '') {
    try { json = JSON.parse(text) } catch { /* preserve raw detail below */ }
  }

  if (!response.ok) {
    const detail = json?.message || text || `HTTP ${response.status}`
    throw new Error(`${options.method || 'POST'} ${endpoint} failed: ${response.status} ${detail}`)
  }

  return json
}

async function postJson(base, token, workerId, endpoint, body = null) {
  return request(base, token, workerId, endpoint, {
    headers: { 'Content-Type': 'application/json' },
    body: body === null ? undefined : JSON.stringify(body),
  })
}

async function uploadArtifact(base, token, workerId, reference, artifact, kind, filePath) {
  if (!fs.existsSync(filePath)) throw new Error(`artifact not found: ${filePath}`)
  const sha = String(artifact[`${kind}_sha256`] || '').trim()
  if (!/^[a-f0-9]{64}$/.test(sha)) throw new Error(`invalid ${kind} sha256 for ${artifact.label_sku}`)

  const data = fs.readFileSync(filePath)
  const form = new FormData()
  form.append('file', new Blob([data]), path.basename(filePath))

  return request(
    base,
    token,
    workerId,
    `/jobs/${encodeURIComponent(reference)}/artifacts/${kind}/${encodeURIComponent(artifact.label_sku)}`,
    {
      headers: { 'X-Content-SHA256': sha },
      body: form,
    },
  )
}

function runGeneratorWithHeartbeat(generator, jobJson, jobDir, heartbeat, heartbeatMs) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [
      generator,
      '--job', jobJson,
      '--output', jobDir,
    ], {
      stdio: ['ignore', 'pipe', 'pipe'],
      windowsHide: true,
    })

    let stdout = ''
    let stderr = ''
    let heartbeatBusy = false
    let heartbeatError = null

    child.stdout.setEncoding('utf8')
    child.stderr.setEncoding('utf8')
    child.stdout.on('data', (chunk) => { stdout += chunk })
    child.stderr.on('data', (chunk) => { stderr += chunk })

    const timer = setInterval(async () => {
      if (heartbeatBusy || heartbeatError) return
      heartbeatBusy = true
      try {
        await heartbeat()
      } catch (error) {
        heartbeatError = error
        child.kill()
      } finally {
        heartbeatBusy = false
      }
    }, heartbeatMs)

    child.on('error', (error) => {
      clearInterval(timer)
      reject(error)
    })

    child.on('close', (code, signal) => {
      clearInterval(timer)
      if (heartbeatError) {
        reject(new Error(`heartbeat failed while generator was running: ${heartbeatError.message}`))
        return
      }
      if (code !== 0) {
        reject(new Error(`Generator exited ${code ?? 'null'} signal=${signal ?? 'none'} :: ${stderr.trim()}`))
        return
      }
      resolve(stdout)
    })
  })
}

async function main() {
  const api = String(arg('--api', '')).replace(/\/$/, '')
  const runtime = arg('--runtime', __dirname)
  const outputRoot = arg('--output-root')
  const workerId = arg('--worker', process.env.COMPUTERNAME || 'company-win-label-01')
  const token = arg('--token', process.env.BYL_BLUE_LABEL_WORKER_TOKEN || '')
  const heartbeatMs = Math.max(1000, Math.min(120_000, Number(arg('--heartbeat-ms', '30000'))))

  if (!api || !outputRoot || !token) {
    throw new Error('usage: dispatch-once.cjs --api URL --runtime DIR --output-root DIR [--worker ID] [--token TOKEN]')
  }
  if (!/^[A-Za-z0-9._-]{3,128}$/.test(workerId)) throw new Error('Invalid worker id')

  const claim = await postJson(api, token, workerId, '/claim')
  if (!claim?.job) {
    process.stdout.write('BLUE_LABEL_DISPATCH_IDLE\n')
    return
  }

  const job = claim.job
  const reference = String(job.reference)
  const attempt = Number(job.attempt_count || 1)
  const generator = path.join(runtime, 'generate-blue-labels.cjs')
  const jobDir = path.join(outputRoot, reference, `attempt-${attempt}`)
  const jobJson = path.join(os.tmpdir(), `${reference}-attempt-${attempt}.json`)
  let started = false

  const heartbeat = () => postJson(
    api, token, workerId, `/jobs/${encodeURIComponent(reference)}/heartbeat`,
  )

  try {
    await postJson(api, token, workerId, `/jobs/${encodeURIComponent(reference)}/start`)
    started = true

    fs.writeFileSync(jobJson, JSON.stringify(job.payload, null, 2) + '\n', 'utf8')
    await heartbeat()

    const runtimeOutput = await runGeneratorWithHeartbeat(generator, jobJson, jobDir, heartbeat, heartbeatMs)

    const manifestPath = path.join(jobDir, 'manifest.json')
    if (!fs.existsSync(manifestPath)) throw new Error(`manifest.json not found: ${manifestPath}`)
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))

    for (const artifact of manifest.artifacts || []) {
      const labelSku = String(artifact.label_sku)
      await heartbeat()
      await uploadArtifact(
        api, token, workerId, reference, artifact, 'btw',
        path.join(jobDir, 'labels', `${labelSku}.btw`),
      )
      await uploadArtifact(
        api, token, workerId, reference, artifact, 'pdf',
        path.join(jobDir, 'labels', `${labelSku}.pdf`),
      )
    }

    await heartbeat()
    const completed = await postJson(api, token, workerId, `/jobs/${encodeURIComponent(reference)}/complete`, {
      manifest,
      output_root: jobDir,
    })

    process.stdout.write(
      `BLUE_LABEL_DISPATCH_OK reference=${reference} attempt=${attempt} status=${completed.job.status} output=${jobDir}\n${runtimeOutput.trim()}\n`,
    )
  } catch (error) {
    if (started) {
      try {
        await postJson(api, token, workerId, `/jobs/${encodeURIComponent(reference)}/fail`, {
          error_code: String(error?.message || '').startsWith('heartbeat failed')
            ? 'heartbeat_failure'
            : 'dispatch_failure',
          error_message: String(error?.message || error).slice(0, 4000),
        })
      } catch (reportError) {
        process.stderr.write(`BLUE_LABEL_FAIL_REPORT_ERROR ${reportError.message}\n`)
      }
    }
    throw error
  } finally {
    fs.rmSync(jobJson, { force: true })
  }
}

main().catch((error) => {
  process.stderr.write(`BLUE_LABEL_DISPATCH_ERROR ${error.stack || error.message || error}\n`)
  process.exit(1)
})
