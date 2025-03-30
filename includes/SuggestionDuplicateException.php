<?php

namespace TranslationManager;

use MWException;

class SuggestionDuplicateException extends MWException {
	/** @var StatusItem|null */
	protected ?StatusItem $translationStatus;

	/**
	 * @param StatusItem|null $tmStatus
	 */
	public function __construct( ?StatusItem $tmStatus ) {
		$this->translationStatus = $tmStatus;
		parent::__construct();
	}

	/**
	 * @return StatusItem|null
	 */
	public function getTranslationManagerStatus(): ?StatusItem {
		return $this->translationStatus;
	}
}
