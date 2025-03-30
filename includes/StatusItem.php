<?php

namespace TranslationManager;

use DBQueryError;
use MalformedTitleException;
use MediaWiki\MediaWikiServices;
use MWException;
use MWTimestamp;
use Title;
use TitleValue;
use Wikimedia\Rdbms\IResultWrapper;

class StatusItem {
	protected const STATUS_CODES = [
		'untranslated',
		'unsuggested',
		'progress',
		'prereview',
		'review',
		'translated',
		'irrelevant'
	];

	protected const LEGAL_REVIEW_STATUS = [
		'not-required',
		'required',
		'completed'
	];

	protected const QUERY_TRANSLATION_TYPES = [
		'TRANSLATIONS_OVER_SUGGESTIONS' => 1,
		'SUGGESTIONS_ONLY' => 2
	];
	/** @var Title|null */
	protected ?Title $title = null;
	/** @var int|null */
	protected ?int $pageId = null;
	/** @var string|null */
	protected ?string $pageName = null;
	/** @var string|null */
	protected ?string $status = null;
	/** @var string|null */
	protected ?string $requiresLegalReview = 'not-required';
	/** @var string|null */
	protected ?string $language = null;
	/** @var string|null */
	protected ?string $suggestedTranslation = null;
	/** @var string|null */
	protected ?string $previousSuggestedTranslation = null;
	/** @var string|null */
	protected ?string $actualTranslation = null;
	/** @var string|null */
	protected ?string $project = null;
	/** @var int|null */
	protected ?int $translatorId = null;
	/** @var string|null */
	protected ?string $translatorName = null;
	/** @var int|null */
	protected ?int $editorId = null;
	/** @var string|null */
	protected ?string $editorName = null;
	/** @var string|null */
	protected ?string $comments = null;
	/** @var string|null */
	protected ?string $articleType = null;
	/** @var int|null */
	protected ?int $pageviews = null;
	/** @var int|null */
	protected ?int $wordcount = null;
	/** @var MWTimestamp|null */
	protected ?MWTimestamp $startDate = null;
	/** @var MWTimestamp|null */
	protected ?MWTimestamp $endDate = null;
	/** @var bool */
	protected bool $isSaved = false;

	public const TABLE_NAME = 'tm_status';

	/**
	 * @param int|string $id
	 * @param string $lang
	 *
	 * @throws MWException
	 */
	public function __construct( $id, string $lang ) {
		if ( !self::isValidLanguage( $lang ) ) {
			throw new MWException( 'invalid language' );
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
	}

	/**
	 * @param string $text
	 * @param string $language
	 *
	 * @return StatusItem|null
	 * @throws MWException
	 */
	public static function newFromSuggestedTranslation( string $text, string $language ): ?StatusItem {
		$dbr = wfGetDB( DB_REPLICA );
		$id = $dbr->selectField(
			self::TABLE_NAME,
			'tms_page_id',
			[ 'tms_suggested_name' => $text, 'tms_lang' => $language ]
		);
		return ( $id === false ? null : new StatusItem( $id, $language ) );
	}

	/**
	 * @param array $flags can be include_all, include_empty
	 * @return array
	 */
	public static function getLegalReviewOptionsForSelect( array $flags = [] ): array {
		$options = [];
		foreach ( self::LEGAL_REVIEW_STATUS as $key ) {
			$options[ self::getLegalReviewStatusText( $key ) ] = $key;
		}

		return self::addOptionsToSelect( $options, $flags );
	}

	/**
	 * @param array $options
	 * @param array $flags include_all, include_empty
	 * @return array
	 */
	public static function addOptionsToSelect( array $options = [], array $flags = [] ): array {
		if ( in_array( 'include_all', $flags ) ) {
			$allText = wfMessage( 'ext-tm-dropdown-all' )->text();
			$options = [ $allText => '' ] + $options;
		}
		if ( in_array( 'include_empty', $flags ) ) {
			$options = [ '' => '' ] + $options;
		}

		return $options;
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

	/**
	 * @return bool
	 */
	public function titleExists(): bool {
		return $this->title instanceof Title;
	}

	/**
	 * @return bool
	 * @throws SuggestionDuplicateException
	 */
	public function save(): bool {
		$dbw = wfGetDB( DB_PRIMARY );

		$fieldMapping = [
			'tms_page_id' => $this->pageId,
			'tms_suggested_name' => $this->suggestedTranslation,
			'tms_lang' => $this->language,
			'tms_project' => $this->project,
			'tms_status' => $this->status,
			'tms_requires_legal_review' => $this->requiresLegalReview,
			'tms_translator_id' => $this->translatorId,
			'tms_editor_id' => $this->editorId,
			'tms_comments' => $this->comments,
			'tms_wordcount' => $this->wordcount,
			'tms_start_date' => $dbw->timestampOrNull( $this->startDate ),
			'tms_end_date' => $dbw->timestampOrNull( $this->endDate ),
		];
		$selector = [ 'tms_page_id' => $this->pageId, 'tms_lang' => $this->language ];

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
				throw new SuggestionDuplicateException(
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
	 * @param string|null $code
	 *
	 * @return bool
	 */
	public static function isValidStatusCode( ?string $code ): bool {
		return in_array( $code, self::getStatusCodes() );
	}

	/**
	 * @param string $lang
	 *
	 * @return bool
	 */
	public static function isValidLanguage( string $lang ): bool {
		$validLanguegs = self::getValidLanguages();
		if ( !empty( $lang ) && in_array( $lang, $validLanguegs ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param int $id
	 * @param string $language
	 *
	 * @return StatusItem
	 * @throws MWException
	 */
	public static function fromId( int $id, string $language ): StatusItem {
		return new StatusItem( $id, $language );
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
	public function setLanguage( string $language ) {
		$this->language = $language;
	}

	/**
	 * @return null|string
	 */
	public function getActualTranslation(): ?string {
		return $this->actualTranslation;
	}

	/**
	 * @return string|null
	 */
	public function getStatus(): ?string {
		return $this->status;
	}

	/**
	 * @param string $status
	 */
	public function setStatus( string $status ) {
		if ( self::isValidStatusCode( $status ) ) {
			$this->status = $status;
		}
	}

	/**
	 * @return string
	 */
	public function getRequiresLegalReview(): string {
		return $this->requiresLegalReview;
	}

	/**
	 * @param string $requiresLegalReview
	 * @return bool Whether the provided value was valid
	 */
	public function setRequiresLegalReview( string $requiresLegalReview ): bool {
		if ( self::isValidLegalReviewStatus( $requiresLegalReview ) ) {
			$this->requiresLegalReview = $requiresLegalReview;
			return true;
		}

		return false;
	}

	/**
	 * @return string[]
	 */
	public static function getValidLegalReviewStatuses(): array {
		return self::LEGAL_REVIEW_STATUS;
	}

	// Validation method

	/**
	 * @param string|null $status
	 * @return bool
	 */
	public static function isValidLegalReviewStatus( ?string $status ): bool {
		return in_array( $status, self::getValidLegalReviewStatuses(), true );
	}

	/**
	 * @return null|string
	 */
	public function getSuggestedTranslation(): ?string {
		return $this->suggestedTranslation;
	}

	/**
	 * @param string|null $newTranslation
	 *
	 * @return string|bool
	 * @internal param string $suggestedTranslation
	 */
	public function setSuggestedTranslation( ?string $newTranslation ) {
		// Make sure the suggested title is valid according to MediaWiki
		// @todo use TitleParser::makeTitleValueSafe() instead
		if ( !empty( $newTranslation ) ) {
			try {
				Title::newFromTextThrow( $newTranslation );
			} catch ( MalformedTitleException $e ) {
				return 'invalidtitle';
			}
		}

		$this->previousSuggestedTranslation = $this->suggestedTranslation;
		$this->suggestedTranslation = $newTranslation;
		return true;
	}

	/**
	 * @return string success/error code
	 * @throws MWException
	 */
	public function createRedirectFromSuggestion(): string {
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

		$remoteWikiApi = new RemoteWikiApi( $this->language );
		return $remoteWikiApi->updateRedirect( $previousSuggestion, $newSuggestion, $this->getName() );
	}

	/**
	 * @return string|null
	 */
	public function getProject(): ?string {
		return $this->project;
	}

	/**
	 * @param string|null $project
	 */
	public function setProject( ?string $project ) {
		$this->project = $project;
	}

	/**
	 * Get the translator ID
	 *
	 * @return int|null
	 */
	public function getTranslatorId(): ?int {
		return $this->translatorId;
	}

	/**
	 * Set the translator ID
	 *
	 * @param int|null $id
	 */
	public function setTranslatorId( ?int $id ) {
		$this->translatorId = $id;
		$this->translatorName = null;
	}

	/**
	 * @return int|null
	 */
	public function getEditorId(): ?int {
		return $this->editorId;
	}

	/**
	 * @param int|null $editorId
	 */
	public function setEditorId( ?int $editorId ) {
		$this->editorId = $editorId;
		$this->editorName = null;
	}

	/**
	 * Get the editor name
	 *
	 * @return string
	 */
	public function getEditorName(): string {
		// If we have a cached name, return it
		if ( $this->editorName !== null ) {
			return $this->editorName;
		}

		// If we have an ID, load the name from the personnel record
		$person = new Personnel( $this->editorId );
		$this->editorName = $person->getName();
		return $this->editorName;
	}

	/**
	 * Get the editor name
	 *
	 * @return string
	 */
	public function getTranslatorName(): string {
		// If we have a cached name, return it
		if ( $this->translatorName !== null ) {
			return $this->translatorName;
		}

		// If we have an ID, load the name from the personnel record
		$person = new Personnel( $this->translatorId );
		$this->translatorName = $person->getName();
		return $this->translatorName;
	}

	/**
	 * Get all active editors for select field
	 * @return array
	 */
	public static function getEditorsForSelect(): array {
		$dbr = wfGetDB( DB_REPLICA );
		$result = $dbr->select(
			'tm_personnel',
			[ 'tmp_id', 'tmp_name' ],
			[ 'tmp_is_active' => 1 ],
			__METHOD__,
			[ 'ORDER BY' => 'tmp_name' ]
		);

		$options = [];
		foreach ( $result as $row ) {
			$options[ $row->tmp_name ] = $row->tmp_id;
		}

		return $options;
	}

	/**
	 * Get personnel name by ID
	 * @param int|null $id
	 * @return string|null
	 */
	public static function getPersonnelNameById( ?int $id ): ?string {
		if ( $id === null ) {
			return null;
		}

		$person = new Personnel( $id );
		return $person->getName();
	}

	/**
	 * @return string|null
	 */
	public function getComments(): ?string {
		return $this->comments;
	}

	/**
	 * @param string|null $comments
	 */
	public function setComments( ?string $comments ) {
		$this->comments = $comments;
	}

	/**
	 * @return int|null
	 */
	public function getWordcount(): ?int {
		return $this->wordcount;
	}

	/**
	 * @param int|null $wordcount
	 *
	 * @return bool
	 */
	public function setWordcount( ?int $wordcount ): bool {
		$this->wordcount = $wordcount;
		return true;
	}

	/**
	 * @return MWTimestamp
	 */
	public function getStartDate(): ?MWTimestamp {
		return $this->startDate;
	}

	/**
	 * @param string|null $startDate
	 *
	 * @return void
	 */
	public function setStartDate( ?string $startDate ) {
		$this->startDate = empty( $startDate ) ? null : new MWTimestamp( $startDate );
	}

	/**
	 * @param string|null $startDate
	 *
	 * @return void
	 */
	public function setStartDateFromField( ?string $startDate ) {
		$this->setStartDate( self::makeTimestampFromField( $startDate ) );
	}

	/**
	 * @param string|null $date
	 * @param bool $end
	 *
	 * @return MWTimestamp|null
	 */
	public static function makeTimestampFromField( ?string $date, bool $end = false ): ?MWTimestamp {
		$time = $end ? 'T23:59:59Z' : 'T00:00:00Z';
		return $date ? new MWTimestamp( $date . $time ) : null;
	}

	/**
	 * @return MWTimestamp
	 */
	public function getEndDate(): ?MWTimestamp {
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
	 * @param string|null $endDate
	 *
	 * @return void
	 */
	public function setEndDateFromField( ?string $endDate ) {
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
				'requires_legal_review' => 'tms_requires_legal_review',
				'comments' => 'tms_comments',
				'start_date' => 'tms_start_date',
				'end_date' => 'tms_end_date',
				'suggested_name' => 'tms_suggested_name',
				'target_language' => 'tms_lang',
				'project' => 'tms_project',
				'translator_id' => 'tms_translator_id',
				'editor_id' => 'tms_editor_id',
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
				$this->translatorId = $row->translator_id;
				$this->editorId = $row->editor_id;
				$this->comments = $row->comments;
				$this->wordcount = $row->wordcount;
				$this->articleType = $row->article_type;
				$this->language = $row->target_language;
				$this->setStartDate( $row->start_date );
				$this->setEndDate( $row->end_date );
				$this->requiresLegalReview = $row->requires_legal_review ?? 'not-required';
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
	public static function getRows( string $lang, ?array $pageIds = null ): IResultWrapper {
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
	): array {
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
		return (array)Hooks::getConfig()->get( 'TranslationManagerValidLanguages' );
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
	 * @param string|null $status
	 *
	 * @return false|string
	 */
	public static function getLegalReviewStatusText( ?string $status ) {
		/* Messages used:
		 * ext-tm-legal-review-all
		 * ext-tm-legal-review-not-required
		 * ext-tm-legal-review-required
		 * ext-tm-legal-review-completed
		 */
		// null is the same as "not required"
		$status = $status ?? 'not-required';
		if ( self::isValidLegalReviewStatus( $status ) || $status === 'all' ) {
			return wfMessage( 'ext-tm-legal-review-' . $status )->text();
		}

		return false;
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
