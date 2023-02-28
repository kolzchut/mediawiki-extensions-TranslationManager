<?php

namespace TranslationManager;

use Addwiki\Mediawiki\DataModel\EditInfo;
use DBQueryError;
use Exception;
use MediaWiki\MediaWikiServices;
use MWTimestamp;
use Title;
use TitleValue;
use Wikimedia\Rdbms\IResultWrapper;

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
	/** @var Title|null */
	protected $title = null;
	/** @var int|null */
	protected $pageId = null;
	/** @var string|null */
	protected $pageName = null;
	/** @var string|null */
	protected $status = null;
	/** @var string|null */
	protected $language = null;
	/** @var string|null */
	protected $suggestedTranslation = null;
	/** @var string|null */
	protected $previousSuggestedTranslation = null;
	/** @var string|null */
	protected $actualTranslation = null;
	/** @var string|null */
	protected $project = null;
	/** @var string|null */
	protected $translator = null;
	/** @var string|null */
	protected $comments = null;
	/** @var string|null */
	protected $articleType = null;
	/** @var int|null */
	protected $pageviews = null;
	/** @var int|null */
	protected $wordcount = null;
	/** @var string|null */
	protected $startDate = null;
	/** @var string|null */
	protected $endDate = null;
	/** @var bool */
	protected $isSaved = false;

	public const TABLE_NAME = 'tm_status';

	/** @var \Config */
	private $config;

	/**
	 * @param int|string $id
	 * @param string $lang
	 *
	 * @throws \MWException
	 */
	public function __construct( $id, string $lang ) {
		if ( !in_array( $lang, self::getValidLanguages() ) ) {
			throw new \MWException( 'invalid language' );
		}
		$this->language = $lang;
		$this->pageId = (int)$id;

		if ( $this->pageId > 0 ) {
			$this->title = Title::newFromID( $this->pageId );
			if ( $this->title && $this->title->exists() ) {
				$this->pageName = $this->title->getPrefixedText();
				$this->populateBasicData();
			}
		}

		$this->config = Hooks::getConfig();
	}

	/**
	 * @param string $text
	 * @param string $language
	 *
	 * @return TranslationManagerStatus|null
	 * @throws \MWException
	 */
	public static function newFromSuggestedTranslation( string $text, string $language ): ?TranslationManagerStatus {
		$dbr = wfGetDB( DB_REPLICA );
		$id = $dbr->selectField(
			self::TABLE_NAME,
			'tms_page_id',
			[ 'tms_suggested_name' => $text, 'tms_lang' => $language ]
		);
		return ( $id === false ? null : new TranslationManagerStatus( $id, $language ) );
	}

	/**
	 * @return array
	 */
	public static function getLanguagesForSelectField(): array {
		$languageCodes = self::getValidLanguages();
		$options = [];
		$languageNameUtils = MediaWikiServices::getInstance()->getLanguageNameUtils();
		foreach ( $languageCodes as $languageCode ) {
			$options[ $languageNameUtils->getLanguageName( $languageCode ) ] = $languageCode;
		}

		return $options;
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
			'tms_lang' => $this->language,
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
					self::newFromSuggestedTranslation( $this->getSuggestedTranslation(), $this->getLanguage() )
				);
			} else {
				throw $e;
			}
		}
	}

	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	protected static function isValidSuggestedTranslation( string $name ): bool {
		$titleObj = Title::newFromText( $name );
		return ( $titleObj && !$titleObj->isExternal() );
	}

	/**
	 * @param string $code
	 *
	 * @return bool
	 */
	public static function isValidStatusCode( $code ): bool {
		return in_array( $code, self::getStatusCodes() );
	}

	/**
	 * @param string $lang
	 *
	 * @return bool
	 */
	public static function isValidLanguage( $lang ): bool {
		$validLanguegs = self::getValidLanguages();
		if ( !empty( $lang ) && is_array( $validLanguegs ) && in_array( $lang, $validLanguegs ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param int $id
	 * @param string $language
	 *
	 * @return TranslationManagerStatus
	 * @throws \MWException
	 */
	public static function fromId( $id, $language ): TranslationManagerStatus {
		return new TranslationManagerStatus( $id, $language );
	}

	/**
	 * @return int|null
	 */
	public function getId(): ?int {
		return $this->pageId;
	}

	/**
	 * @return string|null
	 */
	public function getName(): ?string {
		return $this->pageName;
	}

	/**
	 * @return string|null
	 */
	public function getLanguage(): ?string {
		return $this->language;
	}

	/**
	 * @param string $language
	 *
	 * @return void
	 */
	public function setLanguage( $language ) {
		$this->language = $language;
	}

	/**
	 * @return null|string
	 */
	public function getActualTranslation() {
		return $this->actualTranslation;
	}

	/**
	 * @return mixed|null
	 */
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

	/**
	 * @return string|null
	 */
	public function getProject() {
		return $this->project;
	}

	/**
	 * @param string $project
	 */
	public function setProject( $project ) {
		$this->project = $project;
	}

	/**
	 * @return string|null
	 */
	public function getTranslator() {
		return $this->translator;
	}

	/**
	 * @param string $translator
	 */
	public function setTranslator( $translator ) {
		$this->translator = $translator;
	}

	/**
	 * @return mixed|null
	 */
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
	 * @return int|null
	 */
	public function getWordcount() {
		return $this->wordcount;
	}

	/**
	 * @param int|string $wordcount
	 *
	 * @return bool
	 */
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

	/**
	 * @param string $startDate
	 *
	 * @return void
	 */
	public function setStartDate( string $startDate ) {
		$this->startDate = empty( $startDate ) ? null : new MWTimestamp( $startDate );
	}

	/**
	 * @param string $startDate
	 *
	 * @return void
	 */
	public function setStartDateFromField( string $startDate ) {
		$this->setStartDate( self::makeTimestampFromField( $startDate ) );
	}

	/**
	 * @param string $date
	 * @param bool $end
	 *
	 * @return MWTimestamp|null
	 */
	public static function makeTimestampFromField( string $date, bool $end = false ) {
		$time = $end ? 'T23:59:59Z' : 'T00:00:00Z';
		return $date ? new MWTimestamp( $date . $time ) : null;
	}

	/**
	 * @return MWTimestamp
	 */
	public function getEndDate() {
		return $this->endDate;
	}

	/**
	 * @param string|null $endDate
	 *
	 * @return void
	 */
	public function setEndDate( ?string $endDate ) {
		$this->endDate = empty( $endDate ) ? null : new MWTimestamp( $endDate );
	}

	/**
	 * @param string $endDate
	 *
	 * @return void
	 */
	public function setEndDateFromField( string $endDate ) {
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
				'target_language' => 'tms_lang',
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
				self::TABLE_NAME => [ 'LEFT OUTER JOIN', [ "page_id = tms_page_id", "tms_lang" => $this->language ] ],
				'langlinks' => [ 'LEFT OUTER JOIN', [ 'page_id = ll_from', "ll_lang" => $this->language ] ],
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
			if ( $row->tms_page_id ) {
				$this->isSaved = true;
				$this->suggestedTranslation = $row->suggested_name;
				$this->actualTranslation = $row->actual_translation;
				$this->project = $row->project;
				$this->pageviews = (int)$row->pageviews;
				$this->translator = $row->translator;
				$this->comments = $row->comments;
				$this->wordcount = $row->wordcount;
				$this->articleType = $row->article_type;
				$this->language = $row->target_language;
				$this->setStartDate( $row->start_date );
				$this->setEndDate( $row->end_date );
			}

			$this->status = $row->actual_translation ? 'translated' : $row->status;
		}
	}

	/**
	 * Get rows from DB
	 *
	 * @param string $lang
	 * @param array|null $pageIds
	 *
	 * @return IResultWrapper
	 */
	public static function getRows( string $lang, ?array $pageIds = null ) {
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
				'tms_lang' => $lang
			],
			'join_conds' => [
				self::TABLE_NAME => [ 'LEFT OUTER JOIN', 'page_id = tms_page_id' ],
				'langlinks' => [ 'LEFT OUTER JOIN', [ 'page_id = ll_from', "ll_lang" => $lang ] ],
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
	 * @param string $lang ISO 639-1 language code
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
	public static function getValidLanguages(): array {
		return Hooks::getConfig()->get( 'TranslationManagerValidLanguages' );
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
	/** @var TranslationManagerStatus|null */
	protected $translationStatus;

	/**
	 * @param TranslationManagerStatus|null $tmStatus
	 */
	public function __construct( ?TranslationManagerStatus $tmStatus ) {
		$this->translationStatus = $tmStatus;
		parent::__construct();
	}

	/**
	 * @return TranslationManagerStatus|null
	 */
	public function getTranslationManagerStatus() {
		return $this->translationStatus;
	}
}
