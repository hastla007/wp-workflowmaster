#!/usr/bin/env python3
"""Prints one Markdown table row per line of an E2E results.jsonl (not shipped). Usage: results-table.py file..."""
import json
import sys

for path in sys.argv[1:]:
    for line in open(path, encoding='utf-8'):
        line = line.strip()
        if not line:
            continue
        r = json.loads(line)
        detail = r.get('detail', '').replace('|', '\\|').replace('\n', ' ')
        if len(detail) > 160:
            detail = detail[:157] + '...'
        print(f"| {r['id']} | {r['name'].replace('|', '/')} | **{r['result']}** | {detail} |")
