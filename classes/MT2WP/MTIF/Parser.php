<?php

class MT2WP_MTIF_Parser {

  /**
   * File system pointer resource pointing to the MTIF export
   * data 
   * 
   * @var resource
   * @access protected
   */
  protected $mtif_handle;

  /**
   * Path to the MTIF export data on the file system
   * 
   * @var string
   * @access protected
   */
  protected $mtif_path;

  /**
   * Iterates over the the MTIF export data and returns either an object representing
   * the next Post, or false if the data is not formatted correctly or we've reached
   * the end of the data
   * 
   * @access public
   * @return MT2WP_MTIF_Post|false
   */
  public function next() {

    $current_post_contents = '';
    
    while ($line = fgets($this->mtif_handle)) {

      // If this line is the end of a post section, time to parse the section and
      // see if it contains a valid post
      if ($line === '--------' . PHP_EOL) {

        return $current_post = new MT2WP_MTIF_Post($current_post_contents);
      }

      $current_post_contents .= $line;
    }
    
    return false;
  }

  public function rewind() {
    fclose($this->mtif_path);
    $this->mtif_handle = fopen($this->mtif_path, 'r');
  }

  // ==================== 
  // ! Getter / Setters   
  // ==================== 

  /**
   * Set the path to the MTIF export data on the filesystem.
   * Returns a false if there was no file at this location.
   * Otherwise returns true
   * 
   * @access public
   * @param string $path
   * @return bool
   */
  public function setMTIFPath($path) {
    $this->mtif_path = $path;
    $this->mtif_handle = fopen($this->mtif_path, 'r');
    
    return !! $this->mtif_handle;
  }
}