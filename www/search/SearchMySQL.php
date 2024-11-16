<?php

namespace search;

require_once mnmpath.'/search/iSearch.php';

class SearchMySQL implements iSearch
{
    private $fields;
    private $from;
    private $where;
    private $order;

    public function search($by_date = false, $start = 0, $count = 50)
    {
        if ($_REQUEST['w'] === 'comments') {
            return $this->db_get_search_comments($by_date, $start, $count);
        }
        else {
            return $this->db_get_search_links($by_date, $start, $count);
        }
    }

    private function db_get_search_links($by_date = false, $start = 0, $count = 50)
    {
        global $db, $globals;
        // For now it serves for search specific blogs (used from most voted sites)

        $response = array();
        $start_time = microtime(true);
        $url = $db->escape($_REQUEST['q']);

        $this->from = 'links';

        switch ($_REQUEST['p']) {
            case 'url':
                $this->where = "match(link_url) against ('$url' IN BOOLEAN MODE)";
                break;
            case 'tags':
                $this->where = "match(link_tags) against ('$url' IN BOOLEAN MODE)";
                break;
            case 'title':
                $this->where = "match(link_title) against ('$url' IN BOOLEAN MODE)";
                break;
            case 'site':
                $site_ids = $db->get_col("select blog_id from blogs where blog_url like '%$url%'");

                if ($site_ids) {
                    $list = implode(',', $site_ids);
                    $this->where = "link_blog in ($list)";
                }
                break;
            default:
                if ($_REQUEST['w'] === 'links') $this->where = "link_content_type = 'text' and ";
                if ($_REQUEST['w'] === 'posts') $this->where = "link_content_type = 'article' and ";
                $this->where .= "match(link_tags,link_content,link_title,link_url) against ('$url' IN BOOLEAN MODE)";
        }

        if ($_REQUEST['s']) {
            $status = $db->escape($_REQUEST['s']);
            $this->where .= " and link_status = '$status'";
        }

        if ($_REQUEST['h']) {
            $hours = (int)$_REQUEST['h'];
            $this->where .= " and link_date > date_sub(now(), interval $hours hour)";
        }

        $this->order = "link_date desc";
        if ($_REQUEST['o'] === 'relevance') {
            $this->fields = ',' . $this->where . ' as score ';
            $this->order = 'score desc';
        }

        if ($this->where && $this->from) {
            $sql = "select link_id $this->fields from $this->from where $this->where order by $this->order limit $start,$count";
            $response['rows'] = $db->get_var("select count(*) from $this->from where $this->where");

            if ($response['rows'] > 0) {
                $response['ids'] = array();

                foreach ($db->get_col($sql) as $id) {
                    $response['ids'][] = $id;
                }
            }
        }

        $response['time'] = microtime(true) - $start_time;

        return $response;
    }

    private function db_get_search_comments($by_date = false, $start = 0, $count = 50)
    {
        global $db, $globals;
        // For now it serves for search specific blogs (used from most voted sites)

        $response = array();
        $start_time = microtime(true);
        $url = $db->escape($_REQUEST['q']);

        $this->from = 'comments';
        $this->where = "match(comment_content) against ('$url' IN BOOLEAN MODE)";

        if ($_REQUEST['h']) {
            $hours = (int)$_REQUEST['h'];
            $this->where .= " and link_date > date_sub(now(), interval $hours hour)";
        }

        $this->order = "comment_date desc";
        if ($_REQUEST['o'] === 'relevance') {
            $this->fields = ',' . $this->where . ' as score ';
            $this->order = 'score desc';
        }

        if ($this->where && $this->from) {
            $sql = "select comment_id $this->fields from $this->from where $this->where order by $this->order limit $start,$count";
            $response['rows'] = $db->get_var("select count(*) from $this->from where $this->where");
            if ($response['rows'] > 0) {
                $response['ids'] = array();

                foreach ($db->get_col($sql) as $id) {
                    $response['ids'][] = $id;
                }
            }
        }

        $response['time'] = microtime(true) - $start_time;

        return $response;
    }
}