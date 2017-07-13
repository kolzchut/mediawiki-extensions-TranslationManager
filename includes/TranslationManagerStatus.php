<?php

namespace TranslationManager;

use Title;

class TranslationManagerStatus {
	/* const */ private static $statusCodes = [
		'untranslated',
		'progress',
		'review',
		'translated',
		'irrelevant'
	];

	protected $title = null;
	protected $pageId = null;
	protected $pageName = null;
	protected $status = null;
	protected $suggestedTranslation = null;
	protected $actualTranslation = null;
	protected $project = null;
	protected $translator = null;
	protected $comments = null;
	protected $articleType = null;
	protected $pageviews = null;
	protected $isSaved = false;

	const TABLE_NAME = 'tm_status';

	public function __construct( $id ) {
		$this->pageId = (int)$id;

		if ( $this->pageId > 0 ) {
			$this->title = Title::newFromID( $this->pageId );
			if ( $this->title && $this->title->exists() ) {
				$this->pageName = $this->title->getPrefixedText();
				$this->populateBasicData();
			}
		}
	}

	public function exists() {
		return ( $this->title !== null && get_class( $this->title ) === 'Title' );
	}

	public function save() {
		$fieldMapping = [
			'tms_page_id' => $this->pageId,
			'tms_suggested_name' => $this->suggestedTranslation,
			'tms_project' => $this->project,
			'tms_status' => $this->status,
			'tms_translator' => $this->translator,
			'tms_comments' => $this->comments
		];
		$selector = [ 'tms_page_id' => $this->pageId ];

		$dbw = wfGetDB( DB_MASTER );
		if ( $this->isSaved ) {
			return $dbw->update( self::TABLE_NAME, $fieldMapping, $selector );
		} else {
			return $dbw->insert( self::TABLE_NAME, $fieldMapping );
		}

	}

	protected static function isValidSuggestedTranslation( $name ) {
		$titleObj = Title::newFromText( $name );
		return ( $titleObj && !$titleObj->isExternal() );
	}

	public static function fromId( $id ) {
		$obj = new TranslationManagerStatus( $id );
		return $obj;
	}

	public function getId() {
		return $this->pageId;
	}

	public function getName() {
		return $this->pageName;
	}

	public function getArticleType() {
		return $this->articleType;
	}
	public function getPageviews() {
		return $this->pageviews;
	}
	/**
	 * @return null|string
	 */
	public function getActualTranslation() {
		return $this->actualTranslation;
	}

	public function getStatus() {
		return $this->status;
	}
	/**
	 * @param int $status
	 */
	public function setStatus( $status ) {
		$this->status = $status;
	}

	/**
	 * @return null|string
	 */
	public function getSuggestedTranslation() {
		return $this->suggestedTranslation;
	}
	/**
	 * @param string $suggestedTranslation
	 */
	public function setSuggestedTranslation( $suggestedTranslation ) {
		$this->suggestedTranslation = $suggestedTranslation;
	}

	public function getProject() {
		return $this->project;
	}
	/**
	 * @param string $project
	 */
	public function setProject( $project ) {
		$this->project = $project;
	}

	public function getTranslator() {
		return $this->translator;
	}
	/**
	 * @param string $translator
	 */
	public function setTranslator( $translator ) {
		$this->translator = $translator;
	}

	public function getComments() {
		return $this->comments;
	}
	/**
	 * @param string $comments
	 */
	public function setComments( $comments ) {
		$this->comments = $comments;
	}

	/**
	 * Populates basic data by querying the database table
	 */
	protected function populateBasicData() {
		$dbr = wfGetDB( DB_READ );
		$query = [
			'tables' => [ 'page', self::TABLE_NAME, 'langlinks', 'page_props' ],
			'fields' => [
				'tms_page_id', // Used to know if the status item was saved
				'page_namespace',
				'page_title',
				'actual_translation' => 'll_title',
				'status' => 'tms_status',
				'comments' => 'tms_comments',
				'suggested_translation' => 'tms_suggested_name',
				'project' => 'tms_project',
				'translator' => 'tms_translator',
				'pageviews' => 'tms_pageviews',
				'article_type' => 'pp_value'
			],
			'conds' => [
				'page_namespace' => 0,
				'page_is_redirect' => false,
				'tms_page_id' => $this->pageId
			],
			'join_conds' => [
				self::TABLE_NAME => [ 'LEFT OUTER JOIN', 'page_id = tms_page_id' ],
				'langlinks' => [ 'LEFT OUTER JOIN', [ 'page_id = ll_from', "ll_lang = 'ar'" ] ],
				'page_props' => [ 'LEFT OUTER JOIN', [ 'page_id = pp_page', "pp_propname = 'ArticleType'" ] ],
			],
			'options' => []
		];

		$rowRes = $dbr->select(
			$query['tables'],
			$query['fields'],
			$query['conds'],
			__METHOD__,
			$query['options'],
			$query['join_conds']
		);
		// Extract the data
		$row = $dbr->fetchObject( $rowRes );
		if ( $row ) {
			$this->suggestedTranslation = $row->suggested_translation;
			$this->actualTranslation = $row->actual_translation;
			$this->project = $row->project;
			$this->pageviews = (int)$row->pageviews;
			$this->status = $row->status;
			$this->translator = $row->translator;
			$this->comments = $row->comments;
			$this->articleType = $row->article_type;
			$this->isSaved = $row->tms_page_id ? true : false;
		}
	}

	public static function getStatusCodes() {
		return self::$statusCodes;
	}

}

class TranslationManagerStatusException extends \Exception {
}


class TranslationManagerStatusExistenceException extends \Exception {
}
