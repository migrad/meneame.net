<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Ricardo Galli <gallir at uib dot es>.
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
//      http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

defined('mnminclude') or die();

$link->randkey = rand(10000, 10000000);
$link->url = empty($_REQUEST['url']) ? '' : clean_input_url($_REQUEST['url']);

if ($_POST) {
    require __DIR__.'/step-1-post.php';
}

do_header(_('Enviar historia') . ' 1/3', _('Enviar historia'));

Haanga::Load('story/submit/step-1.html', array(
    'site_properties' => $site_properties,
    'link' => $link,
    'error' => $error,
    'warning' => $warning
));
