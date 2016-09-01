<?php

/**
 * SQLite simple store
 * Super lightweight Zero configuration sqlite-based key => value store
 * with expiration time for PHP.
 *
 * Basically its a simple key value storage with and expireation field which
 * will *delete the value after it expires.
 *
 * Also since its heavily influenced by redis, some of redis useful functions were implemented
 *
 *
 * @author Ohad Raz <admin@bainternet.info>
 * @version 0.1.5
 */
class SQLite_simple_store
{
    /**
     * $db PDO connection
     * @var PDO
     */
    private $db = null;
    /**
     * $tableName
     * @var string
     */
    public $tableName = '';

    /**
     * __construct
     *
     * @param string $tableName
     * @param string $filePath
     *
     */
    function __construct($tableName = '', $filePath = 'db.sqlite')
    {
        $this->tableName = $this->validate_table_name($tableName);
        $this->db = new PDO('sqlite:' . $filePath);
        $this->create_table();
    }

    /**
     * create_table
     * creates store table in the db
     * @return SQLite_simple_store
     */
    function create_table()
    {
        try {
            //create the database
            $this->db->exec("CREATE TABLE $this->tableName (`key` TEXT PRIMARY KEY, `value` TEXT, `exp` INTEGER)");
        } catch (Exception $e) {
            // var_dump($e);    // ???
        }
        return $this;
    }

    /**
     * get
     * gets a specific value based on key, if the value has expired false will be returned.
     * @param  string $key
     * @param  mixed $def
     * @return mixed
     * @throws InvalidKeyException
     */
    function get($key, $def = false)
    {
        if (is_int($key))
            $key = "" . $key;
        if (!is_string($key)) {
            throw new InvalidKeyException('Expected string as key');
        }

        $q = $this->db->prepare(
            "SELECT * FROM $this->tableName WHERE `key` = :key;"
        );
        $q->bindParam(':key', $key, PDO::PARAM_STR);
        $q->execute();

        if ($result = $q->fetch(PDO::FETCH_ASSOC)) {
            if (isset($result['value'])) {
                if ($this->is_expired($result)) {
                    $result = false;
                } else {
                    $result = json_decode($result['value']);
                }
            }
            return $result;
        } else {
            return $def;
        }
    }

    /**
     * set
     * stores a value in the database.
     * @param string $key
     * @param mixed $value
     * @param string $exp
     * @return SQLite_simple_store for chaining
     * @throws InvalidKeyException
     */
    function set($key, $value, $exp = 'NEVER')
    {
        if (is_int($key))
            $key = "" . $key;
        if (!is_string($key)) {
            throw new InvalidKeyException('Expected string as key');
        }

        if ('NEVER' !== $exp && is_numeric($exp)) {
            $exp = intval($exp) + time();
        } else {
            $exp = 0;
        }

        $q = $this->db->prepare(
            "REPLACE INTO $this->tableName VALUES (:key, :value, :exp);"
        );
        $json_val = json_encode($value);
        $q->bindParam(':key', $key, PDO::PARAM_STR);
        $q->bindParam(':value', $json_val, PDO::PARAM_STR);
        $q->bindParam(':exp', $exp, PDO::PARAM_STR);
        $q->execute();
        return $this;
    }

    /**
     * del
     * Deletes a value of the given key
     * @param  string $key
     * @return SQLite_simple_store
     */
    function del($key)
    {
        $q = $this->db->prepare(
            "DELETE FROM  $this->tableName  WHERE `key` = :key;"
        );
        $q->bindParam(':key', $key, PDO::PARAM_STR);
        $q->execute();
        return $this;
    }

    /**
     * delete_all
     * deletes all values from db
     * @return SQLite_simple_store
     */
    public function delete_all()
    {
        $q = $this->db->prepare(
            "DELETE FROM $this->tableName"
        );
        $q->execute();
        return $this;
    }

    /**
     * clean
     * deletes all expired values
     */
    public function clean()
    {
        $this->db->prepare(
            "DELETE FROM $this->tableName where exp != 0 and exp < " . time()
        )->execute();
    }

    /**
     * keys_count
     * returns number of different keys
     * @return int
     */
    public function keys_count()
    {
        $q = $this->db->prepare("SELECT count(*) as c FROM $this->tableName");
        $q->execute();
        return $q->fetch(PDO::FETCH_ASSOC)['c'];

    }

    /**
     * get_all
     * get all values in the db
     *
     * if $validate is true (default)
     * expired values will get deleted and will not be returned.
     * set $validate to false to get all values even if they have expired.
     *
     * @param  boolean $validate
     * @return array
     */
    public function get_all($validate = true)
    {
        $q = $this->db->prepare(
            "SELECT * FROM  $this->tableName"
        );
        $q->execute();
        $data = array();
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            if ($validate) {
                if ($this->is_valid($row)) {
                    $data[] = $row;
                }
            } else {
                $data[] = $row;
            }
        }
        return $data;
    }

    /**
     * get_keys
     * get all keys in the db
     *
     * if $validate is true (default)
     * expired keys and values will get deleted and will not be returned.
     * set $validate to false to get all keys even if they have expired.
     *
     * @param  boolean $validate
     * @return array
     */
    function keys($validate = true)
    {
        $q = $this->db->prepare(
            "SELECT `key`,exp FROM  $this->tableName"
        );
        $q->execute();
        $data = array();
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            if ($validate) {
                if ($this->is_valid($row)) {
                    $data[] = $row['key'];
                }
            } else {
                $data[] = $row['key'];
            }
        }
        return $data;
    }

    /**
     * is_valid
     * checks if a value in the db has not expired
     * @param  array $row
     * @return boolean
     */
    function is_valid($row)
    {
        return !$this->is_expired($row);
    }

    /**
     * is_expired
     * checks if a value in the db has expired
     * @param  array $row
     * @return boolean
     */
    function is_expired($row)
    {
        if (isset($row['exp']) && $row['exp'] == 0)
            $result = false;
        else {
            $time = time();
            if (isset($row['exp']) && ($time < $row['exp'])) {
                $result = false;
            } else {
                $this->del($row['key']);
                $result = true;
            }
        }
        return $result;
    }

    /**
     * exists
     * checks if a key exsits and is not expired
     * @param  string $key
     * @return boolean
     */
    function exists($key)
    {
        return ($this->get($key)) ? true : false;
    }

    /**
     * incr
     * increment value by $by
     * @param  string $key
     * @param  integer $by
     * @return int
     */
    function incr($key, $by = 1)
    {
        return $this->set($key, ((int)$this->get($key) + $by));
    }

    /**
     * decr
     * decrement value by $by
     * @param  string $key
     * @param  integer $by
     * @return int
     */
    function decr($key, $by = 1)
    {
        return $this->incr($key, ($by * -1));
    }

    /**
     * count
     * returns the count of elements in a key
     * @param  string $key
     * @return int
     */
    function count($key)
    {
        return count(
            json_decode(
                json_encode(
                    $this->get($key, array()),
                    true
                )
            )
        );
    }

    /**
     * rpush
     * adds an element to a key (right)
     * @param  string $key
     * @param  mixed $value
     * @return int
     */
    function rpush($key, $value)
    {
        $tmp = $this->get($key, array());
        $tmp[] = $value;
        $this->set($key, $tmp);
        return count($tmp);
    }

    /**
     * lpush
     * adds an element to a key (left)
     * @param  string $key
     * @param  mixed $value
     * @return int
     */
    function lpush($key, $value)
    {
        $tmp = $this->get($key, array());
        array_unshift($tmp, $value);
        $this->set($key, $tmp);
        return count($tmp);
    }

    /**
     * lset
     * sets the value of an element in a key by index
     * @param  string $key
     * @param  int $idx
     * @param  mixed $value
     * @return boolean
     */
    function lset($key, $idx, $value)
    {
        $tmp = $this->get($key, array());
        if ($idx < 0) {
            $idx = count($tmp) - abs($idx);
        }
        if (isset($tmp[$idx])) {
            $tmp[$idx] = $value;
            $this->set($key, $tmp);
            return true;
        }
        return false;
    }

    /**
     * lindex
     * gets an element from a key by its index
     * @param  string $key
     * @param  int $idx
     * @return mixed
     */
    function lindex($key, $idx)
    {
        $tmp = $this->get($key, array());
        if ($idx < 0)
            $idx = count($tmp) - abs($idx);
        return isset($tmp[$idx]) ? $tmp[$idx] : null;
    }

    /**
     * validate_table_name
     * @param  string $table_name
     * @return string valid table name
     */
    function validate_table_name($table_name)
    {
        //first char canot be a digit
        $table_name = is_numeric(substr($table_name, 0, 1)) ? '_' . $table_name : $table_name;
        //replace - and . with _
        return str_replace(array("-", ".", " "), array("_", "_", "_"), $table_name);

    }
}//end class

class InvalidKeyException extends Exception
{
}
