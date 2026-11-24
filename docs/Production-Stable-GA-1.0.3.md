# CajeerLogs v1.0.3 — Safe Update Installation

- Native Linux/systemd CLI installation of a newer signed release, with a verified PostgreSQL backup, isolated database clone and tested migration path.
- Persistent service masking, atomic release directory exchange, durable state journal, readiness checks, pre-commit rollback and post-commit forward recovery.
- No web-triggered privileged commands, automatic installation, or destructive in-place database rollback. Existing Docker/manual upgrade paths are unchanged.
- All API v1 and historical SQL migration compatibility and full release certification gates remain required. The source version does not itself prove GA publication.

See [Safe Update Installation](Safe-Update-Installation-1.0.3.md) for supported host layout, operational requirements, and recovery instructions.
