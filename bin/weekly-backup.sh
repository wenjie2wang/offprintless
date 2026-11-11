#!/bin/sh
# Weekly backup of the Offprintless data folder.
#
# Usage: weekly-backup.sh DATA_FOLDER BACKUP_FOLDER [KEEP]
#   DATA_FOLDER    the folder mounted at /i-librarian/data (it holds database/, pdfs/, supplements/)
#   BACKUP_FOLDER  an existing folder, on another disk. A dated snapshot folder is made in it.
#   KEEP           how many finished snapshots to keep, at least 2 (default 8)
#
# Environment
#   BACKUP_CONTAINER  name of the running app container (default offprintless). The databases are copied INSIDE it, as the
#                     user the site runs as (uid 33). A process of another user that opens a database in write-ahead mode
#                     can leave -wal and -shm files that the site then cannot write ("attempt to write a readonly
#                     database"), so the host user must never open the live databases.
#   BACKUP_DB_MODE    "container" (default) or "direct" (copy on the host with sqlite3; for tests and for a stopped site)
#   BACKUP_DATE       the snapshot name, default today (for tests)
#
# What it saves
#   database/      main.db, logs.db, styles.db and ai.db (if it exists), copied with SQLite's online backup, which reads a
#                  consistent snapshot while the site keeps writing. Copying the file itself can miss committed rows
#                  that are still in the write-ahead log. Each copy is checked with PRAGMA integrity_check.
#   pdfs/          every PDF
#   supplements/   supplements
# What it does not save: cache/ (rebuilt), sessions/ (people sign in again), import/ (temporary).
#
# Snapshots are full folders, but files that did not change since the last snapshot are hard links to it, so a weekly
# backup of 2 GB of PDFs costs only the new and changed files. Deleting an old snapshot never damages a newer one.
#
# Safe by design: a snapshot is built in a folder named .partial-DATE and renamed when finished, with a DONE marker; an
# unfinished one is never counted as a backup and is cleaned up next time. An existing snapshot is never overwritten.
# Old snapshots are deleted only after a new one has finished, and the newest KEEP finished ones are kept.
#
# Exit codes: 0 done, 1 failed, 2 wrong usage.

set -u

if [ $# -lt 2 ] || [ $# -gt 3 ]; then
    echo "usage: weekly-backup.sh DATA_FOLDER BACKUP_FOLDER [KEEP]" >&2
    exit 2
fi

ROOT_SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
DATA=${1%/}
DEST=${2%/}
KEEP=${3:-8}
DATE=${BACKUP_DATE:-$(date +%Y-%m-%d)}

case "$KEEP" in
    ''|*[!0-9]*) echo "KEEP must be a number" >&2; exit 2 ;;
esac

if [ "$KEEP" -lt 2 ]; then
    echo "KEEP must be at least 2, so that a failing run can never leave you without a backup" >&2
    exit 2
fi

fail() {
    echo "backup failed: $1" >&2
    exit 1
}

[ -d "$DATA/database" ] || fail "no database folder in $DATA"
[ -f "$DATA/database/main.db" ] || fail "no main.db in $DATA/database"
[ -d "$DEST" ] || fail "the backup folder $DEST does not exist (is the disk mounted?)"
command -v sqlite3 >/dev/null 2>&1 || fail "sqlite3 is not installed (used to check the copies)"
command -v rsync >/dev/null 2>&1 || fail "rsync is not installed"

FINAL="$DEST/$DATE"
PARTIAL="$DEST/.partial-$DATE"
LOCK="$DEST/.lock.d"

[ -e "$FINAL" ] && fail "the backup $FINAL already exists; it is never overwritten"

# One backup at a time. mkdir is atomic; flock is not on every system.
if ! mkdir "$LOCK" 2>/dev/null; then
    fail "another backup is running (or stopped without cleaning up): remove $LOCK if you are sure it is not running"
fi

cleanup() {
    rmdir "$LOCK" 2>/dev/null
}
trap 'cleanup' EXIT
trap 'cleanup; rm -rf "$PARTIAL"; exit 1' INT TERM

# Unfinished folders from earlier runs.
for old in "$DEST"/.partial-*; do
    [ -d "$old" ] && rm -rf "$old"
done

umask 077
mkdir "$PARTIAL" || fail "could not create $PARTIAL"
mkdir "$PARTIAL/database" || fail "could not create $PARTIAL/database"

abort() {
    echo "backup failed: $1" >&2
    rm -rf "$PARTIAL"
    exit 1
}

# 1) Databases.
MODE=${BACKUP_DB_MODE:-container}
CONTAINER=${BACKUP_CONTAINER:-offprintless}

sha() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

case "$MODE" in
    container)
        command -v docker >/dev/null 2>&1 || abort "docker is not installed (set BACKUP_DB_MODE=direct only for a stopped site)"
        docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true || abort "the container $CONTAINER is not running"
        # The backup folder is made by the site's user, copied out, and removed again.
        INNER="/tmp/backup-$DATE-$$"
        docker exec -u 33 "$CONTAINER" php -r 'exit(0);' >/dev/null 2>&1 || abort "cannot run PHP in $CONTAINER as uid 33"
        docker cp "$ROOT_SCRIPT_DIR/backup-db.php" "$CONTAINER:/tmp/backup-db.php" >/dev/null 2>&1 || abort "could not copy backup-db.php into $CONTAINER"
        docker exec -u 33 "$CONTAINER" php /tmp/backup-db.php "$INNER" /i-librarian/data || { docker exec -u 33 "$CONTAINER" rm -rf "$INNER" /tmp/backup-db.php >/dev/null 2>&1; abort "the database backup inside $CONTAINER failed"; }
        docker cp "$CONTAINER:$INNER/." "$PARTIAL/database/" >/dev/null 2>&1 || { docker exec -u 33 "$CONTAINER" rm -rf "$INNER" /tmp/backup-db.php >/dev/null 2>&1; abort "could not copy the database backup out of $CONTAINER"; }
        docker exec -u 33 "$CONTAINER" rm -rf "$INNER" /tmp/backup-db.php >/dev/null 2>&1
        ;;
    direct)
        # For a stopped site and for tests. Never use this on a running site as another user.
        MANIFEST="$PARTIAL/database/MANIFEST.json"
        first=1
        {
            printf '{\n    "made_at": "%s",\n    "source": "%s",\n    "files": {' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$DATA/database"
            for name in main.db logs.db styles.db ai.db; do
                src="$DATA/database/$name"
                if [ ! -f "$src" ]; then
                    [ "$name" = "main.db" ] && abort "main.db disappeared"
                    continue
                fi
                out="$PARTIAL/database/$name"
                sqlite3 -readonly "$src" ".timeout 15000" ".backup '$out'" >&2 || abort "SQLite could not back up $name"
                check=$(sqlite3 "$out" "PRAGMA integrity_check;" 2>&1 | head -1)
                [ "$check" = "ok" ] || abort "$name failed the integrity check: $check"
                sqlite3 "$out" "PRAGMA journal_mode=DELETE;" >/dev/null 2>&1
                rm -f "$out-wal" "$out-shm"
                [ "$first" -eq 1 ] || printf ','
                first=0
                printf '\n        "%s": {\n            "bytes": %s,\n            "sha256": "%s",\n            "integrity": "%s"\n        }' "$name" "$(wc -c < "$out" | tr -d ' ')" "$(sha "$out")" "$check"
            done
            printf '\n    }\n}\n'
        } > "$PARTIAL/database/MANIFEST.json"
        ;;
    *)
        abort "BACKUP_DB_MODE must be container or direct"
        ;;
esac

# Whatever made the copies, they must be there, sound and in the manifest.
[ -f "$PARTIAL/database/main.db" ] || abort "main.db is missing from the database backup"
[ -f "$PARTIAL/database/MANIFEST.json" ] || abort "the manifest is missing from the database backup"
for f in "$PARTIAL"/database/*.db; do
    [ "$(sqlite3 -readonly "$f" 'PRAGMA integrity_check;' 2>&1 | head -1)" = "ok" ] || abort "$(basename "$f") failed the integrity check after copying"
    grep -q "\"$(basename "$f")\"" "$PARTIAL/database/MANIFEST.json" || abort "$(basename "$f") is not in the manifest"
done

# 2) Files. Unchanged files are hard links to the previous snapshot.
PREV=""
if [ -L "$DEST/latest" ]; then
    cand="$DEST/$(readlink "$DEST/latest")"
    [ -f "$cand/DONE" ] && PREV="$cand"
fi

for folder in pdfs supplements; do
    [ -d "$DATA/$folder" ] || continue
    mkdir -p "$PARTIAL/$folder"
    if [ -n "$PREV" ] && [ -d "$PREV/$folder" ]; then
        rsync -a --delete --link-dest="$PREV/$folder" "$DATA/$folder/" "$PARTIAL/$folder/" || abort "rsync of $folder failed"
    else
        rsync -a --delete "$DATA/$folder/" "$PARTIAL/$folder/" || abort "rsync of $folder failed"
    fi
done

# 3) Finish: marker, rename, latest. Only now is it a backup.
date -u +%Y-%m-%dT%H:%M:%SZ > "$PARTIAL/DONE"
mv "$PARTIAL" "$FINAL" || abort "could not move the finished backup into place"
rm -f "$DEST/latest"
ln -s "$DATE" "$DEST/latest"

# 4) Retention: the newest KEEP finished snapshots stay. This runs only after a new one has finished.
count=0
for d in $(ls -1d "$DEST"/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] 2>/dev/null | sort -r); do
    [ -f "$d/DONE" ] || continue
    count=$((count + 1))
    if [ "$count" -gt "$KEEP" ]; then
        rm -rf "$d"
    fi
done

echo "backup $DATE done: $(du -sh "$FINAL" | cut -f1) (databases checked, $count snapshot(s) kept up to $KEEP)"
exit 0
