<?php

namespace TranslationManager;

use Addwiki\Mediawiki\DataModel\EditInfo;
use DBQueryError;
use Exception;
use MediaWiki\MediaWikiServices;
use MWTimestamp;
use Title;
use TitleValue;

class TranslationManagerStatus {
	protected const STATUS_CODES = [
		'untranslated',
		'unsuggested',
		'progress',
		'prereview',
		'review',
		'translated',
		'irrelevant'
	];

	protected const QUERY_TRANSLATION_TYPES = [
		'TRANSLATIONS_OVER_SUGGESTIONS' => 1,
		'SUGGESTIONS_ONLY' => 2
	];

	protected $title = null;
	protected $pageId = null;
	protected $pageName = null;
	protected $status = null;
	protected $suggestedTranslation = null;
	protected $previousSuggestedTranslation = null;
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

	public const TABLE_NAME = 'tm_status';

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
		$dbr = wfGetDB( DB_REPLICA );
		$id = $dbr->selectField( self::TABLE_NAME, 'tms_page_id', [ 'tms_suggested_name' => $text ] );
		return ( $id === false ? null : new TranslationManagerStatus( $id ) );
	}

	public function exists() {
		return ( $this->title !== null && get_class( $this->title ) === 'Title' );
	}

	/**
	 * @return bool
	 * @throws TMStatusSuggestionDuplicateException
	 */
	public function save() {
		$dbw = wfGetDB( DB_PRIMARY );

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
			} else {
				throw $e;
			}
		}
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
	 * @return string|bool
	 * @internal param string $suggestedTranslation
	 */
	public function setSuggestedTranslation( $newTranslation ) {
		// Make sure the suggested title is valid according to MediaWiki
		// @todo use TitleParser::makeTitleValueSafe() instead
		if ( !empty( $newTranslation ) ) {
			try {
				Title::newFromTextThrow( $newTranslation );
			} catch ( \MalformedTitleException $e ) {
				return 'invalidtitle';
			}
		}

		$this->previousSuggestedTranslation = $this->suggestedTranslation;
		$this->suggestedTranslation = $newTranslation;
		return true;
	}

	/**
	 * @return string success/error code
	 * @throws \MWException
	 */
	public function createRedirectFromSuggestion(): string {
		$apiUrl      = $this->config->get( 'TargetWikiApiURL' );
		$apiUser     = $this->config->get( 'TargetWikiUserName' );
		$apiPassword = $this->config->get( 'TargetWikiUserPassword' );

		if ( $apiUrl === null || $apiUser === null || $apiPassword === null ) {
			throw new \MWException( 'Missing API login details! See README.' );
		}

		$newSuggestion = $this->getSuggestedTranslation();
		$previousSuggestion = $this->previousSuggestedTranslation;

		if ( $newSuggestion === $previousSuggestion ) {
			return 'nochange';
		}

		// We don't create a redirect for an article that is already translated
		if ( $this->getActualTranslation() !== null ) {
			return 'alreadytranslated';
		}

		if ( $newSuggestion === null ) {
			return 'removed';
		}

		$auth = new \Addwiki\Mediawiki\Api\Client\Auth\UserAndPassword( $apiUser, $apiPassword );
		$api = new \Addwiki\Mediawiki\Api\Client\Action\ActionApi( $apiUrl, $auth );
		$services = new \Addwiki\Mediawiki\Api\MediawikiFactory( $api );

		$redirectTitle = new \Addwiki\Mediawiki\DataModel\Title( $newSuggestion );

		// Is this the first suggestion for this title? Then create a redirect.
		$oldRedirect = $previousSuggestion ? $services->newPageGetter()->getFromTitle( $previousSuggestion ) : null;

		if ( $oldRedirect === null || $oldRedirect->getPageIdentifier()->getId() === 0 ) {
			$newContent = new \Addwiki\Mediawiki\DataModel\Content(
				'#REDIRECT [[:he:' . $this->getName() . ']]'
			);
			$identifier = new \Addwiki\Mediawiki\DataModel\PageIdentifier( $redirectTitle );
			$revision   = new \Addwiki\Mediawiki\DataModel\Revision( $newContent, $identifier );
			$editinfo   = new \Addwiki\Mediawiki\DataModel\EditInfo(
				'יצירת הפניה עבור תרגום מוצע', EditInfo::NOTMINOR, EditInfo::BOT
			);
			$success = $services->newRevisionSaver()->save( $revision, $editinfo );
			return $success ? 'created' : 'failed-create';
		} else {
			// There's a previous redirect, so we just move it
			$services->newPageMover()->move(
				$oldRedirect,
				$redirectTitle,
				[ 'reason' => 'התרגום השתנה' ]
			);
			return 'moved';
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
		$dbr = wfGetDB( DB_REPLICA );
		$query = [
			'tables' => [ 'page', self::TABLE_NAME, 'langlinks', 'page_props' ],
			'fields' => [
				// 'tms_page_id' is used to know if the status item was saved
				'tms_page_id',
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

	public static function getRows( $lang, $pageIds = null ) {
		$dbr = wfGetDB( DB_REPLICA );
		$query = [
			'tables' => [ 'page', self::TABLE_NAME, 'langlinks', 'page_props' ],
			'fields' => [
				'tms_page_id',
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
				'page_is_redirect' => false,
			],
			'join_conds' => [
				self::TABLE_NAME => [ 'LEFT OUTER JOIN', 'page_id = tms_page_id' ],
				'langlinks' => [ 'LEFT OUTER JOIN', [ 'page_id = ll_from', "ll_lang = '" . $lang . "'" ] ],
				'page_props' => [ 'LEFT OUTER JOIN', [ 'page_id = pp_page', "pp_propname = 'ArticleType'" ] ],
			],
			'options' => []
		];

		if ( is_array( $pageIds ) ) {
			$query['conds'][] = 'page_id IN (' . $dbr->makeList( $pageIds ) . ')';
		}

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

	/**
	 * @param string $lang
	 * @param string $keyType
	 * @param ?int[] $pageIds
	 * @param ?int $queryType
	 *
	 * @return array
	 */
	public static function getSuggestionsByIds(
		string $lang, string $keyType = 'id', ?array $pageIds = null, ?int $queryType = null
	) {
		// set default
		if ( $queryType === null || !in_array( $queryType, self::QUERY_TRANSLATION_TYPES ) ) {
			$queryType = self::QUERY_TRANSLATION_TYPES[ 'TRANSLATIONS_OVER_SUGGESTIONS' ];
		}

		$titleFormatter = MediaWikiServices::getInstance()->getTitleFormatter();

		$translations = [];
		$rows = self::getRows( $lang, $pageIds );

		foreach ( $rows as $row ) {
			$translation = null;

			if (
				$queryType !== self::QUERY_TRANSLATION_TYPES[ 'SUGGESTIONS_ONLY'] &&
				 $row->actual_translation !== null
			) {
				$translation = $row->actual_translation;
			} else {
				$translation = $row->suggested_name;
			}

			// Don't include empty lines
			if ( $translation ) {
				if ( $keyType === 'title' ) {
					$titleValue = new TitleValue( (int)$row->page_namespace, $row->page_title );
					$key = $titleFormatter->getPrefixedText( $titleValue );
				} else {
					$key = $row->tms_page_id;
				}

				$translations[ $key ] = $translation;
			}

		}

		return $translations;
	}

	/**
	 * @return string[]
	 */
	public static function getStatusCodes(): array {
		return self::STATUS_CODES;
	}

	/**
	 * @return array
	 */
	public static function getAllProjects(): array {
		$projects = [];
		$dbr = wfGetDB( DB_REPLICA );
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

	/**
	 * @return array
	 */
	public static function getAllTranslators(): array {
		$translators = [];
		$dbr = wfGetDB( DB_REPLICA );
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

	/**
	 * @param string $code
	 *
	 * @return false|string
	 */
	public static function getStatusMessageForCode( string $code ) {
		if ( in_array( $code, self::STATUS_CODES ) ) {
			return wfMessage( 'ext-tm-status-' . $code )->escaped();
		}

		return false;
	}

}

class TranslationManagerStatusException extends Exception {
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
