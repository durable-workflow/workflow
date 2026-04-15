# Long-Poll Coordination Load Tests

Validates cache backend performance for multi-node long-poll coordination. Tests measure wake signal propagation latency and throughput under realistic concurrent load.

## Purpose

These tests validate that the cache-backed long-poll coordination mechanism meets performance requirements for production multi-node deployments. They answer:

1. **Latency**: How fast do wake signals propagate from signal to poller detection?
2. **Throughput**: How many signals/sec can the backend handle?
3. **Baseline Validation**: Do cache backends meet minimum performance requirements?

## Test Scenarios

### Wake Latency Tests

Measure time from signal emission to change detection:

- **Redis Backend**: 50 concurrent channels, 100 iterations
- **Database Cache**: 50 concurrent channels, 100 iterations
- **Memcached**: 50 concurrent channels, 100 iterations
- **File Cache (Baseline)**: 10 concurrent channels, 50 iterations

**Metrics:** p50, p95, p99, mean, min, max latency (milliseconds)

### Throughput Tests

Measure signals processed per second under high concurrency:

- **Redis Backend**: 100 concurrent channels, 500 total signals
- **Database Cache**: 100 concurrent channels, 500 total signals

**Metrics:** signals/sec, total signals, duration

## Requirements

### Redis

```bash
# Start Redis
redis-server

# Verify
redis-cli ping # Should return PONG
```

### Memcached

```bash
# Start Memcached
memcached -d

# Verify
echo "stats" | nc localhost 11211
```

### Database Cache

Configured in `.env.testing`:
```env
CACHE_DRIVER=database
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=testing
```

Run cache table migration:
```bash
php artisan cache:table
php artisan migrate
```

## Running Tests

### All Load Tests

```bash
vendor/bin/phpunit --group=load
```

### Specific Backend

```bash
# Redis only
vendor/bin/phpunit --filter=it_measures_redis

# Database only
vendor/bin/phpunit --filter=it_measures_database

# Memcached only
vendor/bin/phpunit --filter=it_measures_memcached
```

### Single Test

```bash
vendor/bin/phpunit tests/LoadTest/LongPollCoordinationLoadTest.php::it_measures_redis_backend_wake_latency
```

## Interpreting Results

### Wake Latency

**Example output:**
```
=== Redis Results ===
  p50: 2.15ms
  p95: 5.32ms
  p99: 8.41ms
  mean: 2.89ms
  min: 0.52ms
  max: 12.33ms
  samples: 100
```

**What it means:**
- **p50**: 50% of wake signals propagate in ≤ 2.15ms
- **p95**: 95% of wake signals propagate in ≤ 5.32ms
- **p99**: 99% of wake signals propagate in ≤ 8.41ms (most important for production)

**Baseline Requirements:**
- Redis: p99 < 10ms ✅
- Database Cache: p99 < 50ms ✅
- Memcached: p99 < 20ms ✅

### Throughput

**Example output:**
```
=== Redis (High Concurrency) Results ===
  signals/sec: 12450
  total signals: 500
  duration: 0.04s
```

**What it means:**
- Backend processed 500 signals in 0.04 seconds = 12,450 signals/sec

**Baseline Requirements:**
- Redis: > 1000 signals/sec ✅
- Database Cache: > 500 signals/sec ✅

## Performance Baselines

These are the minimum acceptable performance levels for production multi-node deployments:

| Backend | p99 Wake Latency | Throughput | Production Ready |
|---------|-----------------|------------|------------------|
| Redis | < 10ms | > 1000 sig/sec | ✅ Yes (recommended) |
| Database Cache | < 50ms | > 500 sig/sec | ✅ Yes |
| Memcached | < 20ms | > 800 sig/sec | ✅ Yes |
| File Cache | N/A | N/A | ❌ No (single-node only) |

## Troubleshooting

### Redis Tests Skipped

**Error:** `Redis not available`

**Fix:**
```bash
# Check Redis running
redis-cli ping

# If not running
redis-server
```

### Memcached Tests Skipped

**Error:** `Memcached not available`

**Fix:**
```bash
# Check Memcached running
echo "stats" | nc localhost 11211

# If not running
memcached -d
```

### Database Cache Tests Fail

**Error:** `SQLSTATE[42S02]: Base table or view not found: 'cache'`

**Fix:**
```bash
php artisan cache:table
php artisan migrate --env=testing
```

### High Latency Results

If latency exceeds baselines:

1. **Check network latency** between test machine and cache backend
2. **Verify cache backend is local** (not remote/cloud)
3. **Check system load** (CPU, memory, disk I/O)
4. **Run tests multiple times** to account for variance

### Low Throughput Results

If throughput below baselines:

1. **Check cache backend configuration** (connection pools, memory limits)
2. **Verify no other processes** consuming cache backend resources
3. **Check for rate limiting** on cache backend
4. **Increase test duration** for more accurate measurement

## Local Docker Environment

For isolated testing:

```yaml
# docker-compose.test.yml
version: '3.8'

services:
  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
      
  memcached:
    image: memcached:1.6-alpine
    ports:
      - "11211:11211"
      
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_DATABASE: testing
    ports:
      - "3306:3306"
```

Run:
```bash
docker-compose -f docker-compose.test.yml up -d
vendor/bin/phpunit --group=load
docker-compose -f docker-compose.test.yml down
```

## CI Integration

Add to `.github/workflows/tests.yml`:

```yaml
- name: Run load tests
  run: |
    docker-compose -f docker-compose.test.yml up -d
    vendor/bin/phpunit --group=load
    docker-compose -f docker-compose.test.yml down
```

## Performance Tuning

If results don't meet baselines, consider:

1. **Redis:**
   - Use Unix socket instead of TCP
   - Enable pipelining
   - Tune maxmemory-policy

2. **Database Cache:**
   - Index `key` column
   - Use connection pooling (ProxySQL, PgBouncer)
   - Increase innodb_buffer_pool_size

3. **Memcached:**
   - Increase memory allocation (-m flag)
   - Use consistent hashing for multi-server setups
   - Tune connection limits

## Notes

- Tests measure **same-process** wake propagation (snapshot → signal → change detection)
- Real multi-node latency includes network RTT between nodes and cache backend
- File cache results are baseline only (no cross-process coordination)
- Run tests on production-like hardware for accurate capacity planning
