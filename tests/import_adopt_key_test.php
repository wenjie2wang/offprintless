<?php
// Run: php tests/import_adopt_key_test.php -- "adopt the key on import": an imported entry that is an article already in
// the library may update that item's BibTeX key and attach a missing PDF, only when asked, and only with edit rights.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "\n        expected=" . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n        actual  =" . json_encode($actual, JSON_UNESCAPED_UNICODE)) . "\n";
}

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
$as = fn($h, $p) => $app->actAs($h, $p);
$publicId = (int) $app->sql("SELECT id FROM projects WHERE is_public='Y'")[0]['id'];

$mk = function (int $id, string $name, int $owner) use ($app) {
    $app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (?,?,?,'Y')", [$id, $owner, $name]);
    $app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (?,?,'owner')", [$id, $owner]);
};
$mk(10, 'Alice-A', $aliceId); $mk(11, 'Alice-B', $aliceId);
// Bob is only a viewer in project 10.
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (10,?,'viewer')", [$bobId]);

$pdf_src = '/tmp/adopt-src.pdf';
$gs = trim((string) shell_exec('command -v gs'));
file_put_contents($pdf_src, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
$put = function (string $name) use ($pdf_src) { copy($pdf_src, IL_DATA_PATH . '/import/' . $name); return IL_DATA_PATH . '/import/' . $name; };

$imp = fn(string $text, array $extra = []) => $app->model('ItemModel')->importText(['text' => $text, 'projects' => [10]] + $extra);
$key_of = fn(string $doi) => $app->sql("SELECT i.bibtex_id k FROM items i JOIN uids u ON u.item_id=i.id AND u.uid_type='DOI' WHERE u.uid=?", [$doi])[0]['k'] ?? null;
$id_of = fn(string $doi) => (int) ($app->sql("SELECT item_id i FROM uids WHERE uid_type='DOI' AND uid=?", [$doi])[0]['i'] ?? 0);
$has_pdf = fn(int $id) => is_file(IL_PDF_PATH . sprintf('/%03d/%03d/%09d.pdf', intdiv($id, 1000000), intdiv($id, 1000) % 1000, $id));
$n_items = fn() => (int) $app->sql('SELECT count(*) c FROM items')[0]['c'];

$as($aliceH, 'U');
$imp("@article{old1, title={Kernel methods for survival data}, author={Doe, Jane}, doi={10.1/k1}, year={2020}}");

echo "--- the option is off by default: nothing changes ---\n";
$p = $put('doe2020kernel-aaaaaaa.pdf');
$r = $imp("@article{doe2020kernel-aaaaaaa, title={Kernel Methods for Survival Data}, author={Doe, Jane}, doi={10.1/k1}, year={2020}, file={FULLTEXT:doe2020kernel-aaaaaaa.pdf:PDF}}");
check('key unchanged', $key_of('10.1/k1'), 'old1');
check('no PDF attached', $has_pdf($id_of('10.1/k1')), false);
check('the import file is left alone', is_file($p), true);
check('reported as a duplicate of this project', count($r['duplicates']), 1);
check('nothing counted as adopted', [(int) ($r['key_updated'] ?? 0), (int) ($r['pdf_attached'] ?? 0)], [0, 0]);

echo "--- with the option on: key adopted and the missing PDF attached ---\n";
$r = $imp("@article{doe2020kernel-aaaaaaa, title={Kernel Methods for Survival Data}, author={Doe, Jane}, doi={10.1/k1}, year={2020}, file={FULLTEXT:doe2020kernel-aaaaaaa.pdf:PDF}}", ['adopt' => '1']);
check('key adopted', $key_of('10.1/k1'), 'doe2020kernel-aaaaaaa');
check('PDF attached', $has_pdf($id_of('10.1/k1')), true);
check('the import file was consumed', is_file($p), false);
check('still one item', $n_items(), 1);
check('counted', [(int) $r['key_updated'], (int) $r['pdf_attached']], [1, 1]);
check('title of the existing item was not overwritten', $app->sql('SELECT title t FROM items')[0]['t'], 'Kernel methods for survival data');

echo "--- an item that already has a PDF keeps it; the import file stays ---\n";
$before = file_get_contents(IL_PDF_PATH . '/000/000/000000001.pdf');
$p2 = $put('other.pdf');
$r = $imp("@article{doe2020kernel-bbbbbbb, title={Kernel Methods for Survival Data}, author={Doe, Jane}, doi={10.1/k1}, year={2020}, file={FULLTEXT:other.pdf:PDF}}", ['adopt' => '1']);
check('existing PDF untouched', file_get_contents(IL_PDF_PATH . '/000/000/000000001.pdf'), $before);
check('import file not consumed', is_file($p2), true);
check('no second PDF counted', (int) $r['pdf_attached'], 0);
check('the key still follows the newest import', $key_of('10.1/k1'), 'doe2020kernel-bbbbbbb');
unlink($p2);

echo "--- a key that another item already uses is not taken ---\n";
$imp("@article{taken-key, title={A different paper entirely}, author={Roe, Rick}, doi={10.1/k2}, year={2019}}");
$imp("@article{taken-key, title={Kernel Methods for Survival Data}, author={Doe, Jane}, doi={10.1/k1}, year={2020}}", ['adopt' => '1']);
check('item 1 keeps its key', $key_of('10.1/k1'), 'doe2020kernel-bbbbbbb');
check('the other item keeps taken-key', $key_of('10.1/k2'), 'taken-key');

echo "--- an item in another project: needs edit rights, and is linked too ---\n";
$imp("@article{old3, title={Bayesian trees in practice}, author={Poe, Ann}, doi={10.1/k3}, year={2021}}", ['projects' => [11]]);
$r = $imp("@article{poe2021bayesian-ccccccc, title={Bayesian Trees in Practice}, author={Poe, Ann}, doi={10.1/k3}, year={2021}}", ['adopt' => '1', 'projects' => [10]]);
check('owner of the item can update the key', $key_of('10.1/k3'), 'poe2021bayesian-ccccccc');

echo "--- a viewer cannot change the key ---\n";
$as($bobH, 'U');
$code = 0;
try { $app->model('ItemModel')->importText(['text' => "@article{bob-key, title={Kernel Methods for Survival Data}, author={Doe, Jane}, doi={10.1/k1}, year={2020}}", 'projects' => [10], 'adopt' => '1']); } catch (Throwable $e) { $code = $e->getCode(); }
check('refused (403)', $code, 403);
check('key unchanged', $key_of('10.1/k1'), 'doe2020kernel-bbbbbbb');

echo "--- a PDF path that leaves the import folder is not followed ---\n";
$as($aliceH, 'U');
$imp("@article{old4, title={Spline smoothing of rates}, author={Lee, Kim}, doi={10.1/k4}, year={2018}}");
$r = $imp("@article{lee2018spline-ddddddd, title={Spline Smoothing of Rates}, author={Lee, Kim}, doi={10.1/k4}, year={2018}, file={FULLTEXT:../../etc/passwd:PDF}}", ['adopt' => '1']);
check('key still adopted', $key_of('10.1/k4'), 'lee2018spline-ddddddd');
check('no PDF attached from outside', $has_pdf($id_of('10.1/k4')), false);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
