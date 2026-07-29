# Paint

Paint is the drawing document service for Elonn. It owns Paint document identity, Paint document records, Paint document operations, and Paint-specific document lifecycle.

Paint uses Storage for immutable Resource bytes. Paint does not own Resource byte persistence, runtime presentation, World placement, member authentication, or sharing outside Paint document records.

## Routes

- `GET /health`
- `GET /ready`
- `GET /`
- `POST /paint/call`

`POST /paint/call` is the canonical Service Call entry point. It requires first-party service authentication.

## Service Authentication

Paint accepts first-party service calls with:

- `X-Elonn-Service`
- `Authorization: Bearer ...` or `X-Elonn-Service-Token`
- optional `X-Elonn-Member-Id`

Paint does not authenticate member credentials directly. Member identity is carried only as service-authenticated context.

## Local Setup

1. Copy `.env.example` to `.env`.
2. Set database credentials.
3. Create the configured Paint database.
4. Run `php scripts/migrate.php`.
5. Set `ELONN_MIND_SERVICE_TOKEN`.
6. Set Storage service configuration before implementing Resource-backed document creation.
7. Run `./test.sh`.

## Migrations

Run schema changes with:

```bash
php scripts/migrate.php
php scripts/migrate.php status
```

Do not edit an applied migration. Add a new one instead.
