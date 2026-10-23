<?php
/** Runs when the plugin is deleted in Plugins (not on deactivation): removes its options, its uploads folder and the mu loader. */
defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

defined( 'BELTOFT_PERFORMANCE_DIR' ) || define( 'BELTOFT_PERFORMANCE_DIR', __DIR__ );
require_once __DIR__ . '/src/Rules/Compiler.php';
require_once __DIR__ . '/src/Loader/LoaderManager.php';
require_once __DIR__ . '/src/Uninstaller.php';
\BeltoftPerformance\Uninstaller::run();
