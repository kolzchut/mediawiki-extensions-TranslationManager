<?php

namespace TranslationManager\Specials\Personnel;

use Html;
use MediaWiki\MediaWikiServices;
use TablePager;
use TranslationManager\Personnel;
use TranslationManager\Specials\SpecialPersonnel;
use Wikimedia\Rdbms\IDatabase;

class PersonnelPager extends TablePager {
	/** @var array */
	private array $formData;

	/**
	 * @param SpecialPersonnel $specialPage
	 * @param array $formData
	 */
	public function __construct( SpecialPersonnel $specialPage, array $formData ) {
		parent::__construct( $specialPage->getContext() );
		$this->mDb = MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$this->formData = $formData;
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo(): array {
		$query = [
			'tables' => [ Personnel::TABLE_NAME ],
			'fields' => [
				'tmp_id',
				'tmp_name',
				'tmp_types',
				'tmp_languages',
				'tmp_is_active',
			],
			'conds' => [],
		];

		// Apply filters
		if ( isset( $this->formData['types'] ) && $this->formData['types'] ) {
			$types = $this->formData['types'];
			$typeConds = [];
			foreach ( $types as $type ) {
				$typeConds[] = 'tmp_types ' . $this->mDb->buildLike(
					$this->mDb->anyString(),
					'"' . $type . '"',
					$this->mDb->anyString()
				);
			}
			$query['conds'][] = $this->mDb->makeList( $typeConds, IDatabase::LIST_OR );
		}

		if ( isset( $this->formData['languages'] ) && $this->formData['languages'] ) {
			$langs = $this->formData['languages'];
			$langConds = [];
			foreach ( $langs as $lang ) {
				$langConds[] = 'tmp_languages ' . $this->mDb->buildLike(
					$this->mDb->anyString(),
					'"' . $lang . '"',
					$this->mDb->anyString()
				);
			}
			$query['conds'][] = $this->mDb->makeList( $langConds, IDatabase::LIST_OR );
		}

		if ( isset( $this->formData['is_active'] ) && $this->formData['is_active'] !== '' ) {
			$query['conds']['tmp_is_active'] = (bool)$this->formData['is_active'];
		}

		return $query;
	}

	/**
	 * @inheritDoc
	 */
	public function getFieldNames(): array {
		return [
			'tmp_name' => $this->msg( 'ext-tm-personnel-name' )->text(),
			'tmp_types' => $this->msg( 'ext-tm-personnel-types' )->text(),
			'tmp_languages' => $this->msg( 'ext-tm-personnel-languages' )->text(),
			'tmp_is_active' => $this->msg( 'ext-tm-personnel-status' )->text(),
			'actions' => $this->msg( 'ext-tm-personnel-actions' )->text(),
		];
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ): string {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'tmp_name':
				return htmlspecialchars( $value );

			case 'tmp_types':
				$types = json_decode( $value );
				$typeLabels = [];
				foreach ( $types as $type ) {
					$typeLabels[] = $this->msg( 'ext-tm-personnel-type-' . $type )->text();
				}
				return htmlspecialchars( implode( ', ', $typeLabels ) );

			case 'tmp_languages':
				$languages = json_decode( $value );
				$languageNameUtils = MediaWikiServices::getInstance()->getLanguageNameUtils();
				$languageLabels = [];
				foreach ( $languages as $langCode ) {
					$languageLabels[] = $languageNameUtils->getLanguageName(
						$langCode, $this->getLanguage()->getCode()
					);
				}
				return htmlspecialchars( implode( ', ', $languageLabels ) );

			case 'tmp_is_active':
				return $value ?
					$this->msg( 'ext-tm-personnel-status-active' )->escaped() :
					$this->msg( 'ext-tm-personnel-status-inactive' )->escaped();

			case 'actions':
				$id = $row->tmp_id;
				$specialPage = $this->getContext()->getTitle();

				$editLink = Html::element(
					'a',
					[
						'href' => $specialPage->getLocalURL( [
							'wpaction' => 'edit',
							'wpid' => $id
						] ),
						'class' => 'mw-ui-button'
					],
					$this->msg( 'ext-tm-personnel-edit' )->text()
				);

				$deleteLink = Html::element(
					'a',
					[
						'href' => $specialPage->getLocalURL( [
							'wpaction' => 'delete',
							'wpid' => $id
						] ),
						'class' => 'mw-ui-button mw-ui-destructive'
					],
					$this->msg( 'ext-tm-personnel-delete' )->text()
				);

				return $editLink . ' ' . $deleteLink;
		}

		return htmlspecialchars( $value );
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultSort(): string {
		return 'tmp_name';
	}

	/**
	 * @inheritDoc
	 */
	public function isFieldSortable( $field ): bool {
		return in_array( $field, [
			'tmp_name',
			'tmp_is_active',
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getTableClass() {
		return 'wikitable';
	}
}
