#!/usr/bin/env python3
"""P7-C2 surgical XLSX sample de-identification, Private worktree only.

Changes only explicit OOXML text slots; preserves all non-target ZIP members byte-for-byte.
Fails closed on unexpected source hashes, indices, or XML structure.
"""
import argparse
import collections
import hashlib
import io
import json
import os
import re
import tempfile
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET
from xml.sax.saxutils import escape

SPEEDA = Path("resources/logistics/templates/speeda-outbound-v1.xlsx")
PI = Path("ops/label-generator/templates/007-PI-sea-canonical-v1.xlsx")
EXPECTED = {
    str(SPEEDA): "2139fb2d2cdf1078b229c25a915dff5073d20628e608c6fccf0fe59ec90b42ec",
    str(PI): "68157db503a12fc721421cae80409958f532208b57b8cb8acd58137776a68a54",
}
N = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
SAMPLE = {24: "P7C-DEMO-0001", 27: "Fictional Recipient 01", 28: "CA",
          29: "San Francisco", 30: "123 Demo Street",
          31: "recipient01@example.invalid", 32: "94105"}


def digest(b):
    return hashlib.sha256(b).hexdigest()


def patch_si(blob, replacement):
    encoded = escape(replacement).encode("utf-8")
    count = 0
    def fn(m):
        nonlocal count
        count += 1
        return m.group(1) + (encoded if count == 1 else b"") + m.group(3)
    new = re.sub(rb"(<t(?:\s[^>]*)?>)(.*?)(</t>)", fn, blob, flags=re.S)
    if count == 0:
        raise ValueError("Cannot safely patch an si without a text node")
    return new


def patch_meta(xml):
    for key in (b"creator", b"lastModifiedBy"):
        patt = rb"(<(?:[A-Za-z0-9_]+:)?" + key + rb"(?:\s[^>]*)?>).*?(</(?:[A-Za-z0-9_]+:)?" + key + rb">)"
        xml = re.sub(patt, lambda m: m.group(1) + b"BYL Synthetic Fixture" + m.group(2), xml, flags=re.S)
    return xml


def sanitize_speeda(parts):
    ss = parts["xl/sharedStrings.xml"]
    entries = list(re.finditer(rb"<si\b[^>]*>.*?</si>", ss, flags=re.S))
    if len(entries) != 897:
        raise ValueError("Unexpected Speeda shared string count")
    refs = collections.Counter()
    for path, data in parts.items():
        if not re.fullmatch(r"xl/worksheets/sheet\d+\.xml", path):
            continue
        for c in ET.fromstring(data).iter(N + "c"):
            v = c.find(N + "v")
            if c.get("t") == "s" and v is not None and v.text is not None:
                refs[int(v.text)] += 1
    if any(refs[k] != 1 for k in SAMPLE):
        raise ValueError("Visible sample shares string index with other worksheet cells")
    unused = [i for i in range(len(entries)) if refs[i] == 0]
    spans = []
    for i, entry in enumerate(entries):
        value = SAMPLE.get(i)
        if i in unused:
            value = f"P7C-REDACTED-UNUSED-{i:04d}"
        spans.append((entry.start(), entry.end(), patch_si(entry.group(), value) if value is not None else entry.group()))
    out = bytearray()
    pos = 0
    for start, end, data in spans:
        out += ss[pos:start]
        out += data
        pos = end
    out += ss[pos:]
    parts["xl/sharedStrings.xml"] = bytes(out)

    sheet = parts["xl/worksheets/sheet1.xml"]
    rx = rb'(<c\b[^>]*\br="I2"[^>]*><v>)([^<]*)(</v></c>)'
    sheet, count = re.subn(rx, lambda m: m.group(1) + b"2025550101" + m.group(3), sheet)
    if count != 1:
        raise ValueError("Expected one numeric Speeda I2 sample contact")
    parts["xl/worksheets/sheet1.xml"] = sheet
    parts["docProps/core.xml"] = patch_meta(parts["docProps/core.xml"])
    return {"synthetic_visible_fields": len(SAMPLE) + 1, "redacted_unused_strings": len(unused)}


def sanitize_pi(parts):
    parts["docProps/core.xml"] = patch_meta(parts["docProps/core.xml"])
    comments = sorted(p for p in parts if re.fullmatch(r"xl/comments\d+\.xml", p))
    if len(comments) != 4:
        raise ValueError("Expected four original PI comment parts")
    for path in comments:
        contents, n = re.subn(rb"(<author>).*?(</author>)",
                              lambda m: m.group(1) + b"BYL Synthetic Fixture" + m.group(2),
                              parts[path], flags=re.S)
        if n != 1:
            raise ValueError("PI comment author count changed")
        parts[path] = contents
    return {"comment_author_parts_anonymized": len(comments)}


def build(src, kind):
    raw = src.read_bytes()
    if digest(raw) != EXPECTED[str(src)]:
        raise ValueError(f"Refusing unexpected source hash for {src.name}")
    with zipfile.ZipFile(io.BytesIO(raw), "r") as z:
        members = z.infolist()
        old = {n.filename: z.read(n.filename) for n in members}
    changed = dict(old)
    report = sanitize_speeda(changed) if kind == "speeda" else sanitize_pi(changed)
    modified = sorted(k for k in changed if changed[k] != old[k])
    allowed = ({"xl/sharedStrings.xml", "xl/worksheets/sheet1.xml", "docProps/core.xml"}
               if kind == "speeda" else {"docProps/core.xml", *(p for p in old if re.fullmatch(r"xl/comments\d+\.xml", p))})
    if not set(modified).issubset(allowed):
        raise ValueError("Unexpected changed member")
    for k, b in changed.items():
        if k.lower().endswith((".xml", ".rels")):
            ET.fromstring(b)
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as out:
        for info in members:
            out.writestr(info, changed[info.filename])
    result = buffer.getvalue()
    with zipfile.ZipFile(io.BytesIO(result)) as chk:
        if chk.testzip() is not None or sorted(chk.namelist()) != sorted(old):
            raise ValueError("Invalid XLSX zip after surgical edits")
    return result, {"name": src.name, "before_sha256": digest(raw),
                    "after_sha256": digest(result), "modified_parts": modified, **report}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    opts = parser.parse_args()
    candidates = [(SPEEDA, "speeda"), (PI, "pi")]
    work = []
    for path, kind in candidates:
        result, report = build(path, kind)
        work.append((path, result, report))
    if opts.apply:
        tmps = []
        try:
            for path, raw, _ in work:
                fd, name = tempfile.mkstemp(prefix="p7c2-", suffix=".xlsx", dir=str(path.parent))
                with os.fdopen(fd, "wb") as f:
                    f.write(raw)
                tmps.append((name, path))
            for name, path in tmps:
                os.replace(name, path)
        finally:
            for name, _ in tmps:
                if os.path.exists(name):
                    os.unlink(name)
    print(json.dumps({"phase": "APPLIED" if opts.apply else "DRY_RUN",
                      "results": [report for _, _, report in work]}, indent=2))


if __name__ == "__main__":
    main()
