#!/usr/bin/env python3
"""Check published on-call instructions against the actual CLI dispatcher and files.

Documentation validation only: this does not execute any potentially mutating commands.
"""
import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SECTIONS = ('Trigger', 'Diagnosis', 'Intervention', 'Verification', 'Escalation')
COMMAND = re.compile(r'\bphp\s+bin/cajeer\s+([^\s#`]+)')
LINK = re.compile(r'(?<!!)\[[^]\n]+\]\(([^)\s]+\.md)(?:#[^)]*)?\)')
FENCE = re.compile(r'^```(?:sh|shell|bash)\s*$([\s\S]*?)^```\s*$', re.MULTILINE)


def dispatcher(root):
    text = (root / 'bin/cajeer').read_text(encoding='utf-8')
    match = re.search(r"if \(!in_array\(\$command, \[(.*?)\], true\)\) \{", text, re.S)
    if match is None:
        raise ValueError('Cannot locate CLI command dispatch allowlist')
    commands = set(re.findall(r"'([a-z][a-z0-9:_-]+)'", match.group(1)))
    if len(commands) < 30 or 'operations:incident' not in commands:
        raise ValueError('CLI dispatcher incomplete or missing operational commands')
    return commands


def validate(root):
    docs = root / 'docs/runbooks'
    if not docs.is_dir():
        raise ValueError('Missing on-call runbooks directory')
    files = sorted(docs.glob('*.md'))
    required = {'README.md', 'runtime-outage.md', 'database-outage.md', 'queue-backlog.md',
                'backup-disaster.md', 'security-incident.md', 'retention-storage.md',
                'maintenance-upgrade.md'}
    if not required.issubset({p.name for p in files}):
        raise ValueError('Required operational scenarios are missing')
    commands = dispatcher(root)
    count = 0
    root = root.resolve()
    for path in files:
        text = path.read_text(encoding='utf-8')
        for section in SECTIONS:
            if not re.search(r'^## ' + re.escape(section) + r'\s*$', text, re.MULTILINE):
                raise ValueError(f'{path.name}: missing section {section}')
        for name in LINK.findall(text):
            # Links cannot escape the release tree or resolve to missing documents.
            target = (path.parent / name).resolve()
            if not target.is_relative_to(root) or not target.is_file():
                raise ValueError(f'{path.name}: missing/unsafe document link: {name}')
        blocks = FENCE.findall(text)
        if not blocks:
            raise ValueError(f'{path.name}: missing executable shell examples')
        for block in blocks:
            for command in COMMAND.findall(block):
                if command not in commands:
                    raise ValueError(f'{path.name}: unknown CLI command: {command}')
                count += 1
    if count < 20:
        raise ValueError('Too few checked operational command examples')
    entrypoint = root / 'docs/Operations-Readiness-0.8.8.md'
    if not entrypoint.is_file() or '[the on-call index](runbooks/README.md)' not in entrypoint.read_text(encoding='utf-8'):
        raise ValueError('The operational entrypoint is missing or unlinked')
    return len(files), count


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT)
    args = parser.parse_args()
    try:
        count, examples = validate(args.root)
    except (ValueError, OSError) as error:
        print(f'Runbook validation failed: {error}', file=sys.stderr)
        return 1
    print(f'Runbooks verified: {count} documents, {examples} executable CLI command references')
    return 0


if __name__ == '__main__':
    sys.exit(main())
