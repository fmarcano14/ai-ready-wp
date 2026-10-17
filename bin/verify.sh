#!/usr/bin/env bash
# Re-runnable checks against a live single-site WordPress.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE="${AI_READY_SITE:-/tmp/ai-ready-site}"
PORT="${AI_READY_PORT:-8091}"
BASE="http://127.0.0.1:${PORT}"
DB_NAME="${AI_READY_DB:-ai_ready}"
DB_USER="${AI_READY_DB_USER:-wp}"
DB_PASS="${AI_READY_DB_PASS:-wp}"

export WP_CLI_ALLOW_ROOT=1

wp() {
	command wp --path="$SITE" "$@"
}

wait_for_http() {
	local i
	for i in $(seq 1 50); do
		if curl -sf -o /dev/null "$BASE/wp-login.php"; then
			return 0
		fi
		sleep 0.2
	done
	echo "WordPress did not answer on ${BASE}" >&2
	return 1
}

install_site() {
	mkdir -p "$SITE"
	if [[ ! -f "$SITE/wp-settings.php" ]]; then
		command wp core download --path="$SITE" --quiet
	fi
	if [[ ! -f "$SITE/wp-config.php" ]]; then
		command wp config create --path="$SITE" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="localhost" --skip-check --quiet
		command wp config set DISABLE_WP_CRON true --raw --path="$SITE"
		command wp core install --path="$SITE" --url="$BASE" --title="Café 🌧️" --admin_user="admin" --admin_password="ai-ready-admin" --admin_email="admin@example.com" --skip-email --quiet
	fi
	command wp config set DISABLE_WP_CRON true --raw --path="$SITE" >/dev/null
	ln -sfn "$ROOT" "$SITE/wp-content/plugins/ai-ready-wp"
	mkdir -p "$SITE/wp-content/mu-plugins"
	cat > "$SITE/wp-content/mu-plugins/ai-ready-fixtures.php" <<'PHP'
<?php
add_shortcode(
	'secret',
	static function () {
		if ( is_user_logged_in() ) {
			return 'MEMBER-SECRET';
		}
		return 'visitor';
	}
);
add_shortcode(
	'who',
	static function () {
		return 'user-' . get_current_user_id();
	}
);
PHP
	if ! command wp plugin is-active ai-ready-wp --path="$SITE" >/dev/null 2>&1; then
		command wp plugin activate ai-ready-wp --path="$SITE" --quiet
	fi
	command wp rewrite structure '/%postname%/' --hard --path="$SITE" --quiet
	command wp option update blogdescription 'Resumen ñ' --path="$SITE" --quiet
}

reset_content() {
	ids="$(command wp post list --post_type=post,page --format=ids --path="$SITE")"
	if [[ -n "${ids// }" ]]; then
		# shellcheck disable=SC2086
		command wp post delete $ids --force --path="$SITE" --quiet
	fi
	PUBLIC="$(command wp post create --post_type=post --post_status=publish --post_title='Café niño 🌧️' --post_name='cafe-nino' --post_content='<p>Hello <code>&lt;div&gt;</code> [who] [secret]MEMBER-SECRET[/secret] [notashortcode id="9"]</p>' --porcelain --path="$SITE")"
	EXCERPT="$(command wp post create --post_type=post --post_status=publish --post_title='River notes' --post_name='river-notes' --post_excerpt='Manual note about ñ' --post_content='<p>[secret]MEMBER-SECRET[/secret] hidden body</p>' --porcelain --path="$SITE")"
	command wp post create --post_type=page --post_status=publish --post_title='Old checklist' --post_name='old-checklist' --post_content='<p>Archive page.</p>' --porcelain --path="$SITE" >/dev/null
	command wp post create --post_type=post --post_status=draft --post_title='DRAFT-MARKER' --post_name='draft-marker' --post_content='<p>DRAFT-BODY</p>' --porcelain --path="$SITE" >/dev/null
	command wp post create --post_type=post --post_status=private --post_title='PRIVATE-MARKER' --post_name='private-marker' --post_content='<p>PRIVATE-BODY</p>' --porcelain --path="$SITE" >/dev/null
	command wp post create --post_type=post --post_status=future --post_title='FUTURE-MARKER' --post_name='future-marker' --post_date='2030-01-01 00:00:00' --post_content='<p>FUTURE-BODY</p>' --porcelain --path="$SITE" >/dev/null
	command wp post create --post_type=post --post_status=publish --post_password='s3cret' --post_title='LOCKED-MARKER' --post_name='locked-marker' --post_content='<p>LOCKED-BODY</p>' --porcelain --path="$SITE" >/dev/null
	EXCLUDED="$(command wp post create --post_type=post --post_status=publish --post_title='EXCLUDED-MARKER' --post_name='excluded-marker' --post_content='<p>EXCLUDED-BODY</p>' --porcelain --path="$SITE")"
	command wp post meta update "$EXCLUDED" _ai_ready_exclude 1 --path="$SITE" --quiet
	NOINDEX="$(command wp post create --post_type=post --post_status=publish --post_title='NOINDEX-MARKER' --post_name='noindex-marker' --post_content='<p>NOINDEX-BODY</p>' --porcelain --path="$SITE")"
	command wp post meta update "$NOINDEX" _yoast_wpseo_meta-robots-noindex 1 --path="$SITE" --quiet
	command wp option update ai_ready_settings '{"summary":"","details":"Short details.","post_types":["post","page"],"optional_types":["page"],"write_full":true,"note_length":200}' --format=json --path="$SITE" --quiet
}

start_server() {
	if curl -sf -o /dev/null "$BASE/wp-login.php"; then
		return 0
	fi
	php -S "127.0.0.1:${PORT}" -t "$SITE" "$ROOT/bin/dev-router.php" >/tmp/ai-ready-php-server.log 2>&1 &
	echo $! >/tmp/ai-ready-php-server.pid
	wait_for_http
}

assert_contains() {
	local haystack="$1" needle="$2" label="$3"
	if [[ "$haystack" != *"$needle"* ]]; then
		echo "FAIL $label: missing [$needle]" >&2
		exit 1
	fi
}

assert_lacks() {
	local haystack="$1" needle="$2" label="$3"
	if [[ "$haystack" == *"$needle"* ]]; then
		echo "FAIL $label: found [$needle]" >&2
		exit 1
	fi
}

install_site
reset_content
start_server

command wp ai-ready generate --path="$SITE" --quiet
command wp ai-ready status --path="$SITE" | tee /tmp/ai-ready-status.txt

INDEX="$(curl -fsS "$BASE/llms.txt")"
FULL="$(curl -fsS "$BASE/llms-full.txt")"
MD="$(curl -fsS "$BASE/cafe-nino.md")"
MD_HEADERS="$(curl -fsSI "$BASE/cafe-nino.md")"
HTML="$(curl -fsS "$BASE/cafe-nino/")"

assert_contains "$INDEX" "# Café 🌧️" "llms h1"
assert_contains "$INDEX" "> Resumen ñ" "llms summary"
assert_contains "$INDEX" "## Posts" "llms posts"
assert_contains "$INDEX" "## Optional" "llms optional"
assert_contains "$INDEX" "Café niño 🌧️" "llms title"
assert_contains "$INDEX" "Manual note about ñ" "llms excerpt"
assert_contains "$INDEX" "/cafe-nino.md" "llms markdown link"
assert_lacks "$INDEX" "DRAFT-MARKER" "llms draft"
assert_lacks "$INDEX" "PRIVATE-MARKER" "llms private"
assert_lacks "$INDEX" "FUTURE-MARKER" "llms future"
assert_lacks "$INDEX" "LOCKED-MARKER" "llms password"
assert_lacks "$INDEX" "EXCLUDED-MARKER" "llms exclude"
assert_lacks "$INDEX" "NOINDEX-MARKER" "llms noindex"
assert_lacks "$INDEX" "MEMBER-SECRET" "llms secret"
assert_lacks "$INDEX" "[notashortcode" "llms shortcode"
assert_lacks "$FULL" "MEMBER-SECRET" "full secret"
assert_contains "$MD" '`'"<div>"'`' "md code"
assert_contains "$MD" "user-0" "md anonymous"
assert_contains "$MD" "visitor" "md visitor"
assert_lacks "$MD" "MEMBER-SECRET" "md secret"
assert_lacks "$MD" "[who]" "md raw shortcode"
assert_contains "$MD_HEADERS" "text/markdown; charset=utf-8" "md type"
assert_contains "$MD_HEADERS" "noindex" "md robots"
assert_contains "$MD_HEADERS" 'rel="canonical"' "md canonical"
assert_contains "$HTML" 'rel="alternate"' "html alternate"
assert_contains "$HTML" "text/markdown" "html markdown type"
assert_contains "$HTML" "cafe-nino.md" "html markdown href"

python3 - "$INDEX" "$MD" <<'PY'
import sys
index, md = sys.argv[1], sys.argv[2]
for text in (index, md):
    text.encode("utf-8").decode("utf-8")
    if "\ufffd" in text:
        raise SystemExit("replacement character in output")
if "Café" not in index or "ñ" not in index or "🌧️" not in index:
    raise SystemExit("accented or emoji text missing")
PY

for path in /draft-marker.md /private-marker.md /future-marker.md /locked-marker.md /excluded-marker.md /noindex-marker.md /definitely-not-a-page.md; do
	code="$(curl -s -o /tmp/ai-ready-body.txt -w '%{http_code}' "$BASE$path")"
	body="$(cat /tmp/ai-ready-body.txt)"
	if [[ "$code" != "404" ]]; then
		echo "FAIL $path status $code" >&2
		exit 1
	fi
	assert_lacks "$body" "MEMBER-SECRET" "$path secret"
	assert_lacks "$body" "LOCKED-BODY" "$path locked"
	assert_lacks "$body" "DRAFT-BODY" "$path draft"
done

UNKNOWN_TYPE="$(curl -sI "$BASE/definitely-not-a-page.md" | tr -d '\r' | awk 'BEGIN{IGNORECASE=1} /^content-type:/ {print}')"
assert_lacks "$UNKNOWN_TYPE" "text/markdown" "unknown md content type"

HOME_CODE="$(curl -s -o /tmp/ai-ready-home.txt -w '%{http_code}' "$BASE/?format=markdown")"
if [[ "$HOME_CODE" != "200" ]]; then
	echo "FAIL home format fell through with $HOME_CODE" >&2
	exit 1
fi
assert_lacks "$(cat /tmp/ai-ready-home.txt)" "text/markdown; charset=utf-8" "home is not a markdown file"

BEFORE="$(curl -fsS "$BASE/llms.txt")"
command wp post update "$EXCERPT" --post_excerpt='CHANGED-UNIQUE-STRING' --path="$SITE" --quiet
AFTER="$(curl -fsS "$BASE/llms.txt")"
if [[ "$BEFORE" != "$AFTER" ]]; then
	echo "FAIL public file changed before the rebuild ran" >&2
	exit 1
fi
command wp cron event run ai_ready_rebuild --path="$SITE" --quiet
REBUILT="$(curl -fsS "$BASE/llms.txt")"
assert_contains "$REBUILT" "CHANGED-UNIQUE-STRING" "cron rebuild"

command wp post update "$PUBLIC" --post_password='s3cret' --path="$SITE" --quiet
HIDDEN="$(curl -fsS "$BASE/llms.txt")"
assert_lacks "$HIDDEN" "cafe-nino.md" "password leaves the index"
HIDE_CODE="$(curl -s -o /tmp/ai-ready-hide.txt -w '%{http_code}' "$BASE/cafe-nino.md")"
if [[ "$HIDE_CODE" != "404" ]]; then
	echo "FAIL password markdown status $HIDE_CODE" >&2
	exit 1
fi
assert_lacks "$(cat /tmp/ai-ready-hide.txt)" "user-0" "password markdown body"
assert_lacks "$(curl -fsS "$BASE/llms-full.txt")" '`<div>`' "password leaves the full file"

STORED="$(find "$SITE/wp-content" -name llms.txt)"
COUNT="$(printf '%s\n' "$STORED" | sed '/^$/d' | wc -l | tr -d ' ')"
if [[ "$COUNT" != "1" ]]; then
	echo "FAIL expected one llms.txt, found $COUNT" >&2
	printf '%s\n' "$STORED" >&2
	exit 1
fi
PREFIX="$(command wp db prefix --path="$SITE" | tr -d '[:space:]')"
if [[ -z "$PREFIX" ]]; then
	echo "FAIL could not read the database prefix" >&2
	exit 1
fi
OPTIONS="$(command wp db query "SELECT option_name FROM ${PREFIX}options WHERE option_value LIKE '%cafe-nino.md%'" --path="$SITE" --skip-column-names)"
if [[ -n "${OPTIONS//[[:space:]]/}" ]]; then
	echo "FAIL generated link stored in options: $OPTIONS" >&2
	exit 1
fi
META="$(command wp db query "SELECT meta_id FROM ${PREFIX}postmeta WHERE meta_value LIKE '%cafe-nino.md%'" --path="$SITE" --skip-column-names)"
if [[ -n "${META//[[:space:]]/}" ]]; then
	echo "FAIL generated link stored in post meta: $META" >&2
	exit 1
fi

DIRECT="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/wp-content/uploads/ai-ready/current/llms.txt")"
if [[ "$DIRECT" == "200" ]]; then
	echo "FAIL storage directory is web-accessible" >&2
	exit 1
fi

set +e
command wp eval 'wp_set_current_user(get_user_by("login","admin")->ID); $_REQUEST["_wpnonce"]="bad"; (new AiReady\AdminPage())->save();' --path="$SITE" >/tmp/ai-ready-nonce.txt 2>&1
NONCE_STATUS=$?
set -e
if [[ "$NONCE_STATUS" -eq 0 ]]; then
	echo "FAIL bad nonce was accepted" >&2
	cat /tmp/ai-ready-nonce.txt >&2
	exit 1
fi

grep -q "Single-site only" "$ROOT/ai-ready-wp.php"
grep -q "single-site" "$ROOT/uninstall.php" || grep -q "ai_ready_settings" "$ROOT/uninstall.php"

echo "verify ok"
