# Analytics

The existing user-facing `/logs/stats` endpoint uses the PostgreSQL repository and its authorization/snapshot logic. ClickHouse 0.7.1 supplies an optional, separately managed **real HTTP analytics adapter** with project-scoped aggregation and versioned ClickHouse schema migrations. No automatic cross-database replication or dashboard cutover occurs in 0.7.1; see [ClickHouse.md](ClickHouse.md) for configuration, operational commands and limitations.
