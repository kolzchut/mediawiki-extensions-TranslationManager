<?php

namespace TranslationProject;

use SpecialPage;
use TablePager;
use Title;
Use Linker;
use stdClass;

/**
 * A pager for viewing the translation status of every article.
 * Should allow modification of status code and adding comments.
 *
 */
class TranslationStatusPager extends TablePager {
	protected $fieldNames = null;
	protected $conds = [];

	/**
	 * @param SpecialPage $page
	 * @param array $conds
	 */
	function __construct( $page, $conds ) {
		$this->conds = $conds;
		parent::__construct( $page->getContext() );
	}

	/**
	 * @see IndexPager::getQueryInfo()
	 */
	public function getQueryInfo() {
		$query = [
			'tables' => [ 'page', 'tp_translation', 'langlinks', 'page_props' ],
			'fields' => [
				'page_namespace',
				'page_title',
				'll_title',
				'status' => 'translation_status',
				'comments' => 'translation_comments',
				'suggested_name' => 'translation_suggested_name',
				'article_type' => 'pp_value'
			],
			'conds' => [
				'page_namespace' => 0,
				'page_is_redirect' => false,
				// 'iwl_prefix' => 'ar'
			],
			'join_conds' => [
				'tp_translation' => [ 'LEFT OUTER JOIN', 'page_id = translation_page_id' ],
				'langlinks' => [ 'LEFT OUTER JOIN', [ 'page_id = ll_from', "ll_lang = 'ar'" ] ],
				'page_props' => [ 'LEFT OUTER JOIN', [ 'page_id = pp_page', "pp_propname = 'ArticleType'" ] ],
			],
			'options' => []
		];

		switch ( $this->conds[ 'status' ] ) {
			case 'all':
				break;
			case 'untranslated':
				$query['conds'][] = 'll_title IS NULL';
				break;
			case 'translated':
				$query['conds'][] = 'll_title IS NOT NULL';
				break;
			default:
				$query['conds']['translation_status'] = $this->conds['status'];
				break;
		}

		if ( isset( $this->conds[ 'articletype' ] ) && $this->conds[ 'articletype' ] !== null ) {
			$query['conds']['pp_value'] = $this->conds[ 'articletype' ];
		}

		return $query;
	}

	/**
	 * @see TablePager::getFieldNames()
	 */
	public function getFieldNames() {

		if ( !$this->fieldNames ) {
			$this->fieldNames = [
				'page_title' => $this->msg( 'translationproject-tableheader-title' )->text(),
				'll_title' => $this->msg( 'translationproject-tableheader-langlink' )->text(),
				'suggested_name' => $this->msg( 'translationproject-tableheader-suggestedname' )->text(),
				'status' => $this->msg( 'translationproject-tableheader-status' )->text(),
				'article_type' => $this->msg( 'translationproject-tableheader-articletype' )->text(),
				'comments' => $this->msg( 'translationproject-tableheader-comments' )->text()
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
		return parent::formatRow( $row );
	}

	public function formatValue( $field, $value ) {
		switch ( $field ) {
			case 'page_title':
				$title = Title::newFromRow( $this->getCurrentRow() );
				$value = Linker::linkKnown( $title );
				break;
		}

		return $value;
	}

	function isFieldSortable( $field ) {
		if ( $field == 'page_title' || $field == 'status' ) {
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
