<?php

namespace TranslationManager;

use DBQueryError;
use Exception;
use MediaWiki\MediaWikiServices;
use Title;
use MWTimestamp;

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
	protected $wordcount = null;
	protected $startDate = null;
	protected $endDate = null;
	protected $isSaved = false;

	const TABLE_NAME = 'tm_status';

	/** @var \Config */
	private $config;

	public function __construct( $id ) {
		$this->pageId = (int)$id;

		if ( $this->pageId > 0 ) {
			$this->title = Title::newFromID( $this->pageId );
			if ( $this->title && $this->title->exists() ) {
				$this->pageName = $this->title->getPrefixedText();
				$this->populateBasicData();
			}
		}

		$this->config = MediaWikiServices::getInstance()
						->getConfigFactory()->makeConfig( 'TranslationManager' );
	}

	public static function newFromSuggestedTranslation( $text ) {
		$dbr = wfGetDB( DB_SLAVE );
		$id = $dbr->selectField( self::TABLE_NAME, 'tms_page_id', [ 'tms_suggested_name' => $text ] );
		return ( $id === false ? null : new TranslationManagerStatus( $id ) );
	}

	public function exists() {
		return ( $this->title !== null && get_class( $this->title ) === 'Title' );
	}

	public function save() {
		$dbw = wfGetDB( DB_MASTER );

		$fieldMapping = [
			'tms_page_id' => $this->pageId,
			'tms_suggested_name' => $this->suggestedTranslation,
			'tms_project' => $this->project,
			'tms_status' => $this->status,
			'tms_translator' => $this->translator,
			'tms_comments' => $this->comments,
			'tms_wordcount' => $this->wordcount,
			'tms_start_date' => $dbw->timestampOrNull( $this->startDate ),
			'tms_end_date' => $dbw->timestampOrNull( $this->endDate ),
		];
		$selector = [ 'tms_page_id' => $this->pageId ];

		try {
			if ( $this->isSaved ) {
				return $dbw->update( self::TABLE_NAME, $fieldMapping, $selector );
			} else {
				$status = $dbw->insert( self::TABLE_NAME, $fieldMapping );
				$this->isSaved = true;
				return $status;
			}
		} catch ( DBQueryError $e ) {
			if ( $e->errno == 1062 ) {
				throw new TMStatusSuggestionDuplicateException(
					self::newFromSuggestedTranslation( $this->getSuggestedTranslation() )
				);
			}
		}

		return false;

	}

	protected static function isValidSuggestedTranslation( $name ) {
		$titleObj = Title::newFromText( $name );
		return ( $titleObj && !$titleObj->isExternal() );
	}

	public static function isValidStatusCode( $code ) {
		return in_array( $code, self::getStatusCodes() );
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
	 * @param string|null $newTranslation
	 *
	 * @return string
	 * @internal param string $suggestedTranslation
	 */
	public function setSuggestedTranslation( $newTranslation ) {
			$oldSuggestion = $this->suggestedTranslation;
			$this->suggestedTranslation = $newTranslation;

			$status = $this->createRedirectFromSuggestion( $oldSuggestion );

			return $status;
	}

	protected function createRedirectFromSuggestion( $oldSuggestion ) {
		$apiUrl      = $this->config->get( 'TargetWikiApiURL' );
		$apiUser     = $this->config->get( 'TargetWikiUserName' );
		$apiPassword = $this->config->get( 'TargetWikiUserPassword' );

		if ( $apiUrl === null || $apiUser === null || $apiPassword === null ) {
			throw new \MWException( 'Missing API login details! See README.' );
		}

		$newSuggestion = $this->getSuggestedTranslation();

		if ( $newSuggestion === $oldSuggestion ) {
			return 'nochange';
		}

		if ( $newSuggestion === null ) {
			return 'removed';
		}

		require_once ( __DIR__ . '/../vendor/autoload.php' );
		$api = new \Mediawiki\Api\MediawikiApi( $apiUrl );
		$api->login( new \Mediawiki\Api\ApiUser( $apiUser, $apiPassword ) );
		$services = new \Mediawiki\Api\MediawikiFactory( $api );

		/*
		$redirectTitle = new \Mediawiki\DataModel\Title( $this->getSuggestedTranslation() );
		$redirectTarget = new \Mediawiki\DataModel\Title( 'he:' . $this->getName() );
		$newRedirect = new \Mediawiki\DataModel\Redirect( $redirectTitle, $redirectTarget );
		*/

		$redirectTitle = new \Mediawiki\DataModel\Title( $newSuggestion );

		// Is this the first suggestion for this title? Then create a redirect.
		try {

			if ( $oldSuggestion === null ) {
				$newContent = new \Mediawiki\DataModel\Content(
					'#REDIRECT [[he:' . $this->getName() . ']]'
				);
				$identifier = new \Mediawiki\DataModel\PageIdentifier( $redirectTitle );
				$revision   = new \Mediawiki\DataModel\Revision( $newContent, $identifier );
				$services->newRevisionSaver()->save( $revision );
				return 'created';
			} else { // There's a previous redirect, so we just move it
				$services->newPageMover()->move(
					$services->newPageGetter()->getFromTitle( $oldSuggestion ),
					$redirectTitle,
					[ 'reason' => 'התרגום השתנה' ]
				);
				return 'moved';
			}
		} catch ( \Mediawiki\Api\UsageException $e ) {
			return $e->getApiCode();
		}

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

	public function getWordcount() {
		return $this->wordcount;
	}

	public function setWordcount( $wordcount ) {
		$wordcount = $wordcount === null ? null : (int)$wordcount;
		$this->wordcount = $wordcount;
		return true;
	}

	/**
	 * @return MWTimestamp
	 */
	public function getStartDate() {
		return $this->startDate;
	}
	public function setStartDate( $startDate ) {
		$this->startDate = empty( $startDate ) ? null : new MWTimestamp( $startDate );
	}
	public function setStartDateFromField( $startDate ) {
		$this->setStartDate( self::makeTimestampFromField( $startDate ) );
	}

	public static function makeTimestampFromField( $date, $end = false ) {
		$time = $end ? 'T23:59:59Z' : 'T00:00:00Z';
		return $date ? new MWTimestamp( $date . $time ) : null;
	}

	/**
	 * @return MWTimestamp
	 */
	public function getEndDate() {
		return $this->endDate;
	}
	public function setEndDate( $endDate ) {
		$this->endDate = empty( $endDate ) ? null : new MWTimestamp( $endDate );
	}
	public function setEndDateFromField( $endDate ) {
		$this->setEndDate( self::makeTimestampFromField( $endDate, true ) );
	}

	/**
	 * Populates basic data by querying the database table
	 */
	protected function populateBasicData() {
		$dbr = wfGetDB( DB_SLAVE );
		$query = [
			'tables' => [ 'page', self::TABLE_NAME, 'langlinks', 'page_props' ],
			'fields' => [
				'tms_page_id', // Used to know if the status item was saved
				'page_namespace',
				'page_title',
				'actual_translation' => 'll_title',
				'status' => 'tms_status',
				'comments' => 'tms_comments',
				'start_date' => 'tms_start_date',
				'end_date' => 'tms_end_date',
				'suggested_name' => 'tms_suggested_name',
				'project' => 'tms_project',
				'translator' => 'tms_translator',
				'wordcount' => 'tms_wordcount',
				'pageviews' => 'tms_pageviews',
				'article_type' => 'pp_value'
			],
			'conds' => [
				'page_namespace' => 0,
				'page_is_redirect' => false,
				'page_id' => $this->pageId
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
			$this->suggestedTranslation = $row->suggested_name;
			$this->actualTranslation = $row->actual_translation;
			$this->project = $row->project;
			$this->pageviews = (int)$row->pageviews;
			$this->status = $row->actual_translation ? 'translated' : $row->status;
			$this->translator = $row->translator;
			$this->comments = $row->comments;
			$this->wordcount = $row->wordcount;
			$this->articleType = $row->article_type;
			$this->setStartDate( $row->start_date );
			$this->setEndDate( $row->end_date );
			$this->isSaved = $row->tms_page_id ? true : false;
		}
	}

	public static function getAll() {
		$dbr = wfGetDB( DB_SLAVE );
		$query = [
			'tables' => [ 'page', self::TABLE_NAME, 'langlinks', 'page_props' ],
			'fields' => [
				'tms_page_id', // Used to know if the status item was saved
				'page_namespace',
				'page_title',
				'actual_translation' => 'll_title',
				'status' => 'tms_status',
				'comments' => 'tms_comments',
				'start_date' => 'tms_start_date',
				'end_date' => 'tms_end_date',
				'suggested_name' => 'tms_suggested_name',
				'project' => 'tms_project',
				'translator' => 'tms_translator',
				'wordcount' => 'tms_wordcount',
				'pageviews' => 'tms_pageviews',
				'article_type' => 'pp_value'
			],
			'conds' => [
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => false,
			],
			'join_conds' => [
				self::TABLE_NAME => [ 'LEFT OUTER JOIN', 'page_id = tms_page_id' ],
				'langlinks' => [ 'LEFT OUTER JOIN', [ 'page_id = ll_from', "ll_lang = 'ar'" ] ],
				'page_props' => [ 'LEFT OUTER JOIN', [ 'page_id = pp_page', "pp_propname = 'ArticleType'" ] ],
			],
			'options' => []
		];

		$rows = $dbr->select(
			$query['tables'],
			$query['fields'],
			$query['conds'],
			__METHOD__,
			$query['options'],
			$query['join_conds']
		);

		return $rows;
	}

	public static function getAllSuggestions() {
		$suggestions = [];
		$rows = self::getAll();
		foreach ( $rows as $row ) {
			if ( isset( $row->suggested_name ) && $row->actual_translation === null ) {
				$suggestions[] = $row;
			}
		}

		return $suggestions;
	}

	public static function getStatusCodes() {
		return self::$statusCodes;
	}

	public static function getAllProjects() {
		$projects = [];
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			self::TABLE_NAME,
			'DISTINCT tms_project',
			[ 'tms_project IS NOT NULL', 'tms_project <> ""' ]
		);
		foreach ( $res as $row ) {
			$projects[] = $row->tms_project;
		}

		return $projects;
	}

	public static function getAllTranslators() {
		$translators = [];
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			self::TABLE_NAME,
			'DISTINCT tms_translator',
			[ 'tms_translator IS NOT NULL', 'tms_translator <> ""' ]
		);
		foreach ( $res as $row ) {
			$translators[] = $row->tms_translator;
		}

		return $translators;
	}

	public static function getAllMainCategories() {
		$mainCategories = [];
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			self::TABLE_NAME,
			'DISTINCT tms_main_category',
			[ 'tms_main_category IS NOT NULL', 'tms_main_category <> ""' ]

		);
		foreach ( $res as $row ) {
			$mainCategories[] = $row->tms_main_category;
		}

		return $mainCategories;
	}

	public static function getStatusMessageForCode( $code ) {
		if ( in_array( $code, self::$statusCodes ) ) {
			return wfMessage( 'ext-tm-status-' . $code )->escaped();
		}

		return false;
	}

}

class TranslationManagerStatusException extends Exception {
}


class TranslationManagerStatusExistenceException extends TranslationManagerStatusException {
}

class TMStatusSuggestionDuplicateException extends TranslationManagerStatusException {
	protected $translationStatus;

	public function __construct( TranslationManagerStatus $tmStatus ) {
		$this->translationStatus = $tmStatus;
		parent::__construct();
	}

	public function getTranslationManagerStatus() {
		return $this->translationStatus;
	}
}

