<?php

namespace TranslationProject;

use DatabaseUpdater;

/**
 * Static class for hooks handled by the TranslationProject extension.
 *
 *
 * @file TranslationProject.hooks.php
 * @ingroup TranslationProject
 *
 * @licence GNU GPL v2+
 */
final class TranslationProjectHooks {

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
			'tp_translation',
			__DIR__ . '/sql/TranslationProject.sql'
		);

		return true;
	}
}
