<?php
/**
 * SpecialPage for TranslationManager extension
 * Used for editing lines in the overview table
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationManager;

use HTMLForm;
use UnlistedSpecialPage;

class SpecialTranslationManagerStatusEditor extends UnlistedSpecialPage {

	/**
	 * @var null|TranslationManagerStatus
	 */
	private $item = null;
	private $editable = false;

	function __construct( $name = 'TranslationManagerStatusEditor' ) {
		parent::__construct( $name );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Whether this special page is listed in Special:SpecialPages
	 * @return Bool
	 */
	public function isListed() {
		return false;
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		$this->editable = $this->getUser()->isAllowed( 'translation-manager-overview' );
		$this->item = new TranslationManagerStatus( $par );

		if ( $this->item->exists() ) {
			$this->getForm()->show();
		} else {
			$this->getOutput()->addElement(
				'div',
				[ 'class' => 'errorbox' ],
				$this->msg( 'ext-tm-statusitem-missingpage' )->escaped()
			);
		}
	}

	public function onSubmit( $data, $form ) {
		if ( $this->editable === false ) {
			return "Not editable";
		}

		foreach ( $data as &$datum ) {
			$datum = $datum === '' ? null : $datum;
		}

		$this->item->setComments( $data['comments'] );
		$this->item->setStatus( $data['status'] );
		$this->item->setTranslator( $data['translator'] );
		$this->item->setProject( $data['project'] );
		$this->item->setSuggestedTranslation( $data['suggested_translation'] );

		try {
			$result = $this->item->save();

			if ( $result === true ) {
				$this->getOutput()->wrapWikiMsg( "<div class=\"successbox\">\n$1\n</div>",
					'ext-tm-statusitem-edit-success' );
			} else {
				$this->error( 'ext-tm-statusitem-edit-error' );
			}

		} catch ( TMStatusSuggestionDuplicateException $e ) {
			$this->error( [
				'ext-tm-statusitem-edit-error-duplicate-suggestion',
				$e->getTranslationManagerStatus()->getName()
			] );
		}
	}

	private function error( $msgName ) {
		return $this->getOutput()->wrapWikiMsg( "<div class=\"errorbox\">\n$1\n</div>",
			$msgName );
	}

	private function getFormFields() {
		$item = $this->item;
		$actualTranslation = $item->getActualTranslation() ?:
			wfMessage( 'ext-tm-statusitem-actualtranslation-missing' )->escaped();

		$fields = [
			'name' => [
				'label-message' => 'ext-tm-statusitem-title',
				'class' => 'HTMLInfoField',
				'default' => $item->getName()
			],
			'actual_translation' => [
				'label-message' => 'ext-tm-statusitem-actualtranslation',
				'class' => 'HTMLInfoField',
				'default' => $actualTranslation
			],
			'suggested_translation' => [
				'label-message' => 'ext-tm-statusitem-suggestedname',
				'help-message' => 'ext-tm-statusitem-suggestedname-help',
				'type' => 'text',
				'default' => $item->getSuggestedTranslation()
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'options-messages' => [
					'ext-tm-status-untranslated' => 'untranslated',
					'ext-tm-status-progress'     => 'progress',
					'ext-tm-status-review'       => 'review',
					'ext-tm-status-translated'   => 'translated',
					'ext-tm-status-irrelevant'   => 'irrelevant',
				],
				'disabled' => $item->getStatus() === 'translated',
				'label-message' => 'ext-tm-statusitem-status',
				'default' => $item->getStatus()
			],
			'translator' => [
				'label-message' => 'ext-tm-statusitem-translator',
				'type' => 'text',
				'default' => $item->getTranslator()
			],
			'project' => [
				'label-message' => 'ext-tm-statusitem-project',
				'type' => 'text',
				'default' => $item->getProject()
			],
			'comments' => [
				'label-message' => 'ext-tm-statusitem-comments',
				'type' => 'text',
				'default' => $item->getComments()
			],

		];
		/*
		if ( !$this->editable ) {
			foreach ( $fields as $field => &$attribs ) {
				$attribs['disabled'] = true;
			}
		}
		*/


		return $fields;
	}

	private function getForm() {
		$editForm = HTMLForm::factory(
			'ooui',
			$this->getFormFields(),
			$this->getContext()
		)->setId( 'mw-trans-status-edit-form' )
		 ->setMethod( 'post' )
		 ->setSubmitCallback( [ $this, 'onSubmit' ] )
		 ->setSubmitTextMsg( 'ext-tm-save-item' )
		 ->prepareForm();

		return $editForm;
	}
}
