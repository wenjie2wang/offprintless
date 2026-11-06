#!/bin/sh
# End-to-end over HTTP against a running instance with an EMPTY data dir (first user becomes admin).
# Usage: tests/e2e.sh http://127.0.0.1:9051
B=${1:-http://127.0.0.1:9051}
fails=0
ok()  { echo "ok    $1"; }
bad() { echo "FAIL  $1  $2"; fails=$((fails+1)); }
chk() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "(expected '$3', got '$2')"; fi; }

csrf() { curl -s -b "$1" -c "$1" "$B/" | sed -n "s/.*csrfToken='\([a-f0-9]*\)'.*/\1/p" | head -1; }
# post JAR PATH [curl data args...]  -> body.  Sends what the real browser client sends.
post() { jar=$1; path=$2; shift 2; t=$(csrf "$jar"); curl -s -b "$jar" -c "$jar" -H 'Accept: application/json' -X POST "$B/index.php/$path" --data-urlencode "csrfToken=$t" "$@"; }
get()  { curl -s -b "$1" -c "$1" -H 'Accept: application/json' "$B/index.php/$2"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H 'Accept: application/json' "$B/index.php/$2"; }
reg()  { post "$1" registration/createaccount --data-urlencode "username=$2" --data-urlencode "password=pw-$2-1234" --data-urlencode "password2=pw-$2-1234" --data-urlencode "first_name=$2" --data-urlencode "last_name=T" --data-urlencode "email=$2@example.org" >/dev/null; }
login(){ post "$1" authentication/signin --data-urlencode "username=$2" --data-urlencode "password=pw-$2-1234" >/dev/null; }
code_post() { jar=$1; path=$2; shift 2; t=$(csrf "$jar"); curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" -H 'Accept: application/json' -X POST "$B/index.php/$path" --data-urlencode "csrfToken=$t" --data-urlencode "$1"; }
has()  { printf '%s' "$1" | grep -c -- "$2"; }

ADM=$(mktemp); ALI=$(mktemp); BOB=$(mktemp)

echo "--- accounts ---"
reg $ADM root;  login $ADM root
chk "first account is admin and can open the dashboard" "$(code $ADM dashboard)" "200"
chk "admin sees the user-management page (admin only)" "$(code $ADM users)" "200"

# Admin creates two creator accounts. The server generates a random temporary password and shows it once.
mkuser() { # name perm -> prints the temporary password (the response is {"info":..,"password":..})
  post $ADM users/create --data-urlencode "user[username]=$1" --data-urlencode "user[email]=$1@example.org" \
       --data-urlencode "user[first_name]=$1" --data-urlencode "user[last_name]=T" --data-urlencode "user[permissions]=$2" \
    | python3 -c "import sys,json,re; m=re.search(r'is:<br>\\s*(\\S+?)<br>', json.load(sys.stdin).get('password','')); print(m.group(1) if m else '')"
}
PA=$(mkuser alice U); PB=$(mkuser bob U)
[ -n "$PA" ] && ok "admin created alice with a temporary password" || bad "admin created alice" "(no password in response)"
loginpw() { post "$1" authentication/signin --data-urlencode "username=$2" --data-urlencode "password=$3" >/dev/null; }
loginpw $ALI alice "$PA"; loginpw $BOB bob "$PB"
chk "alice (creator) signed in" "$(code $ALI dashboard)" "200"
chk "bob (creator) signed in"   "$(code $BOB dashboard)" "200"
chk "alice is NOT allowed on the admin users page" "$(code $ALI users)" "403"

echo "--- projects ---"
post $ALI projects/create --data-urlencode "name=Alice Private" --data-urlencode "access=restricted" >/dev/null
AP=$(get $ALI projects | grep -o '"id":[0-9]*,"id_hash":"[^"]*","name":"[^"]*","project":"Alice Private"' | head -1)
echo "$(get $ALI projects | grep -o 'Alice Private' | head -1)" | grep -q "Alice Private" && ok "alice sees her new project" || bad "alice sees her new project" ""
chk "bob does NOT see alice's project name anywhere in his project list" "$(has "$(get $BOB projects)" 'Alice Private')" "0"
chk "everybody sees the Public project" "$(has "$(get $BOB projects)" 'Public')" "1"

# helpers to find ids by title through the real listing endpoint
project_id() { get "$1" projects | python3 -c "
import sys,json,re
d=json.load(sys.stdin); h=d.get('html','')
m=re.search(r'project#project/browse\\?id=(\\d+)\\\\?\\\"[^>]*>$2<', h)
print(m.group(1) if m else '')"; }
APID=$(project_id $ALI "Alice Private")
[ -n "$APID" ] && ok "alice's project has id $APID" || bad "found alice's project id" ""

echo "--- items ---"
post $ALI import/manual --data-urlencode "title=Alice secret paper" --data-urlencode "reference_type=article" --data-urlencode "projects[]=$APID" >/dev/null
post $BOB import/manual --data-urlencode "title=Bob paper" --data-urlencode "reference_type=article" >/dev/null
lib() { get "$1" items | python3 -c "import sys,json,re,html; d=json.load(sys.stdin); print(html.unescape(re.sub(r'<[^>]+>',' ',d.get('html',''))))"; }
chk "alice sees her own item in the library"               "$(has "$(lib $ALI)" 'Alice secret paper')" "1"
chk "BOB CANNOT SEE alice's item in his library"           "$(has "$(lib $BOB)" 'Alice secret paper')" "0"
chk "bob sees his own item (it was filed in Public)"       "$(has "$(lib $BOB)" 'Bob paper')" "1"
chk "alice also sees bob's item (Public is shared)"        "$(has "$(lib $ALI)" 'Bob paper')" "1"
AID=$(podman exec il-e2e php -r '$p=new PDO("sqlite:/i-librarian/data/database/main.db"); echo $p->query("select id from items where title=\"Alice secret paper\"")->fetchColumn();')
# Note: item?id= is only the page shell (it answers 500 on the unmodified upstream code too); the data
# endpoints below are what matter.
chk "BOB CANNOT read alice's item (item data) by guessing id" "$(code $BOB "item/edit?id=$AID")" "404"
chk "BOB CANNOT read alice's item summary by id"           "$(code $BOB "summary?id=$AID")" "404"
chk "alice can open her item by id"                        "$(code $ALI "summary?id=$AID")" "200"

echo "--- sharing: alice invites bob as a viewer ---"
post $ALI project/update --data-urlencode "project_id=$APID" --data-urlencode "name=Alice Private" --data-urlencode "access=restricted" --data-urlencode "invite=bob" --data-urlencode "role=viewer" >/dev/null
chk "bob can now open the item (viewer)"                   "$(code $BOB "summary?id=$AID")" "200"
chk "bob (viewer) cannot delete the item"                  "$(code_post $BOB item/delete "id=$AID")" "403"

echo
if [ "$fails" -eq 0 ]; then echo "ALL PASSED"; else echo "$fails FAILED"; fi
exit $fails
