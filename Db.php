<?php
/*
 
Distributed under the MIT license, http://www.opensource.org/licenses/mit-license.php

Copyright (c) 2011 Diego Vilariño http://www.ensegundoplano.com/

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/

/**
 * Database php/MySqli Class
 *
 * @author  diego
 * @uses    $var = Db::getInstance();
 */
class Db {

  /**
   * Singleton instace
   * @var object
   */
  static private $instance = null;
  /**
   * Config vars
   */
  private $databaseHost = DATABASEHOST;
  private $databaseUser = DATABASEUSERNAME;
  private $databasePassword = DATABASEPASSWORD;
  private $databaseName = DATABASENAME;
  private $charset = DATABASECHARSET;
  /**
   * Mysqli conection
   */
  private $mysqli = 0;
  /**
   * Affected Rows on Query
   * @var int
   */
  private $affectedRows;
  /**
   * Query results
   * @var array 
   */
  private $databaseResults;
  /**
   * Last Insert identifier
   * @var int 
   */
  private $lastId;
  /**
   * Array of sql transactions
   * @var type 
   */
  private $transResults = array();
  /**
   * Check if is transaction
   * @var bool 
   */
  private $onTransaction = false;
  /**
   * Thread id conection
   */
  private $thread_id = null;
  /**
   * Query string
   * @var string 
   */
  private $query = '';
  /**
   * Config var
   * @var array
   */
  static private $config = null;

  /**
   * Private constructor to prevent instace
   * 
   * $config array to set custom config with this indexes:
   * 
   * $config['host'];
   * $config['user'];
   * $config['password'];
   * $config['database'];
   * $config['charset'];
   * 
   * @param array $config 
   */
  private function __construct($config) {
    if ($config) {
      if (isset($config['host'])) {
        $this->databaseHost = $config['host'];
      }
      if (isset($config['user'])) {
        $this->databaseUser = $config['user'];
      }
      if (isset($config['password'])) {
        $this->databasePassword = $config['password'];
      }
      if (isset($config['database'])) {
        $this->databaseName = $config['database'];
      }
      if (isset($config['charset'])) {
        $this->charset = $config['charset'];
      }
    }
    $this->connect();
  }

  /**
   * Singleton Instance
   * 
   * @return object 
   */
  static public function getInstance($config = null) {

    if (self::$config !== $config) {
      self::$instance = null;
      self::$config = $config;
    }
    if (self::$instance == null) {
      $c = __CLASS__;
      self::$instance = new $c($config);
    }
    return self::$instance;
  }

  /**
   * Connet with mysql
   */
  private function connect() {

    $this->mysqli = new mysqli($this->databaseHost,
                    $this->databaseUser,
                    $this->databasePassword,
                    $this->databaseName);
    if (mysqli_connect_errno()) {
      $this->mysqli = 0;
      trigger_error(mysqli_connect_error());
    }
    if (!$this->mysqli->set_charset($this->charset)) {
      trigger_error("Error character set", E_USER_ERROR);
    }
    $this->thread_id = $this->mysqli->thread_id;
  }

  /**
   * Set query
   * 
   * @param string $query
   * @return bool return false if empty query 
   */
  private function initQuery($query) {

    $this->affectedRows = 0;
    $this->databaseResults = Array();
    $this->lastId = null;

    $this->query = $query;

    if (strlen(trim($this->query)) < 0) {
      trigger_error("Empty Query", E_USER_ERROR);
      return false;
    }

    if ($this->mysqli === 0) {
      $this->connect();
    }
  }

  /**
   * Select data from database
   * 
   * @param string $query
   * @param string $type (object return an array of objects / array return an array of arrays )
   * @return array 
   */
  public function select($query, $type = 'object') {

    $this->initQuery($query);

    if ($result = $this->mysqli->query($this->query)) {
      $this->affectedRows = $result->num_rows;
      if ($type == 'object') {
        $this->databaseResults = $this->getDataObject($result);
      } elseif ($type == 'array') {
        $this->databaseResults = $this->getDataArray($result);
      } else {
        trigger_error("Error select type", E_USER_ERROR);
        exit;
      }
      $result->free();
    }
    return $this->databaseResults;
  }

  /**
   * Execute query (insert/update) on database
   * 
   * @param string $query
   * @return bool (true if ok or false if fail)
   */
  public function execute($query) {

    $this->initQuery($query);

    if ($this->mysqli->query($this->query) === true) {
      $this->affectedRows = $this->mysqli->affected_rows;
      $this->lastId = $this->mysqli->insert_id;
      $result = true;
    } else {
      $result = false;
    }
    if ($this->onTransaction) {
      $this->transResults[] = $result;
    }
    return $result;
  }

  /**
   * Generate and execute multiple insert query
   * Can recive unique object or array, or an array of objects or arrays
   * Generate sql with data keys/properties
   * 
   * @param string $table (database table name)
   * @param array/object $data
   * @return bool (true if ok, false if fail) 
   */
  public function insert($table, $data) {

    $insert_data = array();
    if (is_object($data)) {
      $insert_data[0] = $data;
    } else {
      if (is_array($data)) {
        if (isset($data[0]) && is_array($data[0])) {
          $total = count($data);
          $akeys = array_keys($data[0]);
          for ($i = 0; $i < $total; $i++) {
            $o = new stdClass();
            foreach ($akeys as $key) {
              $o->$key = $data[$i][$key];
            }
            $insert_data[] = $o;
            $o = null;
          }
        } elseif (isset($data[0]) && is_object($data[0])) {
          $insert_data = $data;
        } else {
          $o = new stdClass();
          $akeys = array_keys($data);
          foreach ($akeys as $key) {
            $o->$key = $data[$key];
          }
          $insert_data[] = $o;
        }
      }
    }

    $akeys = array_keys(get_object_vars($insert_data[0]));
    $total_keys = count($akeys);
    $sql = "INSERT INTO " . $table . " (";
    $i = 0;
    foreach ($akeys as $key) {
      $sql .= "`$key`";
      if ($total_keys > $i + 1) {
        $sql .= ',';
      }
      $i++;
    }
    $sql .= ") VALUES (";
    $total_data = count($insert_data);
    for ($i = 0; $i < $total_data; $i++) {
      $j = 0;
      foreach ($akeys as $key) {
        if ($insert_data[$i]->$key) {
          $value = $this->mysqli->real_escape_string($insert_data[$i]->$key);
          $value = "'$value'";
        } else {
          $value = 'NULL';
        }
        $sql .= $value;
        if ($total_keys > $j + 1) {
          $sql .= ',';
        }
        $j++;
      }
      if ($total_data > $i + 1) {
        $sql .= '),(';
      }
    }
    $sql .= ");";
    return $this->execute($sql);
  }

  /**
   * Update record/s on database
   * Pass key = true for massive update
   * 
   * @param string $table
   * @param array/object $data
   * @param string/true $key 
   */
  public function update($table, $data, $key = null) {
    if ($key == null) {
      echo 'Define update key value, this prevent unexpected massive updates';
      exit;
    }
    if (!is_object($data) && !is_array($data)) {
      trigger_error("Non valid update data ", E_USER_ERROR);
      exit;
    } else {
      if (is_array($data)) {
        $o = new stdClass();
        $akeys = array_keys($data);
        foreach ($akeys as $akey) {
          $o->$akey = $data[$akey];
        }
        $data = $o;
      }
    }
    $akeys = array_keys(get_object_vars($data));
    $total_keys = count($akeys);
    if ($key !== true) {
      $total_keys = $total_keys - 1;
    }
    $sql = 'UPDATE ' . $table . ' SET ';
    $i = 0;
    foreach ($akeys as $akey) {
      if ($akey !== $key) {
        if($data->$akey==null){
            $sql .= "`$akey` = NULL";
        }else{
            $sql .= "`$akey` = '" . $this->mysqli->real_escape_string($data->$akey) . "'";
        }
        if ($total_keys > $i + 1) {
          $sql .= ' , ';
        }
        $i++;
      }
    }
    if ($key !== true) {
      $sql .= " WHERE `$key` = '" . $data->$key . "'";
    }
    $sql .= ' ;';
    return $this->execute($sql);
  }

  /**
   * Executes multiple queries
   * Returns an array of objects with rows and result properties
   * result property can be an array of arrays or array of objects, change with $type param 
   *
   * @param array $querys
   * @param string $type ('object','array')
   * @return array
   */
  public function multiQuery($querys, $type = 'object') {
    if (!is_array($querys)) {
      trigger_error("Need an array of querys", E_USER_ERROR);
      exit;
    }
    $sql = '';
    foreach ($querys as $query) {
      $sql .= $query;
    }
    $this->query = $sql;
    $data = array();
    $i = 0;
    if ($this->mysqli->multi_query($sql)) {
      do {
        if ($result = $this->mysqli->store_result()) {
          $this->affectedRows = $result->num_rows;
          if ($type == 'object') {
            $this->databaseResults = $this->getDataObject($result);
          } elseif ($type == 'array') {
            $this->databaseResults = $this->getDataArray($result);
          } else {
            trigger_error("Error select type", E_USER_ERROR);
            exit;
          }
          $dataObj = new stdClass();
          $dataObj->rows = $this->affectedRows;
          $dataObj->results = $this->databaseResults;
          $data[$i] = $dataObj;
          $dataObj = null;
          $result->free();
        }
        $i++;
      } while ($this->mysqli->next_result());
    }
    if ($this->mysqli->errno) {
      echo "Stopped while retrieving result: " . $this->mysqli->error . " ";
    }
    return $data;
  }

  /**
   * Return query affected rows
   * @return int 
   */
  public function getAffectedRows() {
    return $this->affectedRows;
  }

  /**
   * Alias of getAffectedRows()
   * @return int 
   */
  public function total() {
    return $this->getAffectedRows();
  }

  /**
   * Return Last Insert identifier
   * @return int
   */
  public function getLastId() {
    return $this->lastId;
  }

  /**
   * Return array of arrays
   * 
   * @param mysqlresult $result
   * @return array
   */
  private function getDataArray($result) {
    $data = array();
    $i = 0;
    while ($row = $result->fetch_assoc()) {
      foreach ($row as $key => $value) {
        $data[$i][$key] = stripslashes($value);
      }
      $i++;
    }
    return $data;
  }

  /**
   * Return array of objects
   * 
   * @param mysqlresult $result
   * @return array 
   */
  private function getDataObject($result) {
    $data = array();
    $i = 0;
    while ($row = $result->fetch_object()) {
      $data[$i] = new stdClass();
      foreach ($row as $key => $value) {
        $data[$i]->$key = stripslashes($value);
      }
      $i++;
    }
    return $data;
  }

  /**
   * Begin transaction
   * Run querys with execute, update or insert methods
   * 
   * Example: (Use with InnoDB Tables)
   * $db = Db::getInstance();
   * $db->transStart();
   * $db->execute("INSERT INTO `table` (`id` ,`number`) VALUES ( null , '12345');");
   * $db->execute("INSERT INTO `table` (`id` ,`number`) VALUES ( ".($db->getLastId() + 1)." , '12345');");
   * if($db->transComplete()){
   *     echo 'ok';
   * }else{
   *     echo 'fail';
   * }
   * 
   */
  public function transStart() {
    $this->onTransaction = true;
    $this->mysqli->autocommit(false);
  }

  /**
   * Check all querys and execute transaction
   * Auto rollback or commit querys
   * 
   * @param bool $forceRollBack (if true force rollback for test mode or check mode)
   * @return bool (true if transaction is ok, false if fail) 
   */
  public function transComplete($forceRollBack = false) {
    if (!$this->onTransaction) {
      trigger_error("Non transaction initialized", E_USER_ERROR);
      exit;
    }
    if (count($this->transResults) == 0) {
      trigger_error("No sql on transaction", E_USER_ERROR);
      exit;
    }
    if ($forceRollBack) {
      $this->transResults[0] = false;
    }
    foreach ($this->transResults as $result) {
      if (!$result) {
        $this->mysqli->rollback();
        break;
      }
    }
    if ($result) {
      $this->mysqli->commit();
    }
    $this->mysqli->autocommit(true);
    $this->onTransaction = false;
    unset($this->transResults);
    $this->transResults = array();
    return $result;
  }

  /**
   * Return array of mysql database information
   * 
   * @return array 
   */
  public function info() {
    $info = array();
    $info['client_info'] = $this->mysqli->client_info;
    $info['client_version'] = $this->mysqli->client_version;
    $info['server_info'] = $this->mysqli->server_info;
    $info['server_version'] = $this->mysqli->server_version;
    $info['charset'] = $this->mysqli->get_charset();
    $info['host_info'] = $this->mysqli->host_info;
    $info['protocol_version'] = $this->mysqli->protocol_version;
    $info['stat'] = $this->mysqli->stat();
    return $info;
  }

  /**
   * Make ping 
   * 
   * @return bool return true or exit with error 
   */
  public function ping() {
    if ($this->mysqli->ping()) {
      return true;
    } else {
      trigger_error("Non ping", E_USER_ERROR);
      exit;
    }
  }

  /**
   * Return last string query
   * @return string 
   */
  public function getQuery() {
    return $this->query;
  }

  /**
   * Kill connection
   */
  public function kill() {
    $this->mysqli->kill($this->thread_id);
  }

  /**
   * Kill conection and destroy instance
   */
  public function close() {
    $this->kill();
    self::$instance = null;
    $this->__destruct();
  }

  /**
   * Destroy object
   */
  public function __destruct() {
    if (@$this->mysqli) {
      $this->mysqli->close();
    }
    unset($this->mysqli);
  }

  /**
   * Override __clone to get error
   */
  public function __clone() {
    trigger_error('Clone error', E_USER_ERROR);
    exit;
  }

}

?>