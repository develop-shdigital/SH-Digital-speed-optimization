<?php
// Router for PHP's built-in server: real files and folders as they are, everything else to WordPress.
//   php -S 127.0.0.1:8088 -t <wordpress-folder> tools/perf-lab/local-wp/router.php
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = $_SERVER['DOCUMENT_ROOT'] . urldecode( $path );
if ( '/' !== $path && ( is_file( $file ) || is_dir( $file ) ) ) {
	return false;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
