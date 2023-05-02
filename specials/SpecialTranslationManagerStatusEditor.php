<?php
/**
 * SpecialPage for TranslationManager extension
 * Used for editing lines in the overview table
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationManager;

use Exception;
use Html;
use HTMLForm;
use Linker;
use MediaWiki\Logger\LoggerFactory;
use SpecialPage;
use UnlistedSpecialPage;

class SpecialTranslationManagerStatusEditor extends UnlistedSpecialPage {

	/**
	 * @var null|TranslationManagerStatus
	 */
	private ?TranslationManagerStatus $item = null;
	/** @var bool */
	private bool $editable = false;
	/** @var string */
	private $language = null;
	/** @var array */
	private array $errors = [];

	/**
	 * @inheritDoc
	 */
	public function __construct(
		$name = 'TranslationManagerStatusEditor',
		$restriction = 'translation-manager-overview'
	) {
		parent::__construct( $name, $restriction );
	}

	/**
	 * @inheritDoc
	 */
	public function doesWrites(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function isListed(): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ) {
		parent::execute( $subPage );

		$this->editable = $this->userCanExecute( $this->getUser() );
		$this->language = $this->getRequest()->getVal( 'language' );
		$this->language = $this->language ?: $this->getUser()->getOption( 'translationmanager-language' );
		if ( !TranslationManagerStatus::isValidLanguage( $this->language ) ) {
			throw new \ErrorPageError( 'error', 'invalid language name' );
		}
		$this->item = new TranslationManagerStatus( $subPage, $this->language );

		$this->displayNavigation();

		if ( $this->item->exists() ) {
			$this->getForm()->showAlways();
		} else {
			$this->outputError( 'ext-tm-statusitem-missingpage' );
		}
	}

	/**
	 * Callback for on submit.
	 *
	 * @param array $data
	 * @param HTMLForm $form
	 *
	 * @return bool|string
	 * @see HTMLForm::setSubmitCallback(), HTMLForm::trySubmit()
	 *
	 */
	public function onSubmit( array $data, HTMLForm $form ) {
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
		$this->item->setLanguage( $data['language'] );
		$result = $this->item->setSuggestedTranslation( $data['suggested_name'] );
		if ( $result === 'invalidtitle' ) {
			$this->outputError( 'ext-tm-statusitem-edit-error' );
			$this->outputError( 'ext-tm-create-redirect-invalid' );
			return false;
		}

		$this->item->setWordcount( $data['wordcount'] );
		$this->item->setStartDateFromField( $data['start_date'] );
		$this->item->setEndDateFromField( $data['end_date'] );

		try {
			$result = $this->item->save();

			if ( $result !== true ) {
				$this->outputError( 'ext-tm-statusitem-edit-error' );
			} else {
				$this->outputSuccess( 'ext-tm-statusitem-edit-success' );
				try {
					$status = $this->item->createRedirectFromSuggestion();
					switch ( $status ) {
						case 'created':
						case 'moved':
							// Messages: ext-tm-create-redirect-created, ext-tm-create-redirect-moved
							$this->outputSuccess( "ext-tm-create-redirect-$status" );
							break;
						case 'articleexists':
						case 'invalidtitle':
						case 'createfailed':
							// Messages: ext-tm-create-redirect-articleexists,
							//ext-tm-create-redirect-invalidtitle, ext-tm-create-redirect-createfailed
							$this->outputError( "ext-tm-create-redirect-$status" );
							break;
						case 'nochange':
							// The suggested translation wasn't changed, do nothing
							break;
						case 'alreadytranslated':
						case 'removed':
							// Messages: ext-tm-create-redirect-removed, ext-tm-create-redirect-alreadytranslated
							$this->outputWarning( "ext-tm-create-redirect-$status" );
							break;
						default:
							$this->outputError( 'ext-tm-create-redirect-unknown', $status );
					}
				} catch ( Exception $e ) {
					$this->outputError( 'ext-tm-create-redirect-unknown', $e->getMessage() );
				}
			}
		} catch ( TMStatusSuggestionDuplicateException $e ) {
			$this->outputError( 'ext-tm-statusitem-edit-error-duplicate-suggestion',
				$e->getTranslationManagerStatus()->getName()
			);
		} catch ( Exception $e ) {
			$logger = LoggerFactory::getInstance( 'TranslationManager' );
			$logger->debug( 'Unknown error on saving translation status', [ 'exception' => $e ] );
			$this->outputError( 'ext-tm-statusitem-edit-error' );
		}

		return count( $this->errors ) === 0;
	}

	/**
	 * @param string $error message name
	 * @param array|null|string $params additional message params
	 *
	 * @return void
	 */
	protected function outputError( string $error, $params = null ) {
		$this->errors[] = $error;
		$this->getOutput()->addHTML(
			Html::errorBox( $this->msg( $error )->params( $params )->parse() )
		);
	}

	/**
	 * @param string $warning message name
	 *
	 * @return void
	 */
	protected function outputWarning( string $warning ) {
		$this->getOutput()->addHTML(
			Html::warningBox( $this->msg( $warning )->parse() )
		);
	}

	/**
	 * @param string $success message name
	 *
	 * @return void
	 */
	protected function outputSuccess( string $success ) {
		$this->getOutput()->addHTML(
			Html::successBox( $this->msg( $success )->parse() )
		);
	}

	/**
	 * @return array[]
	 */
	private function getFormFields(): array {
		$item = $this->item;
		$actualTranslation = $item->getActualTranslation() ?:
			$this->msg( 'ext-tm-statusitem-actualtranslation-missing' )->escaped();

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

	/**
	 * @return HTMLForm
	 * @throws \MWException
	 */
	private function getForm(): HTMLForm {
		$editForm = HTMLForm::factory(
			'ooui',
			$this->getFormFields(),
			$this->getContext()
		)->setId( 'mw-trans-status-edit-form' )
			->setSubmitCallback( [ $this, 'onSubmit' ] )
			->setSubmitTextMsg( 'ext-tm-save-item' )
			->prepareForm();

		return $editForm;
	}

	protected function displayNavigation() {
		$links[] = Linker::specialLink( 'TranslationManagerOverview' );

		if ( \ExtensionRegistry::getInstance()->isLoaded( 'ExportForTranslation' ) ) {
			$tmpTitle = SpecialPage::getTitleValueFor( 'TranslationManagerWordCounter' );
			$links[]  = $this->getLinkRenderer()->makeKnownLink(
				$tmpTitle,
				null,
				[],
				[ 'target' => $this->item->getName() ]
			);
		}

		$this->getOutput()->addHTML(
			Html::rawElement( 'p', [], $this->getLanguage()->pipeList( $links ) )
		);
	}
}
