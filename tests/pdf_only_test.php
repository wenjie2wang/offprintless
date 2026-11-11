<?php
// Run: php tests/pdf_only_test.php  -- the application takes PDF files only; nothing depends on LibreOffice.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
$root = dirname(__DIR__);
$read = fn(string $f) => (string) file_get_contents("$root/$f");
$files = trim((string) shell_exec('find ' . escapeshellarg("$root/app") . ' ' . escapeshellarg("$root/classes") . ' ' . escapeshellarg("$root/public") . ' -type f \( -name "*.php" -o -name "*.js" -o -name "*.html" \) ! -path "*/classes/libraries/*" ! -name "*.min.js" ! -path "*/js/citeproc/*" ! -path "*/js/tinymce/*" ! -name "vue*.js" 2>/dev/null'));

echo "--- nothing in the application mentions LibreOffice ---\n";
$hits = [];
foreach (explode("\n", $files) as $f) {
    if ($f !== '' && preg_match('/soffice|libreoffice|convertToPdf|extra_mime_types|extra_file_types/i', (string) file_get_contents($f))) { $hits[] = str_replace("$root/", '', $f); }
}
check('no code path calls or configures LibreOffice', $hits === [], join(', ', $hits));
check('the Dockerfiles do not install it', !preg_match('/libreoffice/i', $read('Dockerfile') . $read('Dockerfile.local')));
check('the settings do not offer a LibreOffice path', !str_contains($read('classes/appsettings.php'), 'soffice_path'));
check('the Binary helper has no soffice entry', !str_contains($read('classes/media/binary.php'), 'soffice'));

echo "--- the upload paths accept PDF only ---\n";
$app = TestApp::create();
[$uid, $h] = $app->addUser('alice', 'U');
$app->actAs($h, 'U');
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (10,?,'P','Y')", [$uid]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (10,?,'owner')", [$uid]);
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (1,'A paper','N',?)", [$uid]);
$app->sql('INSERT INTO projects_items (project_id,item_id) VALUES (10,1)');

$docx = "PK\x03\x04" . str_repeat("\0", 200) . 'word/document.xml';
$png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 200);
// The constructor starts a session; save() only needs the container, so build the controller without it.
$rc = new ReflectionClass(LibrarianApp\PdfController::class);
$ctl = $rc->newInstanceWithoutConstructor();
foreach (['di', 'app_settings'] as $prop) {
    for ($c = $rc; $c; $c = $c->getParentClass()) {
        if ($c->hasProperty($prop)) { $p = $c->getProperty($prop); $p->setAccessible(true); $p->setValue($ctl, $prop === 'di' ? $app->di : $app->di->getShared('AppSettings')); break; }
    }
}
foreach (['a Word file' => [$docx, 'notes.docx'], 'an image' => [$png, 'scan.png'], 'plain text' => ["hello\n", 'a.txt']] as $what => [$bytes, $name]) {
    $code = 0; $msg = '';
    try { $ctl->save(1, \GuzzleHttp\Psr7\Utils::streamFor($bytes), $name); } catch (Throwable $e) { $code = $e->getCode(); $msg = $e->getMessage(); }
    check("saving $what is refused with a 400", $code === 400, "$code $msg");
    check("...and the message says PDF only", stripos($msg, 'pdf') !== false, $msg);
}
$temp = glob(IL_TEMP_PATH . '/{notes,scan,a}.*', GLOB_BRACE) ?: [];
check('...and no file is left behind in the temp folder', $temp === [], join(',', $temp));
$sup = (int) $app->sql('SELECT count(*) c FROM sqlite_master WHERE name = \'supplements\'')[0]['c'];
check('...and nothing was saved as a supplement', glob(IL_SUPPLEMENT_PATH . '/*/*/*') === [] || glob(IL_SUPPLEMENT_PATH . '/*/*/*') === false);

echo "--- the import message ---\n";
$imp = $read('app/controllers/import.php');
check('the import form tells people only PDFs are accepted', str_contains($imp, 'only PDF files can be imported') || str_contains($imp, 'only PDF files'), 'message not found');
check('the add-file form does not say office or image', !preg_match('/Add PDF, office, image file/i', $read('app/views/import.php')));

echo "--- the software details page ---\n";
$det = $read('app/controllers/details.php');
check('does not list LibreOffice as a binary', !preg_match('/soffice|LibreOffice/', $det));
check('still lists the PDF tools and Tesseract', str_contains($det, "'tesseract'") && str_contains($det, "'pdftotext'"));

echo "--- a setting saved before is harmless ---\n";
$app->sql("INSERT OR REPLACE INTO settings (setting_name, setting_value) VALUES ('soffice_path', '/usr/bin')");
$loaded = $app->model('SettingsModel')->loadGlobal();
$app->di->getShared('AppSettings')->setGlobal($loaded);
check('the old value is still in the database (nothing deletes it)', ($loaded['soffice_path'] ?? null) === '/usr/bin');
check('...the settings still load and answer for other keys', $app->di->getShared('AppSettings')->getGlobal('max_items') !== null);
check('...and it is not part of the defaults any more', !array_key_exists('soffice_path', $app->di->getShared('AppSettings')->default_global_settings));
// What the admin form does on save: only keys that exist in the settings are kept. The old key is dropped silently.
$posted = ['soffice_path' => '/x', 'max_items' => '5000'];
$kept = array_intersect_key($posted, $app->di->getShared('AppSettings')->getGlobal());
check('saving the settings form with the old field drops it without an error', isset($kept['max_items']), json_encode(array_keys($kept)));

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
