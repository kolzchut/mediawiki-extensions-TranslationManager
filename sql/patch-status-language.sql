-- Update to store translation language

-- Add a word count field to tm_status
ALTER TABLE /*_*/tm_status ADD tms_lang varbinary(35) NOT NULL;
-- Because it used to only support Arabic:
UPDATE /*_*/tm_status SET tms_lang = 'ar' WHERE tms_lang = '';
-- Add a unique contraint
ALTER TABLE /*_*/tm_status DROP PRIMARY KEY, ADD PRIMARY KEY (tms_page_id, tms_lang)
