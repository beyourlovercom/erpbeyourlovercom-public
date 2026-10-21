'use strict'

const fs = require('fs')
const crypto = require('crypto')
const zlib = require('zlib')

const CODE128_PATTERNS = [
  '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
  '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
  '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
  '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
  '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
  '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
  '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
  '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
  '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
  '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
  '114131','311141','411131','211412','211214','211232','2331112',
]

function encodeCode128B(text) {
  if (typeof text !== 'string' || text.length === 0) throw new Error('label_sku is required')
  const values = [104]
  for (const ch of text) {    const code = ch.charCodeAt(0)
    if (code < 32 || code > 126) throw new Error('label_sku must use printable ASCII for Code128B')
    values.push(code - 32)
  }

  let checksum = 104
  for (let i = 1; i < values.length; i += 1) checksum += values[i] * i
  values.push(checksum % 103, 106)

  return values
}

function safeLabelSku(value) {
  const sku = String(value || '').trim()
  if (!/^[A-Za-z0-9._-]+$/.test(sku)) {
    throw new Error('label_sku must match [A-Za-z0-9._-]+')
  }
  return sku
}

function escapeXml(value) {
  return String(value ?? '').replace(/[&<>"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
  })[char])
}

function visualUnits(char) {
  return char.codePointAt(0) > 255 ? 2 : 1
}

function wrapVisual(text, maxUnits = 34, maxLines = 2) {  const chars = Array.from(String(text || '').trim())
  const lines = []
  let current = ''
  let units = 0

  for (const char of chars) {
    const next = visualUnits(char)
    if (units + next > maxUnits && current !== '') {
      lines.push(current.trim())
      if (lines.length >= maxLines) break
      current = ''
      units = 0
    }
    current += char
    units += next
  }

  if (lines.length < maxLines && current.trim()) lines.push(current.trim())
  return lines.slice(0, maxLines)
}

function barcodeRects(text, width = 500) {
  const values = encodeCode128B(text)
  const quietModules = 10
  const modules = values.reduce(
    (total, value) => total + Array.from(CODE128_PATTERNS[value]).reduce((sum, digit) => sum + Number(digit), 0),
    quietModules * 2,
  )
  const moduleWidth = 450 / modules
  let x = (width - modules * moduleWidth) / 2 + quietModules * moduleWidth
  let result = ''
  for (const value of values) {
    let black = true
    for (const digit of CODE128_PATTERNS[value]) {
      const barWidth = Number(digit) * moduleWidth
      if (black) {
        result += `<rect x="${x.toFixed(3)}" y="18" width="${barWidth.toFixed(3)}" height="105"/>`
      }
      x += barWidth
      black = !black
    }
  }

  return result
}

function buildSvg(labelSku, description) {
  const sku = safeLabelSku(labelSku)
  const lines = wrapVisual(description || sku)
  const descriptionTspans = lines
    .map((line, index) => `<tspan x="24" dy="${index === 0 ? 0 : 27}">${escapeXml(line)}</tspan>`)
    .join('')

  return `<svg xmlns="http://www.w3.org/2000/svg" width="50mm" height="30mm" viewBox="0 0 500 300">
<rect width="500" height="300" fill="white"/>
<g fill="black">${barcodeRects(sku)}</g>
<text x="250" y="151" text-anchor="middle" font-family="Arial,Segoe UI,sans-serif" font-size="22" font-weight="700">${escapeXml(sku)}</text>
<text x="24" y="202" font-family="Arial,Segoe UI,sans-serif" font-size="31" font-weight="700">${escapeXml(sku)}</text>
<text x="24" y="240" font-family="Arial,Segoe UI,sans-serif" font-size="23">${descriptionTspans}</text>
</svg>`
}
function decodePngToRgb(buffer) {
  const signature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])
  if (!buffer.subarray(0, 8).equals(signature)) throw new Error('not a PNG file')

  let offset = 8
  let width = 0
  let height = 0
  let bitDepth = 0
  let colorType = 0
  const idat = []

  while (offset < buffer.length) {
    const length = buffer.readUInt32BE(offset)
    const type = buffer.toString('ascii', offset + 4, offset + 8)
    const data = buffer.subarray(offset + 8, offset + 8 + length)
    offset += 12 + length

    if (type === 'IHDR') {
      width = data.readUInt32BE(0)
      height = data.readUInt32BE(4)
      bitDepth = data[8]
      colorType = data[9]
    } else if (type === 'IDAT') idat.push(data)
    else if (type === 'IEND') break
  }

  if (bitDepth !== 8 || ![2, 6].includes(colorType)) {
    throw new Error('PNG must be 8-bit RGB or RGBA')
  }
  const bytesPerPixel = colorType === 6 ? 4 : 3
  const inflated = zlib.inflateSync(Buffer.concat(idat))
  const stride = width * bytesPerPixel
  const rows = []
  let cursor = 0
  let previous = Buffer.alloc(stride)

  const paeth = (a, b, c) => {
    const p = a + b - c
    const pa = Math.abs(p - a)
    const pb = Math.abs(p - b)
    const pc = Math.abs(p - c)
    return pa <= pb && pa <= pc ? a : pb <= pc ? b : c
  }

  for (let y = 0; y < height; y += 1) {
    const filter = inflated[cursor]
    cursor += 1
    const source = inflated.subarray(cursor, cursor + stride)
    cursor += stride
    const row = Buffer.alloc(stride)

    for (let x = 0; x < stride; x += 1) {
      const left = x >= bytesPerPixel ? row[x - bytesPerPixel] : 0
      const up = previous[x] || 0
      const upLeft = x >= bytesPerPixel ? previous[x - bytesPerPixel] || 0 : 0
      const value = source[x]
      if (filter === 0) row[x] = value
      else if (filter === 1) row[x] = (value + left) & 255
      else if (filter === 2) row[x] = (value + up) & 255
      else if (filter === 3) row[x] = (value + Math.floor((left + up) / 2)) & 255
      else if (filter === 4) row[x] = (value + paeth(left, up, upLeft)) & 255
      else throw new Error(`unsupported PNG filter: ${filter}`)
    }

    rows.push(row)
    previous = row
  }

  const rgb = Buffer.alloc(width * height * 3)
  let out = 0
  for (const row of rows) {
    for (let x = 0; x < width; x += 1) {
      rgb[out] = row[x * bytesPerPixel]
      rgb[out + 1] = row[x * bytesPerPixel + 1]
      rgb[out + 2] = row[x * bytesPerPixel + 2]
      out += 3
    }
  }

  return { width, height, rgb }
}

function mmToPt(mm) {
  return mm / 25.4 * 72
}
function writeExactPdfFromPng(inputPng, outputPdf, widthMm = 50, heightMm = 30) {
  const { width, height, rgb } = decodePngToRgb(fs.readFileSync(inputPng))
  const compressed = zlib.deflateSync(rgb, { level: 9 })
  const pageWidth = mmToPt(widthMm)
  const pageHeight = mmToPt(heightMm)
  const parts = []
  const offsets = [0]
  let position = 0

  const push = (value) => {
    const buffer = Buffer.isBuffer(value) ? value : Buffer.from(value, 'binary')
    parts.push(buffer)
    position += buffer.length
  }
  const object = (number, header, data = null) => {
    offsets[number] = position
    push(`${number} 0 obj\n${header}`)
    if (data) {
      push('\nstream\n')
      push(data)
      push('\nendstream')
    }
    push('\nendobj\n')
  }

  push('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n')
  object(1, '<< /Type /Catalog /Pages 2 0 R >>')
  object(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
  object(
    3,
    `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth.toFixed(8)} ${pageHeight.toFixed(8)}] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>`,
  )

  const content = `q\n${pageWidth.toFixed(8)} 0 0 ${pageHeight.toFixed(8)} 0 0 cm\n/Im0 Do\nQ\n`
  object(4, `<< /Length ${Buffer.byteLength(content)} >>`, Buffer.from(content))
  object(
    5,
    `<< /Type /XObject /Subtype /Image /Width ${width} /Height ${height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length ${compressed.length} >>`,
    compressed,
  )

  const xref = position
  push('xref\n0 6\n0000000000 65535 f \n')
  for (let index = 1; index <= 5; index += 1) {
    push(`${String(offsets[index]).padStart(10, '0')} 00000 n \n`)
  }
  push(`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`)
  fs.writeFileSync(outputPdf, Buffer.concat(parts))

  return {
    image_px: [width, height],
    page_pt: [pageWidth, pageHeight],
    page_mm: [widthMm, heightMm],
  }
}

function sha256File(path) {
  return crypto.createHash('sha256').update(fs.readFileSync(path)).digest('hex')
}

module.exports = {
  buildSvg,
  encodeCode128B,
  mmToPt,
  safeLabelSku,
  sha256File,
  writeExactPdfFromPng,
  wrapVisual,
}
