<?php

use search\SearchFactory;

require_once mnmpath.'/search/SearchFactory.php';

function do_search($by_date = false, $start = 0, $count = 50, $proximity = true)
{
    global $globals;

    search_parse_query();

    $SearchFactory = new SearchFactory();
    $SearchObj = SearchFactory::instance($globals['search_type']);

    return $SearchObj->search($by_date, $start, $count);

    /*if ($_REQUEST['w'] === 'links' && ($_REQUEST['p'] === 'site' || $_REQUEST['p'] === 'url_db')) {
        return db_get_search_links($by_date, $start, $count);
    }

    return sphinx_do_search($by_date, $start, $count);*/
}

function search_parse_query()
{
    global $db;

    // Check what should be searched
    switch ($_REQUEST['w']) {
        case 'posts':
        case 'comments':
        case 'links':
            break;

        default:
            $_REQUEST['w'] = 'links';
    }

    $_REQUEST['words'] = $_REQUEST['q'] = trim(substr(strip_tags(stripslashes($_REQUEST['q'])), 0, 500));

    if (!empty($_REQUEST['p'])) {
        $_REQUEST['p'] = clean_input_url($_REQUEST['p']);
    } elseif (preg_match('/^ *(\w+): *(.*)/', $_REQUEST['q'], $matches)) {
        $_REQUEST['words'] = $matches[2];

        switch ($matches[1]) {
            case 'http':
            case 'https':
                $_REQUEST['words'] = $_REQUEST['q'];
                $_REQUEST['o'] = 'date';
                $_REQUEST['p'] = 'url';
                break;

            case 'date':
                $_REQUEST['o'] = 'date';
                break;

            case 'url':
                $_REQUEST['p'] = 'url';
                break;

            case 'title':
                $_REQUEST['p'] = 'title';
                break;

            case 'tag':
            case 'tags':
                $_REQUEST['p'] = 'tags';
                break;
        }
    }

    // Check filters and clean
    if (isset($_REQUEST['h'])) {
        $_REQUEST['h'] = (int)$_REQUEST['h'];
    }

    if (isset($_REQUEST['p']) && ! preg_match('/^(url|tags|title|site|url_db)$/', $_REQUEST['p'])) {
        unset($_REQUEST['p']);
    }

    if (isset($_REQUEST['o']) && ! preg_match('/^(date|relevance|pure)$/', $_REQUEST['o'])) {
        unset($_REQUEST['o']);
    }
}
