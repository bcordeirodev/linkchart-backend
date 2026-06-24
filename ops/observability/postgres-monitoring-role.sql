-- ops/observability/postgres-monitoring-role.sql
-- Read-only role for postgres_exporter. pg_monitor grants read access to
-- pg_stat_* views without any write/DDL capability.
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'monitoring') THEN
    CREATE ROLE monitoring LOGIN PASSWORD 'CHANGE_ME_AT_APPLY';
  END IF;
END $$;
GRANT pg_monitor TO monitoring;
GRANT CONNECT ON DATABASE linkchartprod TO monitoring;
