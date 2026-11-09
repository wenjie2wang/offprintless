#!/bin/sh
# A creator who is only a viewer of the Public project adds an item and its PDF (what the Crossref import does).
# Needs a FRESH instance. Usage: tests/e2e_pdf.sh http://127.0.0.1:9051
B=${1:-http://127.0.0.1:9051}; fails=0
ok()  { echo "ok    $1"; }
bad() { echo "FAIL  $1  $2"; fails=$((fails+1)); }
chk() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "(expected '$3', got '$2')"; fi; }
csrf() { curl -s -b "$1" -c "$1" "$B/" | sed -n "s/.*csrfToken='\([a-f0-9]*\)'.*/\1/p" | head -1; }
post() { jar=$1; path=$2; shift 2; t=$(csrf "$jar"); curl -s -b "$jar" -c "$jar" -H 'Accept: application/json' -X POST "$B/index.php/$path" --data-urlencode "csrfToken=$t" "$@"; }
mkuser() { post "$ADM" users/create --data-urlencode "user[username]=$1" --data-urlencode "user[email]=$1@example.org" --data-urlencode "user[first_name]=$1" --data-urlencode "user[last_name]=T" --data-urlencode "user[permissions]=$2" | python3 -c "import sys,json,re; m=re.search(r'is:<br>\s*(\S+?)<br>', json.load(sys.stdin).get('password','')); print(m.group(1) if m else '')"; }
signin() { post "$1" authentication/signin --data-urlencode "username=$2" --data-urlencode "password=$3" >/dev/null; }
dbq() { podman exec il-e2e php -r '$p=new PDO("sqlite:/i-librarian/data/database/main.db"); $r=$p->query($argv[1]); echo $r ? $r->fetchColumn() : "";' "$1" 2>/dev/null; }

ADM=$(mktemp); ALI=$(mktemp); BOB=$(mktemp)
post $ADM registration/createaccount --data-urlencode "username=root" --data-urlencode "password=pw-root-1234" --data-urlencode "password2=pw-root-1234" --data-urlencode "first_name=r" --data-urlencode "last_name=t" --data-urlencode "email=root@example.org" >/dev/null
signin $ADM root pw-root-1234
PA=$(mkuser alice U); PB=$(mkuser bob U); signin $ALI alice "$PA"; signin $BOB bob "$PB"

# a real PDF
python3 - << 'PY'
objs=["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>"]
pdf="%PDF-1.4\n"; off=[]
for i,o in enumerate(objs): off.append(len(pdf)); pdf+=f"{i+1} 0 obj\n{o}\nendobj\n"
x=len(pdf); pdf+="xref\n0 4\n0000000000 65535 f \n"+"".join(f"{o:010d} 00000 n \n" for o in off)
pdf+=f"trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n{x}\n%%EOF\n"
open("/tmp/e2e.pdf","w").write(pdf)
PY

echo "--- alice (creator, viewer of Public) imports an item with a PDF in one request ---"
# One multipart form, like the browser sends it (token, fields and file together).
RES=$(curl -s -b $ALI -c $ALI -H 'Accept: application/json' -X POST "$B/index.php/import/manual" -F "csrfToken=$(csrf $ALI)" -F "title=From Crossref" -F "reference_type=article" -F "file=@/tmp/e2e.pdf;type=application/pdf")
IID=$(dbq "select id from items where title='From Crossref'")
[ -n "$IID" ] && ok "the item exists (id $IID)" || bad "the item exists" "$RES"
chk "the PDF was attached (file_hash set)" "$(dbq "select file_hash is not null from items where id=$IID")" "1"
PADDED=$(printf '%09d' $IID); SUB1=$(printf '%s' "$PADDED" | cut -c1-3); SUB2=$(printf '%s' "$PADDED" | cut -c4-6)
chk "the PDF file is on disk" "$(podman exec il-e2e sh -c "test -f /i-librarian/data/pdfs/$SUB1/$SUB2/$PADDED.pdf && echo yes")" "yes"
chk "the response did not report 'PDF was not saved'" "$(printf '%s' "$RES" | grep -c 'PDF was not')" "0"

echo "--- bob cannot touch alice's item ---"
code_post() { jar=$1; path=$2; shift 2; curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" -H 'Accept: application/json' -X POST "$B/index.php/$path" -F "csrfToken=$(csrf $jar)" "$@"; }
chk "bob (viewer of Public) cannot delete it" "$(code_post $BOB item/delete -F "id=$IID")" "403"
chk "the item still exists" "$(dbq "select count(*) from items where id=$IID")" "1"
echo; if [ "$fails" -eq 0 ]; then echo "ALL PASSED"; else echo "$fails FAILED"; fi; exit $fails
