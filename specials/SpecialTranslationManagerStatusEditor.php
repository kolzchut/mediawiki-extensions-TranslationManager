<?php
/**
 * SpecialPage for TranslationManager extension
 * Used for editing lines in the overview table
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationManager;

use Html;
use HTMLForm;
use UnlistedSpecialPage;
use SpecialPage;
use Linker;

class SpecialTranslationManagerStatusEditor extends UnlistedSpecialPage {

	/**
	 * @var null|TranslationManagerStatus
	 */
	private $item = null;
	private $editable = false;
	private $language = null;


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
		$this->language = $this->getRequest()->getVal( 'language' );
		$this->language = $this->language ?: $this->getUser()->getOption( 'translationmanager-language' );
		if ( !TranslationManagerStatus::isValidLanguage( $this->language ) ) {
			throw new \ErrorPageError( 'error', 'invalid language name' );
		}
		$this->item = new TranslationManagerStatus( $par, $this->language );

		$this->displayNavigation();

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

		$successMessage = [];
		$errorMessage = [];
		$minorErrorMessages = [];

		$this->item->setComments( $data['comments'] );
		$this->item->setStatus( $data['status'] );
		$this->item->setTranslator( $data['translator'] );
		$this->item->setProject( $data['project'] );
		$this->item->setLanguage( $data['language'] );
		$result = $this->item->setSuggestedTranslation( $data['suggested_name'] );
		switch ( $result ) {
			case 'created':
				$successMessage[] = "ext-tm-create-redirect-created";
				break;
			case 'moved':
				$successMessage[] = "ext-tm-create-redirect-moved";
				break;
			case 'articleexists':
				$minorErrorMessages[] = "ext-tm-create-redirect-articleexists";
				break;
			case 'removed':
				$minorErrorMessages[] = 'ext-tm-create-redirect-removed';
				break;
			case 'nochange':
				break;
			case 'invalidtitle':
				$errorMessage[] = 'ext-tm-create-redirect-invalid';
				break;
			case 'invalidcredentials':
				$minorErrorMessages[] = 'ext-tm-create-redirect-invalidcredentials';
				break;
			default:
				$errorMessage[] = [ "ext-tm-create-redirect-unknown", $result ];
		}

		$this->item->setWordcount( $data['wordcount'] );
		$this->item->setStartDateFromField( $data['start_date'] );
		$this->item->setEndDateFromField( $data['end_date'] );


		if ( count( $errorMessage ) === 0 ) {
			try {
				$result = $this->item->save();

				if ( $result === true ) {
					$successMessage[] = 'ext-tm-statusitem-edit-success';
				} else {
					$errorMessage[] = 'ext-tm-statusitem-edit-error';
				}

			} catch ( TMStatusSuggestionDuplicateException $e ) {
				$errorMessage[] = [
					'ext-tm-statusitem-edit-error-duplicate-suggestion',
					$e->getTranslationManagerStatus()->getName()
				];
			}
		}

		$this->success( $successMessage );
		$this->error( $errorMessage );
		$this->error( $minorErrorMessages );
	}

	/**
	 * @param string|array $msg
	 */
	private function error( $msg ) {
		$this->wrapMsg( $msg, 'error' );
	}

	/**
	 * @param string|array $msg
	 */
	private function success( $msg ) {
		$this->wrapMsg( $msg, 'success' );
	}

	private function wrapMsg( $msg, $class ) {
		if ( empty( $msg ) || !in_array( $class, [ 'error', 'success' ] ) ) {
			return;
		}
		$this->getOutput()->wrapWikiMsg( "<div class=\"{$class}box\">\n$1\n</div>", ...$msg );
	}

	private function getFormFields() {
		$item = $this->item;
		$actualTranslation = $item->getActualTranslation() ?:
			wfMessage( 'ext-tm-statusitem-actualtranslation-missing' )->escaped();

		$startdate = $item->getStartDate() ? $item->getStartDate()->format( 'Y-m-d' ) : null;
		$enddate = $item->getEndDate() ? $item->getEndDate()->format( 'Y-m-d' ) : null;

		$fields = [
			'name' => [
				'label-message' => 'ext-tm-statusitem-title',
				'class' => 'HTMLInfoField',
				'default' => $item->getName()
			],
			'language-display' => [
				'label-message' => 'ext-tm-statusitem-language',
				'class' => 'HTMLInfoField',
				'default' => $this->getLanguage()->fetchLanguageName( $this->language )
			],
			'actual_translation' => [
				'label-message' => 'ext-tm-statusitem-actualtranslation',
				'class' => 'HTMLInfoField',
				'default' => $actualTranslation
			],
			'suggested_name' => [
				'label-message' => 'ext-tm-statusitem-suggestedname',
				'help-message' => 'ext-tm-statusitem-suggestedname-help',
				'type' => 'text',
				'maxlength' => 255,
				'default' => $item->getSuggestedTranslation()

			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'options-messages' => [
					'ext-tm-status-untranslated' => 'untranslated',
					'ext-tm-status-progress'     => 'progress',
					'ext-tm-status-prereview'    => 'prereview',
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
			'start_date' => [
				'label-message' => 'ext-tm-statusitem-startdate',
				'type' => 'date',
				'default' => $startdate
			],
			'end_date' => [
				'label-message' => 'ext-tm-statusitem-enddate',
				'type' => 'date',
				'default' => $enddate
			],
			'wordcount' => [
				'label-message' => 'ext-tm-statusitem-wordcount',
				'class' => 'HTMLUnsignedIntField',
				'default' => $item->getWordcount()
			],
			'comments' => [
				'label-message' => 'ext-tm-statusitem-comments',
				'type' => 'text',
				'default' => $item->getComments()
			],
			'language' => [
				'type' => 'hidden',
				'name' => 'language',
				'default' => $this->language
			]

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

	protected function displayNavigation() {
		$links[] = Linker::specialLink( 'TranslationManagerOverview' );

		$tmpTitle = SpecialPage::getTitleValueFor( 'TranslationManagerWordCounter' );
		$links[] = $this->getLinkRenderer()->makeKnownLink(
			$tmpTitle,
			null,
			[],
			[ 'target' => $this->item->getName() ]
		);

		$this->getOutput()->addHTML(
			Html::rawElement( 'p', [], $this->getLanguage()->pipeList( $links ) )
		);
	}
}
