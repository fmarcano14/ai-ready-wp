#!/usr/bin/env bash
# Usage: WP_PATH=/path/to/wordpress WP_URL=http://127.0.0.1:8088 bin/verify.sh
# Optional: WP_PHAR=/path/to/wp-cli.phar when the wp command is not on PATH.
set -euo pipefail

WP_PATH="${WP_PATH:-}"
WP_URL="${WP_URL:-}"
WP_PHAR="${WP_PHAR:-}"
WP_PHP="${WP_PHP:-php}"

if [[ -z "$WP_PATH" || -z "$WP_URL" ]]; then
	echo "Set WP_PATH and WP_URL. Example: WP_PATH=/tmp/wordpress WP_URL=http://127.0.0.1:8088 bin/verify.sh" >&2
	exit 2
fi

WP_URL="${WP_URL%/}"
cd "$WP_PATH"

if [[ -z "$WP_PHAR" ]] && ! command -v wp >/dev/null 2>&1; then
	echo "wp is not on PATH. Set WP_PHAR to a wp-cli phar." >&2
	exit 2
fi

wp() {
	if [[ -n "$WP_PHAR" ]]; then
		"$WP_PHP" "$WP_PHAR" "$@"
	else
		command wp "$@"
	fi
}

slug_for() {
	echo "ai-ready-verify-$1"
}

delete_fixture() {
	local slug
	slug="$(slug_for "$1")"
	local ids
	ids="$(wp post list --name="$slug" --post_type=post,page --field=ID --format=ids)"
	if [[ -n "$ids" ]]; then
		wp post delete $ids --force >/dev/null
	fi
}

create_page() {
	wp post create \
		--post_type=page \
		--post_status=publish \
		--post_title="$2" \
		--post_name="$(slug_for "$1")" \
		--post_content="$3" \
		--porcelain
}

create_post() {
	wp post create \
		--post_type=post \
		--post_status="$2" \
		--post_title="$3" \
		--post_name="$(slug_for "$1")" \
		--post_content="$4" \
		--post_password="${5:-}" \
		--porcelain
}

for name in public secret noindex excluded; do
	delete_fixture "$name"
done

PUBLIC_ID="$(create_page public 'Verify Public' '<p>Visible body for the verify script.</p>')"
SECRET_ID="$(create_post secret publish 'Verify Secret' '<p>hidden</p>' 'hidden')"
NOINDEX_ID="$(create_post noindex publish 'Verify Noindex' '<p>hidden</p>')"
wp post meta update "$NOINDEX_ID" _yoast_wpseo_meta-robots-noindex 1 >/dev/null
EXCLUDED_ID="$(create_post excluded publish 'Verify Excluded' '<p>hidden</p>')"
wp post meta update "$EXCLUDED_ID" _ai_ready_wp_exclude 1 >/dev/null

wp plugin activate ai-ready-wp >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null
wp option patch update ai_ready_wp_settings discovery_mode enabled >/dev/null
wp option patch update ai_ready_wp_settings enable_markdown 1 >/dev/null
wp ai-ready generate >/dev/null

fail() {
	echo "FAIL: $1" >&2
	exit 1
}

LLMS="$(curl -fsS "$WP_URL/llms.txt")"
printf '%s\n' "$LLMS" | grep -F '[Verify Public](' >/dev/null || fail "llms.txt is missing Verify Public"
if printf '%s\n' "$LLMS" | grep -F 'Verify Secret' >/dev/null; then
	fail "llms.txt lists the password-protected post"
fi
if printf '%s\n' "$LLMS" | grep -F 'Verify Noindex' >/dev/null; then
	fail "llms.txt lists the noindex post"
fi
if printf '%s\n' "$LLMS" | grep -F 'Verify Excluded' >/dev/null; then
	fail "llms.txt lists the excluded post"
fi

PUBLIC_HEADERS="$(curl -sI "$WP_URL/$(slug_for public).md")"
PUBLIC_CODE="$(printf '%s\n' "$PUBLIC_HEADERS" | awk 'BEGIN{c=""} /^HTTP/{c=$2} END{print c}')"
[[ "$PUBLIC_CODE" == "200" ]] || fail "public markdown returned $PUBLIC_CODE"
printf '%s\n' "$PUBLIC_HEADERS" | grep -i 'x-robots-tag: noindex' >/dev/null || fail "public markdown is missing X-Robots-Tag noindex"
curl -sI "$WP_URL/llms.txt" | grep -i 'x-robots-tag: all' >/dev/null || fail "llms.txt is missing X-Robots-Tag all"
curl -fsS "$WP_URL/$(slug_for public).md" -o /tmp/ai-ready-verify-public.md
grep -F 'Visible body for the verify script.' /tmp/ai-ready-verify-public.md >/dev/null || fail "public markdown is missing the body"

for name in secret noindex excluded; do
	code="$(curl -s -o /dev/null -w '%{http_code}' "$WP_URL/$(slug_for "$name").md")"
	[[ "$code" == "404" ]] || fail "$name markdown returned $code"
done

SECOND="$(wp ai-ready generate)"
printf '%s\n' "$SECOND" | grep -F 'Wrote llms.txt' >/dev/null || fail "second generate did not converge"

ETAG="$(curl -sI "$WP_URL/llms.txt" | awk -F': ' 'tolower($1)=="etag"{gsub(/\r/,"",$2); print $2}')"
CODE_304="$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $ETAG" "$WP_URL/llms.txt")"
[[ "$CODE_304" == "304" ]] || fail "If-None-Match returned $CODE_304"

HEAD="$(curl -fsS "$WP_URL/$(slug_for public)/")"
printf '%s\n' "$HEAD" | grep -F 'rel="alternate" type="text/markdown"' >/dev/null || fail "the public page has no markdown link"
printf '%s\n' "$HEAD" | grep -F 'rel="describedby"' >/dev/null || fail "the public page has no describedby link"

for name in public secret noindex excluded; do
	delete_fixture "$name"
done
wp ai-ready generate >/dev/null

echo "PASS: llms.txt, markdown, exclusions, a second generate, and ETag 304"
