<?php
// PHPUnit/WordPress test bootstrap placeholder. Set WP_TESTS_DIR in CI.
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) $_tests_dir = '/tmp/wordpress-tests-lib';
require_once $_tests_dir . '/includes/functions.php';
tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__) . '/delicat-google-login.php';
});
require $_tests_dir . '/includes/bootstrap.php';
