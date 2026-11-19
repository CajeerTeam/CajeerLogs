# Release Policy — CajeerLogs 0.9.9

Semantic Versioning is used for the public API v1 and supported compatibility matrix. A candidate branch or version label does not constitute LTS or a security maintenance guarantee. The exact support lifecycle and promised response times must be approved and published at 1.0 GA.

- **Patch**: backward-compatible fixes, no intentional breaking change to `/api/v1` or active PostgreSQL data shape.
- **Minor**: additive, compatible interfaces with migration and rollback test evidence.
- **Major**: incompatible API/schema changes only after announced migration path.
- **Security**: prioritize critical vulnerabilities; publish advisory with affected releases, mitigations and patched artifacts.
- **Evidence**: only the full verified release archive may be certified; CI matrix, security, load, DR, upgrade/rollback, supply-chain proof and GitHub OIDC attestation must match its SHA-256.
- **Rollback**: restore prior release binary and original compatible database backup; never assume down migrations restore dropped data.

See [Release Candidate Operations](Release-Candidate-Operations-0.9.9.md) and [Operational Checklist](Production-checklist.md).
