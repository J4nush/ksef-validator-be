#!/usr/bin/env bash
set -euo pipefail

backend="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixtures="$backend/packages/ksef-fa3-validator/tests/Fixtures"
image="${API_IMAGE:-ksef-validator-api:local}"
name="ksef-smoke-api-$$"

if [[ "${SKIP_BUILD:-0}" != 1 ]]; then
    docker build --quiet --file "$backend/docker/php/Dockerfile" --target prod --tag "$image" "$backend" > /dev/null
fi

cleanup() {
    docker rm --force "$name" > /dev/null 2>&1 || true
}
trap cleanup EXIT

docker run --detach --name "$name" \
    --read-only --memory 256m --cap-drop ALL --security-opt no-new-privileges:true \
    --tmpfs /tmp:size=16m,mode=1777 \
    --tmpfs /app/storage/framework:size=4m,uid=10001,gid=999 \
    --tmpfs /config:size=4m,uid=10001,gid=999 \
    --tmpfs /data:size=4m,uid=10001,gid=999 \
    --env APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" \
    --env RATE_LIMIT_PER_MINUTE=1000 \
    "$image" > /dev/null

for _ in $(seq 1 60); do
    status="$(docker inspect --format '{{.State.Health.Status}}' "$name")"
    [[ "$status" == healthy ]] && break
    sleep 1
done

if [[ "$status" != healthy ]]; then
    docker logs "$name" >&2
    echo "FAIL: API container is $status" >&2
    exit 1
fi

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

# Requests come from inside the container, so the script publishes no ports.
post() {
    docker exec --interactive "$name" curl --silent --show-error --max-time 30 \
        --request POST --header 'Content-Type: application/xml' --data-binary @- \
        --write-out '\n%{http_code}' http://localhost:8000/api/internal/validate
}

cli() {
    docker exec --interactive "$name" sh -c 'cat > /tmp/cli.xml && php vendor/bin/ksef-validate /tmp/cli.xml --json; status=$?; rm -f /tmp/cli.xml; exit $((status > 2))'
}

check_against_cli() {
    local label="$1" file="$2" response body code expected
    response="$(post < "$file")"
    code="${response##*$'\n'}"
    body="${response%$'\n'*}"
    expected="$(cli < "$file")"

    [[ "$code" == 200 ]] || fail "$label returned $code"
    [[ "$body" == "$expected" ]] || fail "$label differs from the CLI report"

    local seen="seen_${label//-/_}"

    if [[ -n "${!seen:-}" && "${!seen}" != "$body" ]]; then
        fail "$label changed between requests"
    fi

    printf -v "$seen" '%s' "$body"
    echo "ok $label"
}

check_status() {
    local label="$1" expected="$2" response code
    response="$(post)"
    code="${response##*$'\n'}"
    [[ "$code" == "$expected" ]] || fail "$label returned $code, expected $expected"
    echo "ok $label"
}

empty="$(mktemp "$backend/.smoke-empty.XXXXXX")"
broken="$(mktemp "$backend/.smoke-broken.XXXXXX")"
trap 'cleanup; rm -f "$empty" "$broken"' EXIT
printf '<a><b></a>' > "$broken"

for round in 1 2; do
    check_against_cli valid "$fixtures/valid-minimal.xml"
    check_against_cli bad-nip "$fixtures/bad-nip-seller.xml"
    check_against_cli malformed "$broken"
    check_against_cli fa2 "$fixtures/fa2-header.xml"
    check_against_cli doctype "$fixtures/doctype.xml"
    check_against_cli empty "$empty"
    head -c 3000001 /dev/zero | tr '\0' ' ' | check_status "over limit (round $round)" 413
    check_against_cli schema "$fixtures/xsd-missing-p2.xml"
    check_against_cli valid-full "$fixtures/valid-full.xml"
done

headers="$(docker exec "$name" curl --silent --include --max-time 10 --request GET http://localhost:8000/api/internal/validate)"
grep -qi '^allow: POST' <<< "$headers" || fail 'GET is not answered with Allow: POST'
grep -qi '^cache-control: no-store' <<< "$headers" || fail 'Cache-Control: no-store is missing'
! grep -qi '^x-powered-by' <<< "$headers" || fail 'X-Powered-By is sent'
! grep -qi '^set-cookie' <<< "$headers" || fail 'a cookie is set'
echo 'ok headers'

leftovers="$(docker exec "$name" sh -c 'find /tmp /app/storage -type f ! -name octane-server-state.json ! -name config.php')"
[[ -z "$leftovers" ]] || fail "files left behind: $leftovers"
echo 'ok no files left in /tmp or storage'

echo OK
