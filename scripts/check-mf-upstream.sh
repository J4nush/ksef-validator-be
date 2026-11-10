#!/usr/bin/env bash
set -euo pipefail

repository="CIRFMF/ksef-api"
branch="main"
schema_directory="faktury/schemy/FA"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_json="${KSEF_SOURCE_JSON:-$root/packages/ksef-fa3-validator/resources/schemas/source.json}"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

curl_args=(--fail --silent --show-error --location --retry 3)
api_headers=(-H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28")
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
    api_headers+=(-H "Authorization: Bearer $GITHUB_TOKEN")
fi

api() {
    curl "${curl_args[@]}" "${api_headers[@]}" "https://api.github.com/repos/$repository/$1"
}

uri_encode() {
    jq -rn --arg value "$1" '$value | @uri'
}

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}

differences=()

while IFS=$'\t' read -r path pinned; do
    latest="$(api "commits?sha=$branch&per_page=1&path=$(uri_encode "$path")" | jq -r '.[0] | if . == null then "" else .sha + "\t" + .commit.committer.date + "\t" + (.commit.message | split("\n")[0]) end')"
    if [[ -z "$latest" ]]; then
        differences+=("$path: no commits on $branch, the file may have been moved or removed (pinned $pinned)")
        continue
    fi
    IFS=$'\t' read -r latest_sha latest_date latest_subject <<<"$latest"
    if [[ "$latest_sha" != "$pinned" ]]; then
        differences+=("$path: pinned $pinned, latest $latest_sha ($latest_date, \"$latest_subject\")")
    else
        echo "Unchanged: $path"
    fi
done < <(jq -r '.watchedPaths | to_entries[] | .key + "\t" + .value' "$source_json")

xsd_path="$(jq -r '.watchedPaths | keys[] | select(test("schemat_FA\\(3\\)"))' "$source_json" | head -n 1)"
pinned_xsd_sha256="$(jq -r '.xsdSha256' "$source_json")"
curl "${curl_args[@]}" --output "$work/schema.xsd" "https://raw.githubusercontent.com/$repository/$branch/$(uri_encode "$xsd_path" | sed 's/%2F/\//g')"
actual_xsd_sha256="$(sha256_of "$work/schema.xsd")"
if [[ "$actual_xsd_sha256" != "$pinned_xsd_sha256" ]]; then
    differences+=("$xsd_path: SHA-256 pinned $pinned_xsd_sha256, on $branch $actual_xsd_sha256")
else
    echo "Unchanged: SHA-256 of $xsd_path"
fi

api "contents/$(uri_encode "$schema_directory" | sed 's/%2F/\//g')?ref=$branch" >"$work/listing.json"
while IFS= read -r name; do
    if ! jq -e --arg path "$schema_directory/$name" '.watchedPaths | has($path)' "$source_json" >/dev/null; then
        differences+=("$schema_directory/$name: new FA(3) schema file on $branch")
    fi
done < <(jq -r '.[] | select(.type == "file") | .name | select(startswith("schemat_FA(3)"))' "$work/listing.json")

if ((${#differences[@]} > 0)); then
    echo
    echo "Upstream $repository changed since the pinned source:" >&2
    for difference in "${differences[@]}"; do
        echo "  - $difference" >&2
    done
    exit 1
fi

echo "No upstream changes in $repository."
