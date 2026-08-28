<?php
namespace SmartDoor\Config;

class Database {
    private static $instance = null;
    private $pdo;
    private $connected = false;
    
    private function __construct() {
        $this->connect();
    }
    
    private function connect() {
        try {
            $host = getenv('DB_HOST') ?: 'localhost';
            $port = getenv('DB_PORT') ?: 3306;
            $dbname = getenv('DB_NAME');
            $user = getenv('DB_USER');
            $password = getenv('DB_PASSWORD') ?: '';
            
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            
            $this->pdo = new \PDO(
                $dsn,
                $user,
                $password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            
            // Set timezone
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->connected = true;
            
        } catch (\PDOException $e) {
            throw new \Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function insert($table, $data) {
        $keys = array_keys($data);
        $placeholders = array_fill(0, count($keys), '?');
        
        $sql = "INSERT INTO $table (" . implode(',', $keys) . ") "
             . "VALUES (" . implode(',', $placeholders) . ")";
        
        $stmt = $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }
    
    public function update($table, $data, $where, $whereParams = []) {
        $sets = array_map(fn($k) => "$k = ?", array_keys($data));
        
        $sql = "UPDATE $table SET " . implode(',', $sets);
        if ($where) {
            $sql .= " WHERE $where";
        }
        
        $params = array_merge(array_values($data), $whereParams);
        return $this->query($sql, $params);
    }
    
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM $table WHERE $where";
        return $this->query($sql, $params);
    }
    
    public function transaction($callback) {
        try {
            $this->pdo->beginTransaction();
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    public function isConnected() {
        return $this->connected;
    }
}
