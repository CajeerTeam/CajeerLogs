-- Error groups are calculated from the current committed log_events rows, so
-- INSERT / UPDATE / DELETE, retention and redaction never leave stale groups.
-- Normalize high-cardinality identifiers before hashing. Never store the
-- template or original message in an analytics table.
CREATE FUNCTION log_error_signature(raw text, project uuid, service text)
RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT encode(sha256(convert_to(
        'v1:' || project::text || ':' || service || ':' ||
        regexp_replace(
          regexp_replace(
            regexp_replace(
              regexp_replace(
                regexp_replace(
                  regexp_replace(lower(left(raw,4096)),
                    '[[:xdigit:]]{8}-[[:xdigit:]]{4}-[[:xdigit:]]{4}-[[:xdigit:]]{4}-[[:xdigit:]]{12}', '<uuid>', 'g'),
                  '[a-z0-9._%+\-]+@[a-z0-9.\-]+[.][a-z]{2,}', '<email>', 'g'),
                '([0-9]{1,3}[.]){3}[0-9]{1,3}', '<ip>', 'g'),
              '0x[0-9a-f]+', '<hex>', 'g'),
            '[0-9]+', '<num>', 'g'),
          '[[:space:]]+', ' ', 'g'),
        'UTF8')), 'hex')
$$;

-- Restrict the working set to scoped errors and a bounded 31-day range.
-- All filters, including errors with a specific fingerprint, still read the
-- authoritative table instead of a lagging async index.
CREATE INDEX log_events_error_analytics_idx ON log_events(project_id, occurred_at, source_id, service)
    WHERE source_id IS NOT NULL AND level IN ('error','critical','alert','emergency');
