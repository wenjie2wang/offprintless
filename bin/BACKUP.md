# Backups

`bin/weekly-backup.sh` keeps weekly snapshots of the data folder on another disk. `bin/restore-check.sh` proves that a
snapshot is sound without touching the live site.

## What is saved

| Saved | Why |
|---|---|
| `database/` (main, logs, styles, ai) | the library: references, projects, users, notes, chats |
| `pdfs/` | the PDF files |
| `supplements/` | supplements |

Not saved: `cache/` (rebuilt), `sessions/` (people sign in again), `import/` (temporary). `ai.db` is included because
rebuilding the index costs embedding tokens.

The databases are copied with SQLite's online backup, so the site can stay up, and each copy is checked. Snapshots are
full folders, but unchanged files are hard links to the previous week, so the weekly cost is only what changed.

## Install on tars (once)

```sh
mkdir -p /mnt/disk3/backups/offprintless
mkdir -p ~/log/cron-job
crontab -e
```

Add this line (Sunday 03:30; keeps the newest 8 snapshots, about two months):

```
30 3 * * 0 /home/wenjie/offprintless/bin/weekly-backup.sh /media/docker-services/i-librarian /mnt/disk3/backups/offprintless 8 > /home/wenjie/log/cron-job/offprintless_backup_$(date -I).log 2>&1
```

Run it once by hand first to see that it works:

```sh
~/offprintless/bin/weekly-backup.sh /media/docker-services/i-librarian /mnt/disk3/backups/offprintless 8
~/offprintless/bin/restore-check.sh /mnt/disk3/backups/offprintless
```

**How the databases are copied.** Inside the running container, as the user the site runs as (uid 33), with SQLite's online
backup. This matters: if the host user opened the live databases, SQLite could leave `-wal` and `-shm` files owned by that
user, and the site would then fail with "attempt to write a readonly database". I reproduced exactly that in a test, which
is why the script never opens the live databases from the host. It needs `docker` and a running container named
`offprintless` (set `BACKUP_CONTAINER` if yours is named differently). PDFs and supplements are copied by `rsync` on the
host; they are only read.

## Look at it now and then

```sh
ls /mnt/disk3/backups/offprintless          # dated folders and "latest"
tail -3 ~/log/cron-job/offprintless_backup_*.log | tail -8
~/offprintless/bin/restore-check.sh /mnt/disk3/backups/offprintless
```

A week with no new folder means the job did not run or failed; the log says why. The script exits 1 on any failure and
never deletes an older snapshot until a new one has finished.

## Restore

1. Run `restore-check.sh` on the snapshot you want and read the result.
2. `docker compose down`
3. Move the live folders aside, do not delete them:
   `mv /media/docker-services/i-librarian/database /media/docker-services/i-librarian/database.broken-$(date +%F)`
4. Copy the snapshot back:
   `cp -a /mnt/disk3/backups/offprintless/2026-11-15/database /media/docker-services/i-librarian/database`
   and the same for `pdfs` and `supplements` if they were lost.
5. `sudo chown -R 33:33 /media/docker-services/i-librarian/database /media/docker-services/i-librarian/pdfs`
6. `docker compose up -d`

Rehearse steps 1 to 4 once into a scratch folder (not the live one) so that the first real restore is not the first try.

## Not covered

A second disk in the same machine does not protect against theft, fire, a power surge or a mistake that deletes both
disks. Once in a while copy the newest snapshot somewhere else (another machine, a cloud drive).
