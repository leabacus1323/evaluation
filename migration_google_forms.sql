-- Run only if you already installed an older version of the Evaluation System.
-- The updated admin Google Forms page can also add these columns automatically.
ALTER TABLE events
  ADD COLUMN google_form_id VARCHAR(180) NULL,
  ADD COLUMN google_form_url TEXT NULL,
  ADD COLUMN google_form_edit_url TEXT NULL,
  ADD COLUMN google_form_summary_url TEXT NULL,
  ADD COLUMN google_sheet_id VARCHAR(180) NULL,
  ADD COLUMN google_sheet_url TEXT NULL,
  ADD COLUMN google_generated_at DATETIME NULL;
