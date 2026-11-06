<?php
require __DIR__ . '/bootstrap.php';
$app = TestApp::create();
echo "booted; tables: " . count($app->sql("SELECT name FROM sqlite_master WHERE type='table'")) . "\n";
echo "public project: " . json_encode($app->sql("SELECT id, project, is_public FROM projects WHERE is_public='Y'")) . "\n";
