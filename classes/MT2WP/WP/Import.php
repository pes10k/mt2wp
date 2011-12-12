<?php

class MT2WP_WP_Import {

  /**
   * PDO connection to the database that should receive the imported
   * Post data
   *
   * @var PDO
   * @access protected
   */
  protected $pdo;

  /**
   * Whether to put the class in debug mode
   *
   * (default value: false)
   *
   * @var bool
   * @access protected
   */
  protected $debug_mode = false;

  /**
   * The domain of the WordPress install.  Used when generating GUID Urls
   *
   * (default value: 'http://localhost/')
   *
   * @var string
   * @access protected
   */
  protected $wordpress_domain = 'http://localhost/';

  /**
   * The prefix appended to all tables in the WordPress database
   *
   * (default value: 'wp_')
   *
   * @var string
   * @access protected
   */
  protected $table_prefix = 'wp_';

  /**
   * A prepared PDO statement used to delete all term relations for a given
   * post
   *
   * @var PDOStatement
   * @access protected
   */
  protected $delete_term_associations_statement;

  /**
   * A prepared PDO statement used to delete all comments for a given post
   *
   * @var PDOStatement
   * @access protected
   */
  protected $delete_post_comments_statement;

  /**
   * A prepared PDO statement used to delete all comments for a given post
   *
   * @var PDOStatement
   * @access protected
   */
  protected $delete_orphaned_comment_metadata_statement;

  /**
   * A prepared PDO statement used to delete a single post give its post ID
   *
   * @var PDOStatement
   * @access protected
   */
  protected $delete_post_statement;

  /**
   * A prepared PDO statement used to create a post
   *
   * @var PDOStatement
   * @access protected
   */
  protected $create_post_statement;

  /**
   * A prepared PDO statement that returns the primary key for an author
   *
   * @var PDOStatement
   * @access protected
   */
  protected $id_for_author_statment;

  /**
   * A prepared PDO statement to assign a GUID to a post
   *
   * @var PDOStatement
   * @access protected
   */
  protected $set_guid_for_post_statement;

  /**
   * A prepared PDO statement to retreive the ID for a term.
   *
   * @var PDOStatement
   * @access protected
   */
  protected $id_for_term_statement;

  /**
   * A prepared PDO statement to insert a term (by ID) into the
   * WordPress term taxonomy system.  The term is inserted as a category
   *
   * @var PDOStatement
   * @access protected
   */
  protected $add_term_to_taxonomy_statement;

  /**
   * A prepared PDO statement to create a term and slug for a name / string
   *
   * @var PDOStatement
   * @access protected
   */
  protected $create_term_with_name_statement;

  /**
   * A prepared PDO statement to assign a term to a given post
   *
   * @var PDOStatement
   * @access protected
   */
  protected $assign_term_to_post_statement;

  /**
   * A prepared PDO statement to count the number of times a particular term
   * is being used with published posts
   *
   * @var PDOStatement
   * @access protected
   */
  protected $count_term_use_statement;

  /**
   * A prepared PDO statement to assign a use count for a term
   *
   * @var PDOStatement
   * @access protected
   */
  protected $set_term_count_statment;

  /**
   * A prepared PDO statement to create a comment and assign it to a post
   *
   * @var PDOStatement
   * @access protected
   */
  protected $add_comment_to_post_statement;

	/**
	 * A prepared PDO statement to return the term_taxonomy_id for a given
	 * term, depending on which taxonomy its located in
	 *
	 * @var PDOStatement
	 * @access protected
	 */
	protected $term_id_for_taxonomy_statement;

	/**
	 * A prepared PDO statement to that updates the body and teaser
	 * fields of a post
	 *
	 * @var PDOStatement
	 * @access protected
	 */
	protected $update_post_body_and_teaser_statement;

  public function __construct($pdo) {

    if ( ! empty($pdo)) {
      $this->setPDO($pdo);
    }
  }

  /**
   * Deletes all posts, comments, and term associations for posts
   * older than the given date.  Returns an integer count of the number of
   * results that were removed from the database on success.
   *
   * @access public
   * @param DateTime $date
   * @return int
   */
  public function deletePostsOlderThan(DateTime $date) {

    $deletion_count = 0;

    $query = sprintf(
      'SELECT ID AS id FROM %sposts WHERE post_date < "%s" AND post_type = "post"',
      $this->table_prefix,
      $date->format('Y-m-d H:i:s')
    );

    $rs = $this->pdo->query($query);

    while ($row = $rs->fetch(PDO::FETCH_OBJ)) {

      if (is_numeric($row->id) && ! $this->deletePost($row->id)) {

        break;

      } else {

        ++$deletion_count;
      
      }
    }

    $this->removeOrphanedCommentMetadata();
    
    return $deletion_count;
  }

  public function saveMTIFPost(MT2WP_MTIF_Post $post) {

    // First, find the ID for the author of this post
    $author_id = 1;
    if ($post->author()) {

      $new_author_id = $this->idForUserLogin($post->author());
      if (is_numeric($new_author_id)) {

        $author_id = $new_author_id;
      }
    }

    $status = $post->status() === MT2WP_MTIF_Post::STATUS_DRAFT ? 'draft' : 'publish';
    $comment_status = $post->allowsComments() ? 'open' : 'closed';
    $post_date = $post->date()->format('Y-m-d H:i:s');
    $post_date_gmt = gmdate('Y-m-d H:i:s', $post->date()->format('U'));

    $post_values = array(
      ':author_id' => $author_id,
      ':post_date' => $post_date,
      ':post_date_gmt' => $post_date_gmt,
      ':post_content' => $post->body(),
      ':post_title' => $post->title(),
      ':post_excerpt' => $post->excerpt(),
      ':post_status' => $status,
      ':comment_status' => $comment_status,
      ':post_name' => $post->urlAlias(),
      ':to_ping' => '',
      ':pinged' => '',
      ':post_modified' => $post_date,
      ':post_modified_gmt' => $post_date_gmt,
      ':post_content_filtered' => '',
      ':post_parent' => '0',
      ':guid' => '',
      ':menu_order' => '0',
      ':post_type' => 'post',
      ':post_mime_type' => '',
      ':comment_count' => count($post->comments()),
    );

    $rs = $this->create_post_statement->execute($post_values);

    if ($rs !== true) {

			$this->handleError('error executing "create_post_statement" for post "' . $post->title() . '" with date "' . $post_date . '"', $this->create_post_statement);
    }
    else {

      $post_id = $this->pdo->lastInsertId();

      $this->setGUIDForPost($post_id);

      if ($post->primaryCategory()) {

        $primary_term_id = $this->idForTerm($post->primaryCategory());
        $this->assignPostToTerm($post_id, $primary_term_id);
      }

      if ($post->secondayCategories()) {

        foreach ($post->secondayCategories() as $category) {

					if ($category !== $post->primaryCategory()) {

						$this->assignPostToTerm($post_id, $this->idForTerm($category));
					}
        }
      }

      if ($post->comments()) {

        foreach ($post->comments() as $comment) {

          $this->saveCommentForPost($comment, $post_id);
        }
      }

      return true;
    }
  }

  /**
   * If we have a valid PDO object, go through and create all the prepared statements
   * we'll need against it
   *
   * @access protected
   * @return void
   */
  protected function createPDOStatements() {

    if ( ! is_null($this->pdo)) {

      $delete_term_assocations_query = sprintf('DELETE FROM %sterm_relationships WHERE `object_id` = ?', $this->table_prefix);
      $this->delete_term_associations_statement = $this->pdo->prepare($delete_term_assocations_query);

      $delete_comments_query = sprintf('DELETE FROM %scomments WHERE comment_post_ID = ?', $this->table_prefix);
      $this->delete_post_comments_statement = $this->pdo->prepare($delete_comments_query);

      $delete_post_query = sprintf('DELETE FROM %sposts WHERE ID = ?', $this->table_prefix);
      $this->delete_post_statement = $this->pdo->prepare($delete_post_query);

      $create_post_columns = array(
        'post_author' => ':author_id',
        'post_date' => ':post_date',
        'post_date_gmt' => ':post_date_gmt',
        'post_content' => ':post_content',
        'post_title' => ':post_title',
        'post_excerpt' => ':post_excerpt',
        'post_status' => ':post_status',
        'comment_status' => ':comment_status',
        'post_name' => ':post_name',
        'to_ping' => ':to_ping',
        'pinged' => ':pinged',
        'post_modified' => ':post_modified',
        'post_modified_gmt' => ':post_modified_gmt',
        'post_content_filtered' => ':post_content_filtered',
        'post_parent' => ':post_parent',
        'guid' => ':guid',
        'menu_order' => ':menu_order',
        'post_type' => ':post_type',
        'post_mime_type' => ':post_mime_type',
        'comment_count' => ':comment_count',
      );

      $create_post_query = sprintf('INSERT INTO %sposts (%s) VALUES (%s)',
        $this->table_prefix,
        implode(',', array_keys($create_post_columns)),
        implode(',', array_values($create_post_columns))
      );
      $this->create_post_statement = $this->pdo->prepare($create_post_query);

      $id_for_author_query = sprintf('SELECT ID AS id FROM %susers WHERE user_login = :user_login LIMIT 1', $this->table_prefix);
      $this->id_for_author_statment = $this->pdo->prepare($id_for_author_query);

      $set_guid_for_post_query = sprintf('UPDATE %sposts SET guid = :guid WHERE ID = :post_id LIMIT 1', $this->table_prefix);
      $this->set_guid_for_post_statement = $this->pdo->prepare($set_guid_for_post_query);

      $id_for_term_query = sprintf('SELECT `%sterm_taxonomy`.`term_taxonomy_id` AS id FROM `%sterm_taxonomy` INNER JOIN `%sterms` USING (term_id) WHERE `%sterms`.`name` = :name LIMIT 1', $this->table_prefix, $this->table_prefix, $this->table_prefix, $this->table_prefix);
      $this->id_for_term_statement = $this->pdo->prepare($id_for_term_query);

      $create_term_with_name_query = sprintf('INSERT INTO %sterms (name, slug) VALUES (:name, :slug)', $this->table_prefix);
      $this->create_term_with_name_statement = $this->pdo->prepare($create_term_with_name_query);

      $add_term_to_taxonomy_query = sprintf('INSERT INTO %sterm_taxonomy (term_id, taxonomy, parent, count) VALUES (:term_id, "category", 0, 0)', $this->table_prefix);
      $this->add_term_to_taxonomy_statement = $this->pdo->prepare($add_term_to_taxonomy_query);

      $assign_term_to_post_query = sprintf('INSERT INTO %sterm_relationships (object_id, term_taxonomy_id, term_order) VALUES (:post_id, :term_taxonomy_id, 0)', $this->table_prefix);
      $this->assign_term_to_post_statement = $this->pdo->prepare($assign_term_to_post_query);

      $count_term_use_query = sprintf('
        SELECT
          COUNT(*) AS count
        FROM
          %sterm_relationships AS tr
        JOIN
          %sposts AS p ON (tr.object_id = p.ID)
        WHERE
          p.post_status = "publish" AND
          tr.term_taxonomy_id = :term_taxonomy_id
        ',
        $this->table_prefix,
        $this->table_prefix
      );
      $this->count_term_use_statement = $this->pdo->prepare($count_term_use_query);

      $set_term_count_query = sprintf('UPDATE %sterm_taxonomy SET count = :count WHERE term_taxonomy_id = :term_taxonomy_id LIMIT 1', $this->table_prefix);
      $this->set_term_count_statment = $this->pdo->prepare($set_term_count_query);

      $create_comment_columns = array(
        'comment_post_ID' => ':comment_post_id',
        'comment_author' => ':comment_author',
        'comment_author_email' => ':comment_author_email',
        'comment_author_url' => ':comment_author_url',
        'comment_author_IP' => ':comment_author_ip',
        'comment_date' => ':comment_date',
        'comment_date_gmt' => ':comment_date_gmt',
        'comment_content' => ':comment_content',
        'comment_karma' => ':comment_karma',
        'comment_approved' => ':comment_approved',
        'comment_agent' => ':comment_agent',
        'comment_type' => ':comment_type',
        'comment_parent' => ':comment_parent',
        'user_id' => ':user_id',
      );

      $add_comment_to_post_query = sprintf('INSERT INTO %scomments (%s) VALUES (%s)',
        $this->table_prefix,
        implode(',', array_keys($create_comment_columns)),
        implode(',', array_values($create_comment_columns))
      );
      $this->add_comment_to_post_statement = $this->pdo->prepare($add_comment_to_post_query);
      
      $term_id_for_taxonomy_query = sprintf('SELECT term_taxonomy_id FROM %sterm_taxonomy WHERE term_id = :term_id AND taxonomy = :taxonomy LIMIT 1', $this->table_prefix);
      $this->term_id_for_taxonomy_statement = $this->pdo->prepare($term_id_for_taxonomy_query);

      $update_post_body_and_teaser_query = sprintf('UPDATE %sposts SET post_content = :post_content, post_excerpt = :post_excerpt WHERE ID = :post_id LIMIT 1', $this->table_prefix);
      $this->update_post_body_and_teaser_statement = $this->pdo->prepare($update_post_body_and_teaser_query);      
    }
  }

  /**
   * Updates the URLs in a post body and excerpt, moving all references from the
   * source (ie MoveableType / TypePad domain) to the new wordpress domain 
   * 
   * @access public
   * @param int $id
   * @param string $post_body
   * @param string $post_excerpt
   * @param string $mt_domain
   * @return bool
   */
  public function updateAssetUrls($id, $post_body, $post_excerpt, $mt_domain) {

    $search = array(
      'http://' . $mt_domain . '/',
      'https://' . $mt_domain . '/',
    );

    $new_body = str_ireplace($search, $this->wordpress_domain, $post_body); 
    $new_excerpt = str_ireplace($search, $this->wordpress_domain, $post_excerpt);    

    return $this->update_post_body_and_teaser_statement->execute(array(
      ':post_content' => $new_body,
      ':post_excerpt' => $new_excerpt,
      ':post_id' => $id
    ));
  }

  /**
   * Removes any comment metadata that isn't associated with an existing comment
   *
   * @access protected
   * @return void
   */
  protected function removeOrphanedCommentMetadata() {

    $query = sprintf('DELETE
      	cm, c
      FROM
      	`%scommentmeta` AS cm
      LEFT JOIN
      	`%scomments` AS c ON (cm.`comment_id` = c.`comment_ID`)
      WHERE
      	c.`comment_id` IS NULL
      ',
      $this->table_prefix,
      $this->table_prefix
    );

    $this->pdo->exec($query);
  }

  /**
   * Resync the taxonomy term counts for all terms in the system
   *
   * @access public
   * @return void
   */
  public function updateTaxonomyTermCounts() {

    $query = sprintf('SELECT term_id AS id FROM %sterms', $this->table_prefix);

    $rs = $this->pdo->query($query);

    while ($row = $rs->fetch(PDO::FETCH_OBJ)) {

			$term_taxonomy_id = $this->termTaxonomyIdForTerm($row->id);

      $rs2 = $this->count_term_use_statement->execute(array(':term_taxonomy_id' => $term_taxonomy_id));

      if ($rs2 !== true) {

        $this->handleError('error executing "count_term_use_statement" with term_id = "' . $row->id . '"', $this->count_term_use_statement);
      }
      else {

        $row2 = $this->count_term_use_statement->fetch(PDO::FETCH_OBJ);

        if (isset($row2->count)) {

          $rs3 = $this->set_term_count_statment->execute(array(':term_taxonomy_id' => $term_taxonomy_id, ':count' => $row2->count));

          if ($rs3 !== true) {

						$this->handleError('error executing the "set_term_count_statment" with term_id = "' . $row->id . '" and count = "' . $row2->count . '"', $this->set_term_count_statment);
          }
        }
      }
    }
  }

  /**
   * Returns an integer for the user login account.  If there is not
   * a user account for the given ID, return false
   *
   * @access protected
   * @param string $user_login
   * @return int|false
   */
  protected function idForUserLogin($user_login) {

    $rs = $this->id_for_author_statment->execute(array(':user_login' => $user_login));

    if ($rs !== true) {

			$this->handleError('error executing "id_for_author_statment" with user_login = "' . $user_login . '"', $this->id_for_author_statment);
    }
    else {

      while ($row = $this->id_for_author_statment->fetch(PDO::FETCH_OBJ)) {

        if ( ! empty($row->id)) {
          return $row->id;
        }
      }

      return false;
    }
  }

  /**
   * Saves a MT2WP_MTIF_Comment to the WordPress and associates it with the post
   * described by $post_id
   *
   * @access public
   * @param MT2WP_MTIF_Comment $comment
   * @param numeric $post_id
   * @return bool
   */
  public function saveCommentForPost(MT2WP_MTIF_Comment $comment, $post_id) {

    $comment_values = array(
      ':comment_post_id' => $post_id,
      ':comment_author' => $comment->author(),
      ':comment_author_email' => $comment->email(),
      ':comment_author_url' => $comment->url(),
      ':comment_author_ip' => $comment->ip(),
      ':comment_date' => $comment->date()->format('Y-m-d H:i:s'),
      ':comment_date_gmt' => gmdate('Y-m-d H:i:s', $comment->date()->format('U')),
      ':comment_content' => $comment->body(),
      ':comment_karma' => '0',
      ':comment_approved' => '1',
      ':comment_agent' => '',
      ':comment_type' => '',
      ':comment_parent' => '0',
      ':user_id' => '0',
    );

    $rs = $this->add_comment_to_post_statement->execute($comment_values);

    if ($rs !== true) {

			$this->handleError('error executing "add_comment_to_post_statement" for post "' . $post_id . '" with date "' . $post_date . '" and content = "' . $comment->body() . '"', $this->add_comment_to_post_statement);
    }
    else {

      return true;
    }
  }

  /**
   * Returns the primary key of the term.  If there isn't one, create a
   * new term and return that new ID.  That new term is created as a category
   * with no parent
   *
   * Returns false on invalid input
   *
   * @access protected
   * @param string $term
   * @return int|bool
   */
  protected function idForTerm($term) {

    if (empty($term) OR ! is_string($term)) {

      return false;
    }
    else {

      $rs = $this->id_for_term_statement->execute(array(':name' => $term));

      if ($rs !== true) {

        $this->handleError('error executing "id_for_term_statement" with name = "' . $term . '"', $this->id_for_term_statement);
      }
      else {

        while ($row = $this->id_for_term_statement->fetch(PDO::FETCH_OBJ)) {

          if ( ! empty($row->id)) {

            return $row->id;
          }
        }

        // If we've gotten this far, it means that there is not currently a term
        // in the database with this name, so we need to create one
        $insert_values = array(
          ':name' => $term,
          ':slug' => str_replace(' ', '-', strtolower(mb_convert_encoding($term, 'ASCII'))),
        );

        $rs2 = $this->create_term_with_name_statement->execute($insert_values);

        if ($rs2 !== true) {

          $this->handleError('error executing "create_term_with_name_statement" with name = "' . $term . '" and slug = "' . $insert_values[':slug'] . '"', $this->create_term_with_name_statement);
        }
        else {

          $term_id = $this->pdo->lastInsertId();

          $rs3 = $this->add_term_to_taxonomy_statement->execute(array(':term_id' => $term_id));

          if ($rs3 !== true) {

            $this->handleError('error executing "add_term_to_taxonomy_statement" with term_id = "' . $term_id . '"', $this->add_term_to_taxonomy_statement);
          }
          else {

            return $this->pdo->lastInsertId();
          }
        }
      }
    }
  }

  /**
   * Set the GUID of the post equal to the wordpress domain and ?p=<post_id>.  Throws an
   * exception on database error, otherwise returns true
   *
   * @access public
   * @param numeric $post_id
   * @return bool
   */
  public function setGUIDForPost($post_id) {

    $guid = $this->wordpress_domain . '?p=' . $post_id;

    $rs = $this->set_guid_for_post_statement->execute(array(':guid' => $guid, ':post_id' => $post_id));

    if ($rs !== true) {

      $this->handleError('error executing "set_guid_for_post_statement" with guid = "' . $guid . '" and post id "' . $post_id . '"', $this->set_guid_for_post_statement);
    }
    else {

      return true;
    }
  }

  /**
   * Assigns as post to a term, using the primary key of each.
   *
   * @access public
   * @param numeric $post_id
   * @param numeric $term_id
   * @return bool
   */
  public function assignPostToTerm($post_id, $term_id) {

		$taxonomy_term_id = $this->termTaxonomyIdForTerm($term_id);

		if ( ! is_numeric($taxonomy_term_id)) {

			$this->handleError('Cannot find term "' . $taxonomy_term_id . '" in the "category" taxonomy');
		}
		else {

	    $rs = $this->assign_term_to_post_statement->execute(array(':post_id' => $post_id, ':term_taxonomy_id' => $taxonomy_term_id));

	    if ($rs !== true) {

	      $this->handleError('error executing "assign_term_to_post_statement" with post_id = "' . $post_id . '" and term_taxonomy_id = "' . $taxonomy_term_id . '"', $this->assign_term_to_post_statement);
	    }
	    else {

	      return true;
	    }
		}
  }

  /**
   * Deletes a WordPress post and any related comments and term associations.
   * This does not remove any metadata that exists for those comments though,
   * so you should call MT2WP_WP_Import::removeOrphanedCommentMetadata after
   * calling this.
   *
   * If any of the queries fail, return false and quit right away.  Otherwise, return
   * true.  This is run in transaction batches, so if there is an error during deleting
   * a post, no related post data will be effected
   *
   * @access public
   * @param mixed $post_id
   * @return bool
   */
  public function deletePost($post_id) {

    if ( ! is_numeric($post_id)) {

      return false;
    }
    else {

      $this->pdo->beginTransaction();

      $rs1 = $this->delete_term_associations_statement->execute(array($post_id));
      $rs2 = $this->delete_post_comments_statement->execute(array($post_id));
      $rs3 = $this->delete_post_statement->execute(array($post_id));

      if ($rs1 !== true OR $rs2 !== true OR $rs3 !== true) {

        $this->pdo->rollBack();
        return false;
      }
      else {

        $this->pdo->commit();
        return true;
      }
    }
  }

	/**
	 * Returns the ID for the given term id in a particular taxonomy.  Returns the
	 * numeric ID if a match is found.  Otherwise returns false
	 *
	 * @access private
	 * @param numeric $term_id
	 * @param string $type. (default: 'category')
	 * @return int|false
	 */
	private function termTaxonomyIdForTerm($term_id, $type = 'category') {

		$rs = $this->term_id_for_taxonomy_statement->execute(array(':term_id' => $term_id, ':taxonomy' => $type));

		if ($rs !== true) {

			$this->handleError('Unable to find taxonomy term id for term "' . $term_id . '" in taxonomy "' . $type . '"', $this->term_id_for_taxonomy_statement);
		}
		else {

      $row = $this->term_id_for_taxonomy_statement->fetch(PDO::FETCH_OBJ);

			return empty($row->term_taxonomy_id) ? false : $row->term_taxonomy_id;
		}
	}

	/**
	 * Either displays a message to the command line or throws an exception, depending
	 * on how the script is being run
	 *
	 * @access private
	 * @param string $message
	 * @param bool|PDOStatement $pdos
	 * @return void
	 */
	private function handleError($message, $pdos = false) {

		if ( ! isset($_SERVER['HTTP_USER_AGENT'])) {

			echo $message . PHP_EOL;
			if ($pdos) {
				var_dump($pdos->errorInfo());
			}
			exit;
		}
		else {

      throw new Exception($message);
		}
	}

  // ====================
  // ! Getter / Setters
  // ====================

  /**
   * Set the PDO object that will manage the connection to the WordPress
   * database that will be receiving data
   *
   * @access public
   * @param PDO $pdo
   * @return MT2WP_WP_Import
   */
  public function setPDO($pdo) {
    $this->pdo = $pdo;
    $this->createPDOStatements();
    return $this;
  }

  public function setTablePrefix($prefix) {
    $this->table_prefix = $prefix;
    $this->createPDOStatements();
  }

  public function setWordpressDomain($wordpress_domain) {
    $this->wordpress_domain = $wordpress_domain;
  }

  public function setDebugMode($debug_mode) {
    $this->debug_mode = !! $debug_mode;
  }
}