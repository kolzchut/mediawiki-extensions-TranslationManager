<?php

namespace TranslationManager;

use MediaWiki\MediaWikiServices;
use SpecialPage;
use TablePager;
use Title;
use stdClass;
use WRArticleType;
use Html;

/**
 * A pager for viewing the translation status of every article.
 * Should allow modification of status code and adding comments.
 */
class TranslationManagerOverviewPager extends TablePager {
	public $mLimitsShown = [ 100, 500, 1000, 5000 ];
	const DEFAULT_LIMIT = 500;
	// protected $suggestedTranslations;

	protected $fieldNames = null;
	protected $conds = [];
	protected $preventClickjacking = true;

	protected $linkRenderer = null;

	/**
	 * @param SpecialPage $page
	 * @param array $conds
	 */
	function __construct( $page, $conds ) {
		$this->conds = $conds;
		parent::__construct( $page->getContext() );

		list( $this->mLimit, /* $offset */ ) = $this->mRequest->getLimitOffset( self::DEFAULT_LIMIT, '' );
	}

	protected function getLinkRenderer() {
		if ( $this->linkRenderer === null ) {
			$this->linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		}
		return $this->linkRenderer;
	}

	/**
	 * @see IndexPager::getQueryInfo()
	 */
	public function getQueryInfo() {
		$dbr = wfGetDB( DB_SLAVE );
		$query = [
			'tables' => [ 'page', TranslationManagerStatus::TABLE_NAME, 'langlinks', 'page_props' ],
			'fields' => [
				'page_namespace',
				'page_title',
				'actual_translation' => 'll_title',
				'status' => 'tms_status',
				'comments' => 'tms_comments',
				'pageviews' => 'tms_pageviews',
				'wordcount' => 'tms_wordcount',
				'main_category' => 'tms_main_category',
				'translator' => 'tms_translator',
				'project' => 'tms_project',
				'start_date' => 'tms_start_date',
				'end_date' => 'tms_end_date',
				'suggested_name' => 'tms_suggested_name',
				'article_type' => 'pp_value'
			],
			'conds' => [
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => false
			],
			'join_conds' => [
				TranslationManagerStatus::TABLE_NAME => [ 'LEFT OUTER JOIN', 'page_id = tms_page_id' ],
				'langlinks' => [ 'LEFT OUTER JOIN', [ 'page_id = ll_from', "ll_lang = 'ar'" ] ],
				'page_props' => [ 'LEFT OUTER JOIN', [ 'page_id = pp_page', "pp_propname = 'ArticleType'" ] ],
			],
			'options' => []
		];

		switch ( $this->conds[ 'status' ] ) {
			case 'all':
				break;
			case 'untranslated':
				$query['conds'][] = 'll_title IS NULL AND tms_status <> \'translated\'';
				break;
			case 'translated':
				$query['conds'][] = 'll_title IS NOT NULL OR tms_status = \'translated\'';
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
			$query['conds'][] = "tms_start_date >= {$this->conds[ 'start_date_from' ]}";
		}
		if ( isset( $this->conds[ 'start_date_to' ] ) && !empty( $this->conds[ 'start_date_to' ] ) ) {
			$query['conds'][] = "tms_start_date <= {$this->conds[ 'start_date_to' ]}";
		}
		if ( isset( $this->conds[ 'end_date_from' ] ) && !empty( $this->conds[ 'end_date_from' ] ) ) {
			$query['conds'][] = "tms_end_date >= {$this->conds[ 'end_date_from' ]}";
		}
		if ( isset( $this->conds[ 'end_date_to' ] ) && !empty( $this->conds[ 'end_date_to' ] ) ) {
			$query['conds'][] = "tms_end_date <= {$this->conds[ 'end_date_from' ]}";
		}

		$simpleEqualsConds = [
			'article_type' => 'pp_value',
			'translator' => 'tms_translator',
			'project' => 'tms_project',
			'main_category' => 'tms_main_category'
		];
		foreach ( $simpleEqualsConds as $condName => $field ) {
			if ( isset( $this->conds[ $condName ] ) && !empty( $this->conds[ $condName ] ) ) {
				$query['conds'][$field] = $this->conds[ $condName ];
			}
		}


		return $query;
	}

	/**
	 * @see TablePager::getFieldNames()
	 */
	public function getFieldNames() {

		if ( !$this->fieldNames ) {
			$this->fieldNames = [
				'page_title' => $this->msg( 'ext-tm-overview-tableheader-title' )->text(),
				'actual_translation' => $this->msg( 'ext-tm-overview-tableheader-langlink' )->text(),
				'suggested_name' => $this->msg( 'ext-tm-overview-tableheader-suggestedname' )->text(),
				'wordcount' => $this->msg( 'ext-tm-overview-tableheader-wordcount' )->text(),
				'status' => $this->msg( 'ext-tm-overview-tableheader-status' )->text(),
				'translator' => $this->msg( 'ext-tm-overview-tableheader-translator' )->text(),
				'project' => $this->msg( 'ext-tm-overview-tableheader-project' )->text(),
				'start_date' => $this->msg( 'ext-tm-overview-tableheader-startdate' )->text(),
				'end_date' => $this->msg( 'ext-tm-overview-tableheader-enddate' )->text(),
				'comments' => $this->msg( 'ext-tm-overview-tableheader-comments' )->text(),
				'pageviews' => $this->msg( 'ext-tm-overview-tableheader-pageviews' )->text(),
				'main_category' => $this->msg( 'ext-tm-overview-tableheader-maincategory' )->text(),
				'article_type' => $this->msg( 'ext-tm-overview-tableheader-articletype' )->text(),
				'actions' => $this->msg( 'ext-tm-overview-tableheader-actions' )->text()
			];
		}

		return $this->fieldNames;
	}

	/**
	 * @protected
	 * @param stdClass $row
	 * @return string HTML
	 */
	function formatRow( $row ) {
		$title = Title::newFromRow( $row );

		$actions = [
			Html::rawElement(
				'a',
				[
					'href' => SpecialPage::getTitleFor(
						'TranslationManagerStatusEditor', $title->getArticleID()
					)->getLinkURL(),
					'title' => $this->msg( 'ext-tm-overview-action-edit' )->escaped()
				],
				'<i class="fa fa-edit" aria-hidden="true"></i>'
			),
			Html::rawElement(
				'a',
				[
					'href' => SpecialPage::getTitleFor(
						'ExportForTranslation', $title->getPrefixedDBkey()
					)->getLinkURL(),
					'title' => $this->msg( 'ext-tm-overview-action-export' )->escaped()
				],
				'<i class="fa fa-download" aria-hidden="true"></i>'
			),
			Html::rawElement(
				'a',
				[
					'href' => SpecialPage::getTitleFor( 'TranslationManagerWordCounter' )
					                     ->getLinkURL( [ 'target' =>  $title->getPrefixedText() ] ),
					'title' => $this->msg( 'ext-tm-overview-action-wordcount' )->escaped()
				],
				'<i class="fa fa-list-ol" aria-hidden="true"></i>'
			)
		];
		$row->actions = implode( "", $actions );

		if ( !is_null( $row->actual_translation ) ) {
			$row->status = 'translated';
		}

		if ( !empty( $row->actual_translation ) ) {
			$row->actual_translation = Html::rawElement(
				'a',
				[
					'href'  => Title::newFromText( 'ar:' . $row->actual_translation )->getLinkURL(),
					'title' => $this->msg( 'ext-tm-overview-translation-link' )->escaped()
				],
				'<i class="fa fa-link"></i>'
			);
		}


		return parent::formatRow( $row );
	}

	public function formatValue( $field, $value ) {
		switch ( $field ) {
			case 'page_title':
				$title = Title::newFromRow( $this->getCurrentRow() );
				$value = $this->getLinkRenderer()->makeKnownLink( $title );
				break;

			case 'article_type':
				$value = WRArticleType::getReadableArticleTypeFromCode( $value );
				break;
			case 'status':
				$value = is_null( $value ) ? 'untranslated' : $value;
				$value = TranslationManagerStatus::getStatusMessageForCode( $value );
				break;
			case 'wordcount': /* Fall through to pageviews */
			case 'pageviews':
				$value = $this->getLanguage()->formatNum( $value );
				break;
			case 'start_date':
			case 'end_date':
				$value = $value ? $this->getLanguage()->userDate( $value, $this->getUser() ) : null;
				break;
		}

		return $value;
	}

	function isFieldSortable( $field ) {
		if ( $field === 'page_title' || $field === 'status' || $field === 'pageviews' ) {
			return true;
		}

		return false;
	}

	public function getDefaultSort() {
		return 'page_title';
	}

	/**
	 * Better style...
	 * @return string
	 */
	protected function getTableClass() {
		return parent::getTableClass() . ' wikitable';
	}

}
