#!/bin/sh
set -eu

: "${GH_REPO:?Missing repository}"
: "${RELEASE_TAG:?Missing release tag}"
: "${RELEASE_COMMIT:?Missing tested commit}"

# Only the package job may supply these assets; never rebuild with a write token.
cd dist
sha256sum --check SHA256SUMS
case "$RELEASE_TAG" in
    *-beta.*) prerelease=true; latest=false ;;
    *) prerelease=false; latest=legacy ;;
esac

test "$(gh api "repos/$GH_REPO/commits/$RELEASE_TAG" --jq .sha)" = "$RELEASE_COMMIT"
gh release create "$RELEASE_TAG" weewx-php-core.json weewx-php-core.zip SHA256SUMS \
    --verify-tag --draft --prerelease="$prerelease" \
    --title "$RELEASE_TAG" --generate-notes

# Keep incomplete uploads invisible to the updater, including missing API digests.
release_id=$(gh release view "$RELEASE_TAG" --json databaseId,isDraft --jq 'select(.isDraft == true) | .databaseId')
case "$release_id" in
    ''|*[!0-9]*) exit 1 ;;
esac
digest=$(sha256sum weewx-php-core.json | cut -d ' ' -f 1)
size=$(wc -c < weewx-php-core.json | tr -d ' ')
asset=$(gh api "repos/$GH_REPO/releases/$release_id" --jq '.assets[] | select(.name == "weewx-php-core.json" and .state == "uploaded") | "\(.digest) \(.size)"')
test "$asset" = "sha256:$digest $size"
zip_digest=$(sha256sum weewx-php-core.zip | cut -d ' ' -f 1)
zip_size=$(wc -c < weewx-php-core.zip | tr -d ' ')
zip_asset=$(gh api "repos/$GH_REPO/releases/$release_id" --jq '.assets[] | select(.name == "weewx-php-core.zip" and .state == "uploaded") | "\(.digest) \(.size)"')
test "$zip_asset" = "sha256:$zip_digest $zip_size"
test "$(gh api "repos/$GH_REPO/commits/$RELEASE_TAG" --jq .sha)" = "$RELEASE_COMMIT"
gh api --method PATCH "repos/$GH_REPO/releases/$release_id" \
    -F draft=false -F prerelease="$prerelease" -f make_latest="$latest"
