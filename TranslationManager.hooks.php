<?php

namespace TranslationManager;

use DatabaseUpdater;

/**
 * Static class for hooks handled by the TranslationManager extension.
 *
 *
 * @file TranslationManager.hooks.php
 * @ingroup TranslationManager
 *
 * @licence GNU GPL v2+
 */
final class TranslationManagerHooks {

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

		return true;
	}
}
