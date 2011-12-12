<?php

class MT2WP_MTIF_Post {

  const PATTERN_AUTHOR = '/(?:^|\n)AUTHOR: (.*)\n/';
  const PATTERN_TITLE = '/(?:^|\n)TITLE: (.*)\n/';
  const PATTERN_DATE = '/(?:^|\n)DATE: (.*)\n/';
  const PATTERN_STATUS = '/(?:^|\n)STATUS: (.*)\n/';
  const PATTERN_URL = '/(?:^|\n)BASENAME: (.*)\n/';
  const PATTERN_ALLOW_COMMENTS = '/(?:^|\n)ALLOW COMMENTS: (.*)\n/';
  const PATTERN_ALLOW_PINGS = '/(?:^|\n)ALLOW PINGS: (.*)\n/';
  const PATTERN_CONVERT_BREAKS = '/(?:^|\n)CONVERT BREAKS: (.*)\n/';
  const PATTERN_CATEGORY_PRIMARY = '/(?:^|\n)PRIMARY CATEGORY: (.*)/';
  const PATTERN_CATEGORY_SECONDARY = '/(?:^|\n)CATEGORY: (.*)/';
  const PATTERN_BODY = '/(?:^|\n)-----\nBODY:\n(.*?)\n-----\n/is';
  const PATTERN_EXTENDED_BODY = '/(?:^|\n)-----\nEXTENDED BODY:\n(.*?)\n-----\n/is';
  const PATTERN_COMMENT = '/(?:^|\n)COMMENT:\n(.*?)\n-----\n/is';

  const STATUS_DRAFT = 1;
  const STATUS_PUBLISH = 2;

  /**
   * Date the post was published on 
   * 
   * @var DateTime
   * @access protected
   */
  protected $date;

  /**
   * Name of the author who created this post
   * 
   * @var string
   * @access protected
   */
  protected $author = '';
  
  /**
   * Title of the post
   * 
   * @var string
   * @access protected
   */
  protected $title = '';  
  
  /**
   * The main category for this post
   * 
   * @var string
   * @access protected
   */
  protected $primary_category = '';
  
  /**
   * An array of strings of the categories this post belongs to
   * 
   * (default value: array())
   * 
   * @var array
   * @access protected
   */
  protected $seconday_categories = array();
  
  /**
   * The posts status, one of the MT2WP_MTIF_Post::STATUS_* constants
   * 
   * @var int
   * @access protected
   */
  protected $status = 0;
  
  /**
   * URL alias for the post title.  This is the BASENAME value 
   * in the Movable Type Import Format
   * http://www.sixapart.com/movabletype/docs/mtimport
   * 
   * (default value: '')
   * 
   * @var string
   * @access protected
   */
  protected $url_alias = '';
  
  /**
   * Whether the post allows comments (Defaults to FALSE)
   * 
   * (default value: FALSE)
   * 
   * @var bool
   * @access protected
   */
  protected $allows_comments = FALSE;
  
  /**
   * Whether the post allows pings (Defaults to FALSE)
   * 
   * (default value: FALSE)
   * 
   * @var bool
   * @access protected
   */
  protected $allows_pings = FALSE;
  
  /**
   * Whether or not to convert new lines to <br /> entities
   * 
   * (default value: FALSE)
   * 
   * @var bool
   * @access protected
   */
  protected $convert_breaks = FALSE;
  
  /**
   * Teaser body of the post.  This will either be in HTML.  If
   * there is no extneded body, this will be the full version of
   * the post  
   * 
   * (default value: '')
   * 
   * @var string
   * @access protected
   */
  protected $body = '';

  /**
   * Full body of the post.  This will either be in HTML or plain text.
   * This may be empty
   * 
   * (default value: '')
   * 
   * @var string
   * @access protected
   */
  protected $extended_body = '';
  
  /**
   * Brief version of the post, used for teasers
   * 
   * (default value: '')
   * 
   * @var string
   * @access protected
   */
  protected $excerpt = '';
  
  /**
   * An array of MT2WP_MTIF_Comment objects representing zero or more comments
   * 
   * (default value: array())
   * 
   * @var array
   * @access protected
   */
  protected $comments = array();
  
  public function __construct($string = '') {

    $this->date = new DateTime();

    if ( ! empty($string)) {
      $this->parseString($string);
    }    
  }
  
  /**
   * Takes a string describing a single post in the MTIF format as described here:
   * http://www.sixapart.com/movabletype/docs/mtimport
   * 
   * @access public
   * @param string $string
   * @return bool
   */
  public function parseString($string) {
    
    $matches = array();
    
    if (preg_match(self::PATTERN_AUTHOR, $string, $matches) === 1) {
      $this->author = $matches[1];
    }

    if (preg_match(self::PATTERN_TITLE, $string, $matches) === 1) {
      $this->title = $matches[1];
    }

    if (preg_match(self::PATTERN_DATE, $string, $matches) === 1) {
      $this->date = new DateTime($matches[1]);
    }

    if (preg_match(self::PATTERN_STATUS, $string, $matches) === 1) {
      $this->status = strtolower($matches[1]) === 'draft' ? self::STATUS_DRAFT : self::STATUS_PUBLISH;
    }

    if (preg_match(self::PATTERN_URL, $string, $matches) === 1) {
      $this->url_alias = $matches[1];
    }

    if (preg_match(self::PATTERN_CATEGORY_PRIMARY, $string, $matches) === 1) {
      $this->primary_category = $matches[1];
    }

    if (preg_match_all(self::PATTERN_CATEGORY_SECONDARY, $string, $matches) > 0) {

      if ( ! empty($matches[1])) {

        foreach ($matches[1] as $item) {
          $this->seconday_categories[] = $item;
        }
      }
    }
 
    if (preg_match(self::PATTERN_BODY, $string, $matches) === 1) {
      $this->body = $matches[1];
    }
    
    if (preg_match(self::PATTERN_EXTENDED_BODY, $string, $matches) === 1) {
      $this->extended_body = $matches[1];
    }

    if (preg_match(self::PATTERN_ALLOW_COMMENTS, $string, $matches) === 1) {
      $this->allows_comments = trim($matches[1]) === '1';
    }

    if (preg_match(self::PATTERN_ALLOW_PINGS, $string, $matches) === 1) {
      $this->allows_pings = trim($matches[1]) === '1';
    }

    if (preg_match(self::PATTERN_CONVERT_BREAKS, $string, $matches) === 1) {
      $this->convert_breaks = trim($matches[1]) === '1';
    }
    
    if (preg_match_all(self::PATTERN_COMMENT, $string, $matches) > 0) {

      if ( ! empty($matches[1])) {

        foreach ($matches[1] as $item) {
          $this->comments[] = new MT2WP_MTIF_Comment($item);
        }
      }    
    }
  }
  
  // ==================== 
  // ! Getter / Setters   
  // ==================== 
  public function comments() {
    return $this->comments;
  }
  
  public function date() {
    return $this->date;
  }
  
  public function author() {
    return $this->author;
  }
  
  public function title() {
    return $this->title;
  }
  
  public function primaryCategory() {
    return $this->primary_category;
  }
  
  public function secondayCategories() {
    return $this->seconday_categories;
  }
  
  public function status() {
    return $this->status;
  }
  
  public function urlAlias() {
    return $this->url_alias;
  }
  
  public function allowsComments() {
    return $this->allows_comments;
  }
  
  public function allowsPings() {
    return $this->allows_pings;
  }
  
  public function convertBreaks() {
    return $this->convert_breaks;
  }
  
  public function body() {
    return empty($this->extended_body) ? $this->body : $this->extended_body;
  }
  
  public function excerpt() {
    return empty($this->extended_body) ? $this->excerpt : $this->body;
  }
}