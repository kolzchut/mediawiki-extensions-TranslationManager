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

$specialPageAliases = [];

/** English (English] */
$specialPageAliases['en'] = [
	'TranslationManagerOverview' => [ 'TranslationManagerOverview', 'Translation_Project_Overview' ],
	'TranslationManagerStatusEditor' => [ 'TranslationManagerPageStatus', 'Translation_Project_Page_Status' ],
	'TranslationManagerWordCounter' => [ 'TranslationManagerWordCounter' ],
	'TranslationManagerPersonnel' => [ 'TranslationManagerPersonnel' ]
];

/** Arabic (العربية] */
$specialPageAliases['ar'] = [
];

/** Hebrew (עברית] */
$specialPageAliases['he'] = [
	'TranslationManagerOverview' => [ 'סטטוס_מיזם_התרגום' ],
	'TranslationManagerStatusEditor' => [ 'סטטוס_דף_במיזם_התרגום' ],
	'TranslationManagerWordCounter' => [ 'ספירת מילים בתרגום' ],
	'TranslationManagerPersonnel' => [ 'צוות_מיזם_התרגום' ],
];

