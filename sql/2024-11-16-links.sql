ALTER TABLE links DROP INDEX link_url;
CREATE FULLTEXT INDEX link_url ON links (link_url);
ALTER TABLE links ADD FULLTEXT INDEX link_title (link_title);
ALTER TABLE links ADD FULLTEXT INDEX link_content (link_content);
ALTER TABLE links ADD FULLTEXT INDEX link_tags (link_tags);
ALTER TABLE links ADD FULLTEXT INDEX text_fields (link_tags, link_content, link_title, link_url);
