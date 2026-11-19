"""Fail closed on stale, incomplete or invented operational command instructions."""
import importlib.util
import shutil
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('check_runbooks', ROOT / 'scripts/check_runbooks.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

files, count = module.validate(ROOT)
assert files >= 8 and count >= 20
print('PASS real on-call runbooks validated')
with tempfile.TemporaryDirectory() as temp:
    root = Path(temp)
    shutil.copytree(ROOT / 'docs', root / 'docs')
    (root / 'bin').mkdir()
    shutil.copy2(ROOT / 'bin/cajeer', root / 'bin/cajeer')
    target = root / 'docs/runbooks/runtime-outage.md'
    original = target.read_text()
    target.write_text(original.replace('php bin/cajeer doctor', 'php bin/cajeer nonexistent:danger'))
    try: module.validate(root)
    except ValueError as e: assert 'unknown CLI command' in str(e)
    else: raise AssertionError('Invented command accepted')
    target.write_text(original.replace('## Escalation', '## Escalated'))
    try: module.validate(root)
    except ValueError as e: assert 'missing section' in str(e)
    else: raise AssertionError('Missing escalation accepted')
    target.write_text(original.replace('../Production-Security.md', '../missing-guide.md'))
    try: module.validate(root)
    except ValueError as e: assert 'missing/unsafe document link' in str(e)
    else: raise AssertionError('Broken documentation link accepted')
print('PASS broken commands, broken links and incomplete procedures rejected')
