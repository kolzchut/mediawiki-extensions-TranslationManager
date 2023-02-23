# Translation Project extension for MediaWiki

This extension is used to monitor the progression of the Kol-Zchut
Hebrew->Arabic translation project.

## Installation
This extension uses Composer to manage its dependencies. Therefore, you need to have the following in
`composer.local.json` (unless you already use the merge plugin to include `extensions/*/composer.json`):
```
	"extra": {
		"merge-plugin": {
			"include": [
				"extensions/WikiRights/TranslationManager/composer.json",
				"skins/*/composer.json"
			]
		}
	}
```

## Configuration
- $wgTranslationManagerAutoSaveWordCount (boolean): save word count into the database directly from the word counter special page.
- $wgTranslationManagerAutoSetEndTranslationOnWordCount (boolean): set end date to today on word count.
- $wgTranslationManagerValidLanguages (array): an array of allowed language codes (e.g. ['en', 'ar'] )
  These determine the target languages you can manage.

### User Preferences
The extension adds the user preference "translationmanager-language", which is set to the user's preferred
language code (e.g. 'ar') for translation work. This is also used by extension:ExportForTranslation.

### Login details for target wiki
These are required for creating redirects on the target wiki:
- $wgTranslationManagerTargetWikiApiURL: the full url to the api (e.g., 'http://localhost/wiki/api.php')
- $wgTranslationManagerTargetWikiUserName
- $wgTranslationManagerTargetWikiUserPassword

## Dependencies
### Hard dependencies
- Extension:AdditionalFormInputs, which adds a positive-integer HTML field
- addwiki/mediawiki-api: API client to create redirects on a target wiki

### Soft dependencies
- Extension:ExportForTranslation (used for word count) >= 0.3.0
- Extension:ArticleType (extra filtering enabled if available)
- Extension:ArticleContentArea (extra filtering)

## Changelog
### 0.8.0, 2023-01-23
- Multi-lingual support, including a user preference for default language
### 0.7.0, 2021-09-17
- Make extension ArticleType optional; use its new getJoin() function to filter without knowing table specifics.
### 0.6.0, 2021-08-03
- Drop the main_category field and use Extension:ArticleContentArea instead
### 0.5.1, 2021-06-08
- The word counter will now try to compare with the actual exported revision, instead of the current revision.
### 0.5.0, 2021-02-24
- Allow to programatically get suggestions by article IDs (with or without language links), not just
  all rows
### 0.4.1, 2019-08-13
- Do not create redirects if the article is already translated
### 0.4.0, 2017-08-03
- Use an API client to create redirects on a remote wiki whenever a new translation suggestion is added
