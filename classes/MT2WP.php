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
class MT2WP {

  /**
   * Count of the number of posts that were transfered from the MTIF data to
   * Wordpress.  Returns -1 if no transfers were attempted
   *
   * (default value: -1)
   *
   * @var int
   * @access private
   */
  private $num_posts_transfered = -1;

  /**
   * Count of the number of assets that were transfered from MoveableType to
   * Wordpress.  Returns -1 if no transfers were attempted
   *
   * (default value: -1)
   *
   * @var int
   * @access private
   */
  private $num_assets_transfered = -1;

  /**
   * Reference to an instantiated PDO object connecting to the database that
   * holding the Wordpress information
   *
   * @var PDO
   * @access private
   */
  private $pdo;

  /**
   * Container for the current configuration of the importer.  Settings are described in further
   * detail below, at the accessor method
   *
   * (default value: array())
   *
   * @var array
   * @access private
   */
  private $settings = array();

  /**
   * Override the default constructor to set default values configuration values
   *
   * @access public
   * @return void
   */
  public function __construct() {

    $this->setSettings(array(
      'import_posts' => false,
      'import_assets' => false,
      'importer_verbose' => true,
      'importer_delete_wp_posts_before' => false,
      'wp_db_prefix' => 'wp_',
      'wp_db_pass' => '',
      'wp_db_driver' => 'mysql',
      'mt_domain_hidden' => false,
    ));
  }

  /**
   * migrate function.
   *
   * @access public
   * @return bool
   */
  public function migrate() {

    if ( ! class_exists('MT2WP_WP_Import')) {

      include dirname(__FILE__) . '/MT2WP/WP/Import.php';
      include dirname(__FILE__) . '/MT2WP/MTIF/Parser.php';
      include dirname(__FILE__) . '/MT2WP/MTIF/Post.php';
      include dirname(__FILE__) . '/MT2WP/MTIF/Comment.php';
      include dirname(__FILE__) . '/MT2WP/Asset.php';
    }

    // Reset counters so that we can get accurate statistics
    $this->num_posts_transfered = 0;
    $this->num_assets_transfered = 0;

    if ( ! $this->setting('import_posts')) {

      $this->debugMessage('Not importing any posts because of configuration settings');

    } else {

      $wp_import = new MT2WP_WP_Import($this->pdo());
      $wp_import->setWordpressDomain('http://' . $this->setting('wp_domain') . '/');

      if ($this->setting('importer_delete_wp_posts_before')) {

        $num_posts_deleted = $wp_import->deletePostsOlderThan(new DateTime($this->setting('importer_delete_wp_posts_before')));
        $wp_import->updateTaxonomyTermCounts();
        $this->debugMessage('Deleted ' . $num_posts_deleted . ' old posts from Wordpress');

      }

      $parser = new MT2WP_MTIF_Parser;
      $parser->setMTIFPath($this->setting('mt_mtif_file'));
      $i = 0;

      while ($post = $parser->next()) {

        ++$i;

        if ($wp_import->saveMTIFPost($post)) {

          $this->debugMessage('Imported post with title "' . $post->title() . '"');

        } else {

          throw new Exception('There was an error copying over the post with title "' . $post->title() . '"');

        }
      }

      $wp_import->updateTaxonomyTermCounts();

      $this->debugMessage('Successfully imported ' . $i . ' posts into Wordpress');
      $this->num_posts_transfered = $i;

    }

    if ( ! $this->setting('import_assets')) {

      $this->debugMessage('Not migrating over any assets because of configuration settings');

    } else {

      $this->debugMessage('Beginning asset import process');
      $asset_migration_count = 0;

    	// Get assets from current site
    	// Initialize CURL to grab the needed assets from the (possibly non-public) current domain
    	$ch = curl_init();

    	if ($this->setting('mt_domain_hidden')) {

    		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: http://' . $this->setting('mt_domain') . '/'));

    	}

    	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    	curl_setopt($ch, CURLOPT_HEADER, 0);

    	$posts = $this->pdo()->query('SELECT ID, post_content, post_excerpt FROM `' . $this->setting('wp_db_prefix') . 'posts`');
    	$remote_paths = array();

    	while ($row = $posts->fetch(PDO::FETCH_OBJ)) {

    		$found_assets = array_merge(
    		  MT2WP_Asset::findAssetsInString($row->post_content),
    		  MT2WP_Asset::findAssetsInString($row->post_excerpt)
    		);

    		if ( ! empty($found_assets)) {

    			foreach ($found_assets as $asset) {

    				if (stripos($asset->domain(), $this->setting('mt_domain')) === 0 OR
    					stripos($asset->domain(), str_replace('www.', '', $this->setting('mt_domain'))) === 0) {

    					$remote_paths[$asset->remotePath()] = TRUE;

    	        $remote_url = parse_url($asset->url());
    	        $request_url = $remote_url['scheme'] . '://' . $this->setting('mt_domain') . $remote_url['path'];
    			    curl_setopt($ch, CURLOPT_URL, $request_url);
    		      $result = curl_exec($ch);

    	        // Create the directory the image will be copied to if it doesn't exist
    	        $destination_dir = $this->setting('wp_root') . '/' . $asset->remotePath();

    	        if ( ! is_dir($destination_dir)) {
    	          mkdir($destination_dir, 0777, TRUE);
    	        }

    	        // Don't overwrite files that already exist
    	        $destination_file_name = str_replace('//', '/', $destination_dir . '/' . $asset->filename());

    	        if (is_file($destination_file_name)) {

                $this->debugMessage('Not migrating asset ' . $asset->filename() . ' to ' . $destination_file_name . ' because a file with that name already exists');

              } else {

    	          file_put_contents($destination_file_name, $result);
                $this->debugMessage('Migrated asset ' . $asset->filename() . ' to ' . $destination_file_name);
                ++$asset_migration_count;

    	        }

              // Now update the URL of each asset
              $wp_import->updateAssetUrls($row->ID, $row->post_content, $row->post_excerpt, $this->setting('mt_domain'));
    				}
    			}
    		}
    	}

      $this->debugMessage('Successfully migrated ' . $asset_migration_count . ' assets');
      $this->num_assets_transfered = $asset_migration_count;
    }

    return true;
  }

  /**
   * Checks the current configuration of the migrator to make sure everything looks
   * correct before we start changing anything.  Throws an exception if
   * any required configuration settings are missing or in an invalid state.  Otherwise
   * returns true if everything looks correct.
   *
   * Note, some of the checks below might seem pedantic, but since we're potentially overwritting
   * a large amount of information, we want to make absolutly sure that things are set correctly
   * and intentionally
   *
   * @throws Exception
   * @throws PDOException
   * @access public
   * @return bool
   */
  public function sanityCheckSettings() {

    // Check to make sure that a timezone is set and that its a valid PHP timezone
    if ( ! $this->setting('importer_timezone')) {

      throw new Exception('Timezone not set in MT2WP');

    } elseif ( ! date_default_timezone_set($this->setting('importer_timezone'))) {

      throw new Exception('Invalid timezone, "' . $this->setting('importer_timezone') . '" set in MT2WP');

    }

    // Check to make sure that the connection parameters for the WP database
    // are present and valid
    if ( ! $this->setting('wp_db_name')) {

      throw new Exception('No valid database name found for target wordpress database');

    } elseif ( ! $this->setting('wp_db_user')) {

      throw new Exception('No database username provided for target Wordpress database');

    } elseif ( ! $this->setting('wp_db_host')) {

      throw new Exception('No database host provided for target Wordpress database');

    } else {

      $pdo_driver = $this->setting('wp_db_driver');

      if ( ! in_array($pdo_driver, PDO::getAvailableDrivers())) {

        throw new Exception('Invalid PDO driver provided for target Wordpress database: ' . $pdo_driver);

      } else {

        // If it looks like we have valid database connection parameters,
        // Connect to the database and verify that things look like they're in the
        // correct position (ie that the wordpress tables exist and they're prefixed
        // as expected)
        $this->connectToDatabase();

        if ( ! $this->pdo()->query(sprintf('SELECT COUNT(*) AS count FROM %sposts LIMIT 1', $this->setting('wp_db_prefix')))) {

          throw new Exception('Unable to find Wordpress posts table at `' . $this->setting('wp_db_prefix') . 'posts`');

        }
      }
    }

    // Check to make sure that the wordpress install is in place, where its advertised,
    // and, if we're transfering over assets, writeable by the server
    if ( ! $this->setting('wp_root') OR ! is_file($this->setting('wp_root') . DIRECTORY_SEPARATOR . 'wp-config.php')) {

      throw new Exception('Unable to find Wordpress install (no wp config file found at "' . $this->setting('wp_root') . DIRECTORY_SEPARATOR . 'wp-config.php")');

    }

    // If we're set to migrate posts from the provided export data into Wordpress,
    // Check to see that the export data is in place and seems to be formatted correctly
    if ($this->setting('import_posts')) {

      if ( ! is_file($this->setting('mt_mtif_file'))) {

        throw new Exception('Migrator is set to copy posts from MTIF data into Wordpress, but cannot load MTIF data at "' . $this->setting('mt_mtif_file') . '"');

      } elseif ($this->setting('importer_delete_wp_posts_before')) {

        // If we're set to delete old posts, make sure that the given date is a valid date
        if (date_create($this->setting('importer_delete_wp_posts_before')) === false) {

          throw new Exception('Migrator is set to delete old posts, but the DateTime does not recognize "' . $this->setting('importer_delete_wp_posts_before') . '" as a valid time');

        }

      } else {

        $parser = new MT2WP_MTIF_Parser;
        $parser->setMTIFPath($this->setting('mt_mtif_file'));

        $post = $parser->next();

        if (empty($post)) {

          throw new Exception('Migrator is set to copy posts from MTIF data into Wordpress, the MTIF file at "' . $this->setting('mt_mtif_file') . '" does not contain any posts');

        }

        // Finally, check to make sure the wordpress domain is configured correctly
        $wp_domain = $this->setting('wp_domain');

        if (empty($mt_domain)) {

          throw new Exception('Migrator is set to copy posts in Wordpress, but the Wordpress domain is not set');

        } elseif (strpos($mt_domain, '://') !== false OR substr($mt_domain, -1) === '/') {

          throw new Exception('Migrator is set to copy posts in Wordpress, but the current Wordpress domain is set incorrectly.  Should not include protocol information or a trailing slash');

        }
      }
    }

    // If we're moving assets from the moveable type install over to the wordpress install,
    // Check to make sure that the curl functions exist and that we can write to the wordpress
    // section of the filesystem
    if ($this->setting('import_assets')) {

      if ( ! is_writable($this->setting('wp_root'))) {

        throw new Exception('Migrator is set to copy assets from MoveableType to Wordpress, but the Wordpress directory is not writeable');

      } elseif ( ! function_exists('curl_init')) {

        throw new Exception('Migrator is set to copy assets from MoveableType to Wordpress, but the curl libraries are not installed');

      } else {

        // Check to make sure the current Moveable Type domain is formatted correctly
        $mt_domain = $this->setting('mt_domain');

        if (empty($mt_domain)) {

          throw new Exception('Migrator is set to copy assets from MoveableType to Wordpress, but the current MoveableType domain is not set');

        } elseif (strpos($mt_domain, '://') !== false OR substr($mt_domain, -1) === '/') {

          throw new Exception('Migrator is set to copy assets from MoveableType to Wordpress, but the current MoveableType domain is set incorrectly.  Should not include protocol information or a trailing slash');

        }
      }
    }

    // If we've gotten this far, the current configuration of the the migrator looks correct,
    // as far as we can tell
    return true;
  }

  /**
   * Set a single configuration value for the importer.  Valid settings are
   * as follow:
   *
   * IMPORTER SETTINGS
   *
   * import_posts (bool) : Whether to import posts from MoveableType to Wordpress
   *                       one execute
   * import_assets (bool) : Whether to copy over assets referenced in the
   *                        MoveableType data (images, PDFs, etc.) to the Wordpress.
   *                        Note that this option will only copy over files that
   *                        are linked to in at least MoveableType post's body and that
   *                        live on the same domain as the MoveableType install
   * importer_timezone (bool) : Timezone for dates in the Wordpress.  Must be one of the
   *                        dates described at http://php.net/manual/en/timezones.php
   * importer_delete_wp_posts_before (mixed) : Determines whether the importer should
   *                        first delete some set of posts already in Wordpress before
   *                        moving content from MoveableType.  Defaults to not doing so,
   *                        but if provided a date, will delete all posts before that date
   *                        in Wordpress. If set, should be a date in a format that
   *                        the DateTime constructor recognizes.  See
   *                        http://php.net/manual/en/book.datetime.php for examples.
   * importer_verbose (bool) : Whether the importer should echo out a description of what it
   *                        is doing in.  Defaults to true
   *
   * WORDPRESS SETTINGS
   *
   * wp_db_prefix (string) : The prefix for tables in the Wordpress database.  Defaults to "wp_"
   * wp_db_name (string) : The name of the database that contains the Wordpress data
   * wp_db_user (string) : The username for a database account that can write to the Wordpress database
   * wp_db_pass (string) : The password for a database account that can write to the Wordpress database
   * wp_db_host (string) : The host for the database that will hold the Wordpress data
   * wp_db_driver (string) : The PDO database driver for the database that Wordpress is running from.
   *                      Defaults to 'mysql'.  Must be one of the drivers returned from
   *                      PDO::getAvailableDrivers().  See
   *                      http://www.php.net/manual/en/pdo.getavailabledrivers.php for more information.
   * wp_root (string) : The absolute filepath for the local directory containing the Wordpress install.
   *                    (ie, if your Wordpress configuration file is at
   *                    /var/www/vhosts/example.org/httpdocs/wp-config.php, this value should be set to
   *                    '/var/www/vhosts/example.org/httpdocs').  No trailing slash please!
   * wp_domain (string) : the public domain for the wordpress install we're transfering data into.
   *                      Should not include a trailing slash or protocol information
   *                      (ie example.org/some_sub_directory, not
   *                      http://example.org/some_sub_directory/)
   *
   * MOVEABLE TYPE SETTINGS
   *
   * mt_mtif_file (string) : path to the exported MTIF data on the local filesystem (this is the file
   *                         generated when using the Export functionality in MoveableType)
   * mt_domain (string) : The domain MoveableType was configured to run from.  Should not
   *                      include a trailing slash or protocol information (ie example.org, not
   *                      http://example.org/)
   * mt_domain_hidden (bool) : whether the MoveableType install is publically accessible
   *                        or if its on a hidden domain.  Note that this setting does nothing
   *                        if "import_assets" is false
   *
   * @access public
   * @param string $setting_name the name of a setting to configure
   * @param mixed $setting_value the new value for the above setting
   * @return bool Whether a valid setting was set / changed
   */
  public function setSetting($setting_name, $setting_value) {

    if ( ! in_array($setting_name, $this->validSettings())) {

      return FALSE;

    } else {

      $this->settings[$setting_name] = $setting_value;
      return true;

    }
  }

  /**
   * Returns the current state of a given setting.  Throws
   * an Exception if an invalid setting is referenced.  Returns
   * FALSE if a valid but unset setting is referenced.
   *
   * @throws Exception
   * @access public
   * @param string $setting_name
   * @return mixed
   */
  public function setting($setting_name) {

    if ( ! in_array($setting_name, $this->validSettings())) {

      throw new Exception('Attempting to access invalid MT2WP setting: "' . $setting_name . '"');

    }
    else {

      return isset($this->settings[$setting_name])
        ? $this->settings[$setting_name]
        : FALSE;
    }
  }

  /**
   * Convenience method to allow configuring multiple settings at the same time.
   * The provided array should have the names of settings as keys and the
   * coresponding setting's value as the value.
   *
   * Valid settings are described with the setSetting method.
   *
   * Returns true if all settings were set correctly.  Otherwise, returns FALSE
   * if an invalid setting or value was provided
   *
   * @access public
   * @param array $settings
   * @return bool
   */
  public function setSettings($settings) {

    foreach ($settings as $setting_name => $setting_value) {

      if ( ! $this->setSetting($setting_name, $setting_value)) {

        return FALSE;
      }
    }

    return true;
  }

  /**
   * Return the current PDO connection.  If none is set, attempt
   * to create one using the current wp_* settings and set the
   * character set to be UTF8
   *
   * @access public
   * @return PDO
   */
  public function pdo() {

    if ( ! empty($this->pdo)) {

      return $this->pdo;

    } else {

      return $this->connectToDatabase();

    }
  }

  /**
   * Returns the number of posts that were transfered from the MTIF
   * data into wordpress.  Returns -1 if called before any transfer
   * was attempted
   *
   * @access public
   * @return int
   */
  public function numPostsTransfered() {

    return $this->num_posts_transfered;
  }

  /**
   * Returns the number of files that were transfered from the MoveableType
   * into wordpress.  Returns -1 if called before any transfer
   * was attempted
   *
   * @access public
   * @return int
   */
  public function numAssetsTransfered() {

    return $this->num_assets_transfered;
  }

  /**
   * Attempts to create a connection to the target Wordpress containing database.
   * If successful, sets the connection to use 'utf8' and returns the PDO object
   *
   * @throws PDOException
   * @access private
   * @return PDO
   */
  private function connectToDatabase() {

    $pdo_string = sprintf('%s:dbname=%s;host=%s;charset=UTF-8',
      $this->setting('wp_db_driver'),
      $this->setting('wp_db_name'),
      $this->setting('wp_db_host')
    );


    $this->pdo = new PDO($pdo_string, $this->setting('wp_db_user'), $this->setting('wp_db_pass'));

    $this->pdo->exec('SET CHARACTER SET utf8');

    return $this->pdo;
  }

  /**
   * Returns an array containing all valid configuration settings.
   * See the setSetting() method for a description of what each
   * of these settings does
   *
   * @access private
   * @return array
   */
  private function validSettings() {

    static $valid_settings = array(
      'import_posts',
      'import_assets',
      'importer_timezone',
      'importer_delete_wp_posts_before',
      'importer_verbose',
      'wp_db_prefix',
      'wp_db_name',
      'wp_db_user',
      'wp_db_pass',
      'wp_db_host',
      'wp_db_driver',
      'wp_root',
      'wp_domain',
      'mt_mtif_file',
      'mt_domain',
      'mt_domain_hidden',
    );

    return $valid_settings;
  }

  /**
   * If the migrator is set to be verbose, echo out the given message and return
   * true.  Otherwise, do nothing and return false;
   *
   * @access private
   * @param mixed $message
   * @return bool
   */
  private function debugMessage($message) {

    if ($this->setting('importer_verbose')) {

      echo $message . PHP_EOL;
      return true;

    } else {

      return false;

    }
  }
}