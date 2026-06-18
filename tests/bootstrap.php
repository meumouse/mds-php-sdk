<?php
/**
 * PHPUnit bootstrap: Composer autoload + minimal WP constants.
 *
 * WordPress functions are mocked per-test with Brain\Monkey.
 *
 * @package MeuMouse\MDS\SDK
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
