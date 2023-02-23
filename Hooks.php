<?php

namespace TranslationManager;

use Config;
use DatabaseUpdater;
use MediaWiki\MediaWikiServices;
use User;

/**
 * Static class for hooks handled by the TranslationManager extension.
 *
 *
 * @file TranslationManager.hooks.php
 * @ingroup TranslationManager
 *
 * @license GPL-2.0-or-later
 */
final class Hooks {

	/**
	 * @return Config
	 */
	public static function getConfig(): Config {
		return MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'TranslationManager' );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences
	 * Add a preference for the default language for translation
	 * @param User $user User whose preferences are being modified.
	 * @param array[] &$preferences Preferences description array, to be fed to a HTMLForm object.
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		$preferences['translationmanager-language'] = [
			'section' => 'personal/i18n',
			'type' => 'select',
			'options' => TranslationManagerStatus::getLanguagesForSelectField(),
			'label-message' => 'ext-tm-preferences-language',
		];
	}

	/**
	 * Schema update to set up the needed database tables.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
	 *
	 * @param DatabaseUpdater $updater
	 *
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable(
			TranslationManagerStatus::TABLE_NAME,
			__DIR__ . '/sql/TranslationManager.sql'
		);

		$updater->addExtensionField(
			TranslationManagerStatus::TABLE_NAME,
			'tms_wordcount',
			__DIR__ . '/sql/patch-status-wordcount.sql'
		);

		$updater->addExtensionField(
			TranslationManagerStatus::TABLE_NAME,
			'tms_start_date',
			__DIR__ . '/sql/patch-status-timestamps.sql'
		);
		$updater->addExtensionField(
			TranslationManagerStatus::TABLE_NAME,
			'tms_end_date',
			__DIR__ . '/sql/patch-status-timestamps.sql'
		);

		$updater->dropExtensionField(
			TranslationManagerStatus::TABLE_NAME,
			'tms_main_category',
			__DIR__ . '/sql/patch-drop-status-main_category.sql'
		);
		$updater->addExtensionField(
			TranslationManagerStatus::TABLE_NAME,
			'tms_lang',
			__DIR__ . '/sql/patch-status-language.sql'
		);
	}
}
