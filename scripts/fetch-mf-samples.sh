#!/usr/bin/env bash
set -euo pipefail

url="https://www.gov.pl/attachment/937002fa-c6b5-477d-8b56-22105fa728c2"
expected_sha256="41ebd3c57144951c65d68a36fbe433285b5791a86a8bd46cb059503e3f8b1e10"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
target="$root/packages/ksef-fa3-validator/tests/MfSamples/data"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}

echo "Downloading $url"
curl --fail --silent --show-error --location --retry 3 --output "$work/samples.zip" "$url"

actual_sha256="$(sha256_of "$work/samples.zip")"
if [[ "$actual_sha256" != "$expected_sha256" ]]; then
    echo "Checksum mismatch for the MF samples archive." >&2
    echo "  expected: $expected_sha256" >&2
    echo "  actual:   $actual_sha256" >&2
    exit 1
fi

mkdir "$work/raw" "$work/samples"
unzip -q -j "$work/samples.zip" '*.xml' -d "$work/raw"

export LC_ALL=C
for file in "$work/raw"/*; do
    name="$(basename "$file")"
    if [[ ! "$name" =~ _([0-9]+)\.xml$ ]]; then
        echo "Unexpected entry in the archive: $name" >&2
        exit 1
    fi
    number="$((10#${BASH_REMATCH[1]}))"
    destination="$work/samples/przyklad_$number.xml"
    if [[ -e "$destination" ]]; then
        echo "Duplicate sample number $number in the archive." >&2
        exit 1
    fi
    mv "$file" "$destination"
done

rm -rf "$target"
mkdir -p "$target"
mv "$work/samples"/*.xml "$target/"

echo "Extracted $(find "$target" -name 'przyklad_*.xml' | wc -l | tr -d ' ') samples to $target"
