<?php

namespace TranslationManager;

use SpecialPage;
use TablePager;
use Title;
use Linker;
use stdClass;
use WRArticleType;
use Html;

/**
 * A pager for viewing the translation status of every article.
 * Should allow modification of status code and adding comments.
 *
 */
class TranslationManagerOverviewPager extends TablePager {
	public $mLimitsShown = [ 50, 100, 500, 1000, 5000 ];
	const DEFAULT_LIMIT = 1000;
	// protected $suggestedTranslations;

	protected $fieldNames = null;
	protected $conds = [];
	protected $preventClickjacking = true;

	/**
	 * @param SpecialPage $page
	 * @param array $conds
	 */
	function __construct( $page, $conds ) {
		$this->conds = $conds;
		parent::__construct( $page->getContext() );

		list( $this->mLimit, /* $offset */ ) = $this->mRequest->getLimitOffset( self::DEFAULT_LIMIT, '' );
	}

	/**
	 * @see IndexPager::getQueryInfo()
	 */
	public function getQueryInfo() {
		$dbr = wfGetDB( DB_SLAVE );
		$query = [
			'tables' => [ 'page', 'tm_status', 'langlinks', 'page_props' ],
			'fields' => [
				'page_namespace',
				'page_title',
				'll_title',
				'status' => 'tms_status',
				'comments' => 'tms_comments',
				'pageviews' => 'tms_pageviews',
				'main_category' => 'tms_main_category',
				'translator' => 'tms_translator',
				'project' => 'tms_project',
				'suggested_name' => 'tms_suggested_name',
				'article_type' => 'pp_value'
			],
			'conds' => [
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => 0,
				// 'iwl_prefix' => 'ar'
			],
			'join_conds' => [
				'tm_status' => [ 'LEFT OUTER JOIN', 'page_id = tms_page_id' ],
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
				'll_title' => $this->msg( 'ext-tm-overview-tableheader-langlink' )->text(),
				'suggested_name' => $this->msg( 'ext-tm-overview-tableheader-suggestedname' )->text(),
				'status' => $this->msg( 'ext-tm-overview-tableheader-status' )->text(),
				'translator' => $this->msg( 'ext-tm-overview-tableheader-translator' )->text(),
				'project' => $this->msg( 'ext-tm-overview-tableheader-project' )->text(),
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
					)->getLocalURL(),
					'title' => $this->msg( 'ext-tm-overview-action-edit' )->escaped()
				],
				'<i class="fa fa-edit"></i>'
			),
			Html::rawElement(
				'a',
				[
					'href' => SpecialPage::getTitleFor(
						'ExportForTranslation', $title->getPrefixedDBkey()
					)->getLocalURL(),
					'title' => $this->msg( 'ext-tm-overview-action-export' )->escaped()
				],
				'<i class="fa fa-download"></i>'
			)
		];

		$row->actions = implode( " ", $actions );

		if ( !is_null( $row->ll_title ) ) {
			$row->status = 'translated';
		}


		return parent::formatRow( $row );
	}

	public function formatValue( $field, $value ) {
		switch ( $field ) {
			case 'page_title':
				$title = Title::newFromRow( $this->getCurrentRow() );
				$value = Linker::linkKnown( $title );
				break;

			case 'article_type':
				$value = WRArticleType::getReadableArticleTypeFromCode( $value );
				break;
			case 'status':
				$value = is_null( $value ) ? 'untranslated' : $value;
				$value = TranslationManagerStatus::getStatusMessageForCode( $value );
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
