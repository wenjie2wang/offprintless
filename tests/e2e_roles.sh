#!/bin/sh
# HTTP end-to-end for the role-change screen. Needs a FRESH instance (first user becomes admin).
B=${1:-http://127.0.0.1:9051}
fails=0
ok()  { echo "ok    $1"; }
bad() { echo "FAIL  $1  $2"; fails=$((fails+1)); }
chk() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "(expected '$3', got '$2')"; fi; }
csrf() { curl -s -b "$1" -c "$1" "$B/" | sed -n "s/.*csrfToken='\([a-f0-9]*\)'.*/\1/p" | head -1; }
post() { jar=$1; path=$2; shift 2; t=$(csrf "$jar"); curl -s -b "$jar" -c "$jar" -H 'Accept: application/json' -X POST "$B/index.php/$path" --data-urlencode "csrfToken=$t" "$@"; }
get()  { curl -s -b "$1" -c "$1" -H 'Accept: application/json' "$B/index.php/$2"; }
mkuser() { post "$ADM" users/create --data-urlencode "user[username]=$1" --data-urlencode "user[email]=$1@example.org" --data-urlencode "user[first_name]=$1" --data-urlencode "user[last_name]=T" --data-urlencode "user[permissions]=$2" | python3 -c "import sys,json,re; m=re.search(r'is:<br>\s*(\S+?)<br>', json.load(sys.stdin).get('password','')); print(m.group(1) if m else '')"; }
signin() { post "$1" authentication/signin --data-urlencode "username=$2" --data-urlencode "password=$3" >/dev/null; }

ADM=$(mktemp); ALI=$(mktemp); BOB=$(mktemp); CAR=$(mktemp)
post $ADM registration/createaccount --data-urlencode "username=root" --data-urlencode "password=pw-root-1234" --data-urlencode "password2=pw-root-1234" --data-urlencode "first_name=r" --data-urlencode "last_name=t" --data-urlencode "email=root@example.org" >/dev/null
signin $ADM root pw-root-1234
PA=$(mkuser alice U); PB=$(mkuser bob U); PC=$(mkuser carol U)
signin $ALI alice "$PA"; signin $BOB bob "$PB"; signin $CAR carol "$PC"

# Alice creates a project and invites bob (viewer) and carol (editor) in two separate saves.
post $ALI projects/create --data-urlencode "name=Team" --data-urlencode "access=restricted" >/dev/null
PID=$(get $ALI projects | python3 -c "import sys,json,re; h=json.load(sys.stdin)['html']; m=re.search(r'browse\?id=(\d+)[^>]*>Team<', h); print(m.group(1) if m else '')")
[ -n "$PID" ] && ok "alice created project $PID" || bad "alice created a project" ""
dbq() { podman exec il-e2e php -r '$p=new PDO("sqlite:/i-librarian/data/database/main.db"); echo $p->query($argv[1])->fetchColumn();' "$1"; }
BH=$(dbq "select id_hash from users where username='bob'"); CH=$(dbq "select id_hash from users where username='carol'")
# Like the browser form: the second save re-submits bob's ticked checkbox, otherwise he would be removed.
post $ALI project/update --data-urlencode "project_id=$PID" --data-urlencode "name=Team" --data-urlencode "access=restricted" --data-urlencode "invite=bob" --data-urlencode "role=viewer" >/dev/null
post $ALI project/update --data-urlencode "project_id=$PID" --data-urlencode "name=Team" --data-urlencode "access=restricted" --data-urlencode "users[]=$BH" --data-urlencode "invite=carol" --data-urlencode "role=editor" >/dev/null

# The real edit form: which role dropdown is selected for whom?
FORM=$(get $ALI "project/edit?id=$PID" | python3 -c "import sys,json; print(json.load(sys.stdin).get('html',''))")
hash_of() { printf '%s' "$FORM" | python3 -c "
import sys,re
h=sys.stdin.read()
for m in re.finditer(r'name=\\\\?\"roles\[([a-f0-9]+)\]\\\\?\"', h): print(m.group(1))" ; }
NSEL=$(hash_of | wc -l | tr -d ' ')
chk "the edit form shows a role dropdown for each of the 2 members" "$NSEL" "2"
chk "the dropdown offers Editor and Viewer, not Owner" "$(printf '%s' "$FORM" | grep -o 'value=\\"owner\\"' | wc -l | tr -d ' ')" "0"
selected_for() { # which option is selected in the dropdown whose name contains the given hash
  printf '%s' "$FORM" | python3 -c "
import sys,re
h=sys.stdin.read().replace('\\\\\"','\"')
m=re.search(r'name=\"roles\[$1\]\".*?</select>', h, re.S)
print(re.search(r'value=\"(\w+)\" selected', m.group(0)).group(1) if m else '')"; }
HASHES=$(hash_of)
ROLES=""; for h in $HASHES; do ROLES="$ROLES $(selected_for $h)"; done
chk "current roles are shown (one viewer, one editor)" "$(printf '%s\n' $ROLES | sort | tr '\n' ' ')" "editor viewer "

roleof() { dbq "select role from projects_users where project_id=$PID and user_id=(select id from users where username='$1')"; }
chk "before: bob is a viewer"  "$(roleof bob)"   "viewer"
chk "before: carol is an editor" "$(roleof carol)" "editor"

echo "--- alice changes roles through the form: bob -> editor, carol -> viewer ---"
post $ALI project/update --data-urlencode "project_id=$PID" --data-urlencode "name=Team" --data-urlencode "access=restricted" \
  --data-urlencode "users[]=$BH" --data-urlencode "users[]=$CH" --data-urlencode "roles[$BH]=editor" --data-urlencode "roles[$CH]=viewer" >/dev/null
chk "after: bob is an editor"   "$(roleof bob)"   "editor"
chk "after: carol is a viewer"  "$(roleof carol)" "viewer"
chk "alice is still the owner"  "$(roleof alice)" "owner"

echo "--- the effect is real: carol (now a viewer) cannot add an item to the project, bob (now an editor) can ---"
code_import() { curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H 'Accept: application/json' -X POST "$B/index.php/import/manual" --data-urlencode "csrfToken=$(csrf $1)" --data-urlencode "title=$2" --data-urlencode "reference_type=article" --data-urlencode "projects[]=$PID"; }
chk "carol (viewer) is refused"  "$(code_import $CAR 'by carol')" "403"
chk "bob (editor) is allowed"    "$(code_import $BOB 'by bob')"   "200"

echo "--- a non-owner cannot use the form to change roles ---"
RES=$(post $BOB project/update --data-urlencode "project_id=$PID" --data-urlencode "name=Team" --data-urlencode "access=restricted" --data-urlencode "users[]=$BH" --data-urlencode "users[]=$CH" --data-urlencode "roles[$CH]=editor")
chk "bob (editor) is refused" "$(printf '%s' "$RES" | grep -c 'not authorized')" "1"
chk "carol is still a viewer" "$(roleof carol)" "viewer"

echo; if [ "$fails" -eq 0 ]; then echo "ALL PASSED"; else echo "$fails FAILED"; fi
exit $fails
