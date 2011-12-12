<?php

class MT2WP_Asset {

	// Pattern will return either the value of the 'src' attribute for IMG elements,
	// or the value of 'href' attribute for A elements when we're refering to a PDF,
	// as the 1st (only) match.  In the case of the anchors PDF, the '.pdf' extension
	// will not be included.
	//
	// The recommned way of using this is as:
	// 	preg_match_all($pattern, $string, $matches, PREG_SET_ORDER);
	// You can then interate over the results and check to see if either 'img_asset' or 
	// 'a_asset' is populated	
	const REGEX_FILE = '/<img(?:[^\/>]+?)?src=(?:"|\')(?P<img_asset>.*?)(?:"|\')|<a(?:[^\/>]+?)?href=(?:"|\')(?P<a_asset>[^\'"]+?)(?P<pdf_extension>\.pdf)(?:"|\')/im';

	// Pattern that extracts the domain of the remote file from its absolute URL
	const REGEX_DOMAIN = '/https?:\/\/(?P<domain>.*?)\//im';

	const REGEX_PATH = '/https?:\/\/(?:.*?)\/(?P<path>.*?)\/(:?[^\/]+)$/im';

	/**
	 * Takes a string of HTML data and returns a set of MT2WP_Asset objects for references in
	 * the data
	 * 
	 * @access public
	 * @static
	 * @param string $string
	 * @return array
	 */
	static public function findAssetsInString($string) {

		$assets = array();
		$matches = array();
		
		if (preg_match_all(self::REGEX_FILE, $string, $matches, PREG_SET_ORDER) > 0) {

			foreach ($matches as $match) {
				
				if (empty($match['a_asset'])) {
				
					$asset_type = 'img';
					$asset_url = $match['img_asset'];
				}
				else {

					$asset_type = 'a';
					$asset_url = $match['a_asset'] . $match['pdf_extension'];
				}

				$assets[] = new MT2WP_Asset($asset_url, $asset_type);			
			}
		}
		
		return $assets;
	}

	/**
	 * An absolute URL for the file referred to
	 * 
	 * (default value: '')
	 * 
	 * @var string
	 * @access protected
	 */
	protected $asset_url = '';
	
	/**
	 * The filename of the file (just the string to the right of the last 
	 * "/" in the full url of the asset)
	 * 
	 * (default value: '')
	 * 
	 * @var string
	 * @access protected
	 */
	protected $asset_filename = ''; 
	
	/**
	 * A string representation of the HTML element that referred to this
	 * file (such as 'img' or 'a')
	 * 
	 * (default value: '')
	 * 
	 * @var string
	 * @access protected
	 */
	protected $asset_tag = '';
	
	/**
	 * The domain that the asset exists on
	 * 
	 * (default value: '')
	 * 
	 * @var string
	 * @access protected
	 */
	protected $asset_domain = '';
	
	public function __construct($url = FALSE, $tag = FALSE) {

		if ( ! empty($url)) {
			$this->setUrl($url);
		}
		
		if ( ! empty($tag)) {
			$this->setTag($tag);
		}
	}
	
	// =========================== 
	// ! Getter / Setter Methods   
	// =========================== 
	
	/**
	 * Set the absolute URL for this asset.  Also automatically 
	 * sets the domain and filename in the object for this asset
	 * 
	 * @access public
	 * @param string $url
	 * @return MT2WP_Asset
	 */
	public function setUrl($url) {
	
		$this->asset_url = $url;
		$parts = explode('/', $url);
		$this->asset_filename = $parts[count($parts) - 1];
		
		$domain_matches = array();

		if (preg_match(self::REGEX_DOMAIN, $url, $domain_matches) === 1) {
			$this->asset_domain = $domain_matches['domain'];
		}
		
		return $this;
	}
	
	/**
	 * Return the absolute URL of the asset
	 * 
	 * @access public
	 * @return string
	 */
	public function url() {
	
		return $this->asset_url;
	}
	
	/**
	 * Set the tag for this element 
	 * 
	 * @access public
	 * @param string $tag
	 * @return MT2WP_Asset
	 */
	public function setTag($tag) {
	
		$this->asset_tag = strtoupper($tag);
		return $this;
	}
	
	/**
	 * Return the name tag that referred to this file (will always
	 * be uppercase)
	 * 
	 * @access public
	 * @return string
	 */
	public function tag() {
	
		return $this->asset_tag;
	}
	
	public function domain() {

		return $this->asset_domain;
	}
	
	/**
	 * Returns the remote (public) location of the asset.  This is really just the 
	 * string between the domain and the filename.  So, for example, in:
	 * 	http://example.org/path/to/file/here.pdf
	 * this method would return "path/to/file"
	 *
	 * Return FALSE on error
	 * 
	 * @access public
	 * @return string|bool
	 */
	public function remotePath() {
	
		$matches = array();
		
		if (preg_match(self::REGEX_PATH, $this->url(), $matches) === 1) {
		
			return empty($matches['path']) ? FALSE : $matches['path'];
		}
		else {

			return FALSE;
		}
	}
	
	/**
	 * Returns the filename of the asset.  Ex. in the following string
	 *  http://example.com/a_directory/document.pdf
	 * this method would return "document.pdf"
	 * 
	 * @access public
	 * @return string
	 */
	public function filename() {
	 return $this->asset_filename;
	}
}