# Production checklist

- Nginx root на public/
- APP_DEBUG=false
- HTTPS
- 2FA для админов
- backups
- Prometheus scrape
- queue worker
- scheduler timer

## 0.8.8 operational handoff

See [Operations Readiness](Operations-Readiness-0.8.8.md): verify `operations:triage`, keep incident evidence private with `operations:incident`, authenticate offsite backups, perform a restore rehearsal on an empty target, and document a rollback decision/owner. A passing local check does not certify end-to-end DR objectives.
