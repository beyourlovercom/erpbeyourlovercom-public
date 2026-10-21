import { createHash } from 'node:crypto';
import { lstat, readFile, readdir, realpath } from 'node:fs/promises';
import path from 'node:path';
import { inflateRawSync } from 'node:zlib';
import { fileURLToPath } from 'node:url';

const MAX_FILE_BYTES = 10 * 1024 * 1024;
const MAX_ARCHIVE_UNCOMPRESSED_BYTES = 6 * 1024 * 1024;
const MAX_ARCHIVE_ENTRIES = 200;
const allowedTextExtensions = new Set(['.txt', '.json', '.html', '.xml', '.md', '.csv']);
const forbiddenExtensions = new Set(['.db', '.sqlite', '.sqlite3', '.har', '.log', '.env', '.png', '.jpg', '.jpeg', '.webm']);
const forbiddenName = /(?:storage[-_.]?state|runtime[-_.]?env|secret|password|session|cookie|csrf|xsrf|authorization)/i;
const sensitiveContent = /(?:password(?:_confirmation)?|authorization|bearer\s+[a-z0-9._~+\/-]+|["']?(?:cookie|set[-_]?cookie|session[-_]?cookie)["']?\s*[:=]|set-cookie|session(?:_id)?|xsrf|csrf|app_key|database_(?:url|password)|private[_-]?key)/i;
const emailPattern = /[a-z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-z0-9.-]+\.[a-z]{2,}/gi;

function assertSafeName(name) {
    if (!name || name.includes('\0') || path.isAbsolute(name) || name.split(/[\\/]/).includes('..')) {
        throw new Error('Artifact path is unsafe.');
    }
    if (forbiddenName.test(name)) {
        throw new Error('Artifact filename violates the sensitive-data policy.');
    }
}

function scanText(buffer, { allowedEmailDomain, secretValues }) {
    const text = buffer.toString('utf8');

    if (text.includes('\uFFFD') || /[\u0000-\u0008\u000B\u000C\u000E-\u001F]/.test(text)) {
        throw new Error('Artifact text is not valid UTF-8.');
    }
    if (sensitiveContent.test(text)) {
        throw new Error('Artifact contains a blocked sensitive-data marker.');
    }
    for (const secret of secretValues) {
        if (secret && text.includes(secret)) {
            throw new Error('Artifact contains a configured canary secret.');
        }
    }
    if (text.includes('@')) {
        for (const email of text.match(emailPattern) ?? []) {
            if (!email.toLowerCase().endsWith(`@${allowedEmailDomain.toLowerCase()}`)) {
                throw new Error('Artifact contains a non-synthetic email address.');
            }
        }
    }
}

function findEndOfCentralDirectory(buffer) {
    const minimum = Math.max(0, buffer.length - 65_557);

    for (let offset = buffer.length - 22; offset >= minimum; offset--) {
        if (buffer.readUInt32LE(offset) === 0x06054b50) return offset;
    }

    throw new Error('ZIP central directory is missing.');
}

function* readZipEntries(buffer) {
    const end = findEndOfCentralDirectory(buffer);
    const entryCount = buffer.readUInt16LE(end + 10);
    let offset = buffer.readUInt32LE(end + 16);

    if (entryCount > MAX_ARCHIVE_ENTRIES) throw new Error('ZIP has too many entries.');

    for (let index = 0; index < entryCount; index++) {
        if (buffer.readUInt32LE(offset) !== 0x02014b50) throw new Error('ZIP central directory is invalid.');
        const flags = buffer.readUInt16LE(offset + 8);
        const compression = buffer.readUInt16LE(offset + 10);
        const compressedSize = buffer.readUInt32LE(offset + 20);
        const size = buffer.readUInt32LE(offset + 24);
        const nameLength = buffer.readUInt16LE(offset + 28);
        const extraLength = buffer.readUInt16LE(offset + 30);
        const commentLength = buffer.readUInt16LE(offset + 32);
        const externalAttributes = buffer.readUInt32LE(offset + 38);
        const localOffset = buffer.readUInt32LE(offset + 42);
        const name = buffer.subarray(offset + 46, offset + 46 + nameLength).toString('utf8');

        assertSafeName(name);
        if (flags & 0x1) throw new Error('Encrypted ZIP entries are prohibited.');
        if (((externalAttributes >>> 16) & 0o170000) === 0o120000) throw new Error('ZIP symlinks are prohibited.');
        if (size > MAX_FILE_BYTES) throw new Error('ZIP entry is oversized.');
        if (buffer.readUInt32LE(localOffset) !== 0x04034b50) throw new Error('ZIP local header is invalid.');

        const localNameLength = buffer.readUInt16LE(localOffset + 26);
        const localExtraLength = buffer.readUInt16LE(localOffset + 28);
        const dataOffset = localOffset + 30 + localNameLength + localExtraLength;
        if (dataOffset + compressedSize > buffer.length) throw new Error('ZIP entry data is truncated.');
        yield { compressedSize, compression, dataOffset, name, size };
        offset += 46 + nameLength + extraLength + commentLength;
    }
}

function scanBuffer(name, buffer, options, depth = 0, archiveBudget = { uncompressedBytes: 0 }) {
    assertSafeName(name);
    if (buffer.length > MAX_FILE_BYTES) throw new Error('Artifact is oversized.');

    const extension = path.extname(name).toLowerCase();
    if (forbiddenExtensions.has(extension)) throw new Error('Artifact type is always prohibited.');

    if (extension === '.zip') {
        if (depth >= 2) throw new Error('Nested ZIP depth is prohibited.');
        for (const entry of readZipEntries(buffer)) {
            archiveBudget.uncompressedBytes += entry.size;
            if (archiveBudget.uncompressedBytes > MAX_ARCHIVE_UNCOMPRESSED_BYTES) {
                throw new Error('ZIP cumulative uncompressed size exceeds the safety budget.');
            }

            const compressed = buffer.subarray(entry.dataOffset, entry.dataOffset + entry.compressedSize);
            let content;

            if (entry.compression === 0) content = compressed;
            else if (entry.compression === 8) content = inflateRawSync(compressed, { maxOutputLength: MAX_FILE_BYTES });
            else throw new Error('Unsupported ZIP compression method.');

            if (content.length !== entry.size) throw new Error('ZIP entry size does not match metadata.');
            scanBuffer(entry.name, content, options, depth + 1, archiveBudget);
        }

        return;
    }

    if (!allowedTextExtensions.has(extension)) throw new Error('Unknown artifact format is prohibited.');
    scanText(buffer, options);
}

export async function scanArtifactTree(root, options = {}) {
    const allowedEmailDomain = options.allowedEmailDomain ?? 'byl-e2e.invalid';
    const secretValues = options.secretValues ?? [];
    const rootEntry = await lstat(root);
    if (!rootEntry.isDirectory() || rootEntry.isSymbolicLink()) throw new Error('Artifact root must be a real directory.');
    const resolvedRoot = await realpath(root);
    const records = [];

    async function visit(directory) {
        for (const entry of await readdir(directory, { withFileTypes: true })) {
            const candidate = path.join(directory, entry.name);
            const metadata = await lstat(candidate);

            if (metadata.isSymbolicLink() || (!metadata.isDirectory() && !metadata.isFile())) {
                throw new Error('Symlinks and special files are prohibited.');
            }
            if (metadata.isDirectory()) {
                await visit(candidate);
                continue;
            }

            const resolved = await realpath(candidate);
            const relative = path.relative(resolvedRoot, resolved);
            if (relative.startsWith(`..${path.sep}`) || path.isAbsolute(relative)) throw new Error('Artifact escaped its root.');

            const buffer = await readFile(resolved);
            scanBuffer(relative, buffer, { allowedEmailDomain, secretValues });
            records.push({ bytes: buffer.length, digest: createHash('sha256').update(buffer).digest('hex'), path: relative });
        }
    }

    await visit(resolvedRoot);
    const manifestHash = createHash('sha256').update(JSON.stringify(records)).digest('hex');

    return { files: records.length, manifestHash };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    scanArtifactTree(process.argv[2], { secretValues: process.env.BYL_E2E_SCAN_CANARIES?.split(',') ?? [] })
        .then((result) => process.stdout.write(`${JSON.stringify(result)}\n`))
        .catch(() => {
            process.stderr.write('Artifact scan failed without exposing matched content.\n');
            process.exitCode = 1;
        });
}
