<?php
/**
 * SpecialPage for TranslationManager extension
 * Used for counting translated words
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationManager;

use ErrorPageError;
use ExportForTranslation;
use Html;
use HTMLForm;
use MWException;
use MWTimestamp;
use Title;
use UnlistedSpecialPage;

class SpecialTranslationManagerWordCounter extends UnlistedSpecialPage {

	/** @inheritDoc */
	public function __construct( $name = 'TranslationManagerWordCounter' ) {
		parent::__construct( $name );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return false;
	}

	/** @inheritDoc
	 * @throws ErrorPageError
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		if ( !\ExtensionRegistry::getInstance()->isLoaded( 'ExportForTranslation' ) ) {
			throw new ErrorPageError(
				'ext-tm-error-exportfortranslation-not-installed-title',
				'ext-tm-error-exportfortranslation-not-installed'
			);
		}

		$this->getForm()->show();
	}

	/**
	 * @param mixed $data
	 * @param mixed $form
	 *
	 * @throws MWException
	 * @throws TMStatusSuggestionDuplicateException
	 */
	public function onSubmit( $data, $form ) {
		$title = Title::newFromText( $data['page_title'] );
		if ( !$title->exists() ) {
			throw new MWException( 'No such page' );
		}
		$language = $this->getRequest()->getVal( 'language' );
		$statusItem = new TranslationManagerStatus( $title->getArticleID(), $language );
		$translated_text = $data['translated_text'];

		$rev_id = ExportForTranslation\Exporter::getRevIdFromText( $translated_text );
		$original_text = ExportForTranslation\Exporter::export( $title, $rev_id, $language );

		$original_text = self::cleanupTextAndExplode( $original_text );
		$translated_text = self::cleanupTextAndExplode( $translated_text );

		$diff = self::subtractArrays( $translated_text, $original_text );
		$wordCount = count( $diff );

		$successMessage = Html::element( 'p', [], "מספר המילים החדשות בתרגום הוא: " . $wordCount );

		$isDirty = false;
		$config = $this->getConfig();
		if (
			$config->get( 'TranslationManagerAutoSaveWordCount' ) === true &&
			$statusItem->getWordcount() === null
		) {
			$statusItem->setWordcount( $wordCount );
			$isDirty = true;
			$successMessage .= Html::element( 'p', [], 'מספר המילים נשמר.' );
		}
		if ( $config->get( 'TranslationManagerAutoSetEndTranslationOnWordCount' ) === true
			 && $statusItem->getEndDate() === null
		) {
			$statusItem->setEndDate( MWTimestamp::getLocalInstance() );
			$isDirty = true;
			$successMessage .= Html::element( 'p', [], 'תאריך סיום התרגום עודכן.' );
		}

		if ( $isDirty ) {
			$statusItem->save();
		}

		$this->getOutput()->addHTML(
			Html::rawElement( 'div',
				[ 'class' => 'successbox' ],
				$successMessage
			)
		);
	}

	/**
	 * @param string $text
	 *
	 * @return false|string[]
	 */
	private static function cleanupTextAndExplode( string $text ) {
		$text = self::cleanupText( $text );
		return explode( " ", $text );
	}

	/**
	 * @param string $text
	 *
	 * @return array|string|string[]|null
	 */
	private static function cleanupText( string $text ) {
		// We do the following because strtr just didn't work right in utf-8 text
		$replacements = ":,[]={}|*,";
		$replacements = str_split( $replacements );
		$replacements[] = '،';

		$text = str_replace( $replacements, ' ', $text );
		$text = preg_replace( '/<!--[\s\S]*?-->/', '', $text );
		$text = preg_replace( '/\s+/', ' ', $text );

		return $text;
	}

	/**
	 * @param array $a
	 * @param array $b
	 *
	 * @return array
	 */
	private static function subtractArrays( array $a, array $b ) {
		$counts = array_count_values( $b );
		$a = array_filter( $a, static function ( $o ) use ( &$counts ) {
			return empty( $counts[$o] ) || !$counts[$o]--;
		} );

		return $a;
	}

	/**
	 * @return array[]
	 */
	private function getFormFields() {
		return [
			'page_title' => [
				'class' => 'HTMLTitleTextField',
				'name' => 'target',
				'label-message' => 'ext-tm-statusitem-title',
				'namespace' => 0,
				'relative' => true,
				'required' => true,
				'default' => $this->getRequest()->getVal( 'target' )
			],
			'target_language' => [
				'type' => 'select',
				'name' => 'language',
				'label-message' => 'ext-tm-statusitem-language',
				'required' => 'true',
				'options' => TranslationManagerStatus::getLanguagesForSelectField(),
				'default' => $this->getRequest()->getVal( 'language' )
			],
			'translated_text' => [
				'type' => 'textarea',
				'name' => 'translated_text',
				'required' => true,
				// @todo i18n
				'placeholder' => 'הדביקו טקסט כאן'
			]
		];
	}

	/**
	 * @return HTMLForm
	 * @throws MWException
	 */
	private function getForm() {
		$editForm = HTMLForm::factory(
			'ooui',
			$this->getFormFields(),
			$this->getContext()
		)->setId( 'mw-trans-wordcount-form' )
							->setMethod( 'post' )
							->setSubmitCallback( [ $this, 'onSubmit' ] )
							->prepareForm();

		return $editForm;
	}
}
