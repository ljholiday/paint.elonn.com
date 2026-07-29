# Paint

Paint is the drawing document service for Elonn. It owns Paint document identity, Paint document records, Paint document operations, and Paint-specific document lifecycle.

Paint uses Storage for immutable Resource bytes. Paint does not own Resource byte persistence, runtime presentation, World placement, member authentication, or sharing outside Paint document records.

## Routes

- `GET /health`
- `GET /ready`
- `GET /`
- `POST /paint/call`

`POST /paint/call` is the canonical Service Call entry point. It requires first-party service authentication.

## Local Setup

1. Copy `.env.example` to `.env`.
2. Set database credentials.
3. Set `ELONN_MIND_SERVICE_TOKEN`.
4. Set Storage service configuration before implementing Resource-backed document creation.
5. Run `./test.sh`.
