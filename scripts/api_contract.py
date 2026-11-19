#!/usr/bin/env python3
"""Executable OpenAPI v1 compatibility gate using actual PHP routing and response envelopes.

No third-party Python dependencies. The baseline is the unmodified, compressed
0.9.1 specification; it is SHA-pinned so a release cannot silently rebase v1.
"""
from __future__ import annotations

import argparse
import gzip
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
BASELINE_NAME = 'api/v1-baseline-0.9.1.json.gz'
BASELINE_HASH = '22f7297ef45add3312948e99d48b7c08e16219c30babebbe2ce019bc1caf76d8'
METHODS = {'get', 'post', 'put', 'patch', 'delete', 'head'}


class ContractFailure(Exception):
    pass


def check(ok: bool, message: str) -> None:
    if not ok:
        raise ContractFailure(message)


def reference(spec: dict, schema: object) -> object:
    seen = set()
    while isinstance(schema, dict) and '$ref' in schema:
        pointer = schema['$ref']
        check(isinstance(pointer, str) and pointer.startswith('#/'), f'Unsupported ref: {pointer}')
        check(pointer not in seen, f'Cyclic $ref: {pointer}')
        seen.add(pointer)
        value = spec
        for part in pointer[2:].split('/'):
            key = part.replace('~1', '/').replace('~0', '~')
            check(isinstance(value, dict) and key in value, f'Unresolved $ref: {pointer}')
            value = value[key]
        schema = value
    return schema


def operations(spec: dict) -> dict[tuple[str, str], dict]:
    result = {}
    ids = set()
    for path, item in spec['paths'].items():
        check(path.startswith('/') and isinstance(item, dict), f'Invalid path {path}')
        for method, op in item.items():
            if method not in METHODS:
                continue
            name = op.get('operationId')
            check(isinstance(name, str) and name and name not in ids, f'Invalid/duplicate operationId: {name}')
            ids.add(name)
            check(isinstance(op.get('responses'), dict) and op['responses'], f'Missing responses for {method} {path}')
            for code, detail in op['responses'].items():
                check(code == 'default' or re.fullmatch(r'[1-5]\d{2}', code) is not None, f'Invalid HTTP status {code}')
                check(isinstance(reference(spec, detail), dict), f'Invalid HTTP response for {method} {path}')
            result[method.upper(), path] = op
    return result


def verify_refs(spec: dict, value: object, visited: set[str] | None = None) -> int:
    visited = visited if visited is not None else set()
    count = 0
    if isinstance(value, dict):
        if '$ref' in value:
            pointer = value['$ref']
            target = reference(spec, value)
            if pointer in visited:
                return 0
            visited.add(pointer)
            count += 1 + verify_refs(spec, target, visited)
        for key, item in value.items():
            if key != '$ref':
                count += verify_refs(spec, item, visited)
    elif isinstance(value, list):
        for item in value:
            count += verify_refs(spec, item, visited)
    return count


def compare_schema(old_spec: dict, new_spec: dict, before: object, after: object, *, response: bool,
                   path: str, visited: set[tuple[str, str]] | None = None) -> None:
    visited = visited if visited is not None else set()
    old_ref = before.get('$ref', '') if isinstance(before, dict) else ''
    new_ref = after.get('$ref', '') if isinstance(after, dict) else ''
    pair = (old_ref, new_ref)
    if old_ref and new_ref and pair in visited:
        return
    if old_ref or new_ref:
        visited.add(pair)
    before = reference(old_spec, before)
    after = reference(new_spec, after)
    check(isinstance(before, dict) and isinstance(after, dict), f'{path}: missing schema')
    for key in ('type', 'format'):
        if key in before:
            check(before[key] == after.get(key), f'{path}: {key} changed')
    if 'const' in before:
        check(before['const'] == after.get('const'), f'{path}: const changed')
    if 'enum' in before:
        check('enum' in after, f'{path}: enum removed')
        old_values, new_values = before['enum'], after['enum']
        check(all(value in (old_values if response else new_values) for value in (new_values if response else old_values)), f'{path}: enum incompatible')
    for key, positive_is_stricter in [('minimum', True), ('exclusiveMinimum', True), ('minLength', True),
                                      ('minItems', True), ('maximum', False), ('exclusiveMaximum', False),
                                      ('maxLength', False), ('maxItems', False)]:
        if key in before and key in after:
            new, old = after[key], before[key]
            stricter = new > old if positive_is_stricter else new < old
            check(not stricter or response, f'{path}: request bound {key} tightened')
            weaker = new < old if positive_is_stricter else new > old
            check(not weaker or not response, f'{path}: response bound {key} weakened')
        elif key in after and key not in before and not response:
            raise ContractFailure(f'{path}: added request bound {key}')
        elif key in before and key not in after and response:
            raise ContractFailure(f'{path}: removed response bound {key}')
    for key in ('oneOf', 'anyOf', 'allOf', 'not'):
        if key in before or key in after:
            check(before.get(key) == after.get(key), f'{path}: changed composition {key}')
    if 'pattern' in before or 'pattern' in after:
        check(before.get('pattern') == after.get('pattern'), f'{path}: regex pattern changed')
    old_required = set(before.get('required', []))
    new_required = set(after.get('required', []))
    check(old_required <= new_required if response else new_required <= old_required, f'{path}: incompatible required fields')
    old_props, new_props = before.get('properties', {}), after.get('properties', {})
    check(isinstance(old_props, dict) and isinstance(new_props, dict), f'{path}: invalid properties')
    check(old_props.keys() <= new_props.keys(), f'{path}: existing field removed')
    if not response and before.get('additionalProperties', True) is True:
        check(after.get('additionalProperties', True) is not False, f'{path}: new request property restriction')
    if response and before.get('additionalProperties', True) is False:
        check(after.get('additionalProperties', True) is False, f'{path}: response now allows extra fields')
    for prop in old_props:
        compare_schema(old_spec, new_spec, old_props[prop], new_props[prop], response=response, path=f'{path}.{prop}', visited=visited)
    if 'items' in before:
        check('items' in after, f'{path}: array items schema removed')
        compare_schema(old_spec, new_spec, before['items'], after['items'], response=response, path=path+'[]', visited=visited)


def compare(old: dict, new: dict) -> int:
    earlier, current = operations(old), operations(new)
    check(earlier.keys() <= current.keys(), f'Removed API operations: {sorted(earlier.keys() - current.keys())}')
    check(old.get('security') == new.get('security'), 'Global security requirements changed')
    check(old.get('components', {}).get('securitySchemes') == new.get('components', {}).get('securitySchemes'), 'Security scheme definitions changed')
    for (method, path), before in earlier.items():
        after = current[method, path]
        tag = f'{method} {path}'
        check(before.get('security', None) == after.get('security', None), f'{tag}: security contract changed')
        old_params = {(x['in'], x['name']): x for x in before.get('parameters', [])}
        new_params = {(x['in'], x['name']): x for x in after.get('parameters', [])}
        check(old_params.keys() <= new_params.keys(), f'{tag}: parameter removed')
        for key, param in new_params.items():
            if key not in old_params:
                check(not param.get('required', False), f'{tag}: new required parameter {key}')
            else:
                old_param = old_params[key]
                check(not param.get('required', False) or old_param.get('required', False), f'{tag}: parameter became required')
                if 'schema' in old_param:
                    compare_schema(old, new, old_param['schema'], param.get('schema'), response=False, path=f'{tag} param {key}')
        old_body, new_body = before.get('requestBody'), after.get('requestBody')
        if not old_body and new_body:
            check(not new_body.get('required', False), f'{tag}: new mandatory request body')
        if old_body:
            check(new_body is not None, f'{tag}: request body removed')
            check(not new_body.get('required', False) or old_body.get('required', False), f'{tag}: body newly required')
            for content_type, detail in old_body.get('content', {}).items():
                check(content_type in new_body.get('content', {}), f'{tag}: request content type removed')
                if 'schema' in detail:
                    compare_schema(old, new, detail['schema'], new_body['content'][content_type].get('schema'), response=False, path=f'{tag} input')
        check(before['responses'].keys() <= after['responses'].keys(), f'{tag}: response status removed')
        for code, value in before['responses'].items():
            old_response = reference(old, value)
            new_response = reference(new, after['responses'][code])
            for content_type, detail in old_response.get('content', {}).items():
                check(content_type in new_response.get('content', {}), f'{tag} {code}: response content type removed')
                if 'schema' in detail:
                    compare_schema(old, new, detail['schema'], new_response['content'][content_type].get('schema'), response=True, path=f'{tag} {code}')
    return len(earlier)


def validate_error(spec: dict, envelope: dict) -> None:
    schema = spec['components']['schemas']['Error']
    check(isinstance(envelope, dict), 'Error is not JSON object')
    check(set(schema['required']) <= envelope.keys(), 'Missing required error fields')
    check(envelope.keys() <= schema['properties'].keys(), 'Undocumented error field')
    for name in ('error', 'message', 'request_id'):
        check(isinstance(envelope[name], str) and envelope[name] != '', f'Invalid {name} in response')
    if 'details' in envelope:
        check(isinstance(envelope['details'], dict), 'Invalid error details')
        detail_schema = schema['properties']['details']
        check(set(detail_schema['required']) <= envelope['details'].keys(), 'Missing error reason')
        check(envelope['details'].keys() <= detail_schema['properties'].keys(), 'Undocumented detail field')


def main(root: Path) -> None:
    old_bytes = gzip.decompress((root / BASELINE_NAME).read_bytes())
    check(hashlib.sha256(old_bytes).hexdigest() == BASELINE_HASH, 'Pinned API baseline changed')
    old = json.loads(old_bytes)
    new_bytes = (root / 'api/openapi.yaml').read_bytes()
    check(new_bytes == (root / 'openapi.yaml').read_bytes(), 'Published OpenAPI files differ')
    new = json.loads(new_bytes)
    release = json.loads((root / 'release.json').read_bytes())
    check(new['info']['version'] == release['version'], 'OpenAPI version differs from runtime release')
    check(new['servers'] == [{'url': '/api/v1'}] and release['api'] == 'v1', 'Public API base path changed')
    check(new['openapi'].startswith('3.1.'), 'Expected OpenAPI 3.1')
    check(old['info']['version'] == '0.9.1', 'Invalid baseline version')
    ref_count = verify_refs(new, new)
    count = compare(old, new)
    run = subprocess.run(['php', str(root / 'tests/api_router_contract.php')], cwd=root,
                         capture_output=True, text=True, timeout=60)
    check(run.returncode == 0, 'Actual PHP router contract failed: ' + run.stderr[-2000:])
    routes = json.loads(run.stdout)
    actual = {(x['method'], x['path']) for x in routes['operations']}
    declared = set(operations(new))
    check(actual == declared, f'OpenAPI/router drift; missing: {sorted(declared - actual)}, excess: {sorted(actual - declared)}')
    for err in routes['errors']:
        validate_error(new, err['body'])
    check([x['status'] for x in routes['errors']] == [404, 405, 400, 422, 503, 500], 'Error status codes changed')
    print(f'PASS API v1: {count} backward-compatible operations, {len(actual)} executed router operations, '
          f'{ref_count} resolved refs, {len(routes["errors"])} real error response envelopes, {routes["checks"]} runtime checks')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=ROOT)
    args = parser.parse_args()
    try:
        main(args.root.resolve())
    except (ContractFailure, OSError, ValueError, KeyError, subprocess.TimeoutExpired) as exc:
        print('FAIL API v1: ' + str(exc), file=sys.stderr)
        sys.exit(1)
