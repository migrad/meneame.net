<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005-2010 by
// Ricardo Galli <gallir at uib dot es>.
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
//         http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called 'COPYING'.

require_once mnmpath.'/../vendor/autoload.php';

global $globals;

$globals['start_time'] = microtime(true);
$globals['now'] = (int)$globals['start_time'];

register_shutdown_function('shutdown');

if (isset($globals['max_load']) && $globals['max_load'] > 0) {
    check_load($globals['max_load']);
}

// we don't force https if the server name is not the same as de requested host from the client
if (!empty($globals['force_ssl']) && getenv('SERVER_NAME') !== getenv('HTTP_HOST')) {
    $globals['force_ssl'] = false;
}

if ($globals['cli']) {
    $globals['https'] = false;
    $globals['scheme'] = 'http:';
    $globals['user_ip'] = false;
    $globals['proxy_ip'] = false;
    $globals['uri'] = false;
} else {
    if (getenv('HTTP_X_FORWARDED_PROTO') === 'https' || getenv('SERVER_PORT') == 443 || getenv('HTTPS') === 'on') {
        $globals['https'] = true;
        $globals['scheme'] = 'https:';
    } else {
        $globals['https'] = false;

        if (!empty($globals['force_ssl'])) {
            $globals['scheme'] = 'https:';
        } else {
            $globals['scheme'] = 'http:';
        }
    }

    if ($globals['check_behind_proxy']) {
        $globals['proxy_ip'] = getenv('REMOTE_ADDR');
        $globals['user_ip'] = check_ip_behind_proxy();
    } elseif ($globals['behind_load_balancer']) {
        $globals['proxy_ip'] = getenv('REMOTE_ADDR');
        $globals['user_ip'] = check_ip_behind_load_balancer();
    } else {
        $globals['user_ip'] = getenv('REMOTE_ADDR');
        $globals['proxy_ip'] = false;
    }

    $globals['uri'] = preg_replace('/[<>\r\n]/', '', urldecode(getenv('REQUEST_URI')));
    $globals['is_ajax'] = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && (strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
}

// Use proxy and load balancer detection

$globals['user_ip_int'] = inet_ptod($globals['user_ip']);
$globals['cache-control'] = array();

if (($globals['cli'] === false) && getenv('HTTP_HOST')) {
    // Check bots
    if (
        !getenv('HTTP_USER_AGENT')
        || preg_match('/(spider|httpclient|bot|slurp|wget|libwww|\Wphp|wordpress|joedog|facebookexternalhit|squider)[\W\s0-9]/i', getenv('HTTP_USER_AGENT'))
    ) {
        $globals['bot'] = true;
    } else {
        $globals['bot'] = false;
    }

    // Check mobile/TV versions
    if (
        !$globals['bot']
        && isset($_GET['mobile']) || preg_match('/SymbianOS|BlackBerry|iPhone|Nintendo|Mobile|Opera (Mini|Mobi)|\/MIDP|Portable|webOS|Kindle|Fennec/i', getenv('HTTP_USER_AGENT'))
        && !preg_match('/ipad|tablet/i', getenv('HTTP_USER_AGENT'))
    ) {
        // Don't treat iPad as mobiles
        $globals['mobile'] = 1;
    } else {
        $globals['mobile'] = 0;
    }

    // Fill server names
    // Alert, if does not work with port 443, in order to avoid standard HTTP connections to SSL port
    if (empty($globals['server_name'])) {
        $globals['server_name'] = strtolower(getenv('SERVER_NAME'));

        if (getenv('SERVER_PORT') != 80 && getenv('SERVER_PORT') != 443) {
            $globals['server_name'] .= ':'.getenv('SERVER_PORT');
        }
    }
} elseif (empty($globals['server_name'])) {
    $globals['server_name'] = 'meneame.net'; // Warn: did you put the right server name?
}

$globals['base_url_general'] = $globals['base_url']; // Keep the original if it's modified in submnms

// Add always the scheme, it's necessary for headers and rss's
$globals['base_static_noversion'] = $globals['scheme'].'//';

if (empty($globals['static_server'])) {
    $globals['base_static_noversion'] .= $globals['server_name'].$globals['base_url'];
} else {
    $globals['base_static_noversion'] .= $globals['static_server'].$globals['base_url'];
}

$globals['base_static'] = $globals['base_static_noversion'].'v_'.$globals['v'].'/';

// Votes' tags
$globals['negative_votes_values'] = array(-1 => _('irrelevante'), -2 => _('antigua'), -3 => _('cansina'), -4 => _('sensacionalista'), -5 => _('spam'), -6 => _('duplicada'), -7 => _('microblogging'), -8 => _('errónea'), -9 => _('copia/plagio'));

// Allows a script to define to use the alternate server
if (isset($globals['alternate_db_server']) && !empty($globals['alternate_db_servers'][$globals['alternate_db_server']])) {
    $db = new RGDB($globals['db_user'], $globals['db_password'], $globals['db_name'], $globals['alternate_db_servers'][$globals['alternate_db_server']], true);
} else {
    $db = new RGDB($globals['db_user'], $globals['db_password'], $globals['db_name'], $globals['db_server'], true);
    $db->persistent = $globals['mysql_persistent'];
}

show_errors($globals['debug'] ? true : false);

function haanga_bootstrap()
{
    /* bootstrap function, load our custom tags/filter */
    require mnminclude.'haanga_mnm.php';
}

/* Load template engine here */
$config = array(
    'template_dir' => mnmpath.'/'.$globals['haanga_templates'],
    'autoload' => true,
    'bootstrap' => 'haanga_bootstrap',
    'compiler' => array( /* opts for the tpl compiler */
        /* Avoid use if empty($var) */
        'if_empty' => false,
        /* we're smart enought to know when escape :-) */
        'autoescape' => false,
        /* let's save bandwidth */
        'strip_whitespace' => true,
        /* call php functions from the template */
        'allow_exec' => true,
        /* global $global, $current_user for all templates */
        'global' => array('globals', 'current_user'),
    ),
    'use_hash_filename' => false, /* don't use hash filename for generated php */
);

// Allow full or relative pathname for the cache (i.e. /var/tmp or cache)
if (strpos($globals['haanga_cache'], '/') === 0) {
    $config['cache_dir'] = $globals['haanga_cache'].'/Haanga/'.getenv('SERVER_NAME');
} else {
    $config['cache_dir'] = mnmpath.'/'.$globals['haanga_cache'].'/Haanga/'.getenv('SERVER_NAME');
}

Haanga::configure($config);
Haanga::checkCacheDir();

function __($text)
{
    if (func_num_args() === 1) {
        return $text;
    }

    $args = array_slice(func_get_args(), 1);

    return is_array($args[0]) ? strtr($text, $args[0]) : vsprintf($text, $args);
}

function _e($text)
{
    echo __($text);
}

function shutdown()
{
    global $globals, $current_user, $db;

    close_connection();

    if (is_object($db) && $db->connected) {
        Link::store_clicks(); // It will check cache and increment link clicks counter
        $db->close();
    }

    if (empty($globals['access_log']) || empty($globals['user_ip'])) {
        return;
    }

    if (!empty($globals['script'])) {
        $script = $globals['script'];
    } elseif (!getenv('SCRIPT_NAME')) {
        $script = 'null('.urlencode($_SERVER['DOCUMENT_URI']).')';
    } else {
        $script = getenv('SCRIPT_NAME');
    }

    if (!empty($globals['ip_blocked'])) {
        $user = 'B'; // IP is banned
    } elseif ($current_user->user_id > 0) {
        $user = $current_user->user_login;
    } else {
        $user = '-';
    }

    if ($globals['start_time'] > 0) {
        $time = sprintf('%5.3f', microtime(true) - $globals['start_time']);
    } else {
        $time = 0;
    }

    @syslog(LOG_DEBUG, $globals['user_ip'].' '.$user.' '.$time.' '.get_server_name().' '.$script);

    exit;
}
