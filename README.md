# Notifications Service

A queue-backed, multi-channel (SMS / email / push) notification delivery service built on **Laravel 13 + Horizon + Redis + PostgreSQL**. It exposes a versioned REST API, dispatches delivery onto per-priority queues, enforces per-channel rate limiting and a per-channel circuit breaker, retries transient provider failures with jittered backoff, and records every delivery attempt. Operational endpoints (`/health`, `/metrics`) and structured JSON logs with end-to-end correlation ids round it out.

---

## Architecture

```
                       ┌──────────┐
   HTTP client ───────▶│  nginx   │───────▶ php-fpm (app)
   X-Correlation-Id    └──────────┘             │
                                                │  routes/api.php  (v1)
                                                │  AssignCorrelationId ─▶ Context (logs + jobs)
                                                │  FormRequest ─▶ Controller ─▶ API Resource
                                                ▼
                                   ┌──────────────────────────────┐
                                   │      NotificationService      │
                                   │  validate · persist · queue   │
                                   └───────────────┬──────────────┘
                                                   │ dispatch onto priority queue
                                                   ▼
                ┌──────────────────────────────────────────────────────────┐
   Redis ◀──────┤  queues:  notifications-high · notifications-normal · -low │
                └───────────────────────────────┬──────────────────────────┘
                                                 │  strict priority (Horizon, balance:off)
                                                 ▼
                                 ┌──────────────────────────────────┐
                                 │   Horizon worker · scheduler      │
                                 │   SendNotification job            │
                                 │   ├─ middleware EnsureChannelCircuitClosed
                                 │   ├─ middleware RateLimitChannel (100/s/channel)
                                 │   └─ handle: validate → send → record attempt
                                 └───────────────┬──────────────────┘
                                                 │  Http POST { to, channel, content }
                                                 ▼
                                 ┌──────────────────────────────────┐
                                 │   WebhookSiteProvider             │
                                 │   classify → ProviderResult       │
                                 │     2xx+messageId → success       │
                                 │     429 / 5xx / conn → Transient  │
                                 │     4xx / 2xx-no-id  → Permanent  │
                                 └──────────────────────────────────┘

   PostgreSQL   notifications · notification_batches · delivery_attempts · templates
   Redis        queues · cache · rate limiter · circuit breaker · idempotency lock
```

**Request → delivery flow**

1. The API validates the payload (per-channel content rules), persists a `Notification` (`pending`), transitions it to `queued`, and dispatches `SendNotification` onto the queue for its priority.
2. Horizon's single supervisor drains `high → normal → low` in **strict priority order**.
3. Before running, the job passes two middleware: the **circuit breaker** (releases without consuming the retry budget if the channel is open) and the **rate limiter** (≤100 msg/s per channel).
4. The job calls the **provider**, which owns success/failure classification. Success → `sent` + `provider_message_id`; transient → re-throw (retry with backoff); permanent → fail fast to `failed_jobs`.
5. Every attempt writes a `delivery_attempts` row; the correlation id from the originating request rides along via Laravel **Context**, so request, job, and provider-attempt logs share one id.

### Key components

| Concern | Where |
|---|---|
| API (controllers, FormRequests, resources) | `app/Http/` |
| Domain models + enums | `app/Models/`, `app/Enums/` |
| Orchestration | `app/Services/NotificationService.php` |
| Delivery job | `app/Jobs/SendNotification.php` (+ `app/Jobs/Middleware/`) |
| Provider + classification | `app/Delivery/` (`NotificationProvider`, `WebhookSiteProvider`, `ProviderResult`, exceptions) |
| Circuit breaker | `app/Delivery/CircuitBreaker.php` |
| Rate limiter | `app/Support/RateLimiting/ChannelRateLimiter.php` |
| Backoff, idempotency lock, SMS segmentation, content validation | `app/Support/` |
| Health / metrics | `app/Support/Health/`, `app/Support/Metrics/` |

---

## One-command setup

The repo ships with a working `.env` (and `.env.example`). Bring the whole stack up and migrate:

```bash
docker compose up -d --build && docker compose exec app php artisan migrate --force
```

That builds the PHP image once and starts **app** (php-fpm), **nginx**, **postgres:16**, **redis:7**, a **horizon** worker, and a **scheduler**. Code is bind-mounted, so edits are live without a rebuild.

- API: <http://localhost:8000>
- API docs (Scribe / OpenAPI): <http://localhost:8000/docs>
- Horizon dashboard: <http://localhost:8000/horizon>

> Fresh clone without a `.env`? Run `cp .env.example .env && docker compose exec app php artisan key:generate` first.

---

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `APP_URL` | `http://localhost:8000` | Base URL |
| `APP_PORT` | `8000` | Host port → nginx |
| `DB_CONNECTION` | `pgsql` | Database driver |
| `DB_HOST` / `DB_PORT` | `postgres` / `5432` | PostgreSQL |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `notifications` / `notifications` / `secret` | DB credentials |
| `QUEUE_CONNECTION` | `redis` | Queue backend |
| `CACHE_STORE` | `redis` | Cache store |
| `REDIS_CLIENT` | `predis` | Redis client |
| `REDIS_HOST` / `REDIS_PORT` | `redis` / `6379` | Redis |
| `HORIZON_PREFIX` | `notifications_horizon:` | Horizon key namespace |
| `LOG_CHANNEL` / `LOG_STACK` | `stack` / `json` | Structured JSON logging to stdout |
| `WEBHOOK_URL` | `https://webhook.site` | Delivery provider endpoint |
| `WEBHOOK_TIMEOUT` | `10` | Provider request timeout (s) |
| `WEBHOOK_CONNECT_TIMEOUT` | `5` | Provider connect timeout (s) |
| `CIRCUIT_BREAKER_THRESHOLD` | `5` | Consecutive failures before a channel opens |
| `CIRCUIT_BREAKER_COOLDOWN` | `60` | Open-circuit cooldown (s) |
| `CIRCUIT_BREAKER_FAILURE_TTL` | `120` | Rolling failure-window TTL (s) |

---

## API

Base path: `/api/v1`. All responses include an `X-Correlation-Id` header (echoed from the request or generated) and a `correlation_id` in the body.

### `GET /health` — dependency health

```bash
curl -s http://localhost:8000/api/v1/health | jq
```

Returns **200** when database, Redis, and queue are reachable; **503 `degraded`** otherwise (with the failing check named).

### `GET /metrics` — operational metrics

```bash
curl -s http://localhost:8000/api/v1/metrics | jq
```

```json
{
  "window_minutes": 5,
  "queue_depth": { "high": 0, "normal": 2, "low": 10 },
  "counts": { "sent": 1200, "failed": 3 },
  "throughput_per_min": 240.0,
  "latency_ms": { "p50": 42, "p95": 110, "p99": 190 }
}
```

### `POST /notifications` — create one

`content` is required for every channel. **email** also requires `subject`; **push** also requires `title`. SMS is segmented (GSM-7/UCS-2) and the segmentation is stored in `metadata.sms`.

```bash
# SMS
curl -s -X POST http://localhost:8000/api/v1/notifications \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'X-Correlation-Id: demo-001' \
  -d '{"recipient":"+15551234567","channel":"sms","content":"Your code is 123456","priority":"high"}' | jq

# Email
curl -s -X POST http://localhost:8000/api/v1/notifications \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"recipient":"user@example.com","channel":"email","subject":"Welcome","content":"Thanks for signing up","priority":"normal"}' | jq

# Push (with idempotency key — repeat calls return the original)
curl -s -X POST http://localhost:8000/api/v1/notifications \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"recipient":"device-token","channel":"push","title":"Order update","content":"Shipped","idempotency_key":"order-42-shipped"}' | jq
```

### `POST /notifications/batch` — create up to 1000

More than 1000 items is rejected with **422**.

```bash
curl -s -X POST http://localhost:8000/api/v1/notifications/batch \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
        "name": "welcome-wave",
        "notifications": [
          {"recipient":"+15551110000","channel":"sms","content":"Hi 1"},
          {"recipient":"a@example.com","channel":"email","subject":"Hi","content":"Hi 2"}
        ]
      }' | jq
```

### `GET /notifications/{id}` — status + delivery attempts

```bash
curl -s http://localhost:8000/api/v1/notifications/019ea8c5-32cc-7123-8c80-31f9eb291baa | jq
```

### `GET /notifications` — filter + paginate

Filters: `status`, `channel`, `from`, `to` (creation date range), `per_page` (1–100, default 15).

```bash
curl -s 'http://localhost:8000/api/v1/notifications?status=sent&channel=email&from=2026-01-01&to=2026-12-31&per_page=25' | jq
```

### `POST /notifications/{id}/cancel` — cancel if cancellable

Only `pending` / `queued` notifications can be cancelled; otherwise **409 Conflict**.

```bash
curl -s -X POST http://localhost:8000/api/v1/notifications/019ea8c5-32cc-7123-8c80-31f9eb291baa/cancel | jq
```

### `GET /batches/{batchId}` — batch status + rollup

```bash
curl -s http://localhost:8000/api/v1/batches/019ea883-0000-0000-0000-000000000000 | jq
```

```json
{ "data": { "total_count": 5 }, "rollup": { "queued": 3, "sent": 2 }, "correlation_id": "..." }
```

Full request/response schemas, query params, and a downloadable OpenAPI spec / Postman collection are at **`/docs`**.

---

## webhook.site configuration

The default delivery provider POSTs `{ to, channel, content }` to `WEBHOOK_URL` and treats **any 2xx response carrying a `messageId`** as a success. webhook.site's *default* response is a token-less 404 (or a 2xx without a `messageId`), which the provider correctly classifies as a **permanent failure** — so out of the box you'll see notifications go to `failed`.

To see successful deliveries:

1. Open <https://webhook.site> and copy your unique URL (e.g. `https://webhook.site/8f3c…`).
2. Set it in `.env`: `WEBHOOK_URL=https://webhook.site/8f3c…`
3. On webhook.site, edit the URL's **default response** ("Edit" / XHR Response) to return JSON with a message id and a 2xx status:
   ```json
   { "messageId": "{{ $request.uuid }}", "status": "accepted" }
   ```
   (`Content-Type: application/json`, status `200`).
4. Apply config + restart the worker:
   ```bash
   docker compose exec app php artisan config:clear
   docker compose restart horizon
   ```

Now POST a notification and watch it arrive on the webhook.site inspector and transition to `sent`. The provider extracts the id from `messageId` / `message_id` / `uuid` / `id`.

---

## Testing & quality

```bash
composer test            # full Pest suite (artisan test)
composer test:unit       # unit suite only
composer test:feature    # feature suite only
composer lint            # Pint (format)
composer analyse         # PHPStan level 8
composer check           # pint --test + phpstan + tests
```

Run the suite inside the container to exercise the Redis-dependent tests (rate limiter, circuit breaker, idempotency lock, job delivery), which **skip** when Redis is unreachable:

```bash
docker compose exec app composer test
```

**Coverage map**

- **Feature** — API CRUD, batch `>1000` rejection, cancel state rules (`409`), idempotency dedup, rate-limit middleware enforcement, retry → failed terminal, health (`200`/`503`), metrics, correlation-id propagation, circuit breaker + provider classification, end-to-end delivery.
- **Unit** — content validators, SMS segmenter, backoff calculator.

A `notifications:load-test` artisan command drives the live stack to verify ≤100/s rate limiting, strict priority draining, and idempotency under burst:

```bash
docker compose exec app php artisan notifications:load-test rate        --count=300 --channel=sms
docker compose exec app php artisan notifications:load-test priority    --count=150 --channel=email
docker compose exec app php artisan notifications:load-test idempotency --count=25
```

---

## Observability

- **Health**: `GET /api/v1/health` (503 when a dependency is down).
- **Metrics**: `GET /api/v1/metrics` (queue depth, success/failure counts, throughput, latency p50/p95/p99).
- **Horizon**: <http://localhost:8000/horizon> for queue throughput, wait times, and failed jobs.
- **Logs**: structured single-line JSON on stdout (`LOG_STACK=json`). Every line carries `extra.correlation_id`, tying request → job → provider attempt together. Tail a container with `docker compose logs -f horizon`.
