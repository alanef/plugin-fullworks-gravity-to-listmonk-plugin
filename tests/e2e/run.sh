#!/bin/bash
#
# End-to-end test against a real, throwaway Listmonk.
#
# Needs: wp-env running with Gravity Forms active on the dev site (see README,
# "Local Gravity Forms"). Starts Postgres, Listmonk and Mailpit containers on the
# wp-env Docker network, creates an API user, submits real Gravity Forms entries
# through a real feed, checks what Listmonk ends up with and how many opt-in
# e-mails were sent, then removes the containers.
#
#   tests/e2e/run.sh            # run and clean up
#   KEEP=1 tests/e2e/run.sh     # leave Listmonk up (http://localhost:9850, admin / password123)

set -o pipefail
cd "$(dirname "$0")/../.." || exit 1

PORT=$(python3 -c 'import json; print(json.load(open(".wp-env.json"))["port"])')
WP_CONTAINER=$(docker ps --filter "publish=$PORT" --format '{{.Names}}' | head -1)
if [ -z "$WP_CONTAINER" ]; then
    echo "wp-env is not running on port $PORT - run: npm run start" >&2
    exit 1
fi
PREFIX=${WP_CONTAINER%-wordpress-1}
CLI="$PREFIX-cli-1"
NET=$(docker inspect "$WP_CONTAINER" --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}')

if ! docker exec "$CLI" wp plugin is-active gravityforms 2>/dev/null; then
    echo "Gravity Forms is not active on the dev site - see README, 'Local Gravity Forms'" >&2
    exit 1
fi

cleanup() {
    [ -n "$KEEP" ] && return
    docker rm -f fwgtl-listmonk fwgtl-lm-db fwgtl-mailpit > /dev/null 2>&1
}
trap cleanup EXIT
docker rm -f fwgtl-listmonk fwgtl-lm-db fwgtl-mailpit > /dev/null 2>&1

DB_ENV=(-e LISTMONK_db__host=fwgtl-lm-db -e LISTMONK_db__user=listmonk -e LISTMONK_db__password=listmonk -e LISTMONK_db__database=listmonk)

echo "Starting Postgres, Mailpit and Listmonk..."
docker run -d --name fwgtl-lm-db --network "$NET" -e POSTGRES_USER=listmonk -e POSTGRES_PASSWORD=listmonk -e POSTGRES_DB=listmonk postgres:17-alpine > /dev/null || exit 1
docker run -d --name fwgtl-mailpit --network "$NET" -p 9851:8025 axllent/mailpit > /dev/null || exit 1
for _ in $(seq 30); do docker exec fwgtl-lm-db pg_isready -U listmonk > /dev/null 2>&1 && break; sleep 1; done

TOKEN=$(docker run --rm --network "$NET" "${DB_ENV[@]}" \
    -e LISTMONK_ADMIN_USER=admin -e LISTMONK_ADMIN_PASSWORD=password123 -e LISTMONK_ADMIN_API_USER=wpapi \
    listmonk/listmonk:latest ./listmonk --install --idempotent --yes 2>&1 \
    | sed -n 's/^export LISTMONK_ADMIN_API_TOKEN="\(.*\)"$/\1/p')
if [ -z "$TOKEN" ]; then
    echo "Listmonk install did not print an API token" >&2
    exit 1
fi

docker run -d --name fwgtl-listmonk --network "$NET" -p 9850:9000 -e LISTMONK_app__address=0.0.0.0:9000 "${DB_ENV[@]}" listmonk/listmonk:latest > /dev/null || exit 1
AUTH="Authorization: token wpapi:$TOKEN"
for _ in $(seq 30); do curl -sf -H "$AUTH" localhost:9850/api/lists > /dev/null && break; sleep 1; done

# SMTP -> Mailpit, so opt-in e-mails can be counted. Listmonk restarts itself after a settings change.
curl -s -H "$AUTH" localhost:9850/api/settings | python3 -c '
import json, sys
d = json.load(sys.stdin)["data"]
d["smtp"] = [dict(d["smtp"][0], enabled=True, host="fwgtl-mailpit", port=1025, auth_protocol="none", tls_type="none", username="", password="")]
print(json.dumps(d))' | curl -s -X PUT -H "$AUTH" -H 'Content-Type: application/json' --data @- localhost:9850/api/settings > /dev/null
sleep 3
for _ in $(seq 30); do curl -sf -H "$AUTH" localhost:9850/api/lists > /dev/null && break; sleep 1; done
# Listmonk installs "Default list" (1, single) and "Opt-in list" (2, double); add a third.
curl -s -H "$AUTH" -H 'Content-Type: application/json' -d '{"name":"Customers","type":"private","optin":"double"}' localhost:9850/api/lists > /dev/null

echo "Running scenarios..."
OUT=""
for STEP in setup a b c d e f g h i; do
    OUT+=$(docker exec "$CLI" wp eval-file /var/www/html/tests/e2e/scenarios.php "$TOKEN" "$STEP" 2>&1 | grep -E '^(PASS|FAIL|SUFFIX|SUBMIT|     )')$'\n'
done
echo "$OUT" | grep -v '^$'
SUFFIX=$(echo "$OUT" | sed -n 's/^SUFFIX //p')

# One confirmation for the new subscriber and one for the existing subscriber added to a
# double opt-in list; none re-sent on repeat submissions or for anyone else.
MAIL=$(curl -s 'localhost:9851/api/v1/messages?limit=200' | python3 -c "
import json, sys, collections
m = json.load(sys.stdin)['messages']
c = collections.Counter(t['Address'].split('$SUFFIX')[0] for x in m for t in x['To'] if '$SUFFIX@' in t['Address'])
print(json.dumps(dict(sorted(c.items()))))")
if [ "$MAIL" = '{"alice": 1, "bob": 1}' ]; then
    echo "PASS opt-in e-mails: $MAIL"
else
    echo "FAIL opt-in e-mails: expected {\"alice\": 1, \"bob\": 1}, got $MAIL"
    OUT+="FAIL"
fi

docker exec "$CLI" wp option delete fwgtl_e2e > /dev/null 2>&1

if echo "$OUT" | grep -q -E '^(FAIL|SUBMIT)'; then
    echo -e "\033[31m❌ E2E failed\033[0m"
    exit 1
fi
echo -e "\033[32m✅ E2E passed\033[0m"
