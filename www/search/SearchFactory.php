<?php

namespace search;

require_once mnmpath.'/search/SearchMySQL.php';
require_once mnmpath.'/search/SearchSphinx.php';
class SearchFactory
{
    public static function instance($searchType = 'mysql')
    {
        switch($searchType) {
            case 'mysql':
                return new SearchMySQL();
            case 'sphinx':
                return new SearchSphinx();
        }
    }
}