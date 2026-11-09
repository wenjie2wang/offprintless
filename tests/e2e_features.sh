#!/bin/sh
# HTTP end-to-end for the rebrand, the activity logs and the Scopus/ScienceDirect/Patents search pages.
# Needs a FRESH instance (first user becomes admin); container name il-e2e.
B=${1:-http://127.0.0.1:9051}
fails=0
ok()  { echo "ok    $1"; }
bad() { echo "FAIL  $1  $2"; fails=$((fails+1)); }
chk() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "(expected '$3', got '$2')"; fi; }
has() { if printf '%s' "$2" | grep -q "$3"; then ok "$1"; else bad "$1" "(missing '$3')"; fi; }
hasnt() { if printf '%s' "$2" | grep -q "$3"; then bad "$1" "(found '$3')"; else ok "$1"; fi; }
csrf() { curl -s -b "$1" -c "$1" "$B/" | sed -n "s/.*csrfToken='\([a-f0-9]*\)'.*/\1/p" | head -1; }
post() { jar=$1; path=$2; shift 2; t=$(csrf "$jar"); curl -s -b "$jar" -c "$jar" -H 'Accept: application/json' -X POST "$B/index.php/$path" --data-urlencode "csrfToken=$t" "$@"; }
get()  { curl -s -b "$1" -c "$1" -H 'Accept: application/json' "$B/index.php/$2"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H 'Accept: application/json' "$B/index.php/$2"; }
mkuser() { post "$ADM" users/create --data-urlencode "user[username]=$1" --data-urlencode "user[email]=$1@example.org" --data-urlencode "user[first_name]=$1" --data-urlencode "user[last_name]=T" --data-urlencode "user[permissions]=$2" | python3 -c "import sys,json,re; m=re.search(r'is:<br>\s*(\S+?)<br>', json.load(sys.stdin).get('password','')); print(m.group(1) if m else '')"; }
signin() { post "$1" authentication/signin --data-urlencode "username=$2" --data-urlencode "password=$3" >/dev/null; }

ADM=$(mktemp); ALI=$(mktemp)
echo "--- rebrand ---"
FRONT=$(curl -s "$B/")
has "front page carries the new name" "$FRONT" "Offprintless"
has "page title ends with the new name" "$FRONT" "<title>.*Offprintless</title>"
hasnt "page title no longer says I, Librarian" "$FRONT" "<title>I, Librarian"
chk "web manifest name" "$(curl -s "$B/img/manifest.json" | python3 -c 'import sys,json; print(json.load(sys.stdin)["name"])')" "Offprintless"

post $ADM registration/createaccount --data-urlencode "username=root" --data-urlencode "password=pw-root-1234" --data-urlencode "password2=pw-root-1234" --data-urlencode "first_name=r" --data-urlencode "last_name=t" --data-urlencode "email=root@example.org" >/dev/null
signin $ADM root pw-root-1234
PA=$(mkuser alice U); signin $ALI alice "$PA"

echo "--- no 'Pro only' stubs ---"
for p in scopus sciencedirect patents logs; do
  H=$(get $ADM "$p")
  hasnt "$p page has no upgrade nag" "$H" "Upgrade now"
done

echo "--- search pages render a form ---"
for p in scopus sciencedirect patents; do
  H=$(get $ALI "$p")
  has "$p: search form" "$H" "$p-search-form"
  has "$p: submits to its own search action" "$H" "#$p.\/search"
done

echo "--- searching without a key fails clearly, not with a crash ---"
S=$(get $ALI "scopus/search?search_type%5B0%5D=TITLE&search_query%5B0%5D=test")
has "scopus without a key explains what to do" "$S" "API key"
S=$(get $ALI "sciencedirect/search?search_type%5B0%5D=qs&search_query%5B0%5D=test")
has "sciencedirect without a key explains what to do" "$S" "API key"
has "an empty scopus search with no key gives the same clear message" "$(get $ALI 'scopus/search')" "API key"
has "patents needs no key: an empty search is fine" "$(get $ALI 'patents/search' | head -c 200; echo ok)" "ok"
chk "patents empty search is not an error" "$(code $ALI 'patents/search')" "200"

echo "--- the Elsevier key is a global setting ---"
has "admin settings page offers the Elsevier key" "$(get $ADM globalsettings)$(get $ADM settings/global)" "api_elsevier"

echo "--- activity logs ---"
chk "a non-admin cannot open the logs page" "$(code $ALI logs)" "403"
L=$(get $ADM logs)
has "admin sees the per-user table" "$L" "Activity per user"
has "...and alice is in it" "$L" "alice"

echo
[ $fails -eq 0 ] && echo "ALL PASSED" || echo "$fails FAILED"
exit $fails
