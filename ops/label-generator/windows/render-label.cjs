'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')
const { pathToFileURL } = require('url')
const { buildSvg, safeLabelSku, writeExactPdfFromPng } = require('./label-core.cjs')

function argument(name) {
  const index = process.argv.indexOf(name)
  return index >= 0 ? process.argv[index + 1] : null
}

const sku = safeLabelSku(argument('--sku'))
const description = String(argument('--description') || sku).trim()
const pngPath = argument('--png')
const pdfPath = argument('--pdf')
const chrome = argument('--chrome')

if (!pngPath || !pdfPath || !chrome) {
  throw new Error('usage: render-label.cjs --sku X --description Y --png out.png --pdf out.pdf --chrome chrome.exe')
}

for (const output of [pngPath, pdfPath]) {
  fs.mkdirSync(path.dirname(output), { recursive: true })
}

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'byl-blue-label-'))
const htmlPath = path.join(tempDir, 'label.html')
const svg = buildSvg(sku, description)
const html = `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { size: 50mm 30mm; margin: 0; }
html, body { margin: 0; padding: 0; width: 50mm; height: 30mm; overflow: hidden; background: #fff; }
svg { display: block; width: 50mm; height: 30mm; }
</style>
</head>
<body>${svg}</body>
</html>`

try {
  fs.writeFileSync(htmlPath, html, 'utf8')
  const url = pathToFileURL(htmlPath).href

  execFileSync(chrome, [
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--hide-scrollbars',
    '--force-device-scale-factor=1',
    '--window-size=591,354',
    `--screenshot=${pngPath}`,
    url,
  ], { stdio: 'pipe' })

  const pdf = writeExactPdfFromPng(pngPath, pdfPath, 50, 30)
  const result = {
    label_sku: sku,
    description,
    image_px: pdf.image_px,
    page_mm: pdf.page_mm,
    page_pt: pdf.page_pt,
    png_bytes: fs.statSync(pngPath).size,
    pdf_bytes: fs.statSync(pdfPath).size,
  }

  if (result.image_px[0] !== 591 || result.image_px[1] !== 354) {
    throw new Error(`unexpected artwork size: ${result.image_px.join('x')}`)
  }

  process.stdout.write(JSON.stringify(result))
} finally {
  fs.rmSync(tempDir, { recursive: true, force: true })
}
