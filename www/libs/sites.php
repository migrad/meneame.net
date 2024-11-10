<?php
// The source code packaged with this file is Free Software, Copyright (C) 2011 by
// Ricardo Galli <gallir at gmail dot com>.
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
//		http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

class SitesMgr
{
    private static $id = 0;
    private static $parent = false;
    private static $info = false;
    private static $owner;
    private static $followers;

    const PREFERENCES_KEY = 'sub_preferences_';
    const SQL_BASIC = "SELECT subs.*, media.id as media_id, media.size as media_size, media.dim1 as media_dim1, media.dim2 as media_dim2,
			media.extension as media_extension, UNIX_TIMESTAMP(media.date) as media_date
			FROM subs
			LEFT JOIN media ON (media.type='sub_logo' and media.id = subs.id and media.version = 0) ";

    public static $page_modes = array(
        'default' => '',
        'ordered' => 'standard',
        'best comments' => 'best-comments',
        'threads' => 'threads',
        'best threads' => 'best-threads',
        'smart threads' => 'interview',
    );

    public static $extended_properties = array(
        'new_disabled' => 0,
        'no_link' => 0,
        'intro_max_len' => 550,
        'intro_min_len' => 20,
        'intro_lines' => 0,
        'no_anti_spam' => 0,
        'allow_local_links' => 0,
        'allow_paragraphs' => 0,
        'allow_images' => 0,
        'rules' => '',
        'message' => '',
        'box_color' => '',
        'box_background' => '',
        'box_bordercolor' => '',
        'post_html' => '',
    );

    public static $extra_extended_properties = array(
        'twitter_page' => '',
        'twitter_consumer_key' => '',
        'twitter_consumer_secret' => '',
        'twitter_token' => '',
        'twitter_token_secret' => '',
        'facebook_page' => '',
        'facebook_key' => '',
        'facebook_secret' => '',
        'facebook_token' => '',
    );

    public static function __init($id = false)
    {
        global $globals, $db;

        self::$info = false;

        if ($id > 0) {
            self::$id = $id;
        } elseif (!isset($globals['site_id'])) {
            if (empty($globals['site_shortname'])) {
                echo "Error, site_shortname not found, check your global['site_shortname']: ". $globals['site_shortname'];
            }

            self::$info = $db->get_row(SitesMgr::SQL_BASIC."where subs.name = '".$globals['site_shortname']."'");

            if (self::$info) {
                self::$id = self::$info->id;
            } else {
                self::$id = 0;
                return;
            }
        } else {
            self::$id = $globals['site_id'];
        }

        if (self::$info == false) {
            self::$info = $db->get_row(SitesMgr::SQL_BASIC."where subs.id = ".self::$id);
        }

        self::$parent = self::$info->created_from;

        if (self::$id > 0) {
            $db->query('set @site_id = "'.self::$id.'"');
        }
    }

    public static function my_id()
    {
        if (! self::$id) {
            self::__init();
        }

        return self::$id;
    }

    public static function get_owner()
    {
        if (self::$owner) {
            return self::$owner;
        }

        return self::$owner = new User(self::get_info()->owner);
    }

    public static function is_owner()
    {
        global $current_user;

        if (! self::$id) {
            self::__init();
        }

        return $current_user->user_id > 0 && ($current_user->admin || self::$info->owner == $current_user->user_id);
    }

    public static function get_info($id = false, $force = false)
    {
        global $db;

        if ($id && ($id != self::$id)) {
            return $db->get_row(SitesMgr::SQL_BASIC."where subs.id = $id");
        }

        if (!self::$id || $force) {
            self::__init($id);
        }

        return self::$info;
    }

    public static function get_name($id = false, $force = false)
    {
        global $db;

        if ($id && ($id != self::$id)) {
            return $db->get_var("select name from subs where id = $id");
        }

        if (!self::$id || $force) {
            self::__init($id);
        }

        return self::$info->name;
    }

    public static function get_id($name)
    {
        global $db;

        return $db->get_var("select id from subs where name = '".$db->escape($name)."'");
    }

    public static function delete($link)
    {
        global $db;

        return $db->query('DELETE FROM sub_statuses WHERE link = "'.(int)$link->id.'"');
    }

    public static function deploy($link)
    {
        global $db;

        if (! self::$id) {
            self::__init();
        }

        if ($link->status === 'private') {
            return self::delete($link);
        }

        $me = self::get_status(self::$id, $link);

        if ($me->status == $link->status && $me->origen == $link->sub_id && empty($link->sub_changed)) {
            return true;
        }

        $do_changed_id = $do_current = $do_all = $do_delete = $status_changed = $copy_link_karma = false;

        $current = self::$id;
        $origen = $me->origen;

        if ($me->origen != $link->sub_id || ! empty($link->sub_changed)) {
            $do_changed_id = true;  // Force to save all statuses
            $do_all = true;

            $me->origen = $origen = self::get_real_origen($current, $link);

            if ($me->status === 'new') {
                $me->status = $link->status;
            }
        } else { // If origen has changed, don't do the rest
            $me->date = $link->date;

            if ($me->status != $link->status) {
                $status_changed = true;

                switch ($link->status) {
                    case 'discard':
                    case 'autodiscard':
                    case 'abuse':
                        $do_all = true;

                        $me->date = $link->sent_date;

                        break;

                    case 'queued':
                        $me->date = $link->sent_date;

                        if ($me->status === 'published') {
                            $do_current = true;
                        } else {
                            $do_all = true;
                        }

                        break;

                    case 'published':
                    case 'metapublished':
                        $do_current = true;
                        $copy_link_karma = true;

                        $me->karma = $link->karma;
                        $me->date = $link->date;

                        break;

                    default:
                        $do_current = true;
                        syslog(LOG_INFO, "Menéame, status unknown in link $link->id");
                }

                $me->status = $link->status;
            }
        }

        $receivers = array();

        if ($do_current) {
            $receivers[] = $current;
        } elseif ($do_all) {
            $receivers[] = $origen;
            $receivers = array_merge($receivers, self::get_receivers($origen));

            // If the post has been copied/promoted to another site and its status has changed (tipically, to discarded)
            if ($status_changed && $link->votes + $link->negatives > 1) {
                $copies = $db->get_col("select id from sub_statuses where link = $link->id");
                $receivers = array_merge($receivers, $copies);
            }

            $receivers = array_unique($receivers);
        }

        $r = true; // Result of operations, for commit/rollback
        $db->transaction();

        if ($receivers) {
            foreach ($receivers as $r) {
                $new = $db->get_row("select * from sub_statuses where id = $r and link = $link->id");

                if (! $new) {
                    $new = $me;
                    $new->karma = 0;
                }

                $new->date = $me->date;
                $new->origen = $me->origen;

                if (! $do_changed_id) {  // Origen has changed, don't modify the status
                    $new->status = $me->status;
                }

                if ($do_current &&  // changed status to published or from published to queued
                        ($copy_link_karma || round($new->karma) == 0 || $link->karma < $new->karma)) {
                    // If karma was never updated (new published) or the link karma is negative/smaller
                    $new->karma = $link->karma;
                }

                $r = $db->query("replace into sub_statuses (id, status, date, link, origen, karma) values ($r, '$new->status', from_unixtime($new->date), $new->link, $new->origen, $new->karma)");

                if (! $r) {
                    $db->rollback();
                    syslog(LOG_INFO, "Failed transaction replace sub_statuses: ".$link->get_permalink());
                    return false;
                }
            }
        }

        // We delete those old statuses belong to the old sub that were not changed before
        if ($r && $do_changed_id) {
            $avoid = implode(',', $receivers);
            $r = $db->query("delete from sub_statuses where link = $link->id and id not in ($avoid)");
        }

        if (! $r) {
            $db->rollback();
            syslog(LOG_INFO, "Failed transaction in deploy: ".$link->get_permalink());
            return false;
        }

        return $db->commit();
    }

    // TODO: transient, for migration, edit/modify later
    public static function get_real_origen($id, $link)
    {
        return ($link->sub_id > 0) ? $link->sub_id : $id;
    }

    // Receivers are categories from other sub sites that have importe as true
    public static function get_receivers($id = false)
    {
        global $db;

        if ($id == false) {
            $id = self::my_id();
        }

        return $db->get_col('SELECT dst FROM subs_copy WHERE src = "'.(int)$id.'"');
    }

    public static function get_senders($id = false)
    {
        global $db;

        if ($id == false) {
            $id = self::my_id();
        }

        return $db->get_col('SELECT src FROM subs_copy WHERE dst = "'.(int)$id.'"');
    }

    private static function get_status($id, $link)
    {
        global $db;

        $status = $db->get_row("select id, status, unix_timestamp(date) as date, link, origen, karma, 1 as found from sub_statuses where id = $id and link = $link->id");

        if ($status) {
            return $status;
        }

        // Create and object that can be later stored
        $origen = self::get_real_origen(self::$id, $link);
        $status = new stdClass();

        // Retrieve original status in any sub, if it exists
        $original_status = $db->get_var("select status from sub_statuses where link=$link->id and id=origen");

        if ($original_status) {
            $status->status = $original_status;
        } else {
            $status->status = 'new';
        }

        $status->id = $origen;
        $status->link = $link->id;
        $status->date = $link->date;
        $status->origen = $origen;
        $status->karma = 0;
        $status->found = 0;

        return $status;
    }

    public static function store($s)
    { // Store a sub_statuses, as is.
        global $db;

        if (is_numeric($s->date)) {
            $date = "from_unixtime($s->date)";
        } else {
            $date = "'$s->date'";
        }

        return $db->query("replace into sub_statuses (id, status, date, link, origen, karma) values ($s->id, '$s->status', $date, $s->link, $s->origen, $s->karma)");
    }

    public static function get_sub_subs($id = false)
    {
        global $globals, $db;

        $id = (int)$id ?: self::my_id();

        return $db->get_results('
            SELECT subs.*
            FROM subs, subs_copy
            WHERE (
                dst = "'.$id.'"
                AND id = src
            )
            ORDER BY subs.name ASC;
        ');
    }

    public static function get_sub_subs_ids($id = false)
    {
        global $globals, $db;

        if ($id == false) {
            $id = self::my_id();
        }

        return $db->get_col("select id from subs, subs_copy where dst = $id and id = src");
    }

    public static function get_active_sites($children = false)
    {
        global $db;

        return $db->get_col("select id from subs where parent = 0 and enabled");
    }

    public static function can_edit($id = -1)
    {
        global $current_user, $db;

        if (! $current_user->user_id) {
            return false;
        }

        if ($current_user->admin) {
            return true;
        }

        if ($id > 0) {
            return $db->get_var("select owner from subs where id = $id") == $current_user->user_id;
        }

        $n = $db->get_var("select count(*) from subs where owner = $current_user->user_id");

        return $n < 10 && time() - $current_user->user_date > 86400 * 10;
    }

    public static function my_parent()
    {
        // Get original site
        if (! self::$id) {
            self::__init();
        }

        return (self::$parent > 0) ? self::$parent : self::$id;
    }

    public static function get_subscriptions($user)
    {
        global $db;

        return $db->get_results("select subs.* from subs, prefs where pref_user_id = $user and pref_key = 'sub_follow' and subs.id = pref_value order by name asc");
    }

    public static function get_followers()
    {
        if (self::$followers !== null) {
            return self::$followers;
        }

        global $globals, $db;

        if ($globals['memcache_host']) {
            $memcache_followers = 'follower_number' . self::my_id();
        }

        if (!$memcache_followers || false === $followers = memcache_mget($memcache_followers)) {
            // Not in memcache
            $followers = $db->get_var('
                SELECT  COUNT(pref_user_id)
                FROM prefs WHERE (
                    pref_key = "sub_follow"
                    AND pref_value = "'.self::my_id().'"
                )
            ');

            if ($memcache_followers) {
                memcache_madd($memcache_followers, $followers, 1800);
            }
        }

        return self::$followers = $followers;
    }

    public static function store_extended_properties($id = false, &$prefs)
    {
        if ($id == false) {
            $id = self::my_id();
        }

        $dict = array();
        $defaults = array_merge(self::$extended_properties, self::$extra_extended_properties);

        foreach ($prefs as $k => $v) {
            if (is_array($v) || !isset($defaults[$k]) || $defaults[$k] == $v) {
                continue;
            }

            switch ($k) {
                case 'rules':
                case 'message':
                    $dict[$k] = clean_text_with_tags($v, 0, false, 3000);
                    break;

                case 'post_html': // TODO: validate the HTML
                    $dict[$k] = $v;
                    break;

                default:
                    if (isset($defaults[$k]) && is_int($defaults[$k])) {
                        $dict[$k] = intval($v);
                    } else {
                        $dict[$k] = mb_substr(clean_input_string($v), 0, 140);
                    }
            }
        }

        $a = new Annotation(self::PREFERENCES_KEY.$id);

        if (empty($dict)) {
            return $a->delete();
        }

        $a->text = json_encode($dict);

        return $a->store();
    }

    public static function get_extended_properties($id = false)
    {
        static $properties = array(), $last_id = false;

        if ($id == false) {
            $id = self::my_id();
        }

        if (! empty($properties) && $last_id == $id) {
            return $properties;
        }

        $last_id = $id;
        $properties = self::$extended_properties;

        $key = self::PREFERENCES_KEY.$id;
        $a = new Annotation($key);

        if ($a->read() && !empty($a->text) && ($res = json_decode($a->text, true))) {
            foreach ($res as $k => $v) {
                $properties[$k] = $v;
            }
        }

        return $properties;
    }

    public static function can_send($id = false)
    {
        if ($id == false) {
            $id = self::my_id();
        }

        $info = self::get_info($id);

        if (! $info->enabled) {
            return false;
        }

        $properties = self::get_extended_properties($id);

        return empty($properties['new_disabled']);
    }

    public static function getMainSiteId()
    {
        global $db;

        return $db->get_var("SELECT id FROM subs WHERE sub=0 AND enabled=1");
    }
}
