#!/usr/bin/env python3
"""Negative contract mutation tests plus executed PHP/OpenAPI conformance."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('api_contract', ROOT/'scripts/api_contract.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
ORIGINAL = json.loads((ROOT/'api/openapi.yaml').read_bytes())


class ContractRegression(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        result = subprocess.run([sys.executable,str(ROOT/'scripts/api_contract.py')],cwd=ROOT,
                                capture_output=True,text=True,timeout=90)
        assert result.returncode == 0, result.stderr
        cls.full_test = result.stdout.strip()

    def test_real_router_and_error_responses(self):
        self.assertIn('138 executed router operations',self.full_test)

    def rejects(self, change, message):
        changed = copy.deepcopy(ORIGINAL)
        change(changed)
        with self.assertRaisesRegex(module.ContractFailure,message):
            module.compare(ORIGINAL,changed)

    def test_deleted_route_rejected(self):
        self.rejects(lambda x: x['paths'].pop('/auth/login'), 'Removed API operations')

    def test_removed_success_response_rejected(self):
        self.rejects(lambda x: x['paths']['/auth/login']['post']['responses'].pop('200'), 'response status removed')

    def test_new_required_request_field_rejected(self):
        def change(spec):
            schema=spec['paths']['/auth/login']['post']['requestBody']['content']['application/json']['schema']
            schema['required'].append('otp')
        self.rejects(change,'incompatible required fields')

    def test_removed_response_property_rejected(self):
        def change(spec):
            schema=spec['paths']['/auth/login']['post']['responses']['200']['content']['application/json']['schema']
            schema['properties'].pop('csrf_token')
        self.rejects(change,'existing field removed')

    def test_narrowed_request_bound_rejected(self):
        def change(spec):
            schema=spec['paths']['/auth/login']['post']['requestBody']['content']['application/json']['schema']
            schema['properties']['password']['maxLength']=5
        self.rejects(change,'request bound maxLength tightened')

    def test_new_required_query_parameter_rejected(self):
        def change(spec):
            spec['paths']['/health']['get']['parameters']=[{'name':'force','in':'query','required':True,'schema':{'type':'string'}}]
        self.rejects(change,'new required parameter')

    def test_error_validation_rejects_unknown_properties(self):
        with self.assertRaisesRegex(module.ContractFailure,'Undocumented error field'):
            module.validate_error(ORIGINAL,dict(error='bad',message='Bad',request_id='abcd',debug_trace='secret'))


if __name__ == '__main__':
    unittest.main(verbosity=2)
