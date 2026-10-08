#!/bin/sh
# Times LWS requests with ApacheBench; see README.md.
# Usage: BASE=<storage URI without slash> bench.sh [requests]
set -eu
N=${1:-300}
B=${BASE:-http://localhost:8899/lws/perf}
P=$(cat "${TOKEN_CONTROLLER:-/tmp/token-perf.txt}")
V=$(cat "${TOKEN_VIEWER:-/tmp/token-viewer.txt}")

run() {
  label=$1
  shift
  out=$(ab -q -n "$N" -c "${C:-1}" "$@" 2>&1)
  printf '%-40s c=%s %s\n' "$label" "${C:-1}" "$(echo "$out" | awk '
    $1 == "50%" { p50 = $2 } $1 == "95%" { p95 = $2 } $1 == "99%" { p99 = $2 }
    /Requests per second/ { rps = $4 } /Non-2xx responses/ { other = $3 }
    END { printf "p50=%sms p95=%sms p99=%sms rps=%s non-2xx=%s", p50, p95, p99, rps, other + 0 }')"
}

link() {
  curl -s -D - -o /dev/null -H "Authorization: Bearer $P" "$B/root/big/" | tr -d '\r' \
    | grep -i '^link:' | tr ',' '\n' | grep "rel=\"$1\"" | sed 's/.*<\(.*\)>.*/\1/'
}
NEXT=$(link next)
LAST=$(link last)
ETAG=$(curl -s -D - -o /dev/null -H "Authorization: Bearer $P" "$B/root/big/" | tr -d '\r' | awk -F': ' 'tolower($1) == "etag" { print $2 }')

run "storage description (public)" "$B/"
run "controller: root/ (1 member)" -H "Authorization: Bearer $P" "$B/root/"
run "controller: big/, first page" -H "Authorization: Bearer $P" "$B/root/big/"
run "controller: big/, second page" -H "Authorization: Bearer $P" "$NEXT"
run "controller: big/, last page" -H "Authorization: Bearer $P" "$LAST"
run "controller: big/, 304" -H "Authorization: Bearer $P" -H "If-None-Match: $ETAG" "$B/root/big/"
run "controller: a data resource" -H "Authorization: Bearer $P" "$B/root/big/item-05001.txt"
run "viewer: big/, first page (filtered)" -H "Authorization: Bearer $V" "$B/root/big/"
run "viewer: a data resource" -H "Authorization: Bearer $V" "$B/root/big/item-05001.txt"
C=4 run "controller: big/, first page" -H "Authorization: Bearer $P" "$B/root/big/"
C=4 run "viewer: big/, first page (filtered)" -H "Authorization: Bearer $V" "$B/root/big/"
