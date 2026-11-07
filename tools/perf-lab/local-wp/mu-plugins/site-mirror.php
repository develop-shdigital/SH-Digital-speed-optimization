<?php
/**
 * Test only. With MIRROR_SITE (the live origin, for example https://www.example.ch) and
 * MIRROR_UPLOADS (a local folder) set in the environment, the local WordPress presents itself
 * under the live address and writes uploads (the optimizer's CSS files) to that folder. Saved
 * live pages can then be optimized locally (tests/optimize-fixtures.php) and measured against
 * the live site's files through the replay server. Without the variables nothing changes.
 */
$site_mirror_origin  = rtrim( (string) getenv( 'MIRROR_SITE' ), '/' );
$site_mirror_uploads = rtrim( (string) getenv( 'MIRROR_UPLOADS' ), '/' );
if ( '' === $site_mirror_origin || '' === $site_mirror_uploads ) {
	return;
}
$site_mirror_local = rtrim( (string) ( getenv( 'MIRROR_LOCAL' ) ?: 'http://127.0.0.1:8088' ), '/' );
$site_mirror_swap  = static function ( $url ) use ( $site_mirror_local, $site_mirror_origin ) {
	return str_replace( $site_mirror_local, $site_mirror_origin, (string) $url );
};
foreach ( array( 'home_url', 'site_url', 'plugins_url', 'content_url', 'rest_url' ) as $site_mirror_filter ) {
	add_filter( $site_mirror_filter, $site_mirror_swap, 1 );
}
add_filter(
	'upload_dir',
	static function ( $dirs ) use ( $site_mirror_uploads, $site_mirror_origin ) {
		$dirs['basedir'] = $site_mirror_uploads;
		$dirs['baseurl'] = $site_mirror_origin . '/wp-content/uploads';
		$dirs['path']    = $site_mirror_uploads . $dirs['subdir'];
		$dirs['url']     = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	},
	1
);
