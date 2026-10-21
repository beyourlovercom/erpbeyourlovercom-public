'use strict'

const assert = require('assert')
const fs = require('fs')
const os = require('os')
const path = require('path')
const zlib = require('zlib')
const test = require('node:test')
const {
  buildSvg,
  encodeCode128B,
  safeLabelSku,
  writeExactPdfFromPng,
  wrapVisual,
} = require('./label-core.cjs')

test('Code128B emits start, data, checksum and stop', () => {
  assert.deepStrictEqual(encodeCode128B('A'), [104, 33, 34, 106])
  const encoded = encodeCode128B('TEST-001')
  assert.strictEqual(encoded[0], 104)
  assert.strictEqual(encoded.at(-1), 106)
})

test('label sku is filesystem-safe and barcode-safe', () => {
  assert.strictEqual(safeLabelSku('Pillow-grey'), 'Pillow-grey')
  assert.throws(() => safeLabelSku('bad/sku'))
  assert.throws(() => safeLabelSku('蓝标'))
})

test('Chinese description wraps without requiring spaces', () => {
  const lines = wrapVisual('磁吸穿戴裸机玫红色成品APP款用于海外仓蓝标', 16, 2)
  assert.ok(lines.length >= 1)
  assert.ok(lines.length <= 2)
  assert.ok(lines[0].length > 0)
})
test('SVG contract is 50x30 mm and uses system fonts', () => {
  const svg = buildSvg('C549A', '磁吸穿戴裸机玫红色成品 APP款')
  assert.match(svg, /width="50mm"/)
  assert.match(svg, /height="30mm"/)
  assert.match(svg, /Arial,Segoe UI,sans-serif/)
  assert.match(svg, /C549A/)
  assert.match(svg, /<rect x="/)
})

function crc32(buffer) {
  let crc = 0xffffffff
  for (const byte of buffer) {
    crc ^= byte
    for (let bit = 0; bit < 8; bit += 1) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1))
  }
  return (crc ^ 0xffffffff) >>> 0
}

function pngChunk(type, data) {
  const typeBuffer = Buffer.from(type, 'ascii')
  const body = Buffer.concat([typeBuffer, data])
  const result = Buffer.alloc(12 + data.length)
  result.writeUInt32BE(data.length, 0)
  typeBuffer.copy(result, 4)
  data.copy(result, 8)
  result.writeUInt32BE(crc32(body), 8 + data.length)
  return result
}

function onePixelRgbPng() {
  const signature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(1, 0); ihdr.writeUInt32BE(1, 4)
  ihdr[8] = 8; ihdr[9] = 2
  const idat = zlib.deflateSync(Buffer.from([0, 255, 255, 255]))
  return Buffer.concat([signature, pngChunk('IHDR', ihdr), pngChunk('IDAT', idat), pngChunk('IEND', Buffer.alloc(0))])
}

test('exact PDF writes mathematical 50x30 mm MediaBox', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'byl-label-test-'))
  try {
    const png = path.join(dir, 'one.png')
    const pdf = path.join(dir, 'one.pdf')
    fs.writeFileSync(png, onePixelRgbPng())

    const result = writeExactPdfFromPng(png, pdf, 50, 30)
    assert.deepStrictEqual(result.page_mm, [50, 30])
    const body = fs.readFileSync(pdf).toString('latin1')
    assert.match(body, /MediaBox \[0 0 141\.73228346 85\.03937008\]/)
    assert.ok(fs.statSync(pdf).size > 500)
  } finally {
    fs.rmSync(dir, { recursive: true, force: true })
  }
})
