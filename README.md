# ksef-validator-be

Backend of a web tool that checks Polish KSeF FA(3) e-invoices before they are sent to KSeF. Files are validated in memory and never stored.

> **Work in progress.** Only the validation engine is in place so far. The REST API is not built yet.

## Contents

| Path | Description |
|---|---|
| [`packages/ksef-fa3-validator`](packages/ksef-fa3-validator) | Framework-free PHP library and CLI that validates FA(3) invoices offline |

## Development

Everything runs in Docker. From the repository root:

```bash
docker compose build
docker compose run --rm php composer install
docker compose run --rm php composer test
```

## License

MIT. See the license file of each package.
