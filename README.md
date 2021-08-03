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
- $wgTra
### Login details for target wiki
These are required for creating redirects on the target wiki:
- $wgTranslationManagerTargetWikiApiURL: the full url to the api (e.g., 'http://localhost/wiki/api.php')
- $wgTranslationManagerTargetWikiUserName
- $wgTranslationManagerTargetWikiUserPassword

## Dependencies
- Extension:ExportForTranslation. The word counter special page depends on it, which should probably be optional.
- This is currently dependent on extension:WRArticleType (see TODO)
- Optional: if Extension:ArticleContentArea is installed, it will allow querying by content areas.
- addwiki/mediawiki-api (see composer.json)

## Changelog
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
