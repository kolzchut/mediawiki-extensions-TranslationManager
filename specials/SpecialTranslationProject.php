<?php
/**
 * SpecialPage for ExportForTranslation extension
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationProject;

use \QueryPage;
use \HTMLForm;
use \Skin;
use \Title;
use \Linker;

class SpecialTranslationProject extends QueryPage {
	private $statusFilter = null;

	/* const */ private static $statusCodes = [
		'untranslated' => null,
		'progress' => 1,
		'review' => 2,
		'translated' => 3
	];

	function __construct( $name = 'TranslationProject' ) {
		parent::__construct( $name );
	}

	function isCacheable() {
		return false;
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$request = $this->getRequest();

		$statusFilter = $request->getVal( 'status', '' );
		$statusCodes  = self::$statusCodes;


		$form = HTMLForm::factory(
			'ooui', [
				'status' => [
					'type'          => 'combobox',
					'name'          => 'status',
					'options-messages' => [
						'translationproject-status-all' => '',
						'translationproject-status-untranslated' => 'untranslated',
						'translationproject-status-progress' => 'progress',
						'translationproject-status-review' => 'review',
						'translationproject-status-translated' => 'translated',
					],
					'default'       => $statusFilter,
					'label'         => 'סטטוס:',
					//'label-message' => 'pageswithprop-prop'
				],
			], $this->getContext()
		);

		$form->setMethod( 'get' );
		$form->setSubmitCallback( [ $this, 'onSubmit' ] );

		$form->prepareForm();
		$form->displayForm( false );
		if ( $statusFilter !== '' && $statusFilter !== null ) {
			$form->trySubmit();
		}
	}

	public function onSubmit( $data, $form ) {
		$this->statusFilter = $data['status'];
		parent::execute( $data['status'] );
	}

	public function getQueryInfo() {
		return [
			'tables' => [ 'page', 'tp_translation' ],
			'fields' => [
				'namespace' => 'page_namespace',
				'title' => 'page_title',
				'value' => 'page_title'
			],
			'conds' => [
				//'translation_status' => $this->statusFilter,
			],
			'join_conds' => [
				'tp_translation' => [ 'LEFT OUTER JOIN', 'page_id = translation_page_id' ]
			],
			'options' => []
		];
	}

	function getOrderFields() {
		return [ 'page_id' ];
	}

	/**
	 * Formats the results of the query for display. The skin is the current
	 * skin; you can use it for making links. The result is a single row of
	 * result data. You should be able to grab SQL results off of it.
	 * If the function returns false, the line output will be skipped.
	 * @param Skin $skin
	 * @param object $result Result row
	 * @return string|bool String or false to skip
	 */
	public function formatResult( $skin, $result ) {
		$title = Title::newFromRow( $result );
		$ret = Linker::link( $title, null, [], [], [ 'known' ] );

		$ret .= ' (' . $result->translation_status . ')';

		return $ret;

	}


	protected function getStatusCodes() {

	}

	protected function getGroupName() {
		return 'pages';
	}
}
