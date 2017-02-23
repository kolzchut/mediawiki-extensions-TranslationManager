<?php
/**
 * SpecialPage for ExportForTranslation extension
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationProject;

use \SpecialPage;
use \HTMLForm;

class SpecialTranslationProject extends SpecialPage {
	private $statusFilter = null;

	/* const */ private static $statusCodes = [
		'untranslated' => 0,
		'progress' => 1,
		'review' => 2,
		'translated' => 3,
		'irrelevant' => 4

	];

	function __construct( $name = 'TranslationProject' ) {
		parent::__construct( $name );
	}

	public function execute( $par ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$this->outputHeader();
		$request = $this->getRequest();

		// Status parameter validation
		$this->statusFilter = $request->getVal( 'status' );
		$this->statusFilter = array_key_exists( $this->statusFilter, self::$statusCodes ) ? $this->statusFilter : 'all';

		$formHtml = $this->getForm()->getHTML( false );
		$pager = new TranslationStatusPager( $this, [ 'status' => $this->statusFilter ] );
		$out->addHTML( $formHtml );
		$out->addParserOutput( $pager->getFullOutput() );

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
					'translationproject-status-all' => '',
					'translationproject-status-untranslated' => 'untranslated',
					'translationproject-status-progress' => 'progress',
					'translationproject-status-review' => 'review',
					'translationproject-status-translated' => 'translated',
					'translationproject-status-irrelevant' => 'irrelevant',
				],
				'default'       => $this->statusFilter,
				'label'         => 'סטטוס:',    // @todo i18n
				// 'label-message' => 'pageswithprop-prop'
			]
		];
	}

	private function getStatusCodes() {

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
