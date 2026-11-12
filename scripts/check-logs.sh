#!/usr/bin/env bash
set -euo pipefail

backend="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture="$backend/packages/ksef-fa3-validator/tests/Fixtures/valid-minimal.xml"
limit_bytes=3000000

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

compose() {
    docker compose --project-directory "$backend" "$@"
}

[[ -n "$(compose ps --status running --quiet api)" ]] || fail 'the api service is not running, start the stack with docker compose up -d api web'

url="http://$(compose port api 8000)/api/internal/validate"
marker="ZNACZNIK-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
work="$(mktemp -d "$backend/.check-logs.XXXXXX")"
trap 'rm -rf "$work"' EXIT

invoice="$work/faktura-$marker.xml"
sed "s/Przykładowa usługa/Usługa $marker/" "$fixture" > "$invoice"
grep -q "$marker" "$invoice" || fail 'the marker was not placed in the invoice'

oversized="$work/za-duza-$marker.xml"
{
    cat "$invoice"
    head -c "$((limit_bytes + 1))" < <(yes "<!-- $marker -->")
} > "$oversized"

status() {
    curl --silent --output /dev/null --max-time 30 --write-out '%{http_code}' --request POST "$@" "$url"
}

expect_status() {
    local label="$1" expected="$2" code
    shift 2
    code="$(status "$@")"
    [[ "$code" == "$expected" ]] || fail "$label returned $code, expected $expected"
    echo "ok $label: $expected"
}

expect_status 'marked invoice' 200 --header 'Content-Type: application/xml' --data-binary "@$invoice"
expect_status 'oversized body with Content-Length' 413 --header 'Content-Type: application/xml' --data-binary "@$oversized"
expect_status 'oversized chunked body' 413 --header 'Content-Type: application/xml' --header 'Transfer-Encoding: chunked' --data-binary "@$oversized"
expect_status 'wrong content type' 415 --header 'Content-Type: text/plain' --data-binary "@$invoice"

per_minute="$(compose exec -T api printenv RATE_LIMIT_PER_MINUTE || echo 30)"
limited=0
for _ in $(seq 0 "$per_minute"); do
    if [[ "$(status --header 'Content-Type: application/xml' --data-binary "@$invoice")" == 429 ]]; then
        limited=1
        break
    fi
done
(( limited )) || fail "no 429 after $((per_minute + 1)) requests"
echo 'ok rate limit: 429'

logs="$work/compose.log"
compose logs --no-color --timestamps > "$logs" 2>&1
echo "collected $(wc -l < "$logs" | tr -d ' ') log lines from: $(compose ps --all --format '{{.Service}}' | sort | tr '\n' ' ')"

needles=(
    "$marker"
    "faktura-$marker.xml"
    'ZNACZNIK'
    'Przykładowy Sprzedawca'
    'Przykładowy Nabywca'
    'Przykładowa'
    '1111111111'
    '2222222222'
    '1111111112'
    'FV/1/02/2026'
    '<Faktura'
    '<P_7>'
    'DaneIdentyfikacyjne'
    'crd.gov.pl/wzor'
)

found=0
for needle in "${needles[@]}"; do
    if grep --quiet --ignore-case --fixed-strings -- "$needle" "$logs"; then
        echo "found in logs: $needle" >&2
        found=1
    fi
done
(( found == 0 )) || fail 'invoice content reached the logs'
echo "ok logs: none of ${#needles[@]} fragments found"

leftovers="$(compose exec -T api sh -c 'find /tmp /app/storage -type f ! -name octane-server-state.json ! -name config.php')"
[[ -z "$leftovers" ]] || fail "files left in the api container: $leftovers"
echo 'ok no files in /tmp or storage, including storage/logs'

echo OK
