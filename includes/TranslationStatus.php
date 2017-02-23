<?php

namespace TranslationProject;

class TranslationStatus {
	const
		__default = 0,
		NOT_STARTED = 0,
		IN_PROGRESS = 1,
		IN_REVIEW = 2,
		DONE = 3;

	private static function getConstants() {
		$oClass = new \ReflectionClass( __CLASS__ );
		return $oClass->getConstants();
	}

	public static function getDefault() {
		return self::__default;
	}

	public static function getStatusCodes() {
		return array_keys( self::getConstants() );
	}
}
