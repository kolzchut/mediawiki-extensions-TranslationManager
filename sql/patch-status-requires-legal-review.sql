-- Add the requires_legal_review field to tms_status table
ALTER TABLE /*_*/tm_status
	ADD COLUMN tms_requires_legal_review ENUM('not_required', 'required', 'completed') DEFAULT 'not_required';
