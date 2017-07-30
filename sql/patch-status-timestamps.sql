-- Add fields for start/end translation timestamps

-- Add a word count field to tm_status
ALTER TABLE /*_*/tm_status ADD (
  `tms_start_date` BINARY(14) DEFAULT NULL, -- start date of the translation
  `tms_end_date` BINARY(14) DEFAULT NULL -- end date of the translation
);
