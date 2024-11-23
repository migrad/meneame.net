<?php
// The source code packaged with this file is Free Software, Copyright (C) 2005 by
// Ricardo Galli <gallir at uib dot es>.
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
//      http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

class Comment extends LCPBase
{
    public $id = 0;
    public $prefix_id = '';
    public $randkey = 0;
    public $author = 0;
    public $link = 0;
    public $date = false;
    public $order = 0;
    public $votes = 0;
    public $voted = false;
    public $karma = 0;
    public $content = '';
    public $read = false;
    public $ip = '';
    public $type = '';
    public $link_object = null;
    public $strike = null;

    const SQL = " SQL_NO_CACHE comment_id as id, comment_type as type, comment_user_id as author, user_login as username, user_email as email, user_karma as user_karma, user_level as user_level, comment_randkey as randkey, comment_link_id as link, comment_order as `order`, comment_votes as votes, comment_karma as karma, comment_ip_int as ip_int, comment_ip as ip, user_avatar as avatar, comment_content as content, UNIX_TIMESTAMP(comment_date) as date, UNIX_TIMESTAMP(comment_modified) as modified, favorite_link_id as favorite, vote_value as voted, media.size as media_size, media.mime as media_mime, media.extension as media_extension, media.access as media_access, UNIX_TIMESTAMP(media.date) as media_date, 1 as `read` FROM comments
    INNER JOIN users on (user_id = comment_user_id)
    LEFT JOIN favorites ON (@user_id > 0 and favorite_user_id =  @user_id and favorite_type = 'comment' and favorite_link_id = comment_id)
    LEFT JOIN votes ON (comment_date > @enabled_votes and @user_id > 0 and vote_type='comments' and vote_link_id = comment_id and vote_user_id = @user_id)
    LEFT JOIN media ON (media.type='comment' and media.id = comment_id and media.version = 0) ";

    const SQL_BASIC = " SQL_NO_CACHE comment_id as id, comment_type as type, comment_user_id as author, comment_randkey as randkey, comment_link_id as link, comment_order as `order`, comment_votes as votes, comment_karma as karma, comment_ip_int as ip_int, comment_ip as ip, UNIX_TIMESTAMP(comment_date) as date, UNIX_TIMESTAMP(comment_modified) as modified, 1 as `read` FROM comments ";

    public static function from_db($id)
    {
        global $db;

        return $db->get_object('
            SELECT '.Comment::SQL.'
            WHERE comment_id = "'.(int)$id.'";
        ', 'Comment');
    }

    public static function from_ids(array $ids)
    {
        global $db;

        $ids = DbHelper::implodedIds($ids);

        return $db->get_results('
            SELECT '.Comment::SQL.'
            WHERE `comment_id` IN ('.$ids.')
            ORDER BY FIELD (`comment_id`, '.$ids.') ASC;
        ', 'Comment');
    }

    public static function update_read_conversation($time = false)
    {
        global $db, $globals, $current_user;
        $key = 'c_last_read';

        if (!$current_user->user_id) {
            return false;
        }

        if (!$time) {
            $time = $globals['now'];
        }
        $previous = (int) $db->get_var("select pref_value from prefs where pref_user_id = $current_user->user_id and pref_key = '$key'");
        if ($time > $previous) {
            $r = $db->query("delete from prefs where pref_user_id = $current_user->user_id and pref_key = '$key'");
            if ($r) {
                $db->query("insert into prefs set pref_user_id = $current_user->user_id, pref_key = '$key', pref_value = $time");
            }
        }
        return User::reset_notification($current_user->user_id, 'comment');
    }

    public static function get_unread_conversations($user = 0)
    {
        global $db, $globals, $current_user;

        $n = User::get_notification($user, 'comment');
        if (is_null($n)) {
            $key = 'c_last_read';
            if (!$user && $current_user->user_id > 0) {
                $user = $current_user->user_id;
            }
            $last_read = intval($db->get_var("select pref_value from prefs where pref_user_id = $user and pref_key = '$key'"));
            $n = (int) $db->get_var("select count(*) from conversations where conversation_user_to = $user and conversation_type = 'comment' and conversation_time > FROM_UNIXTIME($last_read)");
            User::reset_notification($user, 'comment', $n);
        }
        return $n;
    }

    // Print the comments recursively, $tree is a CommentTree instance
    public static function print_tree($tree, $link = null, $length = 0, $sort_roots = null, $initial_level = 0, array $strikes = [])
    {
        global $db;

        if (empty($tree->rootsIds)) {
            return;
        }

        $ids = implode(',', array_keys($tree->nodesIds));
        $sql = "SELECT".Comment::SQL."WHERE comment_id in ($ids)";
        $comments = $db->get_results($sql, "Comment", 'id');

        if ($sort_roots) {
            ksort($tree->rootsIds);
        }

        $seen = array();
        $traverse = function ($node, $level) use (&$comments, $link, &$seen, $length, &$traverse, $strikes) {
            if (isset($seen[$node->id]) || !isset($comments[$node->id])) {
                return;
            }

            $seen[$node->id] = true;
            $comment = $comments[$node->id];

            if ($level == 0 || $level > 6) {
                echo '<div class="threader zero">';
            } else {
                echo '<div class="threader">';
            }

            echo '<div class="expandable-mark"></div>';

            if ($link && $length && ($link->page_mode === 'interview') && $comment->author == $link->author) {
                $len = $length * 4;
            } else {
                $len = $length;
            }

            $comment->thread_level = $level;
            $comment->link_object = $link;
            $comment->setStrikeByIds($strikes);
            $comment->print_summary($len, !empty($link));

            if ($node->children) {
                echo '<div class="threader-childs">';

                foreach ($node->children as $child) {
                    $traverse($child, $level + 1);
                }

                echo '</div>';
            }

            echo '</div>';

            return;
        };

        foreach ($tree->rootsIds as $id => $node) {
            $traverse($node, $initial_level);
        }
    }

    public function store($full = true)
    {
        global $db, $current_user, $globals;

        if (!$this->date) {
            $this->date = $globals['now'];
        }

        $comment_content = $db->escape($this->normalize_content());

        if ($this->type === 'admin') {
            $comment_type = 'admin';
        } elseif ($this->type === '') {
            $comment_type = 'normal';
        }
        else {
            $comment_type = $this->type;
        }

        $db->transaction();

        if ($this->id === 0) {
            $this->ip = $db->escape($globals['user_ip']);
            $this->ip_int = $db->escape($globals['user_ip_int']);

            $previous = $db->get_var("select count(*) from comments where comment_link_id=$this->link FOR UPDATE");

            if (!$previous > 0 && $previous !== '0') {
                syslog(LOG_INFO, "Failed to assign order to comment $this->id in insert");
                $this->order = 0;
            } else {
                $this->order = (int)$previous + 1;
            }

            $r = $db->query("INSERT INTO comments (comment_user_id, comment_link_id, comment_type, comment_karma, comment_ip_int, comment_ip, comment_date, comment_randkey, comment_content, comment_order) VALUES ($this->author, $this->link, '$comment_type', $this->karma, $this->ip_int, '$this->ip', FROM_UNIXTIME($this->date), $this->randkey, '$comment_content', $this->order)");
            $new_id = $db->insert_id;

            if ($r) {
                $this->id = $new_id;

                // Insert comment_new event into logs
                if ($full) {
                    Log::insert('comment_new', $this->id, $current_user->user_id);
                }
            }
        } else {
            $r = $db->query("UPDATE comments set comment_user_id=$this->author, comment_link_id=$this->link, comment_type='$comment_type', comment_karma=$this->karma, comment_date=FROM_UNIXTIME($this->date), comment_modified=now(), comment_randkey=$this->randkey, comment_content='$comment_content' WHERE comment_id=$this->id");

            // Insert comment_new event into logs
            if ($r && $full) {
                if ($globals['now'] - $this->date < 86400) {
                    Log::conditional_insert('comment_edit', $this->id, $current_user->user_id, 60);
                }

                $this->update_order();
            }
        }

        if (!$r) {
            syslog(LOG_INFO, "Error storing comment $this->id");
            $db->rollback();
            return false;
        }

        if ($full) {
            $this->update_conversation();
        }

        // Check we got a good order value
        if (!$this->order) {
            syslog(LOG_INFO, "Trying to assign order to comment $this->id after commit");
            $this->update_order();
        }

        $db->commit();

        return true;
    }

    public function update_order()
    {
        global $db;

        if ($this->id == 0 || $this->link == 0) {
            return false;
        }

        $order = (int)$db->get_var("select count(*) from comments where comment_link_id=$this->link and comment_id <= $this->id FOR UPDATE");
        if (!$order) {
            syslog(LOG_INFO, "Failed to get order in update_order for $this->id, old value $this->order");
            return false;
        }
        if ($order != $this->order) {
            $db->query("update comments set comment_order=$order where comment_id=$this->id");
            $rows = $db->affected_rows;
            if ($rows > 0) {
                syslog(LOG_INFO, "Fixing order for $this->id, $this->order -> $order");
            }
            $this->order = $order;
        }
        return $this->order;
    }

    public function read()
    {
        global $db, $current_user;
        if (($result = $db->get_row("SELECT".Comment::SQL."WHERE comment_id = $this->id"))) {
            foreach (get_object_vars($result) as $var => $value) {
                $this->$var = $value;
            }
            return true;
        }
        $this->read = false;
        return false;
    }

    public function read_basic()
    {
        return $this->read();
    }

    public function check_visibility()
    {
        global $globals, $current_user;

        $this->ignored = ($current_user->user_id > 0)
            && ($this->type !== 'admin')
            && (User::friend_exists($current_user->user_id, $this->author) < 0);

        $this->hidden = ($globals['comment_hidden_karma'] < 0 && $this->karma < $globals['comment_hidden_karma'])
            || ($this->user_level === 'disabled' && $this->type !== 'admin');

        $this->hide_comment = !isset($this->not_ignored) && ($this->ignored || ($this->hidden && ($current_user->user_comment_pref & 1) == 0));
    }

    public function prepare_summary_text($length = 0)
    {
        global $globals, $current_user;

        if ($this->single_link) {
            $this->html_id = $this->order;
        } else {
            $this->html_id = $this->id;
        }

        $this->can_edit = (!isset($this->basic_summary) || !$this->basic_summary) && (($this->author == $current_user->user_id && $globals['now'] - $this->date < $globals['comment_edit_time']) || (($this->author != $current_user->user_id || $this->type === 'admin') && $current_user->user_level === 'god'));

        if ($length > 0) {
            $this->truncate($length);
        }

        $this->txt_content = $this->to_html($this->content);

        if ($this->media_size > 0) {
            $this->media_thumb_dir = Upload::get_cache_relative_dir($this->id);
            $this->media_url = Upload::get_url('comment', $this->id, 0, $this->media_date, $this->media_mime);
        }
    }

    public function print_summary($length = 0, $single_link = true, $return_string = false)
    {
        global $current_user, $globals;

        if (!$this->read) {
            return;
        }

        if ((!$this->link_object || $this->link_object->id != $this->link) && $this->link > 0) {
            $this->link_object = Link::from_db($this->link);
        }

        $link = $this->link_object;

        if ($link) {
            if (!empty($link->relative_permalink)) {
                $this->link_permalink = $link->relative_permalink;
            } else {
                $this->link_permalink = $link->get_relative_permalink();
            }
        } elseif (!empty($globals['permalink'])) {
            $this->link_permalink = $globals['permalink'];
        }

        $this->single_link = $single_link;

        $this->check_visibility();

        $this->css_class = 'comment';
        $this->css_class_body = 'comment-body';
        $this->css_class_header = 'comment-header';
        $this->css_class_text = 'comment-text';
        $this->css_class_footer = 'comment-footer';

        $negative = ($this->votes > 8 && $this->karma < 0);

        if (($negative || $this->strike || $this->hidden || $this->ignored) && !$link->is_sponsored()) {
            $this->css_class_footer .= ' phantom';
            $this->css_class .= ' phantom';

            if ($negative) {
                $this->css_class_footer .= ' negative';
                $this->css_class .= ' negative';
            }

            if (($this->strike || $this->ignored) && !$current_user->admin) {
                $this->css_class_footer .= ' ignored';
                $this->css_class .= ' ignored';
            }

            if ($this->strike) {
                $this->css_class_footer .= ' strike';
                $this->css_class .= ' strike';
            }
        } elseif ($this->type === 'admin') {
            $this->css_class .= ' admin';
        }
        elseif ($this->type === 'rel') {
            $this->css_class .= ' rel';
        } else {
            if ($globals['comment_highlight_karma'] > 0 && $this->karma > $globals['comment_highlight_karma']) {
                $this->css_class .= ' high';
            }

            if ($link && $link->author == $this->author) {
                $this->css_class .= ' author';
            }
        }

        if ($this->author == $current_user->user_id) {
            $this->css_class .= ' user';
        }

        $this->prepare_summary_text($length);

        $this->can_vote = empty($this->strike)
            && ($current_user->user_id > 0)
            && ($this->author != $current_user->user_id)
            && ($this->date > ($globals['now'] - $globals['time_enabled_comments']))
            && ($this->user_level !== 'disabled');

        $this->user_can_vote = $current_user->user_karma > $globals['min_karma_for_comment_votes'] && !$this->voted;
        $this->modified_time = txt_time_diff($this->date, $this->modified);

        $this->has_votes_info = $this->votes > 0 && $this->date > $globals['now'] - 30 * 86400; // Show votes if newer than 30 days

        if ($link && ($this->can_reply = $link->comments_allowed())) {
            $this->can_reply = $current_user->user_id > 0 && $this->date > $globals['now'] - $globals['time_enabled_comments'];
        }

        if ($this->type === 'rel') $this->can_reply = false;

        $this->can_report = $this->can_reply
            && Report::check_min_karma()
            && ($this->author != $current_user->user_id)
            && ($this->type !== 'admin')
            && !$this->ignored
            && !$link->is_sponsored();

        return Haanga::Load('comment_summary.html', array('self' => $this), $return_string);
    }

    public function vote_exists()
    {
        global $current_user;

        $vote = new Vote('comments', $this->id, $current_user->user_id);

        return $this->voted = $vote->exists(false);
    }

    public function insert_vote($value = 0)
    {
        global $current_user, $db;

        if (!$value) {
            $value = $current_user->user_karma;
        }

        $vote = new Vote('comments', $this->id, $current_user->user_id);

        // Affinity
        if ($current_user->user_id != $this->author && ($affinity = User::get_affinity($this->author, $current_user->user_id))) {
            if ($value < -1 && $affinity < 0) {
                $value = round(min(-1, $value * abs($affinity / 100)));
            } elseif ($value > 1 && $affinity > 0) {
                $value = round(max($value * $affinity / 100, 1));
            }
        }

        if ($vote->exists(true)) {
            return false;
        }

        $vote->value = $value;
        $db->transaction();

        if (($r = $vote->insert()) && ($current_user->user_id != $this->author)) {
            $r = $db->query("update comments set comment_votes=comment_votes+1, comment_karma=comment_karma+$value, comment_date=comment_date where comment_id=$this->id");
        }

        if ($r && $db->commit()) {
            return $vote->value;
        }

        syslog(LOG_INFO, "failed insert comment vote for $this->id");

        return false;
    }

    public function print_text($length = 0)
    {
        global $current_user, $globals;

        $this->prepare_summary_text($length);

        return Haanga::Load('comment_summary_text.html', array('self' => $this));
    }

    public function username()
    {
        global $db;
//TODO
        return $this->username = $db->get_var("SELECT  user_login FROM users WHERE user_id = $this->author");
    }

    // Add calls for tooltip javascript functions
    public function put_comment_tooltips(&$str)
    {
        return preg_replace('/(^|[\(,;\.\s¿¡])#([1-9][0-9]*)/', "$1<a class='tooltip c:$this->link-$2' href=\"".$this->link_permalink."/c0$2#c-$2\" rel=\"nofollow\">#$2</a>", $str);
    }

    public function same_text_count($min = 30)
    {
        global $db;

        // WARNING: $db->escape(clean_lines($comment->content)) should be the sama as in libs/comment.php (unify both!)
        return (int) $db->get_var("select count(*) from comments where comment_user_id = $this->author  and comment_date > date_sub(now(), interval $min minute) and comment_content = '".$db->escape(clean_lines($this->content))."'");
    }

    public function get_links()
    {
        global $current_user;

        $this->links = array();
        $this->banned = false;

        $localdomain = preg_quote(get_server_name(), '/');
        preg_match_all('/([\(\[:\.\s]|^)(https*:\/\/[^ \t\n\r\]\(\)\&]{5,70}[^ \t\n\r\]\(\)]*[^ .\t,\n\r\(\)\"\'\]\?])/i', $this->content, $matches);
        foreach ($matches[2] as $match) {
            require_once mnminclude.'ban.php';

            $link = clean_input_url($match);
            $components = parse_url($link);

            if ($components && !preg_match("/.*$localdomain$/", $components['host'])) {
                $link_ban = check_ban($link, 'hostname', false, true); // Mark this comment as containing a banned link

                $this->banned |= $link_ban;

                if ($link_ban) {
                    syslog(LOG_NOTICE, "Meneame: banned link in comment: $match ($current_user->user_login)");
                }

                if (array_search($components['host'], $this->links) === false) {
                    $this->links[] = $components['host'];
                }
            }
        }
    }

    public function same_links_count($min = 30)
    {
        global $db, $current_user;

        if ($this->id > 0) {
            $not_me = "and comment_id != $this->id";
        } else {
            $not_me = '';
        }

        $count = 0;
        $localdomain = preg_quote(get_server_name(), '/');

        foreach ($this->links as $host) {
            if ($this->banned) {
                $interval = $min * 2;
            } elseif (preg_match("/.*$localdomain$/", $host)) {
                $interval = $min / 3;
            } else {
                // For those pointing to dupes
                $interval = $min;
            }

            $link = '://'.$host;
            $link = preg_replace('/([_%])/', "\$1", $link);
            $link = $db->escape($link);
            $same_count = (int) $db->get_var("select count(*) from comments where comment_user_id = $this->author and comment_date > date_sub(now(), interval $interval minute) and comment_content like '%$link%' $not_me");
            $count = max($count, $same_count);
        }

        return $count;
    }

    // Static function to print comment form
    public static function print_form($link, $rows = 5)
    {
        global $current_user, $globals;

        if (!$link->votes > 0) {
            return;
        }

        $comment = new Comment(); // Foo comment
        $comment->randkey = rand(1000000, 100000000);

        if (!$link || !$link->comments_allowed()) {
            // Comments already closed
            echo '<div class="commentform warn">'."\n";
            echo _('comentarios cerrados')."\n";
            echo '</div>'."\n";
        } elseif (
            $current_user->authenticated
            && (
                $current_user->user_id == $link->author
                || (
                    $current_user->user_karma >= $globals['min_karma_for_comments']
                    && $current_user->user_date < $globals['now'] - $globals['min_time_for_comments']
                )
            )
        ) {
            // User can comment
            echo '<div class="commentform">'."\n";
            echo '<form action="" method="post" enctype="multipart/form-data" class="comment">';

            echo '<input type="hidden" name="process" value="newcomment" />';
            echo '<input type="hidden" name="randkey" value="'.$comment->randkey.'" />';

            echo '<fieldset>'."\n";
            echo '<legend>'._('envía un comentario').'</legend>';
            $vars = compact('link', 'comment');
            Haanga::Load('comment_edit.html', $vars);

            echo '<div class="note" style="margin-top:10px">'._('comentarios xenófobos, racistas o difamatorios causarán la anulación de la cuenta').'</div>';

            echo '</fieldset>'."\n";
            echo '</form>'."\n";
            echo "</div>\n";
        } else {
            // Not enough karma or anonymous user
            if ($tab_option == 1) {
                do_comment_pages($link->comments, $current_page);
            }

            if ($current_user->authenticated) {
                if ($current_user->user_date >= $globals['now'] - $globals['min_time_for_comments']) {
                    $remaining = txt_time_diff($globals['now'], $current_user->user_date + $globals['min_time_for_comments']);
                    $msg = _('debes esperar')." $remaining "._('para escribir el primer comentario');
                }
                if ($current_user->user_karma < $globals['min_karma_for_comments']) {
                    $msg = _('no tienes el mínimo karma requerido')." (".$globals['min_karma_for_comments'].") "._('para comentar').": ".$current_user->user_karma;
                }
                echo '<div class="commentform warn">'."\n";
                echo $msg."\n";
                echo '</div>'."\n";
            } elseif (!$globals['bot']) {
                echo '<div class="commentform warn">'."\n";
                echo '<a href="'.get_auth_link().'login.php?return='.urlencode($globals['uri']).'">'._('Autentifícate si deseas escribir').'</a> '._('comentarios').'. '._('O crea tu cuenta').' <a href="'.$globals['base_url'].'register.php">aquí.</a>'."\n";
                echo '</div>'."\n";

                print_oauth_icons();
            }
        }
    }

    public static function save_from_post($link, $redirect = true)
    {
        global $db, $current_user, $globals;

        require_once mnminclude.'ban.php';

        if (check_ban_proxy()) {
            return _('dirección IP no permitida');
        }

        // Check if is a POST of a comment

        if (!(
            ($link->votes > 0)
            && ($link->date > ($globals['now'] - $globals['time_enabled_comments'] * 1.01))
            && ($link->comments < $globals['max_comments'])
            && ((int)$_POST['link_id'] == $link->id)
            && $current_user->authenticated
            && ((int)$_POST['user_id'] == $current_user->user_id)
            && ((int)$_POST['randkey'] > 0)
        )) {
            return _('comentario o usuario incorrecto');
        }

        if ($current_user->user_karma < $globals['min_karma_for_comments'] && $current_user->user_id != $link->author) {
            return _('karma demasiado bajo');
        }

        $comment = new Comment;

        $comment->link = $link->id;
        $comment->ip = $globals['user_ip'];
        $comment->randkey = (int)$_POST['randkey'];
        $comment->author = (int)$_POST['user_id'];
        $comment->karma = round($current_user->user_karma);
        $comment->content = clean_text_with_tags($_POST['comment_content'], 0, false, 10000);

        // Validate if the comment is a command. If it is, it is assigned a comment type other than "normal"
        $comment->checkCommand();

        // Check if is an admin comment.
        // If the comment already has a type assigned, it is possible that it is a command. We avoid changing it.
        if ($current_user->user_level === 'god' && $_POST['type'] === 'admin' && $comment->type !== '') {
            $comment->type = 'admin';
        }

        // Don't allow to comment with a clone
        $hours = (int)$globals['user_comments_clon_interval'];

        if ($hours > 0) {
            $clones = $current_user->get_clones($hours + 1);

            if ($clones) {
                $l = implode(',', $clones);
                $c = (int) $db->get_var("select count(*) from comments where comment_date > date_sub(now(), interval $hours hour) and comment_user_id in ($l)");

                if ($c > 0) {
                    syslog(LOG_NOTICE, "Meneame, clon comment ($current_user->user_login, $comment->ip) in $link->uri");
                    return _('ya hizo un comentario con usuarios clones');
                }
            }
        }

        // Basic check to avoid abuses from same IP
        if (!$current_user->admin && $current_user->user_karma < 6.2) {
            // Don't check in case of admin comments or higher karma

            // Avoid astroturfing from the same link's author
            if ($link->status !== 'published' && $link->ip == $globals['user_ip'] && $link->author != $comment->author) {
                UserAuth::insert_clon($comment->author, $link->author, $link->ip);
                syslog(LOG_NOTICE, "Meneame, comment-link astroturfing ($current_user->user_login, $link->ip): ".$link->get_permalink());
                return _('no se puede comentar desde la misma IP del autor del envío');
            }

            // Avoid floods with clones from the same IP
            if ((int)$db->get_var("select count(*) from comments where comment_link_id = $link->id and comment_ip='$comment->ip' and comment_user_id != $comment->author") > 1) {
                syslog(LOG_NOTICE, "Meneame, comment astroturfing ($current_user->user_login, $comment->ip)");
                return _('demasiados comentarios desde la misma IP con usuarios diferentes');
            }
        }

        if (mb_strlen($comment->content) < 5 || !preg_match('/[a-zA-Z:-]/', $_POST['comment_content'])) {
            // Check there are at least a valid char
            return _('texto muy breve o caracteres no válidos');
        }

        if (!$current_user->admin && ($link->author != $comment->author)) {
            $comment->get_links();

            if ($comment->banned && $current_user->Date() > $globals['now'] - 86400) {
                syslog(LOG_NOTICE, "Meneame: comment not inserted, banned link ($current_user->user_login)");
                return _('comentario no insertado, enlace a sitio deshabilitado (y usuario reciente)');
            }

            // Lower karma to comments' spammers
            $comment_count = (int) $db->get_var("select count(*) from comments where comment_user_id = $current_user->user_id and comment_date > date_sub(now(), interval 3 minute)");

            // Check the text is not the same
            $same_count = $comment->same_text_count();
            $same_links_count = $comment->same_links_count();

            if ($comment->banned) {
                $same_links_count *= 2;
            }

            $same_count += $same_links_count;
        } else {
            $comment_count = $same_count = 0;
        }

        $comment_limit = round(min($current_user->user_karma / 6, 2) * 2.5);
        $karma_penalty = 0;

        if ($comment_count > $comment_limit || $same_count > 2) {
            if ($comment_count > $comment_limit) {
                $karma_penalty += ($comment_count - 3) * 0.1;
            }
            if ($same_count > 1) {
                $karma_penalty += $same_count * 0.25;
            }
        }

        // Check image limits
        if (!empty($_FILES['image']['tmp_name'])) {
            if ($limit_exceded = Upload::current_user_limit_exceded($_FILES['image']['size'])) {
                return $limit_exceded;
            }
        }

        $db->transaction();

        // Check the comment wasn't already stored
        $stored = (int)$db->get_var('
            SELECT COUNT(*)
            FROM `comments`
            WHERE (
                `comment_link_id` = "'.(int)$comment->link.'"
                AND `comment_user_id` = "'.(int)$comment->author.'"
                AND `comment_randkey` = "'.$comment->randkey.'"
            )
            FOR UPDATE;
        ');

        if ($stored) {
            $db->rollback();
            return _('comentario duplicado');
        }

        $comment->content = User::removeReferencesToIgnores($comment->content);
        $comment->content = User::removeReferencesToLinkCommentsIgnores($link, $comment->content);

        if ($karma_penalty > 0) {
            $db->rollback();

            $user = new User($current_user->user_id);
            $user->add_karma(-$karma_penalty, _('texto repetido o abuso de enlaces en comentarios'));

            return _('penalización de karma por texto repetido o abuso de enlaces');
        }

        if (!$comment->store()) {
            $db->rollback();
            return _('error insertando comentario');
        }

        $comment->insert_vote();
        $link->update_comments();

        $db->commit();

        // Check image upload or delete
        if ($_POST['image_delete']) {
            $comment->delete_image();
        } else {
            $comment->store_image_from_form('image');
        }

        // Comment stored, just redirect to it page
        if ($redirect) {
            header('HTTP/1.1 303 Load');
            die(header('Location: '.$link->get_permalink().'/c0'.$comment->order.'#c-'.$comment->order));
        }

        return $comment;
    }

    public function update_conversation()
    {
        global $db, $globals, $current_user;

        $previous_ids = $db->get_col("select distinct conversation_to from conversations where conversation_type='comment' and conversation_from=$this->id");

        if ($previous_ids) {
            // Select users previous conversation to decrease in the new system
            $previous_users = $db->get_col("select distinct conversation_user_to from conversations where conversation_type='comment' and conversation_from=$this->id");
        } else {
            $previous_users = array();
        }

        $seen_users = array();
        $seen_ids = array();
        $refs = 0;

        $orders = array();

        if (preg_match_all('/(?:^|\W)(#(?:\d+)|@(?:[\p{L}\.][\.\d\-_\p{L}]+))\b/u', $this->content, $matches)) {
            foreach ($matches[1] as $order) {
                $order = substr($order, 1); // Delete the # or @
                $orders[$order] += 1;
            }
        }

        if (!$this->date) {
            $this->date = time();
        }

        foreach ($orders as $order => $val) {
            if ($refs > 10) {
                // Limit the number of references to avoid abuses/spam
                syslog(LOG_NOTICE, "Meneame: too many references in comment: $this->id ($current_user->user_login)");
                break;
            }

            if (!preg_match('/^\d+/', $order)) {
                $username_to = $db->escape($order);
                $to = $db->get_row("select 0 as id, user_id from users where user_login = '$username_to'");
            } elseif ($order == 0) {
                $to = $db->get_row("select 0 as id, link_author as user_id from links where link_id = $this->link");
            } else {
                $to = $db->get_row("select comment_id as id, comment_user_id as user_id from comments where comment_link_id = $this->link and comment_order=$order and comment_type != 'admin'");
            }

            if (!$to) {
                continue;
            }

            if (!in_array($to->id, $previous_ids) && !in_array($to->id, $seen_ids)) {
                if (
                    User::friend_exists($to->user_id, $this->author) >= 0
                    && $to->user_id != $this->author
                    && !in_array($to->user_id, $seen_users) // Limit the number of references to avoid abuses/spam and multip
                    && !in_array($to->user_id, $previous_users)
                ) {
                    User::add_notification($to->user_id, 'comment');
                }

                $db->query("insert into conversations (conversation_user_to, conversation_type, conversation_time, conversation_from, conversation_to) values ($to->user_id, 'comment', from_unixtime($this->date), $this->id, $to->id)");
            }

            $refs++;

            if (!in_array($id, $seen_ids)) {
                $seen_ids[] = $to->id;
            }

            if (!in_array($to, $seen_users)) {
                $seen_users[] = $to->user_id;
            }
        }

        $to_delete = array_diff($previous_ids, $seen_ids);

        if ($to_delete) {
            $to_delete = implode(',', $to_delete);
            $db->query("delete from conversations where conversation_type='comment' and conversation_from=$this->id and conversation_to in ($to_delete)");
        }

        $to_unnotify = array_diff($previous_users, $seen_users);

        foreach ($to_unnotify as $to) {
            User::add_notification($to, 'comment', -1);
        }
    }

    public function get_relative_individual_permalink()
    {
        // Permalink of the "comment page"
        global $globals;
        return $globals['base_url'].'c/'.$this->id;
    }

    public function normalize_content()
    {
        return $this->content = clean_lines(normalize_smileys($this->content));
    }

    public function store_image_from_form($field = 'image', $type = null)
    {
        return parent::store_image_from_form('comment', $field);
    }

    public function store_image($file, $type = null)
    {
        return parent::store_image('comment', $file);
    }

    public function move_tmp_image($file, $mime, $type = null)
    {
        return parent::move_tmp_image('comment', $file, $mime);
    }

    public function delete_image($type = null)
    {
        $media = new Upload('comment', $this->id, 0);
        $media->delete();

        $this->media_size = 0;
        $this->media_mime = '';
    }

    public function setStrikeByIds(array $strikes)
    {
        if (array_key_exists($this->id, $strikes) === false) {
            return;
        }

        $this->strike = $strikes[$this->id];
        $this->hide_comment = true;
    }

    public function checkCommand() {
        if (preg_match('/^!rel:/', $this->content) !== FALSE) {
            $this->type = 'rel';
            $this->content = preg_replace('/^!rel:\s(.*)$/', _('Relacionada: ') . '$1', $this->content);
        }
    }
}
