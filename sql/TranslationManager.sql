-- MySQL version of the database schema for the Translation Manager extension.
-- Licence: GNU GPL v2+

-- Project status
CREATE TABLE IF NOT EXISTS /*_*/tm_status (
  tms_page_id INT unsigned NOT NULL PRIMARY KEY,
  tms_status varchar(255) binary,
  tms_translator varchar(255) binary,
  tms_comments varbinary(767),
  tms_suggested_name varchar(255) binary,
  tms_project varchar(255) binary,
    -- Number of pageviews, imported from an Analytics system
  tms_pageviews INT UNSIGNED,
  tms_main_category varchar(255) binary,
  tms_wordcount INT UNSIGNED,
  tms_start_date BINARY(14) DEFAULT NULL, -- start date of the translation
  tms_end_date BINARY(14) DEFAULT NULL, -- end date of the translation
  page_lang varbinary(20) NOT NULL,
  CONSTRAINT tms_page_id_tms_lang UNIQUE KEY (tms_page_id, tms_lang)
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/tms_suggested_name ON /*_*/tm_status (tms_suggested_name);
CREATE INDEX /*i*/tms_status ON /*_*/tm_status (tms_status);
CREATE INDEX /*i*/tms_translator ON /*_*/tm_status (tms_translator);
CREATE INDEX /*i*/tms_project ON /*_*/tm_status (tms_project);
CREATE INDEX /*i*/tms_pageviews ON /*_*/tm_status (tms_pageviews);
CREATE INDEX /*i*/tms_main_category ON /*_*/tm_status (tms_main_category);



