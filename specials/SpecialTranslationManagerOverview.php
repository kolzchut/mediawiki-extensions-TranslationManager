<?php
/**
 * SpecialPage for TranslationManager extension
 *
 * @file
 * @ingroup Extensions
 */

namespace TranslationManager;

use ExtensionRegistry;
use Html;
use HTMLForm;
use MediaWiki\Extension\ArticleContentArea\ArticleContentArea;
use MediaWiki\Extension\ArticleType\ArticleType;
use MWException;
use SpecialPage;
use Wikimedia\Timestamp\TimestampException;

class SpecialTranslationManagerOverview extends SpecialPage {
	/** @var ?string */
	private ?string $statusFilter = null;
	/** @var ?string */
	private ?string $titleFilter = null;
	/** @var ?string */
	private ?string $langFilter = null;
	/** @var ?TranslationManagerOverviewPager */
	protected ?TranslationManagerOverviewPager $pager = null;

	/** @inheritDoc */
	public function __construct( $name = 'TranslationManagerOverview' ) {
		parent::__construct( $name );
	}

	/** @inheritDoc
	 * @throws TimestampException
	 * @throws MWException
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$this->outputHeader();
		$request = $this->getRequest();

		$out->addModuleStyles( 'mediawiki.special.translationManagerOverview.styles' );

		// Status parameter validation
		$this->statusFilter = $request->getVal( 'status' );
		$this->statusFilter = TranslationManagerStatus::isValidStatusCode( $this->statusFilter ) ?
			$this->statusFilter : 'all';
		$this->titleFilter = trim( $request->getText( 'page_title' ) );

		$this->langFilter = $request->getVal( 'language' );
		$this->langFilter = TranslationManagerStatus::isValidLanguage( $this->langFilter ) ?
			$this->langFilter : null;
		if ( !$request->getVal( 'go' ) ) {
			$this->langFilter = $this->getUser()->getOption( 'translationmanager-language' );
		}

		$conds = [
			'lang' => $this->langFilter,
			'status' => $this->statusFilter,
			'page_title' => $this->titleFilter,
			'translator' => $request->getVal( 'translator' ),
			'project' => $request->getVal( 'project' ),
			'pageviews' => $request->getInt( 'pageviews' ),
			// Range of start date
			'start_date_from' => $this->timestampFromVal( 'start_date_from' ),
			'start_date_to' => $this->timestampFromVal( 'start_date_to', true ),
			// Range of end date
			'end_date_from' => $this->timestampFromVal( 'end_date_from' ),
			'end_date_to' => $this->timestampFromVal( 'end_date_to', true )
		];

		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$conds[ 'main_category' ] = $request->getVal( 'main_category' );
		}
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			$conds[ 'article_type' ] = self::validateArticleType( $request->getVal( 'article_type' ) );
		}

		$this->pager = new TranslationManagerOverviewPager( $this, $conds );

		$formHtml = $this->getForm()->getHTML( false );
		$out->addHTML( $formHtml );

		// Any truth-y value for "go" is good
		if ( $request->getVal( 'go' ) ) {
			$pagerOutput = $this->pager->getFullOutput();
			$res = $this->pager->getResult();
			$total_wordcount = 0;
			foreach ( $res as $row ) {
				$total_wordcount += (int)$row->wordcount;
			}

			$out->addHTML(
				Html::element(
					'div', [],
					$this->msg( 'ext-tm-overview-total-wordcount' )->numParams( $total_wordcount )->text()
				)
			);
			$out->addHTML(
				Html::element( 'div', [],
					$this->msg( 'ext-tm-overview-number-of-records' )->numParams( $res->numRows() )->text()
				)
			);

			$out->addParserOutput( $pagerOutput );
		}
	}

	/**
	 * @param string|null $code
	 *
	 * @return null|string
	 */
	private static function validateArticleType( ?string $code ): ?string {
		if (
			ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) &&
			ArticleType::isValidArticleType( $code )
		) {
			return $code;
		}

		return null;
	}

	/**
	 * @return array
	 */
	private function getFormFields(): array {
		$options = [
			'projectOptions'      => TranslationManagerStatus::getAllProjects(),
			'translatorOptions'   => TranslationManagerStatus::getAllTranslators()
		];

		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$options['mainCategoryOptions'] = ArticleContentArea::getValidContentAreas();
		}

		// Format the arrays for a select field and Add an "all" options
		foreach ( $options as &$option ) {
			$option = self::makeOptionsWithAllForSelect( $option );
		}

		$fields = [
			'go'         => [
				'type'    => 'hidden',
				'default' => 1,
				'name'    => 'go'
			],
			'page_title' => [
				'class'         => 'HTMLTitleTextField',
				'name'          => 'page_title',
				'label-message' => 'ext-tm-statusitem-title',
				'namespace'     => 0,
				'relative'      => true,
				'required'      => false
			],
			'language' => [
				'name' => 'language',
				'type' => 'select',
				'options' => TranslationManagerStatus::getLanguagesForSelectField(),
				'label-message' => 'ext-tm-statusitem-language',
				'default' => $this->langFilter
			],
		];

		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$fields['main_category'] = [
				'type'          => 'select',
				'name'          => 'main_category',
				'label-message' => 'ext-tm-statusitem-maincategory',
				'options'       => $options[ 'mainCategoryOptions' ],
			];
		}

		$fields = array_merge(
			$fields, [
			'status'          => [
				'type'             => 'select',
				'name'             => 'status',
				'options-messages' => [
					'ext-tm-status-all'          => '',
					'ext-tm-status-untranslated' => 'untranslated',
					'ext-tm-status-unsuggested'  => 'unsuggested',
					'ext-tm-status-progress'     => 'progress',
					'ext-tm-status-prereview'    => 'prereview',
					'ext-tm-status-review'       => 'review',
					'ext-tm-status-translated'   => 'translated',
					'ext-tm-status-irrelevant'   => 'irrelevant'
				],
				'label-message'    => 'ext-tm-statusitem-status'
			],
			'project'         => [
				'type'          => 'select',
				'name'          => 'project',
				'options'       => $options[ 'projectOptions' ],
				'label-message' => 'ext-tm-statusitem-project'
			],
			'translator'      => [
				'type'          => 'select',
				'name'          => 'translator',
				'options'       => $options[ 'translatorOptions' ],
				'label-message' => 'ext-tm-statusitem-translator'
			],
			'start_date_from' => [
				'label-message' => 'ext-tm-overview-filter-startdate-from',
				'type'          => 'date',
				'name'          => 'start_date_from'
			],
			'start_date_to'   => [
				'label-message' => 'ext-tm-overview-filter-startdate-to',
				'type'          => 'date',
				'name'          => 'start_date_to'
			],
			'end_date_from'   => [
				'label-message' => 'ext-tm-overview-filter-enddate-from',
				'type'          => 'date',
				'name'          => 'end_date_from'
			],
			'end_date_to'    => [
				'label-message' => 'ext-tm-overview-filter-enddate-to',
				'type'          => 'date',
				'name'          => 'end_date_to'
			],
			'pageviews'       => [
				'class'         => 'HTMLUnsignedIntField',
				'name'          => 'pageviews',
				'label-message' => 'ext-tm-overview-filter-pageviews',
			]
		] );

		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			$fields['article_type'] = [
				'type'          => 'select',
				'name'          => 'article_type',
				'label-message' => 'ext-tm-statusitem-articletype',
				'options'       => self::getArticleTypeOptions()
			];
		}

		$fields['limit'] = [
			'type' => 'select',
			'name' => 'limit',
			'label-message' => 'table_pager_limit_label',
			'options' => $this->pager->getLimitSelectList(),
			'default' => $this->pager->getLimit(),
		];

		return $fields;
	}

	/**
	 * @param string $valName
	 * @param string $end
	 *
	 * @return string|null
	 * @throws TimestampException
	 */
	private function timestampFromVal( string $valName, $end = false ): ?string {
		$val = $this->getRequest()->getVal( $valName );
		if ( !empty( $val ) ) {
			return TranslationManagerStatus::makeTimestampFromField( $val, $end )->getTimestamp( TS_MW );
			// return new DateTime( $val );
		}

		return null;
	}

	/**
	 * @param array $arr
	 *
	 * @return array|false
	 */
	private static function makeOptionsForSelect( array $arr ) {
		// Remove empty elements using array_fitler
		$arr = array_filter( $arr );
		return array_combine( $arr, $arr );
	}

	/**
	 * @param array $arr
	 *
	 * @return array|string[]
	 */
	private static function makeOptionsWithAllForSelect( array $arr ): array {
		// @todo i18n
		return [ 'הכל' => '' ] + self::makeOptionsForSelect( $arr );
	}

	/**
	 * @return array
	 */
	private static function getArticleTypeOptions(): array {
		return self::makeOptionsWithAllForSelect( ArticleType::getValidArticleTypes() );
	}

	/**
	 * @return HTMLForm
	 * @throws MWException
	 */
	private function getForm(): HTMLForm {
		$filterForm = HTMLForm::factory(
			'ooui',
			$this->getFormFields(),
			$this->getContext()
		);

		$filterForm->setId( 'mw-trans-status-filter-form' );
		$filterForm->setMethod( 'get' );
		$filterForm->suppressReset( false );
		$filterForm->prepareForm();

		return $filterForm;
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'pages';
	}
}
