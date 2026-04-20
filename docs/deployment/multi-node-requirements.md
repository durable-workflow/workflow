# Multi-Node Deployment Requirements

## Overview

This document outlines the requirements for deploying the workflow v2 engine across multiple server nodes. Multi-node deployments enable horizontal scaling, high availability, and geographic distribution.

## Prerequisites

### Shared Database

All nodes must connect to the same database instance:
- ✅ MySQL 8.0+
- ✅ PostgreSQL 13+
- ✅ MariaDB 10.5+

**Connection Configuration:**

All nodes share identical database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=db.internal.example.com
DB_PORT=3306
DB_DATABASE=workflows
DB_USERNAME=workflow_user
DB_PASSWORD=<secure-password>
```

**Connection Pooling:**

For high-throughput deployments, use connection pooling:
- ProxySQL (MySQL)
- PgBouncer (PostgreSQL)
- RDS Proxy (AWS)

### Shared Cache Backend

All nodes must connect to the same cache instance for long-poll wake signal coordination.

**Required Cache Backends:**
- ✅ Redis 6.0+ (recommended)
- ✅ Database cache (MySQL/PostgreSQL)
- ✅ Memcached 1.6+
- ❌ File cache (single-node only)

**Redis Configuration:**

All nodes share identical Redis credentials:
```env
CACHE_DRIVER=redis
REDIS_HOST=redis.internal.example.com
REDIS_PASSWORD=<secure-password>
REDIS_PORT=6379
REDIS_DB=1
```

**Database Cache Configuration:**

Uses shared database for cache storage:
```env
CACHE_DRIVER=database
# DB_* credentials same as above
```

**Why Shared Cache Required:**

Wake signals must propagate across nodes. When Node A creates a task, Node B's poller must be notified immediately. File-based cache is per-node and cannot propagate signals.

See [Long-Poll Coordination](../long-poll-coordination.md) for technical details.

## Node Configuration

### Environment Variables

All nodes should have identical configuration for workflow behavior:
```env
# Namespace (consistent across nodes)
DW_V2_NAMESPACE=production

# Compatibility (deploy same build to all nodes)
DW_V2_CURRENT_COMPATIBILITY=build-20260415-1a2b3c4
DW_V2_SUPPORTED_COMPATIBILITIES=build-20260415-1a2b3c4

# Task dispatch
DW_V2_TASK_DISPATCH_MODE=queue

# Limits (consistent across nodes)
DW_V2_LIMIT_PENDING_ACTIVITIES=2000
DW_V2_LIMIT_PENDING_CHILDREN=1000
# ... etc
```

The legacy `WORKFLOW_V2_*` names are honored as fallbacks during the
deprecation window — see zorporation/durable-workflow#494 — but new
deployments should use the `DW_V2_*` primary names.

### Node-Specific Variables

Each node can have unique values for:
```env
# Worker identity (unique per node)
APP_NAME=workflow-node-1

# Local storage
FILESYSTEM_DISK=local

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=info
```

## Deployment Process

### Zero-Downtime Deployment

1. **Pre-deployment validation:**
   ```bash
   # Verify database connectivity
   php artisan migrate:status
   
   # Verify cache connectivity
   php artisan tinker
   >>> cache()->put('deploy-test', time());
   >>> cache()->get('deploy-test');
   ```

2. **Deploy to first node (canary):**
   ```bash
   git pull origin main
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. **Verify canary health:**
   ```bash
   curl https://node-1.example.com/api/health
   ```

4. **Deploy to remaining nodes (rolling):**
   - Deploy one node at a time
   - Wait for health check to pass
   - Move to next node

5. **Post-deployment verification:**
   ```bash
   # Verify all nodes responding
   for node in node-{1..5}; do
     curl https://$node.example.com/api/health
   done
   ```

### Database Migrations

**Critical:** Run migrations on ONE node only before deploying to others.

```bash
# On deployment leader node
php artisan migrate --force
```

Other nodes will see migrated schema on boot. Do NOT run migrations on multiple nodes simultaneously.

## Load Balancing

### HTTP Load Balancer

Distribute control plane API requests across nodes:
```nginx
upstream workflow_api {
    least_conn;
    server node-1.example.com:443;
    server node-2.example.com:443;
    server node-3.example.com:443;
}

server {
    listen 443 ssl;
    server_name api.workflows.example.com;
    
    location / {
        proxy_pass https://workflow_api;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

**Health Check Endpoint:**
```
GET /api/health
```

Returns 200 if node healthy.

### Worker Distribution

Workers connect directly to database and cache. No load balancer needed.

**Horizontal Scaling:**
- Add more nodes → more polling capacity
- Each node polls independently
- Tasks claimed atomically (database fencing)
- No coordination overhead beyond wake signals

## Monitoring

### Key Metrics Per Node

**Task Throughput:**
- Workflow tasks claimed/sec
- Activity tasks claimed/sec
- Task completion rate

**Poll Efficiency:**
- Wake signal hit rate (% polls triggered by wake signal vs timeout)
- Timing hint hit rate (% polls using `available_at` hint)
- Average poll latency

**Cache Health:**
- Cache hit rate
- Cache latency (p50, p99)
- Cache error rate

**Database Health:**
- Query latency (p50, p99)
- Connection pool utilization
- Deadlock rate

### Aggregate Metrics

**Cluster Capacity:**
- Total tasks/sec across all nodes
- Total active workers
- Queue depth (ready tasks not yet claimed)

**Wake Signal Propagation:**
- Time from task creation to worker poll (p50, p99)
- Cross-node wake latency

## Troubleshooting

### Symptom: Tasks Stuck in Ready State

**Check:** Are all nodes polling?
```sql
-- Count ready tasks by queue
SELECT queue, COUNT(*) 
FROM workflow_tasks 
WHERE status = 'ready' 
GROUP BY queue;
```

**Fix:** Ensure workers running on all nodes.

### Symptom: Workers Not Receiving Tasks from Other Nodes

**Check:** Cache backend coordination
```bash
# Node 1: Create wake signal
php artisan tinker
>>> app('Workflow\V2\Support\CacheLongPollWakeStore')->signal('test-channel');

# Node 2: Check signal received
>>> $store = app('Workflow\V2\Support\CacheLongPollWakeStore');
>>> $before = $store->snapshot(['test-channel']);
>>> # (Node 1 signals again)
>>> $store->changed($before); // Should return true
```

**Fix:** Verify shared cache configuration. Check network connectivity between nodes and cache backend.

### Symptom: Duplicate Task Execution

**This should not happen** due to atomic claim fencing. If it does:

1. Check database isolation level:
   ```sql
   SELECT @@transaction_isolation; -- Should be READ-COMMITTED or higher
   ```

2. Check for clock skew between nodes:
   ```bash
   date -u # Run on all nodes, compare
   ```

3. File bug report with reproduction steps.

## Best Practices

1. **Start with 2-3 nodes** for most workloads
2. **Scale horizontally** by adding nodes (not vertically)
3. **Monitor wake signal latency** to detect cache backend issues
4. **Use connection pooling** for high-throughput workloads
5. **Keep nodes in same datacenter/region** to minimize latency
6. **Use blue-green deployment** for zero-downtime migrations
7. **Test multi-node locally** using Docker Compose before production

## Example Docker Compose Setup

```yaml
version: '3.8'

services:
  db:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: workflows
      MYSQL_ROOT_PASSWORD: secret
    volumes:
      - db_data:/var/lib/mysql
      
  redis:
    image: redis:7-alpine
    volumes:
      - redis_data:/data
      
  node-1:
    build: .
    environment:
      APP_NAME: workflow-node-1
      CACHE_DRIVER: redis
      REDIS_HOST: redis
      DB_HOST: db
      DB_DATABASE: workflows
      DB_USERNAME: root
      DB_PASSWORD: secret
    depends_on:
      - db
      - redis
      
  node-2:
    build: .
    environment:
      APP_NAME: workflow-node-2
      CACHE_DRIVER: redis
      REDIS_HOST: redis
      DB_HOST: db
      DB_DATABASE: workflows
      DB_USERNAME: root
      DB_PASSWORD: secret
    depends_on:
      - db
      - redis

volumes:
  db_data:
  redis_data:
```

Run:
```bash
docker-compose up -d
docker-compose exec node-1 php artisan migrate
docker-compose logs -f
```

## Security Considerations

1. **Network isolation:** Database and cache should not be public
2. **TLS/SSL:** Use encrypted connections for database and Redis
3. **Authentication:** Secure database and cache with strong passwords
4. **Firewall:** Only workflow nodes should reach database/cache ports
5. **Secrets management:** Use environment-specific secret stores (Vault, AWS Secrets Manager)

## Performance Tuning

### Database

- **Index optimization:** Ensure indexes on `workflow_tasks` (status, queue, available_at)
- **Connection pooling:** Use PgBouncer or ProxySQL
- **Query optimization:** Monitor slow query log

### Cache

- **Redis persistence:** Consider RDB snapshots + AOF for durability
- **Memory allocation:** Plan for 10MB per 10,000 active channels
- **Eviction policy:** Use `volatile-lru` or `allkeys-lru`

### Application

- **Polling concurrency:** Increase workers per node for throughput
- **Task dispatch mode:** Use `queue` mode for background processing
- **Lease durations:** Tune based on task execution time (default: 10s)

