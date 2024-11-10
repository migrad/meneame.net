<?php
defined('mnminclude') or die();

if ($globals['bot']) {
    return Haanga::Load('user/empty.html');
}

$query = '
    FROM links, votes
    WHERE (
        vote_type = "links"
        AND vote_user_id = "'.(int)$user->id.'"
        AND link_content_type = "article"
        AND link_id = vote_link_id
        AND link_author != "'.(int)$user->id.'"
    )
';

$count = (int)$db->get_var('SELECT  COUNT(*) '.$query.';');

if ($count === 0) {
    return Haanga::Load('user/empty.html');
}

$sort = empty($_GET['sort']) ? 'date' : $_GET['sort'];

if ($sort === 'votes') {
    $order = 'link_votes DESC';
} elseif ($sort === 'karma') {
    $order = 'link_karma DESC';
} else {
    $order = 'vote_id DESC';
}

$links = $db->get_results('
    SELECT  link_id, vote_value
    '.$query.'
    ORDER BY '.$order.'
    LIMIT '.(int)$offset.', '.(int)$limit.';
');

if (empty($links)) {
    return Haanga::Load('user/empty.html');
}

Haanga::Load('user/sort_header.html', [
    'sort' => $sort
]);

foreach ($links as $linkdb) {
    $link = Link::from_db($linkdb->link_id);

    if ($linkdb->vote_value < 0) {
        echo '<div class="vote-negative-alert" style="display:inline;">';
        echo get_negative_vote($linkdb->vote_value);
        echo "</div>\n";
    }

    $link->print_summary('short', 0, false);
}

do_pages($count, $limit);

echo '<br/><span style="color: #FF6400;"><strong>' . _('Nota') . '</strong>: ' . _('sólo se visualizan los votos de los últimos meses') . '</span><br />';
