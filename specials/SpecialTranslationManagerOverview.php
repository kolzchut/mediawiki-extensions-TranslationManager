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

	/* const */ private static $statusCodes = [
		'untranslated' => null,
		'progress'     => 1,
		'review'       => 2,
		'translated'   => 3,
		'irrelevant'   => 4

	];

	function __construct( $name = 'TranslationManagerOverview' ) {
		parent::__construct( $name );
	}

	public function execute( $par ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$this->outputHeader();
		$request = $this->getRequest();

		// Status parameter validation
		$this->statusFilter = self::validateStatusCode( $request->getVal( 'status' ) );
		$this->typeFilter   = self::validateArticleType( $request->getVal( 'articletype' ) );

		$conds = [
			'status'      => $this->statusFilter,
			'articletype' => $this->typeFilter,
			'translator'  => $request->getVal( 'translator' ),
			'project'     => $request->getVal( 'project' ),
			'pageviews'    => $request->getInt( 'pageviews' )
		];
		$pager = new TranslationManagerOverviewPager( $this, $conds );

		$formHtml = $this->getForm()->getHTML( false );
		$out->addHTML( $formHtml );
		$out->addParserOutput( $pager->getFullOutput() );

	}

	private static function validateStatusCode( $code ) {
		if ( array_key_exists( $code, self::$statusCodes ) ) {
			return $code;
		}

		return 'all';
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

		return [
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
				'default'          => $this->statusFilter,
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
			'articletype' => [
				'type'          => 'select',
				'name'          => 'articletype',
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
