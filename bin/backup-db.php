<?php

/**
 * Consistent copies of the SQLite databases of a site that is in use.
 *
 * Usage: php backup-db.php DESTINATION DATA_FOLDER
 *   DESTINATION  a folder that does not exist yet (or is empty). It is created private (0700).
 *   DATA_FOLDER  the folder that holds database/main.db (the app's data folder).
 *
 * Meant to run INSIDE the container as the user the site runs as (uid 33): a process of another user that opens a database
 * in write-ahead mode can leave -wal and -shm files that the site then cannot write ("attempt to write a readonly
 * database"). weekly-backup.sh feeds this file to `docker exec -u 33 ... php`.
 *
 * Copies main.db, logs.db, styles.db and, when it exists, ai.db with SQLite's online backup, which reads a consistent
 * snapshot while the site keeps writing. Each copy is checked with PRAGMA integrity_check, and MANIFEST.json records size,
 * sha256 and the result.
 *
 * Exit codes: 0 done, 1 failed (the destination is removed again), 2 wrong usage.
 */

if (PHP_SAPI !== 'cli') {

    exit(2);
}

if ($argc !== 3) {

    fwrite(STDERR, "usage: php backup-db.php DESTINATION DATA_FOLDER\n");
    exit(2);
}

[, $dest, $data] = $argv;
$dest = rtrim($dest, '/');
$source = rtrim($data, '/') . '/database';
$made = false;

$fail = function (string $why) use ($dest, &$made): void {

    fwrite(STDERR, "backup failed: $why\n");

    // Only a folder this run created is removed; an existing backup is never touched.
    if ($made) {

        foreach (glob($dest . '/*') ?: [] as $f) {

            @unlink($f);
        }

        @rmdir($dest);
    }

    exit(1);
};

if (is_file($source . '/main.db') === false) {

    $fail("no main.db in $source");
}

if (file_exists($dest) && (is_dir($dest) === false || count(array_diff(scandir($dest), ['.', '..'])) > 0)) {

    $fail("the destination $dest exists and is not empty; it is never overwritten");
}

if (is_dir($dest) === false && @mkdir($dest, 0700, true) === false) {

    $fail("could not create $dest");
}

$made = true;
@chmod($dest, 0700);
$files = [];

foreach (['main.db', 'logs.db', 'styles.db', 'ai.db'] as $name) {

    $from = "$source/$name";

    if (is_file($from) === false) {

        if ($name === 'main.db') {

            $fail('main.db disappeared');
        }

        continue;
    }

    $to = "$dest/$name";

    try {

        $src = new SQLite3($from, SQLITE3_OPEN_READWRITE);
        $src->enableExceptions(true);
        $src->busyTimeout(15000);

        $dst = new SQLite3($to, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $dst->enableExceptions(true);

        if ($src->backup($dst) === false) {

            $fail("SQLite could not back up $name");
        }

        $src->close();

        // A copy is only worth keeping if it is a sound database.
        $integrity = (string) $dst->querySingle('PRAGMA integrity_check');
        $dst->exec('PRAGMA journal_mode = DELETE');
        $dst->close();

    } catch (Throwable $e) {

        $fail("$name: " . $e->getMessage());
    }

    if ($integrity !== 'ok') {

        $fail("$name failed the integrity check: $integrity");
    }

    foreach (['-wal', '-shm'] as $suffix) {

        @unlink($to . $suffix);
    }

    @chmod($to, 0600);
    $files[$name] = ['bytes' => filesize($to), 'sha256' => hash_file('sha256', $to), 'integrity' => $integrity];
}

$manifest = ['made_at' => gmdate('c'), 'source' => $source, 'files' => $files];

if (file_put_contents("$dest/MANIFEST.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {

    $fail('could not write the manifest');
}

@chmod("$dest/MANIFEST.json", 0600);
echo "backed up " . count($files) . " databases to $dest\n";
exit(0);
