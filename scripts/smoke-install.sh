#!/usr/bin/env bash
set -euo pipefail

backend="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
package="$backend/packages/ksef-fa3-validator"
image="$(docker compose -f "$backend/compose.yaml" config --images php)"

if ! docker image inspect "$image" > /dev/null 2>&1; then
    docker compose -f "$backend/compose.yaml" build php
fi

volume="ksef-smoke-$$-$RANDOM"
docker volume create "$volume" > /dev/null
trap 'docker volume rm -f "$volume" > /dev/null' EXIT

in_image() {
    docker run --rm \
        --env COMPOSER_HOME=/work/.composer \
        --env COMPOSER_ALLOW_SUPERUSER=1 \
        --volume "$volume:/work" \
        --workdir /work/consumer \
        "$@"
}

# A volume instead of a bind mount works without host file sharing and keeps the package's own vendor out.
COPYFILE_DISABLE=1 tar --no-xattrs -C "$package" --exclude=./vendor --exclude=./.phpunit.cache -cf - . \
    | in_image --interactive --workdir /work "$image" sh -c 'mkdir package consumer && tar -C package -xf -'

in_image --interactive "$image" sh -c 'cat > composer.json' <<'JSON'
{
    "name": "example/consumer",
    "type": "project",
    "repositories": [
        {
            "type": "path",
            "url": "/work/package",
            "options": {
                "symlink": false,
                "versions": {
                    "michal-ziolek/ksef-fa3-validator": "dev-smoke"
                }
            }
        }
    ],
    "require": {
        "michal-ziolek/ksef-fa3-validator": "dev-smoke"
    }
}
JSON

in_image "$image" composer install --no-interaction --no-progress

expect_exit() {
    local expected="$1" file="$2" output code=0
    output="$(in_image --network none "$image" vendor/bin/ksef-validate "/work/package/tests/Fixtures/$file" --json)" || code=$?

    if [[ "$code" != "$expected" ]]; then
        echo "FAIL: $file exited with $code, expected $expected" >&2
        exit 1
    fi

    printf '%s' "$output"
}

passed="$(expect_exit 0 valid-minimal.xml)"

if [[ "$passed" != *'"result":"PASSED"'* ]]; then
    echo 'FAIL: valid-minimal.xml did not report PASSED' >&2
    exit 1
fi

expect_exit 1 bad-nip-seller.xml > /dev/null

echo OK
