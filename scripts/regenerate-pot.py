#!/usr/bin/env python3
"""
Regenerate languages/ultimate-performance.pot from src/Admin/AdminPage.php and other
plugin source files. Extracts __(), esc_html__(), esc_attr_e(), _e(), _x() etc.

Run: python3 /home/z/my-project/work/ultimate-cache-extract/scripts/regenerate-pot.py
"""
import os
import re
import sys
from pathlib import Path

ROOT = Path('/home/z/my-project/work/up-final-v3')
PHP_FILES = []
for ext_dir in ['src', 'ultimate-performance.php']:
    p = ROOT / ext_dir
    if p.is_file() and p.suffix == '.php':
        PHP_FILES.append(p)
    elif p.is_dir():
        PHP_FILES.extend(p.rglob('*.php'))

# Filter out vendor
PHP_FILES = [p for p in PHP_FILES if 'vendor' not in str(p)]

# Match translation function calls: __('msg'), esc_html__('msg'), _e('msg'),
# _x('msg', 'ctx'), etc. The 2nd arg is msgctxt ONLY for *_x / _nx variants;
# for __/esc_html__/_e/etc. the 2nd arg is the text domain, NOT msgctxt.
TRANS_RE = re.compile(
    r'\b('
    r'__|esc_html__|esc_attr__|_e|esc_html_e|esc_attr_e|'
    r'_n|_n_noop|_nx|_nx_noop|'
    r'_x|esc_html_x|esc_attr_x'
    r')\s*\(\s*'
    r'([\'"])((?:\\.|(?!\2).)*)\2'
    r'(?:\s*,\s*'
    r'([\'"])((?:\\.|(?!\4).)*)\4'  # 2nd arg — msgctxt only for _x variants
    r')?'
)

CONTEXTUAL = { '_x', 'esc_html_x', 'esc_attr_x', '_nx', '_nx_noop' }

entries = {}  # msgid -> set of (file, line)
contexts = {}  # msgid -> msgctxt

for php_path in sorted(PHP_FILES):
    try:
        src = php_path.read_text(encoding='utf-8')
    except Exception:
        continue
    rel = str(php_path.relative_to(ROOT))
    for lineno, line in enumerate(src.splitlines(), 1):
        for m in TRANS_RE.finditer(line):
            fn = m.group(1)
            msgid = m.group(3)
            second = m.group(5) if m.group(5) else None
            if not msgid:
                continue
            entries.setdefault(msgid, set()).add(f"{rel}:{lineno}")
            if second is not None and fn in CONTEXTUAL:
                contexts[msgid] = second

pot_path = ROOT / 'languages' / 'ultimate-performance.pot'
existing = pot_path.read_text(encoding='utf-8') if pot_path.exists() else ''

main_src = (ROOT / 'ultimate-performance.php').read_text(encoding='utf-8')
m = re.search(r"^\s*\* Version:\s*([0-9.]+)", main_src, re.M)
version = m.group(1) if m else '0.0.0'

# Extract existing copyright header (preserve only the # comment lines,
# NOT the msgid/msgstr/Project-Id-Version block — that is regenerated below
# with the current version so stale versions do not leak into commits).
existing_header = []
for line in existing.splitlines():
    if not line.startswith('#'):
        break
    existing_header.append(line)

# Build a proper POT metadata header
from datetime import datetime, timezone
now = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M+0000')

header_lines = existing_header if existing_header else [
    '# Copyright (C) 2026 Ultimate Performance Contributors',
    '# This file is distributed under the GPL-2.0-or-later license.',
]

out_lines = list(header_lines) + [
    'msgid ""',
    'msgstr ""',
    f'"Project-Id-Version: Ultimate Performance {version}\\n"',
    '"Report-Msgid-Bugs-To: https://github.com/alirezax5/ultimate-cache/issues\\n"',
    f'"POT-Creation-Date: {now}\\n"',
    '"MIME-Version: 1.0\\n"',
    '"Content-Type: text/plain; charset=UTF-8\\n"',
    '"Content-Transfer-Encoding: 8bit\\n"',
    '"X-Generator: ultimate-cache-pot-gen 1.0\\n"',
    '"Language-Team: LANGUAGE <LL@li.org>\\n"',
]
out = '\n'.join(out_lines) + '\n'

for msgid in sorted(entries.keys()):
    refs = sorted(entries[msgid])
    out += '\n'
    # One reference per line — required for the audit's `^#: (\S+):(\d+)$` regex.
    for ref in refs:
        out += f'#: {ref}\n'
    if msgid in contexts:
        out += f'msgctxt "{contexts[msgid]}"\n'
    escaped = msgid.replace('\\', '\\\\').replace('"', '\\"').replace("\n", '\\n')
    out += f'msgid "{escaped}"\n'
    out += 'msgstr ""\n'

pot_path.write_text(out, encoding='utf-8')
print(f"POT regenerated: {len(entries)} msgids, version {version}")
print(f"  -> {pot_path.relative_to(ROOT)}")
