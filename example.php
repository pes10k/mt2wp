<?php

/**
 * MTIF to Wordpress Importer
 *
 * Package to move content from a MoveableType export file (MTIF) to 
 * wordpress.  Copies over posts from the MTIF data, including
 * URL aliases, teasers, body text, and taxonomy terms.  Will also
 * associate posts with authors, if a wordpress user with the name of the 
 * TypePad author exists.
 *
 *
 * @package MT2WP
 * @author Peter Snyder <snyderp@gmail.com>
 * @version 0.1
 */

include dirname(__FILE__) . '/classes/MT2WP.php';

// Transfering the MTIF data over can take a long time and consume a lot
// of memory.  You should set these settings sufficently high to allow
// the system to transfer things over fully
error_reporting(E_STRICT);
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 'On');
ini_set('memory_limit', '2G');
ini_set('max_execution_time', '36000');
set_time_limit(36000);

$settings = array(
  'import_posts' => true,
  'import_assets' => true,
  'importer_timezone' => 'America/New_York',
  'importer_delete_wp_posts_before' => '',
  'importer_verbose' => true,
  'wp_db_prefix' => 'wp_',
  'wp_db_name' => '',
  'wp_db_user' => '',
  'wp_db_pass' => '',
  'wp_db_host' => '',
  'wp_root' => '',
  'wp_domain' => '',
  'mt_mtif_file' => '',
  'mt_domain' => '',
  'mt_domain_hidden' => false,
);

date_default_timezone_set($settings['importer_timezone']);

$migrator = new MT2WP();
$migrator->setSettings($settings);

if ( ! $migrator->migrate()) {

  echo 'An error occurred during the migration process' . PHP_EOL;

} else {

  echo PHP_EOL . 'Successfully imported ' . $migrator->numPostsTransfered() . ' posts and ' . $migrator->numAssetsTransfered() . ' assets' . PHP_EOL;

}