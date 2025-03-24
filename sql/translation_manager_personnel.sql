CREATE TABLE /*_*/tm_personnel (
	tmp_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	tmp_name VARCHAR(255) NOT NULL,
	tmp_types VARCHAR(255) NOT NULL COMMENT 'JSON array of types ("translator", "editor")',
	tmp_languages VARCHAR(255) NOT NULL COMMENT 'JSON array of language codes',
	tmp_is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Boolean: 1=Active, 0=Inactive',
	UNIQUE KEY (tmp_name)
) /*$wgDBTableOptions*/;
