<?php
// The source code packaged with this file is Free Software, Copyright (C) 2010 by
// Ricardo Galli <gallir at gmail dot com>.
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
//      http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

class RGDB extends mysqli
{
    const POINT_KEY = 'rgdb_savepoint_';
    const MAX_ROWS = 10000;

    public $dbuser;
    public $dbpassword;
    public $dbname;
    public $dbhost;
    public $port;
    public $connected;
    public $in_transaction;
    public $show_errors;
    public $initial_query;
    public $connect_timeout;
    public $ban_checked;
    public $max_rows;
    public $persistent;

    public function __construct($dbuser = '', $dbpassword = '', $dbname = '', $dbhost = 'localhost', $check_ban = false)
    {
        $this->dbuser = $dbuser;
        $this->dbpassword = $dbpassword;
        $this->dbname = $dbname;
        $this->dbhost = $dbhost;
        $this->port = null;
        $this->connected = false;
        $this->in_transaction = 0;
        $this->show_errors = true;
        $this->initial_query = false;
        $this->connect_timeout = 10;

        // Check the IP is not banned before doing anything more
        if ($check_ban) {
            $this->ban_checked = check_ip_noaccess(1); // 1 == only cache
        } else {
            $this->ban_checked = true;
        }

        // In case it's run from a web server we limit the number of rows
        if (!empty($_SERVER['HTTP_HOST'])) {
            $this->max_rows = self::MAX_ROWS;
        } else {
            $this->max_rows = PHP_INT_MAX;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (!$this->connected) {
            return;
        }

        // Rollback dangling transactions
        if ($this->in_transaction > 0) {
            parent::rollback();
            syslog(LOG_INFO, "Dangling transactions, rollback forced " . $_SERVER['SCRIPT_NAME']);
        }

        parent::close();

        $this->connected = false;
    }

    public function hide_errors(): void
    {
        $this->show_errors = false;
    }

    public function show_errors(): void
    {
        $this->show_errors = true;
    }

    public function initial_query($query)
    {
        $this->initial_query = $query;

        if ($this->connected) {
            return $this->query($query);
        }
    }

    public function savepoint_name()
    {
        if ($this->in_transaction > 1) {
            return self::POINT_KEY . $this->in_transaction;
        }
    }

    public function transaction(): int
    {
        $this->in_transaction++;

        if ($this->in_transaction == 1) {
            $this->query('START TRANSACTION');
        } elseif (!$this->query('SAVEPOINT ' . $this->savepoint_name())) {
            syslog(LOG_INFO, 'Error SAVEPOINT ' . $this->savepoint_name() . ' ' . $_SERVER['SCRIPT_NAME']);
        }

        return $this->in_transaction;
    }

    public function commit($flags = null, $name = null): bool
    {
        if ($this->in_transaction <= 0) {
            syslog(LOG_INFO, 'Error COMMIT, transaction = 0 ' . $_SERVER['SCRIPT_NAME']);
            return false;
        }

        if ($this->in_transaction > 1) {
            $r = $this->query('RELEASE SAVEPOINT ' . $this->savepoint_name());
        } else {
            $r = parent::commit();
        }

        if (!$r) {
            syslog(LOG_INFO, 'Error commit/RELEASE SAVEPOINT ' . $this->savepoint_name() . ' ' . $_SERVER['SCRIPT_NAME']);
        }

        $this->in_transaction--;

        return $r;
    }

    public function rollback($flags = null, $name = null): bool
    {
        if ($this->in_transaction <= 0) {
            syslog(LOG_INFO, 'Error ROLLBACK, transaction = 0 ' . $_SERVER['SCRIPT_NAME']);
            return false;
        }

        if ($this->in_transaction > 1) {
            $r = $this->query('ROLLBACK TO ' . $this->savepoint_name());
        } else {
            $r = parent::rollback();
        }

        if (!$r) {
            syslog(LOG_INFO, 'Error rollback/ROLLBACK TO ' . $this->savepoint_name() . ' ' . $_SERVER['SCRIPT_NAME']);
        }

        $this->in_transaction--;

        return $r;
    }

    // Reset the connection to the slave if it was using the master
    public function barrier(): void
    {
    }

    public function connect($host = null, $username = null, $passwd = null, $dbname = null, $port = null, $socket = null): bool
    {
        if ($this->connected) {
            return true;
        }

        @$this->init();
        @$this->options(MYSQLI_OPT_CONNECT_TIMEOUT, $this->connect_timeout);

        if ($this->persistent && version_compare(PHP_VERSION, '5.3.0') > 0) {
            $this->connected = @$this->real_connect('p:' . $this->dbhost, $this->dbuser, $this->dbpassword, $this->dbname, $this->port);
        } else {
            $this->connected = @$this->real_connect($this->dbhost, $this->dbuser, $this->dbpassword, $this->dbname, $this->port);
        }

        if (!$this->connected) {
            die(header('HTTP/1.1 503 Service Unavailable'));
        }

        $this->set_charset('utf8');

        // Check the IP is not banned before doing anything more
        if (!$this->ban_checked) {
            check_ip_noaccess(2); // 2 == don't check in cache
            $this->ban_checked = true;
        }

        if ($this->initial_query) {
            $this->query($this->initial_query);
        }

        return true;
    }

    public function escape($str): string
    {
        $this->connect();

        return $this->real_escape_string($str);
    }

    public function print_error($str = ''): bool
    {
        if ($this->show_errors) {
            if (headers_sent() === false) {
                header('HTTP/1.1 503 Database error');
                header('Content-Type: text/plain');
            }

            dd($str, $this->error, true);
        }

        syslog(LOG_NOTICE, "rgdb.php ($this->dbhost) error $str " . $_SERVER['REQUEST_URI'] . " ($this->error)");

        return false;
    }

    public function flush(): void
    {
        $this->last_result = array();
    }

    public function query($query, $class_name = null, $index_name = null): bool
    {
        $is_select = preg_match('/^\s*(select|show)\s/i', trim($query));

        $this->connect();

        // Flush cached values..
        $this->last_result = array();

        $result = @parent::query($query);

        if (!$result) {
            return $this->print_error($query);
        }

        if (!$class_name) {
            $class_name = 'stdClass';
        }

        if (!$is_select) {
            return true;
        }

        $num_rows = 0;

        while (($row = $result->fetch_object($class_name)) && ($num_rows < $this->max_rows)) {
            // We put a limit
            if ($index_name) {
                $index = $row->$index_name;
            } else {
                $index = $num_rows;
            }

            $this->last_result[$index] = $row;

            $num_rows++;
        }

        if ($num_rows >= $this->max_rows) {
            syslog(LOG_INFO, 'MAX_ROWS reached by ' . $globals['user_ip'] . ' in ' . $_SERVER['REQUEST_URI']);
        }

        @$result->close();

        return true;
    }

    public function object_iterator($query, $class = null)
    {
        $is_select = preg_match('/^ *(select|show)\s/i', $query);

        $this->connect();

        // query succeeded
        if (!$this->real_query($query)) {
            return false;
        }

        // SELECT, SHOW, DESCRIBE
        if ($is_select && $this->field_count) {
            return new QueryResult($this, $class);
        }

        // INSERT, UPDATE, DELETE
        return $this->affected_rows;
    }

    public function get_var($query = null, $x = 0, $y = 0)
    {
        // If there is a query then perform it if not then use cached results..
        if ($query) {
            $this->query($query);
        }

        // Extract var out of cached results based x,y vals
        if (!empty($this->last_result[$y]) && is_object($this->last_result[$y])) {
            $values = array_values(get_object_vars($this->last_result[$y]));
        }

        // If there is a value return it else return null
        if (isset($values[$x])) {
            return $values[$x];
        }
    }

    public function get_object($query, $class)
    {
        return $this->get_row($query, 0, $class);
    }

    public function get_row($query = null, $y = 0, $class_name = null)
    {
        if ($query) {
            $this->query($query, $class_name);
        }

        if (isset($this->last_result[$y])) {
            return $this->last_result[$y];
        }
    }

    //  Function to get 1 column from the cached result set based in X index
    public function get_col($query = null, $x = 0): array
    {
        // If there is a query then perform it if not then use cached results..
        if ($query) {
            $this->query($query);
        }

        // Extract the column values
        $return = array();
        $n = count($this->last_result);

        for ($i = 0; $i < $n; $i++) {
            $return[$i] = $this->get_var(null, $x, $i);
        }

        return $return;
    }

    // Return the the query as a result set - see docs for more details
    public function get_results($query = null, $class_name = null, $index_name = null)
    {
        // If there is a query then perform it if not then use cached results..
        if ($query) {
            $this->query($query, $class_name, $index_name);
        }

        // Send back array of objects. Each row is an object
        return $this->last_result ?: array();
    }

    public function get_enum_values($table, $column): array
    {
        if (($table === 'links') && ($column === 'link_status')) {
            return [
                'discard' => 1,
                'queued' => 2,
                'published' => 3,
                'abuse' => 4,
                'duplicated' => 5,
                'autodiscard' => 6,
                'metapublished' => 7
            ];
        }

        // Retrieve available status values
        $row = $this->get_row('SHOW COLUMNS FROM `' . $table . '` LIKE "' . $column . '"');

        preg_match_all("/'(.*?)'/", $row->Type, $matches);

        if (empty($matches[1])) {
            return array();
        }

        $enum = array();

        foreach ($matches[1] as $v => $str) {
            $enum[$str] = $v + 1;
        }

        return $enum;
    }
}

// Iterators inspired from:
//     http://techblog.procurios.nl/k/news/view/33914/14863/Syntactic-Sugar-for-MySQLi-Results-using-SPL-Iterators.html

class ObjectIterator implements Iterator
{
    protected $result;
    protected $class;
    protected $position;
    protected $currentRow;

    public function __construct($result, $class = null)
    {
        $this->result = $result;
        $this->class = $class;
    }

    public function __destruct()
    {
        $this->result->free();
    }

    public function rewind(): void
    {
        $this->result->data_seek($this->position = 0);
        $this->currentRow = $this->result->fetch_object($this->class);
    }

    public function next(): void
    {
        $this->currentRow = $this->result->fetch_object($this->class);
        ++$this->position;
    }

    public function valid(): bool
    {
        return $this->position < $this->result->num_rows;
    }

    public function current(): mixed
    {
        $this->currentRow->read = true;

        return $this->currentRow;
    }

    public function key():mixed
    {
        return $this->position;
    }
}

class QueryResult extends MySQLi_Result implements IteratorAggregate
{
    public function __construct($result, $class = null)
    {
        parent::__construct($result);
        $this->class = $class;
    }

    public function getIterator(): ObjectIterator
    {
        return new ObjectIterator($this, $this->class);
    }
}
