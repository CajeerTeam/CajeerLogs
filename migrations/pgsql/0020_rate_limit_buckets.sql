-- Shared token buckets; integer credits avoid floating-point drift.
CREATE TABLE api_rate_buckets (
    bucket_key varchar(64) PRIMARY KEY,
    credits bigint NOT NULL CHECK (credits >= 0),
    updated_us bigint NOT NULL,
    expires_at timestamptz NOT NULL
);
CREATE INDEX api_rate_buckets_expiry_idx ON api_rate_buckets(expires_at);
CREATE FUNCTION api_rate_charge(p_key text, p_rate integer, p_burst integer)
RETURNS TABLE(allowed boolean, retry_after integer, remaining integer)
LANGUAGE plpgsql AS $$
DECLARE old_credits bigint; old_us bigint; now_us bigint; available bigint;
BEGIN
 IF p_rate < 1 OR p_rate > 100000 OR p_burst < 1 OR p_burst > p_rate THEN
  RAISE EXCEPTION 'Invalid rate policy' USING ERRCODE='22023';
 END IF;
 LOOP
  INSERT INTO api_rate_buckets(bucket_key,credits,updated_us,expires_at)
  VALUES(p_key,p_burst::bigint*60000000,0,clock_timestamp()+interval '2 hours') ON CONFLICT DO NOTHING;
  SELECT b.credits,b.updated_us INTO old_credits,old_us FROM api_rate_buckets b WHERE b.bucket_key=p_key FOR UPDATE;
  EXIT WHEN FOUND;
 END LOOP;
 now_us := greatest(old_us, floor(extract(epoch FROM clock_timestamp())*1000000)::bigint);
 available := least(p_burst::bigint*60000000,old_credits+least(now_us-old_us,60000000)*p_rate);
 allowed := available>=60000000;
 IF allowed THEN available := available-60000000; END IF;
 retry_after := CASE WHEN allowed THEN 0 ELSE greatest(1,ceil((60000000-available)::numeric/(p_rate::bigint*1000000))::integer) END;
 remaining := (available/60000000)::integer;
 UPDATE api_rate_buckets SET credits=available,updated_us=now_us,expires_at=clock_timestamp()+interval '2 hours' WHERE bucket_key=p_key;
 RETURN NEXT;
END $$;
