# Vortos Scheduler — Operations Runbook

## Architecture Overview

The Vortos Scheduler is a leader-elected, sharded cron/interval dispatcher backed by DBAL (Postgres recommended). Each scheduler node runs a daemon (`SchedulerDaemon`) that:

1. Races to acquire shard leases via `LeasePort` (Redis or Postgres advisory locks).
2. Scans due schedules for each held shard (`DueScan`).
3. Dispatches fires atomically via `FireDispatcher` (insert-then-enqueue, idempotency guaranteed by `UNIQUE(tenant_id, schedule_id, slot)`).
4. Publishes an audit entry for each event via `SchedulerAuditProjector`.

**Sharding**: `abs(crc32(scheduleId)) % shardCount` — same formula in both DueScan and SchedulerDaemon. Never change one without the other.

**Idempotency anchor**: the fire-ledger row `(tenant_id, schedule_id, slot)`. A double-tick, split-brain, or lost leader never causes a double-fire — the unique constraint absorbs it.

---

## Key Tables

| Table | Purpose |
|-------|---------|
| `vortos_scheduler_schedules` | Schedule definitions |
| `vortos_scheduler_runs` | Fire-ledger (exactly-once) |
| `vortos_scheduler_leases` | SQL-backed lease store (alternative to Redis/Postgres advisory) |
| `vortos_scheduler_audit_log` | HMAC-chained audit trail |
| `vortos_scheduler_audit_checkpoints` | Per-epoch audit chain checkpoints (S11) |
| `vortos_scheduler_fire_queue` | Outbox for dispatched commands |
| `vortos_scheduler_static_overrides` | Operator pause/resume of static schedules |
| `vortos_scheduler_approvals` | 4-eyes approval records |

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `SCHEDULER_SHARD_COUNT` | `1` | Number of scheduler shards |
| `SCHEDULER_LEASE_TTL_SEC` | `30` | Lease TTL in seconds (minimum 5) |
| `SCHEDULER_MAX_IDLE_SEC` | `60` | Max sleep between ticks when no work |
| `SCHEDULER_TENANT_MAX_CONCURRENT_FIRES` | `0` | Per-tenant fire cap per tick (0 = unlimited) |
| `SCHEDULER_MAX_CATCHUP_AGE_SECONDS` | `86400` | Misfire catch-up horizon (1 day) |
| `SCHEDULER_ASSUMED_DONE_TTL_SECONDS` | `3600` | TTL after which an in-flight run is assumed done |
| `SCHEDULER_AUDIT_HMAC_KEY` | *(required for audit)* | Secret key for HMAC chain signing |
| `SCHEDULER_AUDIT_EPOCH_SIZE` | `1000` | Entries between audit checkpoints |
| `SCHEDULER_DEADMAN_TOLERANCE_SEC` | `300` | Late-fire tolerance before dead-man alert |
| `SCHEDULER_CB_FAILURE_THRESHOLD` | `5` | Circuit-breaker open threshold (consecutive failures) |
| `SCHEDULER_CB_RECOVERY_WINDOW_SEC` | `30` | Circuit-breaker half-open window |
| `SCHEDULER_METRICS_MAX_CARDINALITY` | `200` | Max distinct schedule_id Prometheus labels |
| `SCHEDULER_RESOLVER_CACHE_TTL_SEC` | `5` | In-process resolver cache TTL (0 = disabled) |
| `VORTOS_CACHE_DSN` | `redis://redis:6379` | Redis DSN for Redis lease store |

---

## CLI Commands

```bash
# List all schedules with status
php bin/console scheduler:list [--tenant=TENANT] [--status=active|paused|disabled]

# Run all due schedules now (bypasses the daemon, for testing/backfill)
php bin/console scheduler:run-now SCHEDULE_NAME [--reason="manual run"]

# Pause a schedule
php bin/console scheduler:pause SCHEDULE_NAME [--reason="maintenance"]

# Resume a paused schedule
php bin/console scheduler:resume SCHEDULE_NAME

# Approve a pending 4-eyes schedule change
php bin/console scheduler:approve APPROVAL_ID

# Run health checks (9 checks, fail-closed)
php bin/console scheduler:doctor

# Prune old completed/failed runs
php bin/console scheduler:prune --before="-30 days"

# Export audit trail
php bin/console scheduler:audit:export [--from=DATE] [--to=DATE] [--chain-key=KEY] > audit.jsonl
```

---

## Starting and Stopping the Daemon

```bash
# Start daemon (foreground, Docker)
docker compose exec backend php bin/console scheduler:run

# Graceful stop: send SIGTERM
kill -SIGTERM <pid>

# Supervisord (recommended for production)
# The SchedulerExtension auto-registers a WorkerProcessDefinition if the
# vortos-docker package is installed. See WorkerProcessDefinition for config.
```

---

## Health Checks / deploy:doctor

The scheduler registers a `SchedulerPreflightCheck` (wrapping `SchedulerDoctor`) with the `vortos.preflight_check` tag when `vortos-deploy` is installed. This means `deploy:doctor` (and `deploy:release`) will automatically gate on scheduler health.

The 9 checks (C1–C9) cover:
- **C1**: Cron expression validity for all active schedules
- **C2**: Schedule name collision (static ↔ dynamic)
- **C3**: Command allowlist (all scheduled commands must be in the allowlist)
- **C4**: Lease backend reachability (acquire + release probe)
- **C5**: DB table existence (schedules, runs, leases, audit_log)
- **C6**: 4-eyes approval coverage (schedules requiring approval have an active approval store)
- **C7**: Misfire policy safety (no `fireEachMissed` with large catch-up windows without explicit override)
- **C8**: Catchup bounds (no schedule overdue by > maxCatchupAgeSec)
- **C9**: Per-shard lease probe (verifies each shard can acquire a test lease)

Run manually: `php bin/console scheduler:doctor`

---

## Responding to Alerts

### Dead-Man Alert (schedule hasn't fired in > tolerance window)

```bash
# 1. Check recent runs for the schedule
php bin/console scheduler:list --name=SCHEDULE_NAME

# 2. Check doctor for any blocking issues
php bin/console scheduler:doctor

# 3. If the daemon is down, restart it
docker compose restart scheduler-daemon

# 4. Force a manual run to catch up
php bin/console scheduler:run-now SCHEDULE_NAME --reason="manual catch-up after outage"
```

### Audit Chain Integrity Failure

The audit chain uses HMAC-SHA256. A broken chain means either:
- A bug in the `SchedulerAuditProjector`
- Direct database tampering (security incident)
- An incorrect `SCHEDULER_AUDIT_HMAC_KEY` (e.g. key rotation without re-signing)

```bash
# Export the chain for forensic analysis
php bin/console scheduler:audit:export --chain-key=scheduler:TENANT:production > chain.jsonl

# Escalate to the security team if tampering is suspected
# The chain is append-only — there is no repair path. Snapshot the table for evidence.
```

### Circuit Breaker Stuck Open

The `DispatchCircuitBreaker` opens after `SCHEDULER_CB_FAILURE_THRESHOLD` consecutive failures and recovers automatically after `SCHEDULER_CB_RECOVERY_WINDOW_SEC` seconds. If the backend is unavailable for a prolonged period:

1. Identify the failing backend (command bus, queue, DB).
2. Fix the underlying issue.
3. The circuit breaker will self-heal on the next tick after the recovery window.
4. If `SCHEDULER_CB_FAILURE_THRESHOLD` fires are already missed, they will be caught up on the next tick (up to `SCHEDULER_MAX_CATCHUP_AGE_SECONDS`).

### Cardinality Overflow in Metrics

If you see `vortos_scheduler_metric_overflow_total` counter incrementing:
- More than `SCHEDULER_METRICS_MAX_CARDINALITY` distinct schedule IDs are emitting metrics.
- Increase `SCHEDULER_METRICS_MAX_CARDINALITY` or investigate whether dynamic schedules are being created without cleanup.
- Schedules mapped to `__overflow__` still fire and audit correctly — only the Prometheus label is bucketed.

---

## Scaling

### Adding Shards

```bash
# 1. Update SCHEDULER_SHARD_COUNT in environment (e.g., from 1 to 4)
# 2. Run migrations (no schema change required — shard index is computed, not stored)
# 3. Restart all daemon instances
#    Each instance will race for the new shard keys. Shard assignment is automatic.
# NOTE: Increasing shard count redistributes schedules. A brief period of missed fires
#       is possible during rollover. Set maxCatchupAgeSec to catch these up automatically.
```

### Adding Daemon Instances

Simply start more instances. Leader election via LeasePort is automatic. Standby nodes sleep with node-seeded jitter (crc32(hostname:pid)) to prevent synchronised wake-up storms.

---

## Security Notes

- **SCHEDULER_AUDIT_HMAC_KEY** must be a high-entropy secret (32+ bytes). Rotate via key-versioned HMAC: add the new key, re-verify old records, then remove the old key.
- The fire-ledger and audit log are append-only by design. Grant only `INSERT` and `SELECT` to the scheduler DB user.
- The `SchedulerPreflightCheck` never mutates schedule data. It is safe to run during deploy preflight.
- The 4-eyes gate prevents single-actor changes to sensitive schedules. Ensure `FourEyesGate` is configured for any schedule that runs with elevated privileges.

---

## Benchmarks

Run performance benchmarks before major releases:

```bash
docker compose exec backend php vendor/bin/phpbench run packages/Vortos/src/Scheduler/Tests/Bench \
  --bootstrap=vendor/autoload.php --report=default
```

Key metrics to watch:
- `benchCronNextRunAfterHourly` — should be < 500µs/op
- `benchCachingResolverCacheHit` — should be < 50µs/op
- `benchDueScan200Schedules` — should be < 5ms/op

---

## Soak and Chaos Tests

```bash
# 24-hour soak (in-memory, ~2 seconds wall time)
docker compose exec backend php vendor/bin/phpunit --group=soak --testsuite=Scheduler

# Chaos scenarios (transient failures, circuit breaker, idempotency)
docker compose exec backend php vendor/bin/phpunit --group=chaos --testsuite=Scheduler
```
