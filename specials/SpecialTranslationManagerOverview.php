<?php
/**
 * SpecialPage for TranslationManager extension
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationManager;

use Html;
use \SpecialPage;
use \HTMLForm;
use \WRArticleType;

class SpecialTranslationManagerOverview extends SpecialPage {
	private $statusFilter = null;
	private $typeFilter = null;
	private $titleFilter = null;
	private $langFilter = null;

	function __construct( $name = 'TranslationManagerOverview' ) {
		parent::__construct( $name );
	}

	public function execute( $par ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$this->outputHeader();
		$request = $this->getRequest();

		$out->addModuleStyles( 'mediawiki.special.translationmanageroverview.styles' );

		// Status parameter validation
		$this->statusFilter = $request->getVal( 'status' );
		$this->statusFilter = TranslationManagerStatus::isValidStatusCode( $this->statusFilter ) ?
			$this->statusFilter : null;
		$this->typeFilter   = self::validateArticleType( $request->getVal( 'article_type' ) );
		$this->titleFilter = $request->getVal( 'page_title' );
		$this->langFilter = $request->getVal( 'language' );
		$this->langFilter = TranslationManagerStatus::isValidLanguage( $this->langFilter ) ?
			$this->langFilter : null;
		if ( !$request->getVal( 'go' ) ) {
			$this->langFilter = $this->getUser()->getOption( 'translationmanager-language' );
		}

		$conds = [
			'lang' => $this->langFilter,
			'status'      => $this->statusFilter,
			'article_type' => $this->typeFilter,
			'page_title' => $this->titleFilter,
			'translator'  => $request->getVal( 'translator' ),
			'project'     => $request->getVal( 'project' ),
			'pageviews'    => $request->getInt( 'pageviews' ),
			'main_category' => $request->getVal( 'main_category' ),
			// Range of start date
			'start_date_from' =>  $this->timestampFromVal( 'start_date_from' ),
			'start_date_to' => $this->timestampFromVal( 'start_date_to', true ),
			// Range of end date
			'end_date_from' =>  $this->timestampFromVal( 'end_date_from' ),
			'end_date_to' =>  $this->timestampFromVal( 'end_date_to', true )

		];

		$formHtml = $this->getForm()->getHTML( false );
		$out->addHTML( $formHtml );

		if ( $request->getVal( 'go' ) ) { // Any truth-y value is good
			$pager = new TranslationManagerOverviewPager( $this, $conds );

			$pagerOutput = $pager->getFullOutput();
			$res = $pager->getResult();
			$total_wordcount = 0;
			foreach ( $res as $row ) {
				$total_wordcount += (int)$row->wordcount;
			}

			$out->addHTML(
				Html::element(
					'div', [],
					$this->msg( 'ext-tm-overview-total-wordcount' )->numParams( $total_wordcount )->text()
				)
			);
			$out->addHTML(
				Html::element( 'div', [],
					$this->msg( 'ext-tm-overview-number-of-records' )->numParams( $res->numRows() )->text()
				)
			);

			$out->addParserOutput( $pagerOutput );
		}

	}

	private static function validateArticleType( $code ) {
		if ( class_exists( 'WRArticleType' ) && WRArticleType::isValidArticleType( $code ) ) {
			return $code;
		}

		return null;
	}

	private function getFormFields() {
		$options = [
			'projectOptions' => TranslationManagerStatus::getAllProjects(),
			'translatorOptions' => TranslationManagerStatus::getAllTranslators(),
			'mainCategoryOptions' => TranslationManagerStatus::getAllMainCategories(),
		];

		// Format the arrays for a select field and Add an "all" options
		foreach ( $options as &$option ) {
			$option = self::makeOptionsWithAllForSelect( $option );
		}

		return [
			'go' => [
				'type' => 'hidden',
				'default' => 1,
				'name' => 'go'
			],
			'page_title' => [
				'class' => 'HTMLTitleTextField',
				'name' => 'page_title',
				'label-message' => 'ext-tm-statusitem-title',
				'namespace' => 0,
				'relative' => true
			],
			'language' => [
				'name' => 'language',
				'type' => 'select',
				'options' => TranslationManagerStatus::getLanguagesForSelectField(),
				'label-message' => 'ext-tm-statusitem-language',
				'default' => $this->langFilter
			],
			'main_category' => [
				'type'          => 'select',
				'name' => 'main_category',
				'label-message' => 'ext-tm-statusitem-maincategory',
				'options' => $options['mainCategoryOptions'],
			],
			'status'      => [
				'type'             => 'select',
				'name'             => 'status',
				'options-messages' => [
					'ext-tm-status-all'          => '',
					'ext-tm-status-untranslated' => 'untranslated',
					'ext-tm-status-progress'     => 'progress',
					'ext-tm-status-prereview'    => 'prereview',
					'ext-tm-status-review'       => 'review',
					'ext-tm-status-translated'   => 'translated',
					'ext-tm-status-irrelevant'   => 'irrelevant'
				],
				'label-message'    => 'ext-tm-statusitem-status'
			],
			'project'     => [
				'type'          => 'select',
				'name'          => 'project',
				'options'       => $options['projectOptions'],
				'label-message' => 'ext-tm-statusitem-project'
			],
			'translator'  => [
				'type'          => 'select',
				'name'          => 'translator',
				'options'       => $options['translatorOptions'],
				'label-message' => 'ext-tm-statusitem-translator'
			],
			'start_date_from' => [
				'label-message' => 'ext-tm-overview-filter-startdate-from',
				'type' => 'date',
				'name' => 'start_date_from'
			],
			'start_date_to' => [
				'label-message' => 'ext-tm-overview-filter-startdate-to',
				'type' => 'date',
				'name' => 'start_date_to'
			],
			'end_date_from' => [
				'label-message' => 'ext-tm-overview-filter-enddate-from',
				'type' => 'date',
				'name' => 'end_date_from'
			],
			'end_date_end' => [
				'label-message' => 'ext-tm-overview-filter-enddate-to',
				'type' => 'date',
				'name' => 'end_date_end'
			],
			'pageviews' => [
				'class' => 'HTMLUnsignedIntField',
				'name' => 'pageviews',
				'label-message' => 'ext-tm-overview-filter-pageviews',
			],
			'article_type' => [
				'type'          => 'select',
				'name'          => 'article_type',
				'options'       => self::getArticleTypeOptions(),
				'label-message' => 'ext-tm-statusitem-articletype'
			]
		];
	}

	private function timestampFromVal( $valName, $end = false ) {
		$val = $this->getRequest()->getVal( $valName );
		if ( !empty( $val ) ) {
			return TranslationManagerStatus::makeTimestampFromField( $val )->getTimestamp( TS_MW );
		}

		return null;
	}
	


	public static function makeOptionsForSelect( $arr ) {
		$arr = array_filter( $arr ); // Remove empty elements
		$arr = array_combine( $arr, $arr );

		return $arr;
	}

	public static function makeOptionsWithAllForSelect( $arr ) {
		$arr = [ 'הכל' => null ] + self::makeOptionsForSelect( $arr ); // @todo i18n

		return $arr;
	}

	private static function getArticleTypeOptions() {
		global $wgArticleTypeConfig;

		return self::makeOptionsWithAllForSelect( $wgArticleTypeConfig[ 'types' ] );
	}

	private function getForm() {
		$filterForm = HTMLForm::factory(
			'ooui',
			$this->getFormFields(),
			$this->getContext()
		);

		$filterForm->setId( 'mw-trans-status-filter-form' );
		$filterForm->setMethod( 'get' );
		$filterForm->suppressReset( false );
		$filterForm->prepareForm();

		return $filterForm;
	}

	protected function getGroupName() {
		return 'pages';
	}
}
