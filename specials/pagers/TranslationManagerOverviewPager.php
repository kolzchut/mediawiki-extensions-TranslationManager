<?php

namespace TranslationManager;

use ExtensionRegistry;
use Html;
use MediaWiki\Extension\ArticleContentArea\ArticleContentArea;
use MediaWiki\Extension\ArticleType\ArticleType;
use SpecialPage;
use TablePager;
use Title;
use WRArticleType;

/**
 * A pager for viewing the translation status of every article.
 * Should allow modification of status code and adding comments.
 */
class TranslationManagerOverviewPager extends TablePager {
	/**
	 * @var int[]
	 */
	public $mLimitsShown = [ 100, 500, 1000, 5000 ];
	/**
	 * @var array
	 */
	protected $conds = [];
	/**
	 * @var bool
	 */
	protected $preventClickjacking = true;

	/**
	 * @param SpecialPage $page
	 * @param array $conds
	 */
	public function __construct( $page, $conds ) {
		parent::__construct( $page->getContext() );

		$this->conds = $conds;
		$this->mDefaultLimit = 500;
		list( $this->mLimit, /* $offset */ ) =
			$this->getRequest()->getLimitOffsetForUser(
				$this->getUser(),
				$this->mDefaultLimit,
				''
			);
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo(): array {
		$dbr = wfGetDB( DB_REPLICA );
		$query = [
			'tables' => [ 'page', TranslationManagerStatus::TABLE_NAME, 'langlinks' ],
			'fields' => [
				'page_namespace',
				'page_title',
				'actual_translation' => 'll_title',
				'status' => 'tms_status',
				'comments' => 'tms_comments',
				'pageviews' => 'tms_pageviews',
				'wordcount' => 'tms_wordcount',
				'translator' => 'tms_translator',
				'project' => 'tms_project',
				'start_date' => 'tms_start_date',
				'end_date' => 'tms_end_date',
				'suggested_name' => 'tms_suggested_name'
			],
			'conds' => [
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => false
			],
			'join_conds' => [
				TranslationManagerStatus::TABLE_NAME => [ 'LEFT OUTER JOIN', [ 'page_id = tms_page_id', 'tms_lang' => $this->conds['lang'] ] ],
				'langlinks' => [ 'LEFT OUTER JOIN', [ 'page_id = ll_from', 'll_lang' => $this->conds['lang'] ] ],
				'options' => []
			]
		];

		// If Extension:ArticleContentArea is available, use it
		if ( \ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$contentArea = null;
			if ( isset( $this->conds[ 'main_category' ] ) && !empty( $this->conds[ 'main_category' ] ) ) {
				$contentArea = $this->conds[ 'main_category' ];
			}
			$query = array_merge_recursive( $query, ArticleContentArea::getJoin( $contentArea ) );
		}

		// If Extension:ArticleType is available, use it
		if ( \ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			$articleType = null;
			if ( isset( $this->conds[ 'article_type' ] ) && !empty( $this->conds[ 'article_type' ] ) ) {
				$articleType = $this->conds[ 'article_type' ];
			}
			$query = array_merge_recursive( $query, ArticleType::getJoin( $articleType ) );
		}

		switch ( $this->conds[ 'status' ] ) {
			case 'all':
				break;
			case 'progress':
				// Fall-through to 'review'
			case 'prereview':
				// Fall-through to 'review'
			case 'review':
				// Has to both match AND be untranslated
				$query['conds']['tms_status'] = $this->conds['status'];
				$query['conds'][] = 'll_title IS NULL';
				break;
			case 'unsuggested':
				// Not translated AND no suggestion
				$query['conds'][] = 'll_title IS NULL';
				$query['conds'][] = 'tms_suggested_name IS NULL OR tms_suggested_name = ""';
				$query['conds'][] = 'tms_status <> "irrelevant"';
				break;
			case 'untranslated':
				$query['conds'][] = 'll_title IS NULL';
				$query['conds'][] = 'tms_status IS NULL OR tms_status = "untranslated"';
				break;
			case 'translated':
				$query['conds'][] = 'll_title IS NOT NULL OR tms_status = "translated"';
				break;
			default:
				$query['conds']['tms_status'] = $this->conds['status'];
				break;
		}

		if ( isset( $this->conds[ 'page_title' ] ) && !empty( $this->conds[ 'page_title' ] ) ) {
			$titleFilter = Title::newFromText( $this->conds['page_title'] )->getDBkey();
			$query['conds'][] = 'page_title' . $dbr->buildLike( $dbr->anyString(),
					strtolower( $titleFilter ), $dbr->anyString() );
		}

		if (
			isset( $this->conds[ 'pageviews' ] ) &&
			!empty( $this->conds[ 'pageviews' ] ) &&
			$this->conds[ 'pageviews' ] > 0
		) {
			$query['conds'][] = "tms_pageviews >= {$this->conds[ 'pageviews' ]}";
		}

		if ( isset( $this->conds[ 'start_date_from' ] ) && !empty( $this->conds[ 'start_date_from' ] ) ) {
			$query['conds'][] = "tms_start_date >= " . $dbr->timestamp( $this->conds[ 'start_date_from' ] );
		}
		if ( isset( $this->conds[ 'start_date_to' ] ) && !empty( $this->conds[ 'start_date_to' ] ) ) {
			$query['conds'][] = "tms_start_date <= " . $dbr->timestamp( $this->conds[ 'start_date_to' ] );
		}
		if ( isset( $this->conds[ 'end_date_from' ] ) && !empty( $this->conds[ 'end_date_from' ] ) ) {
			$query['conds'][] = "tms_end_date >= " . $dbr->timestamp( $this->conds[ 'end_date_from' ] );
		}
		if ( isset( $this->conds[ 'end_date_to' ] ) && !empty( $this->conds[ 'end_date_to' ] ) ) {
			$query['conds'][] = "tms_end_date <= " . $dbr->timestamp( $this->conds[ 'end_date_to' ] );
		}

		$simpleEqualsConds = [
			'translator' => 'tms_translator',
			'project' => 'tms_project'
		];
		foreach ( $simpleEqualsConds as $condName => $field ) {
			if ( isset( $this->conds[ $condName ] ) && !empty( $this->conds[ $condName ] ) ) {
				$query['conds'][$field] = $this->conds[ $condName ];
			}
		}

		return $query;
	}

	/**
	 * @inheritDoc
	 */
	public function getFieldNames(): array {
		static $headers = null;

		if ( $headers == [] ) {
			$headers = [
				'actions' => 'ext-tm-overview-tableheader-actions',
				'page_title' => 'ext-tm-overview-tableheader-title',
				'actual_translation' => 'ext-tm-overview-tableheader-langlink',
				'suggested_name' => 'ext-tm-overview-tableheader-suggestedname',
				'wordcount' => 'ext-tm-overview-tableheader-wordcount',
				'status' => 'ext-tm-overview-tableheader-status',
				'translator' => 'ext-tm-overview-tableheader-translator',
				'project' => 'ext-tm-overview-tableheader-project',
				'start_date' => 'ext-tm-overview-tableheader-startdate',
				'end_date' => 'ext-tm-overview-tableheader-enddate',
				'comments' => 'ext-tm-overview-tableheader-comments',
				'pageviews' => 'ext-tm-overview-tableheader-pageviews'
			];

			if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
				$headers[ 'content_area' ] = 'ext-tm-overview-tableheader-maincategory';
			}

			if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
				$headers[ 'article_type' ] = 'ext-tm-overview-tableheader-articletype';
			}

			foreach ( $headers as $key => $val ) {
				$headers[$key] = $this->msg( $val )->text();
			}
		}

		return $headers;
	}

	/**
	 * @inheritDoc
	 */
	public function formatRow( $row ): string {
		$title = Title::newFromRow( $row );

		$actions = [
			Html::rawElement(
				'a',
				[
					'href' => SpecialPage::getTitleFor(
						'TranslationManagerStatusEditor', $title->getArticleID()
					)->getLinkURL( [ 'language' => $this->conds['lang'] ] ),
					'title' => $this->msg( 'ext-tm-overview-action-edit' )->escaped()
				],
				'<i class="fa fa-edit" aria-hidden="true"></i>'
			)
		];

		if ( \ExtensionRegistry::getInstance()->isLoaded( 'ExportForTranslation' ) ) {
			$actions[] = Html::rawElement(
				'a',
				[
					'href'  => SpecialPage::getTitleFor(
						'ExportForTranslation', $title->getPrefixedDBkey()
					)->getLinkURL( [ 'language' => $this->conds[ 'lang' ] ] ),
					'title' => $this->msg( 'ext-tm-overview-action-export' )->escaped()
				],
				'<i class="fa fa-download" aria-hidden="true"></i>'
			);
			$actions[] = Html::rawElement(
				'a',
				[
					'href'  => SpecialPage::getTitleFor( 'TranslationManagerWordCounter' )
										  ->getLinkURL( [
											  'target'   => $title->getPrefixedText(),
											  'language' => $this->conds[ 'lang' ]
										  ] ),
					'title' => $this->msg( 'ext-tm-overview-action-wordcount' )->escaped()
				],
				'<i class="fa fa-list-ol" aria-hidden="true"></i>'
			);
		}
		$row->actions = implode( "", $actions );

		if ( $row->actual_translation !== null ) {
			$row->status = 'translated';
		}

		if ( !empty( $row->actual_translation ) ) {
			$row->actual_translation = Html::rawElement(
				'a',
				[
					'href'  => Title::newFromText( $this->conds['lang'] . ':' . $row->actual_translation )->getLinkURL(),
					'title' => $this->msg( 'ext-tm-overview-translation-link' )->escaped()
				],
				'<i class="fa fa-link"></i>'
			);
		}

		return parent::formatRow( $row );
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ) {
		switch ( $name ) {
			case 'page_title':
				$title = Title::newFromRow( $this->getCurrentRow() );
				$value = $this->getLinkRenderer()->makeKnownLink( $title );
				break;

			case 'article_type':
				$value = WRArticleType::getReadableArticleTypeFromCode( $value );
				break;
			case 'status':
				$value = $value === null ? 'untranslated' : $value;
				$value = TranslationManagerStatus::getStatusMessageForCode( $value );
				break;
			case 'wordcount':
				// Fall through to pageviews
			case 'pageviews':
				$value = $this->getLanguage()->formatNum( $value );
				break;
			case 'start_date':
			case 'end_date':
				$value = $value ? $this->getLanguage()->date( $value ) : null;
				break;
		}

		return $value;
	}

	/**
	 * @inheritDoc
	 */
	protected function isFieldSortable( $field ) {
		if ( $field === 'page_title' || $field === 'status' || $field === 'pageviews' ) {
			return true;
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultSort(): string {
		return 'page_title';
	}

	/**
	 * Better style for the tables
	 * @inheritDoc
	 */
	protected function getTableClass(): string {
		return parent::getTableClass() . ' wikitable';
	}

}
