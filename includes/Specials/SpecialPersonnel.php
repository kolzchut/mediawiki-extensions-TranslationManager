<?php

namespace TranslationManager\Specials;

use Html;
use HTMLForm;
use MWException;
use SpecialPage;
use TranslationManager\Personnel;
use TranslationManager\Specials\Personnel\PersonnelPager;
use TranslationManager\StatusItem;

class SpecialPersonnel extends SpecialPage {
	/** HTMLForm identifier of the deactivation confirmation form */
	public const DELETE_FORM_ID = 'tmPersonnelDeleteForm';

	/**
	 * @inheritDoc
	 */
	public function __construct(
		$name = 'TranslationManagerPersonnel', $restriction = 'translation-manager-overview'
	) {
		parent::__construct( $name, $restriction );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ) {
		parent::execute( $subPage );

		$out = $this->getOutput();
		$out->addModuleStyles( 'mediawiki.special.translationManagerOverview.styles' );

		$request = $this->getRequest();

		// Handle actions like edit, add, delete
		$action = $request->getVal( 'wpaction', 'list' );
		$id = $request->getInt( 'wpid' );

		switch ( $action ) {
			case 'edit':
				$this->showForm( $id );
				break;
			case 'add':
				$this->showForm();
				break;
			case 'delete':
				$this->handleDelete( $id );
				break;
			case 'list':
			default:
				$action = 'list';
				$this->showList();
				break;
		}

		if ( $action !== 'list' ) {
			$out->addBacklinkSubtitle( $this->getPageTitle() );
		}
	}

	/**
	 * Show the edit/add form
	 *
	 * @param int|null $id
	 */
	private function showForm( ?int $id = null ) {
		$out = $this->getOutput();

		$person = null;
		if ( $id !== null ) {
			try {
				$person = new Personnel( $id );
			} catch ( MWException $e ) {
				$out->addHTML( Html::errorBox( $this->msg( 'ext-tm-personnel-not-found' )->escaped() ) );
				$this->showList();
				return;
			}
		}

		$fields = [
			'name' => [
				'name' => 'name',
				'type' => 'text',
				'label-message' => 'ext-tm-personnel-name',
				'required' => true,
				'default' => $person ? $person->getName() : '',
			],
			'types' => [
				'name' => 'types',
				'type' => 'multiselect',
				'label-message' => 'ext-tm-personnel-types',
				'required' => true,
				'options' => [
					$this->msg( 'ext-tm-personnel-type-translator' )->text() => 'translator',
					$this->msg( 'ext-tm-personnel-type-editor' )->text() => 'editor',
				],
				'default' => $person ? $person->getTypes() : [],
			],
			'languages' => [
				'name' => 'languages',
				'type' => 'multiselect',
				'label-message' => 'ext-tm-personnel-languages',
				'required' => true,
				'options' => StatusItem::getLanguagesForSelectField(),
				'default' => $person ? $person->getLanguages() : [],
			],
			'is_active' => [
				'name' => 'is_active',
				'type' => 'radio',
				'label-message' => 'ext-tm-personnel-status',
				'options' => [
					$this->msg( 'ext-tm-personnel-status-active' )->text() => true,
					$this->msg( 'ext-tm-personnel-status-inactive' )->text() => false,
				],
				'default' => $person ? $person->getIsActive() : true,
			],
		];

		if ( $person ) {
			$fields['id'] = [
				'type' => 'hidden',
				'default' => $person->getId(),
			];
		}

		$hiddenFields = [
			'wpaction' => $id !== null ? 'edit' : 'add'
		];

		$htmlForm = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$htmlForm
			->addHiddenFields( $hiddenFields )
			->setSubmitCallback( [ $this, 'handleFormSubmit' ] )
			->setSubmitText( $this->msg( 'ext-tm-personnel-save' )->text() )
			->showCancel()
			->setCancelTarget( self::getPageTitle() )
			->show();
	}

	/**
	 * @param array $formData
	 * @return bool
	 * @throws MWException
	 */
	public function handleFormSubmit( array $formData ): bool {
		$id = $formData['id'] ?? null;
		$person = new Personnel( $id );

		$person->setName( $formData['name'] )
			->setTypes( $formData['types'] )
			->setLanguages( $formData['languages'] )
			->setIsActive( $formData['is_active'] );

		$status = $person->save();

		if ( $status->isOK() ) {
			$this->getOutput()->addHTML( Html::successBox( $this->msg( 'ext-tm-personnel-saved' )->escaped() ) );
			$this->showList();
			return true;
		} else {
			$this->getOutput()->addHTML( Html::errorBox( $status->getMessage()->escaped() ) );
			$this->showForm( $person->getId() );
			return false;
		}
	}

	/**
	 * @param int $id
	 * @return void
	 */
	private function handleDelete( int $id ) {
		$out = $this->getOutput();

		try {
			$person = new Personnel( $id );
		} catch ( MWException $e ) {
			$out->addHTML( Html::errorBox( $this->msg( 'ext-tm-personnel-not-found' )->escaped() ) );
			$this->showList();
			return;
		}

		// HTMLForm only runs the callback for a POST with a valid edit token and this
		// form's identifier; anything else shows the confirmation form again.
		$form = HTMLForm::factory( 'ooui', [], $this->getContext() );
		$form
			->setFormIdentifier( self::DELETE_FORM_ID )
			->addHiddenFields( [ 'wpaction' => 'delete', 'wpid' => $id ] )
			->setWrapperLegendMsg( 'ext-tm-personnel-delete-confirm-title' )
			->setPreHtml( Html::element( 'p', [], $this->msg(
				'ext-tm-personnel-delete-confirm', $person->getName()
			)->text() ) )
			->setSubmitTextMsg( 'ext-tm-personnel-delete-confirm-button' )
			->setSubmitDestructive()
			->showCancel()
			->setCancelTarget( $this->getPageTitle() )
			->setSubmitCallback( static function () use ( $person ) {
				$person->setIsActive( false );
				$status = $person->save();
				return $status->isOK() ? true : $status;
			} );

		if ( $form->show() !== true ) {
			return;
		}

		$out->addHTML( Html::successBox( $this->msg( 'ext-tm-personnel-deleted' )->escaped() ) );
		$this->showList();
	}

	/**
	 * Show the list of personnel with filtering options
	 */
	private function showList() {
		$out = $this->getOutput();
		$request = $this->getRequest();

		// Add new button
		$out->addHTML(
			Html::element(
				'a',
				[
					'href' => $this->getPageTitle()->getLocalURL( [ 'wpaction' => 'add' ] ),
					'class' => 'mw-ui-button mw-ui-progressive'
				],
				$this->msg( 'ext-tm-personnel-add-new' )->text()
			)
		);

		// Filter form
		$formDescriptor = [
			'types' => [
				'name' => 'types',
				'type' => 'multiselect',
				'label-message' => 'ext-tm-personnel-filter-type',
				'options-messages' => [
					'ext-tm-personnel-type-translator' => 'translator',
					'ext-tm-personnel-type-editor' => 'editor',
				],
			],
			'languages' => [
				'name' => 'languages',
				'type' => 'multiselect',
				'label-message' => 'ext-tm-personnel-filter-language',
				'options' => StatusItem::getLanguagesForSelectField(),
			],
			'is_active' => [
				'name' => 'is_active',
				'type' => 'select',
				'label-message' => 'ext-tm-personnel-filter-status',
				'options-messages' => [
					'ext-tm-personnel-filter-status-all' => '',
					'ext-tm-personnel-status-active' => '1',
					'ext-tm-personnel-status-inactive' => '0',
				],
			],
		];

		$filterForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$filterForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'ext-tm-personnel-filter-legend' )
			->setSubmitText( $this->msg( 'ext-tm-personnel-filter-submit' )->text() )
			->setSubmitCallback( static function () {
				return true;
			} )
			->prepareForm();

		$submitResult = $filterForm->tryAuthorizedSubmit();
		$filterForm->displayForm( $submitResult === true ? false : $submitResult );

		// Get filter values from request
		$formData = [
			'types' => $request->getArray( 'types', [] ),
			'languages' => $request->getArray( 'languages', [] ),
			'is_active' => $request->getVal( 'is_active', '' ),
		];

		// Create and show pager
		$pager = new PersonnelPager( $this, $formData );
		$out->addParserOutputContent( $pager->getFullOutput() );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'translation';
	}
}
