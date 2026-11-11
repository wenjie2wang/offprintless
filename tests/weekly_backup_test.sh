#!/bin/sh
# Run: sh tests/weekly_backup_test.sh  -- bin/weekly-backup.sh: layout, hard-linked snapshots, retention, safety.
# Uses the real script with a stand-in for the database step (bin/backup-db.php is tested on its own) and a fake data folder.
cd "$(dirname "$0")/.." || exit 1
SCRIPT="$PWD/bin/weekly-backup.sh"
fails=0
ok()  { echo "ok    $1"; }
bad() { echo "FAIL  $1  $2"; fails=$((fails+1)); }
chk() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "(expected '$3', got '$2')"; fi; }

T=$(mktemp -d)
D="$T/data"; B="$T/backups"; mkdir -p "$D/database" "$D/pdfs/000/000" "$D/supplements" "$D/cache" "$B"
printf 'pdf-one' > "$D/pdfs/000/000/000000001.pdf"
printf 'pdf-two' > "$D/pdfs/000/000/000000002.pdf"
printf 'cache'   > "$D/cache/x"
printf 'a-note'  > "$D/supplements/note.txt"
mkdb() { sqlite3 "$1" "PRAGMA journal_mode=WAL; CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT); WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM c WHERE x < $2) INSERT INTO t (v) SELECT 'row ' || x FROM c;" >/dev/null; }
mkdb "$D/database/main.db" 300; mkdb "$D/database/logs.db" 20; mkdb "$D/database/styles.db" 5
count() { sqlite3 "$1" "SELECT count(*) FROM t"; }
sha() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
export BACKUP_DB_MODE=direct
run() { BACKUP_DATE="$1" sh "$SCRIPT" "$D" "$B" "${2:-4}" >"$T/out" 2>&1; echo $?; }

echo "--- usage ---"
chk "no arguments: usage error (2)" "$(sh "$SCRIPT" >/dev/null 2>&1; echo $?)" "2"
chk "a missing data folder fails (1)" "$(sh "$SCRIPT" "$T/nope" "$B" >/dev/null 2>&1; echo $?)" "1"
chk "a missing backup folder fails (1), it is never created" "$(sh "$SCRIPT" "$D" "$T/nodisk/backups" >/dev/null 2>&1; echo $?)" "1"
[ ! -e "$T/nodisk" ] && ok "...and nothing was made under the missing path" || bad "...and nothing was made under the missing path" ""

echo "--- first run ---"
chk "exits 0" "$(run 2026-11-15)" "0"
S="$B/2026-11-15"
[ -f "$S/database/main.db" ] && ok "databases are in <date>/database" || bad "databases are in <date>/database" "$(ls -R "$S" | head)"
[ -f "$S/database/MANIFEST.json" ] && ok "...with their manifest" || bad "...with their manifest" ""
chk "main.db has all its rows" "$(count "$S/database/main.db")" "300"
chk "logs.db and styles.db are there too" "$(count "$S/database/logs.db"),$(count "$S/database/styles.db")" "20,5"
chk "ai.db is optional: absent is fine" "$([ -e "$S/database/ai.db" ] && echo present || echo absent)" "absent"
chk "the copy passes the integrity check" "$(sqlite3 "$S/database/main.db" 'PRAGMA integrity_check')" "ok"
chk "the manifest hash matches the file" "$(grep -c "$(sha "$S/database/main.db")" "$S/database/MANIFEST.json")" "1"
[ ! -e "$S/database/main.db-wal" ] && [ ! -e "$S/database/main.db-shm" ] && ok "no -wal or -shm files in the copy" || bad "no -wal or -shm files in the copy" "$(ls "$S/database")"
[ -f "$S/supplements/note.txt" ] && ok "supplements are copied" || bad "supplements are copied" ""
chk "PDFs are copied" "$(cat "$S/pdfs/000/000/000000001.pdf" 2>/dev/null)" "pdf-one"
[ ! -e "$S/cache" ] && ok "the cache is not backed up (it is rebuilt)" || bad "the cache is not backed up" ""
[ -f "$S/DONE" ] && ok "a DONE marker is written last" || bad "a DONE marker is written last" ""
chk "the backup folder is private (700)" "$(stat -c %a "$S" 2>/dev/null || stat -f %Lp "$S")" "700"
[ -L "$B/latest" ] && [ "$(readlink "$B/latest")" = "2026-11-15" ] && ok "latest points at the new backup" || bad "latest points at the new backup" "$(readlink "$B/latest")"

echo "--- second week: unchanged files are hard links, changed ones are copied, deleted ones are gone ---"
printf 'pdf-one-v2' > "$D/pdfs/000/000/000000001.pdf"
printf 'pdf-three' > "$D/pdfs/000/000/000000003.pdf"
rm -f "$D/pdfs/000/000/000000002.pdf"
chk "exits 0" "$(run 2026-11-22)" "0"
S2="$B/2026-11-22"
chk "a changed PDF has the new content" "$(cat "$S2/pdfs/000/000/000000001.pdf")" "pdf-one-v2"
chk "...and last week's copy still has the old content" "$(cat "$S/pdfs/000/000/000000001.pdf")" "pdf-one"
chk "a new PDF is in the new backup" "$(cat "$S2/pdfs/000/000/000000003.pdf" 2>/dev/null)" "pdf-three"
[ ! -e "$S2/pdfs/000/000/000000002.pdf" ] && ok "a deleted PDF is not in the new backup" || bad "a deleted PDF is not in the new backup" ""
[ -f "$S/pdfs/000/000/000000002.pdf" ] && ok "...but is still in last week's backup" || bad "...but is still in last week's backup" ""
printf 'same' > "$D/pdfs/000/000/000000004.pdf"; run 2026-11-23 >/dev/null; run 2026-11-24 >/dev/null
a=$(ls -i "$B/2026-11-23/pdfs/000/000/000000004.pdf" | awk '{print $1}'); b=$(ls -i "$B/2026-11-24/pdfs/000/000/000000004.pdf" | awk '{print $1}')
chk "an unchanged PDF is a hard link (no second copy on disk)" "$a" "$b"

echo "--- the manifest is the same shape from both database paths ---"
chk "main.db has its own entry with bytes, sha256 and integrity" "$(grep -A4 '"main.db"' "$B/$(ls "$B" | grep -E '^20' | tail -1)/database/MANIFEST.json" | grep -c -E 'bytes|sha256|integrity')" "3"
chk "the manifest is valid JSON" "$(python3 -c "import json,sys; json.load(open(sys.argv[1])); print('yes')" "$B/$(ls "$B" | grep -E '^20' | tail -1)/database/MANIFEST.json" 2>/dev/null || echo no)" "yes"

echo "--- retention: keeps the newest N finished backups ---"
rm -rf "$B"; mkdir -p "$B"
for d in 2026-10-04 2026-10-11 2026-10-18 2026-10-25 2026-11-01 2026-11-08; do run $d >/dev/null; done
chk "after six runs with keep=4 there are four" "$(ls -d "$B"/20* | wc -l | tr -d ' ')" "4"
[ -d "$B/2026-11-08" ] && [ -d "$B/2026-11-01" ] && [ ! -d "$B/2026-10-04" ] && ok "the oldest were removed, the newest kept" || bad "the oldest were removed, the newest kept" "$(ls "$B")"
# An unfinished folder (no DONE) is not counted as a backup and never deletes a finished one.
mkdir -p "$B/2026-11-09" && printf x > "$B/2026-11-09/not-finished"
mkdir -p "$B/.partial-2026-11-09" && printf x > "$B/.partial-2026-11-09/half"
run 2026-11-15 >/dev/null
chk "a half-finished folder does not count towards keep, finished ones survive" "$(ls -d "$B"/20*/DONE 2>/dev/null | wc -l | tr -d ' ')" "4"
[ ! -d "$B/.partial-2026-11-09" ] && ok "...and a stale .partial folder from a crashed run is cleaned up" || bad "...and a stale .partial folder from a crashed run is cleaned up" ""
[ -d "$B/2026-11-09" ] && ok "...but a dated folder without DONE is left for you to look at (never deleted blindly)" || bad "...but a dated folder without DONE is left for you to look at" ""
chk "keep must be at least 2 (1 is refused, so a failing run cannot leave nothing)" "$(BACKUP_DATE=2026-12-01 sh "$SCRIPT" "$D" "$B" 1 >/dev/null 2>&1; echo $?)" "2"

echo "--- a database that is not sound ---"
before=$(ls -d "$B"/20*/DONE | wc -l | tr -d ' ')
cp "$D/database/main.db" "$T/main.good"
printf 'this is not a database%.0s' 1 2 3 4 5 6 7 8 9 10 > "$D/database/main.db"; rm -f "$D/database/main.db-wal" "$D/database/main.db-shm"
out=$(BACKUP_DATE=2026-12-06 sh "$SCRIPT" "$D" "$B" 4 2>&1; echo "rc=$?")
case "$out" in *rc=1) ok "a corrupt main.db fails (1)";; *) bad "a corrupt main.db fails (1)" "$out";; esac
[ ! -d "$B/2026-12-06" ] && ok "no half backup is left" || bad "no half backup is left" ""
chk "no finished backup was deleted by the failed run" "$(ls -d "$B"/20*/DONE | wc -l | tr -d ' ')" "$before"
[ "$(readlink "$B/latest")" != "2026-12-06" ] && ok "latest still points at a good backup" || bad "latest still points at a good backup" ""
cp "$T/main.good" "$D/database/main.db"

echo "--- a site that is in use: committed rows still in the write-ahead log ---"
rm -f "$D/database/main.db" "$D/database/main.db-wal" "$D/database/main.db-shm"
mkdb "$D/database/main.db" 10
( printf 'PRAGMA wal_autocheckpoint=0;\nINSERT INTO t (v) SELECT v FROM t;\nINSERT INTO t (v) SELECT v FROM t;\n.shell sleep 6\n' | sqlite3 "$D/database/main.db" >/dev/null 2>&1 ) &
BG=$!
sleep 2
plain=$(sqlite3 "file:$T/plaincopy.db?mode=rwc" "SELECT 1" >/dev/null 2>&1; cp "$D/database/main.db" "$T/plaincopy.db"; sqlite3 "$T/plaincopy.db" 'SELECT count(*) FROM t' 2>/dev/null || echo 0)
chk "exits 0 while another process holds the database open" "$(run 2026-12-13)" "0"
chk "the backup has every committed row (a plain file copy had $plain)" "$(count "$B/2026-12-13/database/main.db")" "40"
kill $BG 2>/dev/null; wait $BG 2>/dev/null
rm -f "$D/database/main.db-wal" "$D/database/main.db-shm"

echo "--- never overwrites ---"
last=$(ls -d "$B"/20*/DONE | tail -1 | xargs dirname | xargs basename)
chk "a second run on the same day is refused (1)" "$(run "$last")" "1"
chk "...and the existing backup is intact" "$(sqlite3 "$B/$last/database/main.db" 'SELECT count(*) FROM t')" "40"

echo "--- only one run at a time ---"
mkdir -p "$B/.lock.d"
chk "a running backup blocks a second one (1)" "$(run 2027-01-03)" "1"
rmdir "$B/.lock.d"

echo "--- the live folder is left exactly as it was ---"
# A reader that opens a WAL database can create -wal and -shm files owned by itself, and then the site (another user)
# cannot write. The backup must never leave such files behind in the live folder.
L=$(mktemp -d); mkdir -p "$L/data/database" "$L/b"
sqlite3 "$L/data/database/main.db" "PRAGMA journal_mode=WAL; CREATE TABLE t (id INTEGER); INSERT INTO t VALUES (1);" >/dev/null
rm -f "$L/data/database/main.db-wal" "$L/data/database/main.db-shm"
before=$(ls -A "$L/data/database" | sort | tr '\n' ' ')
BACKUP_DATE=2027-02-07 sh "$SCRIPT" "$L/data" "$L/b" 4 >/dev/null 2>&1
after=$(ls -A "$L/data/database" | sort | tr '\n' ' ')
chk "no -wal, -shm or other file appears in the live database folder" "$after" "$before"
rm -rf "$L"

echo "--- container mode (the default) ---"
C=$(mktemp -d); mkdir -p "$C/data/database" "$C/b"
sqlite3 "$C/data/database/main.db" "CREATE TABLE t (id INTEGER); INSERT INTO t VALUES (1);" >/dev/null
chk "with no docker or no such container it fails (1)" "$(env -u BACKUP_DB_MODE BACKUP_CONTAINER=no-such-container-xyz BACKUP_DATE=2027-03-07 sh "$SCRIPT" "$C/data" "$C/b" 4 >"$C/out" 2>&1; echo $?)" "1"
chk "...and says why" "$(grep -c -E 'docker is not installed|is not running' "$C/out")" "1"
[ ! -d "$C/b/2027-03-07" ] && [ ! -d "$C/b/.partial-2027-03-07" ] && ok "...and leaves nothing behind" || bad "...and leaves nothing behind" "$(ls -A "$C/b")"
chk "an unknown mode is refused (1)" "$(BACKUP_DB_MODE=weird BACKUP_DATE=2027-03-14 sh "$SCRIPT" "$C/data" "$C/b" 4 >/dev/null 2>&1; echo $?)" "1"
rm -rf "$C"

echo "--- restore-check.sh ---"
CHECK="$PWD/bin/restore-check.sh"
RT=$(mktemp -d); RD="$RT/data"; RB="$RT/b"; mkdir -p "$RD/database" "$RD/pdfs/000/000" "$RB"
sqlite3 "$RD/database/main.db" "PRAGMA journal_mode=WAL; CREATE TABLE items (id INTEGER PRIMARY KEY, file_hash TEXT); CREATE TABLE users (id INTEGER PRIMARY KEY); INSERT INTO items (file_hash) VALUES ('a'), ('b'); INSERT INTO users DEFAULT VALUES;" >/dev/null
printf 1 > "$RD/pdfs/000/000/000000001.pdf"; printf 2 > "$RD/pdfs/000/000/000000002.pdf"
BACKUP_DATE=2026-12-20 sh "$SCRIPT" "$RD" "$RB" 4 >/dev/null 2>&1
chk "a good backup is sound (0)" "$(sh "$CHECK" "$RB" >"$RT/o" 2>&1; echo $?)" "0"
chk "...and says what it found" "$(grep -c 'holds 2 items and 1 users' "$RT/o")" "1"
chk "no argument is a usage error (2)" "$(sh "$CHECK" >/dev/null 2>&1; echo $?)" "2"
chk "a missing snapshot is a problem (1)" "$(sh "$CHECK" "$RB" 2000-01-01 >/dev/null 2>&1; echo $?)" "1"
printf 'x' >> "$RB/2026-12-20/database/main.db"
chk "a database changed after the backup is detected (1)" "$(sh "$CHECK" "$RB" >"$RT/o" 2>&1; echo $?)" "1"
chk "...by its hash" "$(grep -c 'does not match its recorded hash' "$RT/o")" "1"
BACKUP_DATE=2026-12-27 sh "$SCRIPT" "$RD" "$RB" 4 >/dev/null 2>&1
rm -f "$RB/2026-12-27/pdfs/000/000/000000002.pdf"
chk "a missing PDF is detected (1)" "$(sh "$CHECK" "$RB" 2026-12-27 >"$RT/o" 2>&1; echo $?)" "1"
chk "...and reported" "$(grep -c 'only 1 PDF files for 2 items' "$RT/o")" "1"
BACKUP_DATE=2027-01-03 sh "$SCRIPT" "$RD" "$RB" 4 >/dev/null 2>&1; rm -f "$RB/2027-01-03/DONE"
chk "a snapshot without DONE is detected (1)" "$(sh "$CHECK" "$RB" 2027-01-03 >/dev/null 2>&1; echo $?)" "1"
# the real thing: restore into a fresh folder and open it like the app would
BACKUP_DATE=2027-01-10 sh "$SCRIPT" "$RD" "$RB" 4 >/dev/null 2>&1
mkdir -p "$RT/restored" && cp -R "$RB/2027-01-10/database" "$RT/restored/database" && cp -R "$RB/2027-01-10/pdfs" "$RT/restored/pdfs"
chk "a restored database opens and has the data" "$(sqlite3 "$RT/restored/database/main.db" 'SELECT count(*) FROM items')" "2"
chk "...and accepts writes" "$(sqlite3 "$RT/restored/database/main.db" "PRAGMA journal_mode=WAL; INSERT INTO items (file_hash) VALUES ('c'); SELECT count(*) FROM items;" | tail -1)" "3"
rm -rf "$RT"

rm -rf "$T"
if [ "$fails" -eq 0 ]; then echo; echo "ALL PASSED"; exit 0; else echo; echo "$fails FAILED"; exit 1; fi
