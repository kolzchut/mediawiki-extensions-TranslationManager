<?php

namespace TranslationManager;

use Config;
use DatabaseUpdater;
use MediaWiki\MediaWikiServices;
use TranslationManager\Maintenance\MigrateTranslatorNames;
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
			'options' => StatusItem::getLanguagesForSelectField(),
			'label-message' => 'ext-tm-preferences-language',
		];
	}

	/**
	 * Schema update to set up the needed database tables.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable(
			StatusItem::TABLE_NAME,
			__DIR__ . '/sql/TranslationManager.sql'
		);

		$updater->addExtensionField(
			StatusItem::TABLE_NAME,
			'tms_wordcount',
			__DIR__ . '/sql/patch-status-wordcount.sql'
		);

		$updater->addExtensionField(
			StatusItem::TABLE_NAME,
			'tms_start_date',
			__DIR__ . '/sql/patch-status-timestamps.sql'
		);
		$updater->addExtensionField(
			StatusItem::TABLE_NAME,
			'tms_end_date',
			__DIR__ . '/sql/patch-status-timestamps.sql'
		);

		$updater->dropExtensionField(
			StatusItem::TABLE_NAME,
			'tms_main_category',
			__DIR__ . '/sql/patch-drop-status-main_category.sql'
		);
		$updater->addExtensionField(
			StatusItem::TABLE_NAME,
			'tms_lang',
			__DIR__ . '/sql/patch-status-language.sql'
		);

		$updater->addExtensionTable(
			Personnel::TABLE_NAME,
			__DIR__ . '/sql/translation_manager_personnel.sql'
		);

		$updater->addExtensionField(
			StatusItem::TABLE_NAME,
			'tms_requires_legal_review',
			__DIR__ . '/sql/patch-status-requires-legal-review.sql'
		);

		$updater->addExtensionField(
			StatusItem::TABLE_NAME,
			'tms_editor_id',
			__DIR__ . '/sql/patch-status-editor_id.sql'
		);

		$updater->addExtensionField(
			StatusItem::TABLE_NAME,
			'tms_translator_id',
			__DIR__ . '/sql/patch-status-translator_id.sql'
		);

		$updater->addPostDatabaseUpdateMaintenance( MigrateTranslatorNames::class );
	}
}
