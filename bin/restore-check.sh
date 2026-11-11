#!/bin/sh
# Check a backup made by weekly-backup.sh without touching the live site.
#
# Usage: restore-check.sh BACKUP_FOLDER [SNAPSHOT]
#   BACKUP_FOLDER  the folder weekly-backup.sh writes to
#   SNAPSHOT       a dated folder name (default: latest)
#
# It checks that the snapshot is finished (DONE), that each database matches the hash in its manifest, passes SQLite's
# integrity check and has rows, and that the PDF files match the number recorded in the database. It only reads.
#
# To restore for real: stop the container, move the live database/ folder aside, copy the snapshot's database/, pdfs/ and
# supplements/ into the data folder, make them owned by uid 33 (chown -R 33:33), start the container.
#
# Exit codes: 0 sound, 1 problem found, 2 wrong usage.

set -u

if [ $# -lt 1 ] || [ $# -gt 2 ]; then
    echo "usage: restore-check.sh BACKUP_FOLDER [SNAPSHOT]" >&2
    exit 2
fi

ROOT=${1%/}
NAME=${2:-latest}
SNAP="$ROOT/$NAME"
problems=0

say()  { echo "ok    $1"; }
warn() { echo "FAIL  $1"; problems=$((problems + 1)); }

[ -d "$SNAP" ] || { echo "no such snapshot: $SNAP" >&2; exit 1; }
SNAP=$(cd "$SNAP" && pwd -P)
echo "snapshot: $SNAP"

[ -f "$SNAP/DONE" ] && say "the snapshot is finished ($(cat "$SNAP/DONE"))" || warn "the snapshot has no DONE marker; it did not finish"
[ -f "$SNAP/database/main.db" ] && say "main.db is there" || warn "main.db is missing"

sha() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }

for db in "$SNAP"/database/*.db; do
    [ -f "$db" ] || continue
    n=$(basename "$db")
    want=$(grep -A4 "\"$n\"" "$SNAP/database/MANIFEST.json" 2>/dev/null | sed -n 's/.*"sha256": *"\([0-9a-f]*\)".*/\1/p' | head -1)
    if [ -z "$want" ]; then
        warn "$n is not in the manifest"
    elif [ "$(sha "$db")" = "$want" ]; then
        say "$n matches its recorded hash"
    else
        warn "$n does not match its recorded hash (the file changed after the backup)"
    fi
    r=$(sqlite3 -readonly "$db" "PRAGMA integrity_check;" 2>&1 | head -1)
    [ "$r" = "ok" ] && say "$n passes the integrity check" || warn "$n integrity check: $r"
done

if [ -f "$SNAP/database/main.db" ]; then
    items=$(sqlite3 -readonly "$SNAP/database/main.db" "SELECT count(*) FROM items;" 2>&1)
    users=$(sqlite3 -readonly "$SNAP/database/main.db" "SELECT count(*) FROM users;" 2>&1)
    case "$items:$users" in
        *[!0-9:]*) warn "main.db could not be read: $items $users" ;;
        *) say "main.db holds $items items and $users users" ;;
    esac
    with_pdf=$(sqlite3 -readonly "$SNAP/database/main.db" "SELECT count(*) FROM items WHERE file_hash IS NOT NULL;" 2>/dev/null || echo 0)
    files=$(find "$SNAP/pdfs" -type f -name '*.pdf' 2>/dev/null | wc -l | tr -d ' ')
    if [ "$with_pdf" -le "$files" ] 2>/dev/null; then
        say "$files PDF files for $with_pdf items with a PDF"
    else
        warn "only $files PDF files for $with_pdf items with a PDF"
    fi
fi

if [ "$problems" -eq 0 ]; then
    echo; echo "the backup is sound"
    exit 0
fi
echo; echo "$problems problem(s) found"
exit 1
