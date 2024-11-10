<?php
defined('mnminclude') or die();

$query = '
    FROM posts, favorites
    WHERE (
        favorite_user_id = "'.(int)$user->id.'"
        AND favorite_type = "post"
        AND post_id = favorite_link_id
    )
';

$count = $db->get_var('SELECT  COUNT(*) '.$query.';');

if ($count === 0) {
    return Haanga::Load('user/empty.html');
}

$sort = empty($_GET['sort']) ? 'date' : $_GET['sort'];

if ($sort === 'votes') {
    $order = 'post_votes DESC';
} elseif ($sort === 'karma') {
    $order = 'post_karma DESC';
} else {
    $order = 'post_id DESC';
}

$posts = $db->get_results('
    SELECT '.Post::SQL.'
    INNER JOIN (
        SELECT post_id
        '.$query.'
        ORDER BY '.$order.'
        LIMIT '.$offset.', '.$limit.'
    ) AS `id`
    USING (post_id);
', 'Post');

require __DIR__ . '/notes-common.php';
