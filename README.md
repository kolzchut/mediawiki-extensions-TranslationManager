# MediaWiki extension Translation Project

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
- $wgTranslationManagerAutoSaveWordCount (boolean): save word count into db directly from the word counter special page.
- $wgTranslationManagerAutoSetEndTranslationOnWordCount (boolean): set end date to today on word count.

### Login details for target wiki
These are required for creating redirects on the target wiki:
- $wgTranslationManagerTargetWikiApiURL: the full url to the api (e.g., 'http://localhost/wiki/api.php')
- $wgTranslationManagerTargetWikiUserName
- $wgTranslationManagerTargetWikiUserPassword

## Dependencies
- This is currently dependent on extension:WRArticleType (see TODO)
- addwiki/mediawiki-api (see composer.json)
- PHP >= 5.6 (simply because I used the splat operator...)

## Changelog
### 0.4.1, 2019-08-13
- Do not create redirects if the article is already translated
### 0.4.0, 2017-08-03
- Use an API client to create redirects on a remote wiki whenever a new translation suggestion is added
