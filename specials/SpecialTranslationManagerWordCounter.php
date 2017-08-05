<?php
/**
 * SpecialPage for TranslationManager extension
 * Used for counting translated words
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationManager;

use ExportForTranslation;
use Html;
use HTMLForm;
use MWException;
use MWTimestamp;
use UnlistedSpecialPage;
use Title;

class SpecialTranslationManagerWordCounter extends UnlistedSpecialPage {

	function __construct( $name = 'TranslationManagerWordCounter' ) {
		parent::__construct( $name );
	}

	public function doesWrites() {
		return false;
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		$this->getForm()->show();
	}

	public function onSubmit( $data, $form ) {
		$title = Title::newFromText( $data['page_title'] );
		if ( !$title->exists() ) {
			throw new MWException( 'No such page' );
		}

		$statusItem = new TranslationManagerStatus( $title->getArticleID() );
		$original_text = ExportForTranslation::export( $title->getPrefixedText() );
		$translated_text = $data['translated_text'];

		$original_text = self::cleanupTextAndExplode( $original_text );
		$translated_text = self::cleanupTextAndExplode( $translated_text );

		$diff = self::subtractArrays( $translated_text, $original_text );
		$wordCount = count( $diff );

		$successMessage = Html::element( 'p', [], "מספר המילים החדשות בתרגום הוא: " . $wordCount );

		// $this->getOutput()->addElement( 'pre', [], print_r( $diff, true ) );

		$isDirty = false;
		$config = $this->getConfig();
		if ( $config->get( 'TranslationManagerAutoSaveWordCount' ) === true && $statusItem->getWordcount() === null ) {
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

		private static function cleanupTextAndExplode( $text ) {
			$text = self::cleanupText( $text );
			return explode( " ", $text );
		}

		private static function cleanupText( $text ) {

			// We do the following because strtr just didn't work right in utf-8 text
			$replacements = ":,[]={}|*,";
			$replacements = str_split( $replacements );
			$replacements[]='،';

			$text = str_replace( $replacements, ' ', $text );
			$text = preg_replace( '/<!--[\s\S]*?-->/', '', $text );
			$text = preg_replace( '/\s+/', ' ', $text );

			return $text;
		}


		private static function subtractArrays( array $a, array $b ) {
			$counts = array_count_values( $b );
			$a = array_filter( $a, function( $o ) use ( &$counts ) {
				return empty( $counts[$o] ) || !$counts[$o]--;
			} );

			return $a;
		}

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
			'translated_text' => [
				'type' => 'textarea',
				'name' => 'translated_text',
				'required' => true,
				'placeholder' => 'הדביקו טקסט כאן' // @todo i18n
			]
		];
	}

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
