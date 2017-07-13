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
			'tm_status',
			__DIR__ . '/sql/TranslationManager.sql'
		);

		return true;
	}
}
