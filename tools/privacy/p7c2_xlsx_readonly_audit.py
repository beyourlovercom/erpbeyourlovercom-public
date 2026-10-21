#!/usr/bin/env python3
"""P7-C2 private-only read-only XLSX contact-risk inventory.

No file mutation; never prints source customer-like cell contents.
This is a detection aid, NOT proof of PII removal or ERP regression clearance.
"""
import argparse
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

EMAIL = re.compile(r"(?i)(?<![\w.+-])[a-z0-9][a-z0-9._%+\-]*@[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}(?![\w.-])")
PHONE = re.compile(r"(?<![\w])(?:\+?\d[\d ()\-.]{6,}\d)(?![\w])")
SAFE_EMAIL_DOMAIN = {"example.invalid", "example.com", "example.net", "example.org", "test.invalid"}
XML_NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"


def fields(value):
    mails = [m.group() for m in EMAIL.finditer(value)]
    unsafe_email = [v for v in mails if v.rsplit("@", 1)[1].lower() not in SAFE_EMAIL_DOMAIN]
    phone_like = []
    for match in PHONE.finditer(value):
        digits = re.sub(r"\D", "", match.group())
        if 8 <= len(digits) <= 15 and not re.fullmatch(r"20255501\d{2}", digits):
            # Treat as an indicator, not a determination of personal data.
            phone_like.append(match.group())
    return unsafe_email, phone_like


def audit(path, max_locations=50):
    file_bytes = path.read_bytes()
    result = {
        "file": path.name,
        "sha256": hashlib.sha256(file_bytes).hexdigest(),
        "size_bytes": len(file_bytes),
        "zip_members": 0,
        "worksheets": [],
        "worksheet_xml_count": 0,
        "formula_count": 0,
        "merged_range_count": 0,
        "risky_email_occurrences": 0,
        "phone_like_occurrences": 0,
        "flagged_coordinates": [],
        "other_xml_risk_parts": [],
        "warnings": ["Indicators need manual classification; names, addresses, files, history and runtime are not cleared."],
    }
    with zipfile.ZipFile(path) as xlsx:
        names = xlsx.namelist()
        result["zip_members"] = len(names)
        common = []
        if "xl/sharedStrings.xml" in names:
            root = ET.fromstring(xlsx.read("xl/sharedStrings.xml"))
            for si in root.findall(f"{XML_NS}si"):
                common.append("".join(t.text or "" for t in si.iter(f"{XML_NS}t")))
        result["shared_string_count"] = len(common)
        # Sheet XML paths are used instead of potentially sensitive human labels.
        for member in sorted(n for n in names if re.fullmatch(r"xl/worksheets/sheet\d+\.xml", n)):
            root = ET.fromstring(xlsx.read(member))
            count = 0
            result["worksheet_xml_count"] += 1
            result["formula_count"] += len(root.findall(f".//{XML_NS}f"))
            result["merged_range_count"] += len(root.findall(f".//{XML_NS}mergeCell"))
            for cell in root.iter(f"{XML_NS}c"):
                value = ""
                typ = cell.attrib.get("t", "")
                v = cell.find(f"{XML_NS}v")
                if typ == "s" and v is not None and v.text:
                    try:
                        value = common[int(v.text)]
                    except (ValueError, IndexError):
                        result["warnings"].append("Invalid shared-string index")
                elif typ == "inlineStr":
                    value = "".join(t.text or "" for t in cell.iter(f"{XML_NS}t"))
                elif typ in ("str", "e") and v is not None:
                    value = v.text or ""
                elif typ not in ("b",) and v is not None:
                    value = v.text or ""
                if not value:
                    continue
                emails, phones = fields(value)
                if not (emails or phones):
                    continue
                result["risky_email_occurrences"] += len(emails)
                result["phone_like_occurrences"] += len(phones)
                count += 1
                if len(result["flagged_coordinates"]) < max_locations:
                    # No source cell content, fragments, domains, or contact values.
                    result["flagged_coordinates"].append({"part": member, "cell": cell.attrib.get("r", "?"), "email_risk": bool(emails), "phone_like": bool(phones)})
            result["worksheets"].append({"xml_part": member, "flagged_cells": count})
        result["flagged_coordinate_limit"] = max_locations
        result["other_embedded_parts_present"] = sorted(n for n in names if n.startswith("xl/") and ("comments" in n.lower() or "externalLinks" in n or "connections" in n))
        # Review shared-string leftovers, comments, metadata, links and drawing XML,
        # including strings that no visible worksheet cell currently references.
        other_xml = sorted(n for n in names if n.lower().endswith((".xml", ".rels", ".vml"))
                           and not re.fullmatch(r"xl/worksheets/sheet\d+\.xml", n))
        for member in other_xml:
            try:
                root = ET.fromstring(xlsx.read(member))
            except ET.ParseError:
                result["warnings"].append("Unreadable XML part: " + member)
                continue
            email_count = 0
            phone_count = 0
            for node in root.iter():
                for value in [node.text or "", *node.attrib.values()]:
                    emails, phones = fields(value)
                    email_count += len(emails)
                    phone_count += len(phones)
            if email_count or phone_count:
                result["other_xml_risk_parts"].append({"part": member, "email_risk": email_count, "phone_like": phone_count})
        result["warnings"].append("Other XML risks can duplicate worksheet-referenced shared strings; classify before clearing.")
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("xlsx", nargs="+", type=Path)
    parser.add_argument("--max-locations", type=int, default=50)
    args = parser.parse_args()
    results = []
    for file in args.xlsx:
        if not file.is_file():
            parser.error(f"File not found: {file.name}")
        try:
            results.append(audit(file, max_locations=max(0, args.max_locations)))
        except (zipfile.BadZipFile, ET.ParseError, OSError) as exc:
            print(json.dumps({"file": file.name, "error": type(exc).__name__}), file=sys.stderr)
            return 2
    print(json.dumps({"stage": "P7-C2 read-only inventory (NOT privacy clearance)", "results": results}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
