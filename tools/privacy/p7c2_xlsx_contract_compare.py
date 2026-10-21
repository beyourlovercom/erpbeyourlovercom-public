#!/usr/bin/env python3
"""P7-C2 read-only structural contract compare for original and sanitized XLSX.

Does not edit either file or display worksheet cell contents.
Complements privacy audit; does not prove confidentiality or runtime compatibility.
"""
import argparse
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

NS = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'


def xsha(data):
    return hashlib.sha256(data).hexdigest()


def extract(path):
    with zipfile.ZipFile(path) as z:
        names = sorted(z.namelist())
        required = {'xl/workbook.xml', 'xl/styles.xml'}
        if not required.issubset(names):
            raise ValueError('Workbook or styles XML is missing')
        wb = ET.fromstring(z.read('xl/workbook.xml'))
        sheet_nodes = wb.findall(f'{NS}sheets/{NS}sheet')
        sheets = [(n.attrib.get('name', ''), n.attrib.get('state', 'visible')) for n in sheet_nodes]
        worksheet_parts = sorted((n for n in names if re.fullmatch(r'xl/worksheets/sheet\d+\.xml', n)),
                                 key=lambda n: int(re.search(r'sheet(\d+)', n).group(1)))
        layouts = []
        for part in worksheet_parts:
            root = ET.fromstring(z.read(part))
            formulas = []
            styles = []
            occupied = []
            for c in root.iter(f'{NS}c'):
                addr = c.attrib.get('r', '')
                occupied.append(addr)
                styles.append((addr, c.attrib.get('s', '0')))
                f = c.find(f'{NS}f')
                if f is not None:
                    formulas.append((addr, f.text or '', sorted(f.attrib.items())))
            layouts.append({
                'part': part,
                'formula_cells': formulas,
                'styles_by_cell': styles,
                'occupied_coordinates': occupied,
                'merged_ranges': sorted(n.attrib.get('ref', '') for n in root.iter(f'{NS}mergeCell')),
                'row_layout': [(n.attrib.get('r'), n.attrib.get('ht'), n.attrib.get('hidden'))
                               for n in root.iter(f'{NS}row')],
                'column_layout': [sorted(n.attrib.items()) for n in root.iter(f'{NS}col')],
                'data_validations': [ET.tostring(n, encoding='unicode') for n in root.iter(f'{NS}dataValidation')],
            })
        return {'zip_members': names, 'sheets': sheets, 'layouts': layouts,
                'styles_sha256': xsha(z.read('xl/styles.xml')),
                'comment_part_names': sorted(n for n in names if re.fullmatch(r'xl/comments\d+\.xml', n)),
                'formula_count': sum(len(x['formula_cells']) for x in layouts)}


def compare(old, new, kind):
    errors = []
    if old['zip_members'] != new['zip_members']:
        errors.append('ZIP member paths changed')
    if old['sheets'] != new['sheets']:
        errors.append('Sheet names, order or visibility changed')
    if old['styles_sha256'] != new['styles_sha256']:
        errors.append('Styles XML bytes changed; needs independent visual review')
    if old['comment_part_names'] != new['comment_part_names']:
        errors.append('Comment parts added or removed')
    if len(old['layouts']) != len(new['layouts']):
        errors.append('Worksheet part count changed')
    for i, (a, b) in enumerate(zip(old['layouts'], new['layouts']), 1):
        for key in ('formula_cells', 'styles_by_cell', 'merged_ranges', 'row_layout',
                    'column_layout', 'data_validations'):
            if a[key] != b[key]:
                errors.append(f'worksheet {i}: {key} changed')
        if a['occupied_coordinates'] != b['occupied_coordinates']:
            errors.append(f'worksheet {i}: cell slots added/removed')
    if kind == 'pi':
        expected = ['PI', 'PL-美国仓', 'PL', '美国仓', '加拿大仓']
        if [name for name, _ in new['sheets']] != expected:
            errors.append('PI canonical five-sheet contract changed')
        if new['formula_count'] <= 0:
            errors.append('PI unexpectedly has no formulas')
        if not any(addr == 'D24' and re.sub(r'\s+', '', f).upper() in ('SUM(D5:D22)',
                 'SUM(\'PI\'!D5:D22)') for x in new['layouts'] for addr, f, _ in x['formula_cells']):
            errors.append('PI D24 SUM(D5:D22) missing or changed')
        if len(new['comment_part_names']) != 4:
            errors.append('PI expected four comments XML parts')
    elif kind == 'speeda' and len(new['sheets']) != 2:
        errors.append('Speeda expected two worksheets')
    return errors


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('original', type=Path)
    p.add_argument('candidate', type=Path)
    p.add_argument('--kind', required=True, choices=('pi', 'speeda', 'generic'))
    a = p.parse_args()
    try:
        before, after = extract(a.original), extract(a.candidate)
        problems = compare(before, after, a.kind)
    except (OSError, ValueError, zipfile.BadZipFile, ET.ParseError) as exc:
        print(json.dumps({'state': 'ERROR', 'error_type': type(exc).__name__}))
        return 2
    report = {'stage': 'P7-C2 structural pre/post compare only', 'kind': a.kind,
              'state': 'PASS' if not problems else 'FAIL', 'errors': problems,
              'before': {'sheet_count': len(before['sheets']), 'formula_count': before['formula_count']},
              'after': {'sheet_count': len(after['sheets']), 'formula_count': after['formula_count']},
              'note': 'A PASS does not clear customer names, addresses, hidden metadata or Git history.'}
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if not problems else 1


if __name__ == '__main__':
    sys.exit(main())
