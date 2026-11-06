<?php
/**
 * Boots the real I, Librarian framework against a throwaway data dir so tests can call real models.
 * Usage: require 'tests/bootstrap.php'; $app = TestApp::create(); $m = $app->model('ItemModel', $userId);
 */
namespace {

use Librarian\Container\DependencyInjector;
use Librarian\Factory;
use Librarian\Loader;

final class TestApp {

    public DependencyInjector $di;

    public static function create(): TestApp {

        $root = dirname(__DIR__);
        $tmp = sys_get_temp_dir() . '/il-test-' . getmypid() . '-' . bin2hex(random_bytes(3));

        foreach (['', '/database', '/cache/temp', '/cache/icons', '/cache/pages', '/pdfs', '/supplements', '/sessions', '/import'] as $d) {

            mkdir($tmp . $d, 0777, true);
        }

        define('IL_VERSION', 'test');
        define('IL_PUBLIC_PATH', $root . '/public');
        define('IL_PRIVATE_PATH', $root);
        define('IL_APP_PATH', $root . '/app');
        define('IL_CLASS_PATH', $root . '/classes');
        define('IL_CONFIG_PATH', $root . '/config');
        define('IL_DATA_PATH', $tmp);
        define('IL_CACHE_PATH', $tmp . '/cache');
        define('IL_DB_PATH', $tmp . '/database');
        define('IL_PDF_PATH', $tmp . '/pdfs');
        define('IL_SUPPLEMENT_PATH', $tmp . '/supplements');
        define('IL_TEMP_PATH', $tmp . '/cache/temp');
        define('IL_BASE_URL', 'http://localhost/');
        define('IL_PATH_URL', '');

        require IL_CLASS_PATH . '/loader.php';
        (new Loader())->register();

        $app = new TestApp();
        $app->di = new DependencyInjector();
        (new Factory($app->di))->assemble();

        $app->di->getShared('AppSettings')->loadIni();
        $app->di->getShared('AppSettings')->setGlobal([]);

        $inst = $app->di->getShared('Installation');
        $inst->install();
        $inst->upgrade();

        return $app;
    }

    /** Create a user directly in the database. Returns [id, id_hash]. */
    public function addUser(string $name, string $perm): array {

        $db = $this->di->get('Db_main');
        $db->connect();
        $hash = hash('sha256', $name);
        $db->run("INSERT INTO users (id_hash, username, password, permissions, status) VALUES (?, ?, 'x', ?, 'A')", [$hash, $name, $perm]);
        $id = $db->lastInsertId();
        $db->close();

        return [$id, $hash];
    }

    /** Act as the given user (the models read the session). */
    public function actAs(string $id_hash, string $perm): void {

        $_SESSION['il']['user_id'] = $id_hash;
        $_SESSION['il']['permissions'] = $perm;
        $_SESSION['il']['settings'] = $this->di->getShared('AppSettings')->default_user_settings;
    }

    public function model(string $class) {

        $c = "\\LibrarianApp\\$class";

        return new $c($this->di);
    }

    public function sql(string $sql, array $cols = []) {

        $db = $this->di->get('Db_main');
        $db->connect();
        $db->run($sql, $cols);
        $rows = $db->getResultRows();
        $db->close();

        return $rows;
    }
}
}
