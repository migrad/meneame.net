ALTER TABLE comments MODIFY COLUMN comment_type enum('normal','admin','private','rel') DEFAULT 'normal' NOT NULL;