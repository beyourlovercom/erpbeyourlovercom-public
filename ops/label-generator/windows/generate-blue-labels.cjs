'use strict'

const crypto = require('crypto')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')

function arg(name, fallback = null) {
  const index = process.argv.indexOf(name)
  return index >= 0 ? process.argv[index + 1] : fallback
}

function sha256File(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex')
}

function requireFile(file, label) {
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) throw new Error(`${label} not found: ${file}`)
}

function safeSku(value) {
  const sku = String(value || '').trim()
  if (!/^[A-Za-z0-9._-]+$/.test(sku)) throw new Error(`Invalid label_sku: ${sku}`)
  return sku
}

function findBrowser() {
  const candidates = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  ]
  const found = candidates.find(fs.existsSync)
  if (!found) throw new Error('Chrome or Edge is required on the label worker.')
  return found
}

const jobPath = arg('--job')
const outputDir = arg('--output')
const runtimeDir = __dirname
const templatePath = arg('--template', path.join(runtimeDir, 'templates', 'blue-label-picture-master-v1.btw'))
if (!jobPath || !outputDir) throw new Error('usage: generate-blue-labels.cjs --job job.json --output dir [--template master.btw]')

requireFile(jobPath, 'job')
requireFile(templatePath, 'BarTender master')
const renderScript = path.join(runtimeDir, 'render-label.cjs')
const embedScript = path.join(runtimeDir, 'embed-picture.vbs')
requireFile(renderScript, 'render-label.cjs')
requireFile(embedScript, 'embed-picture.vbs')

const cscript = path.join(process.env.WINDIR || 'C:\\Windows', 'SysWOW64', 'cscript.exe')
requireFile(cscript, '32-bit cscript')
const chrome = findBrowser()

const job = JSON.parse(fs.readFileSync(jobPath, 'utf8').replace(/^\uFEFF/, ''))
if (job.schema_version !== 'byl.blue-label.job.v1') throw new Error(`Unsupported schema_version: ${job.schema_version}`)
if (job.template_version !== 'blue-label-picture-master-v1') throw new Error(`Unsupported template_version: ${job.template_version}`)
if (!job.job_id || !job.plan_reference || !Array.isArray(job.items) || job.items.length < 1) throw new Error('Invalid blue-label job contract')

const seen = new Set()
for (const item of job.items) {
  const labelSku = safeSku(item.label_sku)
  if (!String(item.product_sku || '').trim()) throw new Error(`product_sku is required for ${labelSku}`)
  if (!String(item.label_description || '').trim()) throw new Error(`label_description is required for ${labelSku}`)
  if (!Number.isInteger(Number(item.restock_qty)) || Number(item.restock_qty) <= 0) throw new Error(`restock_qty must be > 0 for ${labelSku}`)
  if (seen.has(labelSku)) throw new Error(`Duplicate label_sku in job: ${labelSku}`)
  seen.add(labelSku)
}

if (fs.existsSync(outputDir)) throw new Error(`OutputDir already exists; outputs are immutable: ${outputDir}`)
fs.mkdirSync(path.dirname(outputDir), { recursive: true })

const staging = path.join(path.dirname(outputDir), `.${path.basename(outputDir)}.tmp-${crypto.randomUUID().replaceAll('-', '')}`)
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'byl-blue-label-'))
const labelsDir = path.join(staging, 'labels')
fs.mkdirSync(labelsDir, { recursive: true })

let bartenderRuntime = null
const artifacts = []

try {
  for (const item of job.items) {
    const labelSku = safeSku(item.label_sku)
    const description = String(item.label_description).trim()
    const png = path.join(temp, `${labelSku}.png`)
    const pdf = path.join(labelsDir, `${labelSku}.pdf`)
    const btw = path.join(labelsDir, `${labelSku}.btw`)

    const renderRaw = execFileSync(process.execPath, [
      renderScript, '--sku', labelSku, '--description', description,
      '--png', png, '--pdf', pdf, '--chrome', chrome,
    ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
    const render = JSON.parse(renderRaw.trim())

    const btOutput = execFileSync(cscript, ['//nologo', embedScript, templatePath, png, btw], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    }).trim()
    const btOk = btOutput.split(/\r?\n/).find((line) => line.startsWith('BT_OK'))
    if (!btOk) throw new Error(`BarTender did not return BT_OK for ${labelSku}`)
    if (!bartenderRuntime) bartenderRuntime = btOk

    fs.rmSync(png, { force: true })
    requireFile(btw, `${labelSku} BTW`)
    requireFile(pdf, `${labelSku} PDF`)
    if (fs.statSync(btw).size < 1024) throw new Error(`${labelSku} BTW is unexpectedly small`)
    if (fs.statSync(pdf).size < 1024) throw new Error(`${labelSku} PDF is unexpectedly small`)

    artifacts.push({
      product_sku: String(item.product_sku).trim(),
      label_sku: labelSku,
      label_description: description,
      restock_qty: Number(item.restock_qty),
      btw: `labels/${labelSku}.btw`,
      pdf: `labels/${labelSku}.pdf`,
      btw_sha256: sha256File(btw),
      pdf_sha256: sha256File(pdf),
      label_size_mm: render.page_mm.map(Number),
      artwork_px: render.image_px.map(Number),
      barcode: 'Code128B',
    })
  }

  const manifest = {
    schema_version: 'byl.blue-label.output.v1',
    generator_version: 'blue-label-runtime-v1',
    job_id: String(job.job_id),
    plan_reference: String(job.plan_reference),
    plan_snapshot_checksum: String(job.plan_snapshot_checksum || ''),
    template_version: String(job.template_version),
    template_sha256: sha256File(templatePath),
    source_job_sha256: sha256File(jobPath),
    generated_at_utc: new Date().toISOString(),
    runtime: { node: process.version, chrome, bartender: bartenderRuntime },
    artifacts,
  }

  fs.writeFileSync(path.join(staging, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n', 'utf8')
  fs.renameSync(staging, outputDir)
  process.stdout.write(`BLUE_LABEL_JOB_OK job_id=${job.job_id} items=${job.items.length} output=${outputDir}\n`)
} catch (error) {
  fs.rmSync(staging, { recursive: true, force: true })
  throw error
} finally {
  fs.rmSync(temp, { recursive: true, force: true })
}
