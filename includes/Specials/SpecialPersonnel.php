<?php

namespace TranslationManager\Specials;

use Html;
use HTMLForm;
use MediaWiki\MediaWikiServices;
use MWException;
use SpecialPage;
use TranslationManager\Personnel;
use TranslationManager\StatusItem;

class SpecialPersonnel extends SpecialPage {

	public function __construct() {
		parent::__construct( 'TranslationManagerPersonnel', 'translation-manager-admin' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();
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
				$this->showList();
				break;
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
				'type' => 'text',
				'label-message' => 'ext-tm-personnel-name',
				'required' => true,
				'default' => $person ? $person->getName() : '',
			],
			'types' => [
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
				'type' => 'multiselect',
				'label-message' => 'ext-tm-personnel-languages',
				'required' => true,
				'options' => StatusItem::getLanguagesForSelectField(),
				'default' => $person ? $person->getLanguages() : [],
			],
			'is_active' => [
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

		$result = $person->save();

		if ( $result ) {
			$this->getOutput()->addHTML( Html::successBox( $this->msg( 'ext-tm-personnel-saved' )->escaped() ) );
			$this->showList();
			return true;
		} else {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'ext-tm-personnel-error-saving' )->escaped() ) );
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
		$request = $this->getRequest();

		try {
			$person = new Personnel( $id );
		} catch ( MWException $e ) {
			$out->addHTML( Html::errorBox( $this->msg( 'ext-tm-personnel-not-found' )->escaped() ) );
			$this->showList();
			return;
		}

		if ( !$request->wasPosted() || !$request->getCheck( 'confirm_delete' ) ) {
			$out->addHTML( Html::element( 'h2', [], $this->msg( 'ext-tm-personnel-delete-confirm-title' )->text() ) );
			$out->addHTML( Html::element( 'p', [], $this->msg(
				'ext-tm-personnel-delete-confirm', $person->getName()
			)->text() ) );

			$out->addHTML( Html::openElement( 'form', [
				'method' => 'post',
				'wpaction' => $this->getPageTitle()->getLocalURL( [ 'wpaction' => 'delete', 'id' => $id ] ),
			] ) );

			$out->addHTML( Html::submitButton( $this->msg( 'ext-tm-personnel-delete-confirm-button' )->text(), [
				'name' => 'confirm_delete',
				'class' => 'mw-ui-button mw-ui-destructive',
			] ) );

			$out->addHTML( Html::element( 'a', [
				'href' => $this->getPageTitle()->getLocalURL(),
				'class' => 'mw-ui-button',
			], $this->msg( 'ext-tm-personnel-cancel' )->text() ) );

			$out->addHTML( Html::closeElement( 'form' ) );
			return;
		}

		$person->setIsActive( false );
		$result = $person->save();

		if ( $result ) {
			$out->addHTML( Html::successBox( $this->msg( 'ext-tm-personnel-deleted' )->escaped() ) );
		} else {
			$out->addHTML( Html::errorBox( $this->msg( 'ext-tm-personnel-error-deleting' )->escaped() ) );
		}

		$this->showList();
	}

	/**
	 * Show the list of personnel
	 */
	private function showList() {
		$out = $this->getOutput();
		$personnel = Personnel::getPersonnel();

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

		// Table of personnel
		$out->addHTML( '<table class="wikitable"><thead><tr>' );
		$headers = [
			'ext-tm-personnel-name',
			'ext-tm-personnel-types',
			'ext-tm-personnel-languages',
			'ext-tm-personnel-status',
			'ext-tm-personnel-actions'
		];

		foreach ( $headers as $header ) {
			$out->addHTML( '<th>' . $this->msg( $header )->escaped() . '</th>' );
		}

		$out->addHTML( '</tr></thead><tbody>' );

		foreach ( $personnel as $person ) {
			$out->addHTML( '<tr>' );

			// Name
			$out->addHTML( '<td>' . htmlspecialchars( $person->getName() ) . '</td>' );

			// Types
			$typeLabels = [];
			if ( $person->isType( 'translator' ) ) {
				$typeLabels[] = $this->msg( 'ext-tm-personnel-type-translator' )->text();
			}
			if ( $person->isType( 'editor' ) ) {
				$typeLabels[] = $this->msg( 'ext-tm-personnel-type-editor' )->text();
			}
			$out->addHTML( '<td>' . htmlspecialchars( implode( ', ', $typeLabels ) ) . '</td>' );

			// Languages
			$languageNameUtils = MediaWikiServices::getInstance()->getLanguageNameUtils();
			$languageLabels = [];
			foreach ( $person->getLanguages() as $langCode ) {
				$languageLabels[] = $languageNameUtils->getLanguageName( $langCode, $this->getLanguage()->getCode() );
			}
			$out->addHTML( '<td>' . htmlspecialchars( implode( ', ', $languageLabels ) ) . '</td>' );

			// Status
			$statusLabel = $person->getIsActive() ?
				$this->msg( 'ext-tm-personnel-status-active' )->text() :
				$this->msg( 'ext-tm-personnel-status-inactive' )->text();
			$out->addHTML( '<td>' . htmlspecialchars( $statusLabel ) . '</td>' );

			// Actions
			$actions = [
				Html::element(
					'a',
					[
						'href' => $this->getPageTitle()->getLocalURL( [
							'wpaction' => 'edit',
							'wpid' => $person->getId()
						] ),
						'class' => 'mw-ui-button'
					],
					$this->msg( 'ext-tm-personnel-edit' )->text()
				),
				Html::element(
					'a',
					[
						'href' => $this->getPageTitle()->getLocalURL( [
							'wpaction' => 'delete',
							'wpid' => $person->getId()
						] ),
						'class' => 'mw-ui-button mw-ui-destructive'
					],
					$this->msg( 'ext-tm-personnel-delete' )->text()
				)
			];
			$out->addHTML( '<td>' . implode( ' ', $actions ) . '</td>' );

			$out->addHTML( '</tr>' );
		}

		$out->addHTML( '</tbody></table>' );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'translation';
	}

}
