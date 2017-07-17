<?php
/**
 * SpecialPage for TranslationManager extension
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationManager;

use \SpecialPage;
use \HTMLForm;
use \WRArticleType;

class SpecialTranslationManagerOverview extends SpecialPage {
	private $statusFilter = null;
	private $typeFilter = null;
	private $titleFilter = null;

	function __construct( $name = 'TranslationManagerOverview' ) {
		parent::__construct( $name );
	}

	public function execute( $par ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$this->outputHeader();
		$request = $this->getRequest();

		// Status parameter validation
		$this->statusFilter = $request->getVal( 'status' );
		$this->statusFilter = TranslationManagerStatus::isValidStatusCode( $this->statusFilter ) ? $this->statusFilter : 'all';
		$this->typeFilter   = self::validateArticleType( $request->getVal( 'article_type' ) );
		$this->titleFilter = $request->getVal( 'page_title' );

		$conds = [
			'status'      => $this->statusFilter,
			'article_type' => $this->typeFilter,
			'page_title' => $this->titleFilter,
			'translator'  => $request->getVal( 'translator' ),
			'project'     => $request->getVal( 'project' ),
			'pageviews'    => $request->getInt( 'pageviews' ),
			'main_category' => $request->getVal( 'main_category' )
		];
		$pager = new TranslationManagerOverviewPager( $this, $conds );

		$formHtml = $this->getForm()->getHTML( false );
		$out->addHTML( $formHtml );
		$out->addParserOutput( $pager->getFullOutput() );

	}

	private static function validateArticleType( $code ) {
		if ( class_exists( 'WRArticleType' ) && WRArticleType::isValidArticleType( $code ) ) {
			return $code;
		}

		return null;
	}

	public function onSubmit( $data, $form ) {
		$this->statusFilter = $data[ 'status' ];
		parent::execute( $data[ 'status' ] );
	}

	private function getFormFields() {
		$projectOptions = self::makeOptionsWithAllForSelect( TranslationManagerStatus::getAllProjects() );
		$translatorOptions = self::makeOptionsWithAllForSelect( TranslationManagerStatus::getAllTranslators() );
		$mainCategoryOptions = self::makeOptionsWithAllForSelect( TranslationManagerStatus::getAllMainCategories() );

		return [
			'page_title' => [
				'class' => 'HTMLTitleTextField',
				'name' => 'page_title',
				'label-message' => 'ext-tm-statusitem-title',
				'namespace' => 0,
				'relative' => true
			],
			'main_category' => [
				'type'          => 'select',
				'name' => 'main_category',
				'label-message' => 'ext-tm-statusitem-maincategory',
				'options' => $mainCategoryOptions,
			],
			'status'      => [
				'type'             => 'select',
				'name'             => 'status',
				'options-messages' => [
					'ext-tm-status-all'          => '',
					'ext-tm-status-untranslated' => 'untranslated',
					'ext-tm-status-progress'     => 'progress',
					'ext-tm-status-review'       => 'review',
					'ext-tm-status-translated'   => 'translated',
					'ext-tm-status-irrelevant'   => 'irrelevant',
				],
				'label-message'    => 'ext-tm-statusitem-status'
			],
			'project'     => [
				'type'          => 'select',
				'name'          => 'project',
				'options'       => $projectOptions,
				'label-message' => 'ext-tm-statusitem-project'
			],
			'translator'  => [
				'type'          => 'select',
				'name'          => 'translator',
				'options'       => $translatorOptions,
				'label-message' => 'ext-tm-statusitem-translator'
			],
			'pageviews' => [
				'type'          => 'int',
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


	private static function makeOptionsForSelect( $arr ) {
		$arr = array_combine( $arr, $arr );

		return $arr;
	}

	private static function makeOptionsWithAllForSelect( $arr ) {
		$arr = [ 'הכל' => '' ] + self::makeOptionsForSelect( $arr ); // @todo i18n

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
		// $filterForm->setSubmitCallback( [ $this, 'onSubmit' ] );
		$filterForm->prepareForm();

		return $filterForm;
	}

	protected function getGroupName() {
		return 'pages';
	}
}
