<?php

namespace TranslationManager;

use Addwiki\Mediawiki\Api\Client\Action\ActionApi;
use Addwiki\Mediawiki\Api\Client\Action\Request\ActionRequest;
use Addwiki\Mediawiki\Api\Client\Auth\UserAndPassword;
use Addwiki\Mediawiki\Api\MediawikiFactory;
use Addwiki\Mediawiki\DataModel\Content as AddwikiContent;
use Addwiki\Mediawiki\DataModel\EditInfo;
use Addwiki\Mediawiki\DataModel\PageIdentifier;
use Addwiki\Mediawiki\DataModel\Revision as AddwikiRevision;
use Addwiki\Mediawiki\DataModel\Title as AddwikiTitle;

class RemoteWikiApi {

	/** @var ActionApi */
	private ?ActionApi $api;
	/** @var MediawikiFactory */
	private $services;

	/**
	 * @param string $lang
	 * @throws \MWException
	 */
	public function __construct( $lang ) {
		$config = Hooks::getConfig();

		$apiUrl = $config->get( 'TranslationManagerTargetWikiApiURL' );
		$apiUser = $config->get( 'TranslationManagerTargetWikiUserName' );
		$apiPassword = $config->get( 'TranslationManagerTargetWikiUserPassword' );

		if ( $apiUrl === null || $apiUser === null || $apiPassword === null ) {
			throw new \MWException( 'Missing API login details! See README.' );
		}

		$apiUrl = str_replace( '$1', $lang, $apiUrl );
		$auth = new UserAndPassword( $apiUser, $apiPassword );
		$this->api = new ActionApi( $apiUrl, $auth );
		$this->services = new MediawikiFactory( $this->api );
	}

	/**
	 * @param string $oldSuggestion
	 * @param string $newSuggestion
	 * @param string $originTitle
	 * @return string( 'failed-exists', 'moved', 'noop', 'created', 'failed-create' )
	 */
	public function updateRedirect( $oldSuggestion, $newSuggestion, $originTitle ): string {
		$newSuggestionTitle = new AddwikiTitle( $newSuggestion );

		$oldRedirect = $oldSuggestion ? $this->services->newPageGetter()->getFromTitle( $oldSuggestion ) : null;

		$newSuggestionPageStatus = $this->getPageStatus( $newSuggestion );
		if ( $newSuggestionPageStatus === 'exists' ) {
			return 'failed-exists';
		}

		// If there's no old suggestion, just create the new one
		if ( !$oldSuggestion ) {
			return $this->createNewRedirect( $originTitle, $newSuggestionTitle );
		}

		// There's an old suggestion, and, the behavior depends on its status:.
		// If it's a redirect, we just move it.
		// If it doesn't actually exist, we ignore it and create a new redirect
		// If it exists as an article, or any other unforseen circumstance, we do nothing.
		$oldSuggestionPageStatus = $this->getPageStatus( $oldSuggestion );
		if ( $oldSuggestionPageStatus === 'redirect' ) {
			// There's a previous redirect, so we just move it
			$this->services->newPageMover()->move(
				$oldRedirect,
				$newSuggestionTitle,
				[ 'reason' => 'התרגום השתנה' ]
			);
			return 'moved';
		} elseif ( $oldSuggestionPageStatus === 'missing' ) {
			// Just create the new redirect
			return $this->createNewRedirect( $originTitle, $newSuggestionTitle );
		}

		return 'articleexists';
	}

	/**
	 * @param string $originTitle
	 * @param AddwikiTitle $redirectTitle
	 * @return string( 'created', 'failed-create' )
	 */
	private function createNewRedirect( $originTitle, AddwikiTitle $redirectTitle ) {
		$newContent = new AddwikiContent( '#REDIRECT [[:he:' . $originTitle . ']]' );
		$identifier = new PageIdentifier( $redirectTitle );
		$revision = new AddwikiRevision( $newContent, $identifier );
		$editinfo = new EditInfo( 'יצירת הפניה עבור תרגום מוצע', EditInfo::NOTMINOR, EditInfo::BOT );
		$success = $this->services->newRevisionSaver()->save( $revision, $editinfo );
		return $success ? 'created' : 'failed-create';
	}

	/**
	 * @param string $pageName
	 * @return string( 'redirect', 'missing', 'exists' )
	 */
	private function getPageStatus( string $pageName ) {
		$request = ActionRequest::simpleGet(
			'query',
			[
				'titles' => $pageName,
				'prop' => 'info',
				'format' => 'json'
			]
		);
		$response = $this->api->request( $request );
		$pages = $response['query']['pages'];
		if ( !is_array( $pages ) ) {
			return false;
		}
		foreach ( $pages as $page ) {
			if ( isset( $page['redirect'] ) ) {
				return 'redirect';
			}
			if ( isset( $page['missing'] ) ) {
				return 'missing';
			}
		}

		return 'exists';
	}
}
