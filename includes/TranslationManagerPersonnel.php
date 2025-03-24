<?php

namespace TranslationManager;

use MWException;
use stdClass;

class TranslationManagerPersonnel {
	public const TABLE_NAME = 'tm_personnel';
	/** @var int */
	private int $id;
	/** @var string */
	private string $name;
	/** @var ?array */
	private ?array $types;
	/** @var ?array */
	private ?array $languages;
	/** @var bool */
	private bool $isActive;

	/**
	 * @param int|null $id
	 * @throws MWException
	 */
	public function __construct( ?int $id = null ) {
		if ( $id !== null ) {
			$this->loadFromDatabase( $id );
		}
	}

	/**
	 * Create a new object from a database row
	 *
	 * @param stdClass $row Database row
	 * @return self
	 */
	public static function newFromRow( stdClass $row ): self {
		$personnel = new self();
		$personnel->id = $row->tmp_id;
		$personnel->name = $row->tmp_name;
		$personnel->types = json_decode( $row->tmp_types, true );
		$personnel->languages = json_decode( $row->tmp_languages, true );
		$personnel->isActive = (bool)$row->tmp_is_active;
		return $personnel;
	}

	/**
	 * Load personnel data from database
	 *
	 * @param int $id
	 * @return bool
	 * @throws MWException
	 */
	private function loadFromDatabase( int $id ): bool {
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow( self::TABLE_NAME, '*', [ 'tmp_id' => $id ] );

		if ( !$row ) {
			throw new MWException( "Personnel with ID $id not found" );
		}

		$personnel = self::newFromRow( $row );
		$this->id = $personnel->id;
		$this->name = $personnel->name;
		$this->types = $personnel->types;
		$this->languages = $personnel->languages;
		$this->isActive = $personnel->isActive;

		return true;
	}

	/**
	 * Save personnel data to database
	 *
	 * @return bool
	 */
	public function save(): bool {
		$dbw = wfGetDB( DB_PRIMARY );

		$data = [
			'tmp_name' => $this->name,
			'tmp_types' => json_encode( $this->types ),
			'tmp_languages' => json_encode( $this->languages ),
			'tmp_is_active' => (int)$this->isActive,
		];

		if ( isset( $this->id ) && $this->id ) {
			$dbw->update( self::TABLE_NAME, $data, [ 'tmp_id' => $this->id ] );
		} else {
			$dbw->insert( self::TABLE_NAME, $data );
			$this->id = $dbw->insertId();
		}

		return true;
	}

	/**
	 * @return int|null
	 */
	public function getId(): ?int {
		return $this->id;
	}

	/**
	 * @param string $name
	 * @return self
	 */
	public function setName( string $name ): self {
		$this->name = $name;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @param array $types
	 * @return self
	 */
	public function setTypes( array $types ): self {
		$this->types = $types;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getTypes(): ?array {
		return $this->types;
	}

	/**
	 * @param array $languages
	 * @return self
	 */
	public function setLanguages( array $languages ): self {
		$this->languages = $languages;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getLanguages(): ?array {
		return $this->languages;
	}

	/**
	 * @param bool $isActive
	 * @return $this
	 */
	public function setIsActive( bool $isActive ): self {
		$this->isActive = $isActive;
		return $this;
	}

	/**
	 * @return bool
	 */
	public function getIsActive(): bool {
		return $this->isActive;
	}

	/**
	 * Check if personnel is of a specific type
	 *
	 * @param string $type
	 * @return bool
	 */
	public function isType( string $type ): bool {
		return in_array( $type, $this->types );
	}

	/**
	 * Check if personnel handles a specific language
	 *
	 * @param string $language
	 * @return bool
	 */
	public function handlesLanguage( string $language ): bool {
		return in_array( $language, $this->languages );
	}

	/**
	 * Get all active translators for a language
	 *
	 * @param string $language
	 * @return TranslationManagerPersonnel[]
	 */
	public static function getActiveTranslatorsForLanguage( string $language ): array {
		return self::getPersonnelByTypeAndLanguage( 'translator', $language, true );
	}

	/**
	 * Get all active editors for a language
	 *
	 * @param string $language
	 * @return array
	 */
	public static function getActiveEditorsForLanguage( string $language ): array {
		return self::getPersonnelByTypeAndLanguage( 'editor', $language, true );
	}

	/**
	 * Get personnel by type and language
	 *
	 * @param string $type
	 * @param string $language
	 * @param bool $onlyActive
	 * @return array
	 */
	public static function getPersonnelByTypeAndLanguage(
		string $type, string $language, bool $onlyActive = false
	): array {
		$dbr = wfGetDB( DB_REPLICA );
		$conds = [];

		if ( $onlyActive ) {
			$conds['tmp_is_active'] = true;
		}

		$result = $dbr->select(
			self::TABLE_NAME,
			'*',
			$conds
		);

		$personnel = [];
		foreach ( $result as $row ) {
			$person = self::newFromRow( $row );
			if ( in_array( $type, $person->getTypes() ) && in_array( $language, $person->getLanguages() ) ) {
				$personnel[$person->getId()] = $person->getName();
			}
		}

		return $personnel;
	}

	/**
	 * Get all personnel
	 *
	 * @return self[]
	 */
	public static function getAllPersonnel(): array {
		$dbr = wfGetDB( DB_REPLICA );
		$result = $dbr->select(
			self::TABLE_NAME,
			'*',
			[]
		);

		$personnel = [];
		foreach ( $result as $row ) {
			$personnel[] = self::newFromRow( $row );
		}

		return $personnel;
	}
}
