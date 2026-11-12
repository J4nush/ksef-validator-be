# ksef-validator-be

Backend of a web tool that checks Polish KSeF FA(3) e-invoices before they are sent to KSeF. Files are validated in memory and never stored.

## Contents

| Path | Description |
|---|---|
| [`app/`](app) | Laravel REST API running on Octane with FrankenPHP |
| [`openapi/openapi.yaml`](openapi/openapi.yaml) | API contract (OpenAPI 3.1) |
| [`packages/ksef-fa3-validator`](packages/ksef-fa3-validator) | Framework-free PHP library and CLI that validates FA(3) invoices offline |

## API

`POST /api/internal/validate` takes the raw invoice XML as the request body with `Content-Type: application/xml` or `text/xml`, up to 3,000,000 bytes.

- `200` returns the validation report for every engine result, including invalid invoices. The report format is described by [`report.schema.json`](packages/ksef-fa3-validator/resources/report.schema.json).
- `413`, `415`, `429` (with `Retry-After`), `404`, `405` and `500` return `application/problem+json`.

The API has no database, sessions or cookies. Invoice content is not written to logs or disk.

## Development

Everything runs in Docker. From the repository root:

```bash
cp .env.example .env
docker compose build
docker compose run --rm php composer install
docker compose run --rm php php artisan key:generate
docker compose run --rm php composer test
```

To run the API together with the frontend (cloned next to this repository as `../frontend`):

```bash
docker compose up -d api web
```

The ports are set by `API_PORT` and `WEB_PORT` in `.env`.

With the stack running, `scripts/check-logs.sh` sends an invoice with a unique marker to the API on the 200, 413, 415 and 429 paths, then fails if the marker or invoice content shows up in the logs of any service or if files are left in `/tmp` or `storage` of the API container.

Engine package commands run from its directory:

```bash
docker compose run --rm -w /app/packages/ksef-fa3-validator php composer test
```

## License

MIT. See [LICENSE](LICENSE).
