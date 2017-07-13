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
		'progress' => 1,
		'review' => 2,
		'translated' => 3,
		'irrelevant' => 4

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
		$this->typeFilter = self::validateArticleType( $request->getVal( 'articletype' ) );

		$conds = [
			'status' => $this->statusFilter,
			'articletype' => $this->typeFilter
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
		$this->statusFilter = $data['status'];
		parent::execute( $data['status'] );
	}

	private function getFormFields() {
		return [
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'options-messages' => [
					'ext-tm-status-all' => '',
					'ext-tm-status-untranslated' => 'untranslated',
					'ext-tm-status-progress' => 'progress',
					'ext-tm-status-review' => 'review',
					'ext-tm-status-translated' => 'translated',
					'ext-tm-status-irrelevant' => 'irrelevant',
				],
				'default'       => $this->statusFilter,
				'label'         => 'סטטוס:',    // @todo i18n
				// 'label-message' => 'pageswithprop-prop'
			],
			'articletype' => [
				'type' => 'select',
				'name' => 'articletype',
				'options' => self::getArticleTypeOptions(),
				'label'         => 'סוג ערך:',    // @todo i18n
				// 'label-message' => 'pageswithprop-prop'
			]
		];
	}

	private static function getArticleTypeOptions() {
		global $wgArticleTypeConfig;

		$options = [];
		$options[ 'הכל' ] = ''; // @todo i18n
		$options = array_merge(
			$options, array_combine( $wgArticleTypeConfig['types'], $wgArticleTypeConfig['types'] )
		);

		return $options;

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
