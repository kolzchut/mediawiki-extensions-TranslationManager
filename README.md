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
- This is currently dependent on extension:WRArticleType (see #TODO)
- addwiki/mediawiki-api (see composer.json)
- PHP >= 5.6 (simply because I used the splat operator...)

## Changelog
### 0.5.1, 2018-08-10
- Properly display language names in dropdown fields
### 0.5.0, 2018-08-08
- Multi-lingual support, including a user preference for default language
### 0.4.0, 2017-08-03
- Use an API client to create redirects on a remote wiki whenever a new translation suggestion is added

## TODO
- MAJOR: Re-work TranslationManagerStatus to work like Extension:Draft!
- Develop something like Extension:Drafts API-AJAX editing
- Add logging to changes in status lines
