<?php

/**
 * Aliases for the special pages of the TranslationManager extension.
 *
 *
 * @file TranslationManager.i18n.alias.php
 * @ingroup TranslationManager
 *
 * @licence GNU GPL v2+
 */
// @codingStandardsIgnoreFile

$specialPageAliases = array();

/** English (English) */
$specialPageAliases['en'] = array(
	'TranslationManagerOverview' => array( 'TranslationManagerOverview', 'Translation_Project_Overview' ),
	'TranslationManagerStatusEditor' => array( 'TranslationManagerPageStatus', 'Translation_Project_Page_Status' )
);

/** Arabic (العربية) */
$specialPageAliases['ar'] = array(
);

/** Hebrew (עברית) */
$specialPageAliases['he'] = array(
	'TranslationManagerOverview' => array( 'סטטוס_מיזם_התרגום' ),
	'TranslationManagerStatusEditor' => array( 'סטטוס_דף_במיזם_התרגום' )
);

