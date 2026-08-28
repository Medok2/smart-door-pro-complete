<?php
namespace SmartDoor\Models;

use SmartDoor\Config\Database;

class BaseModel {
    protected static $table;
    protected $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public static function find($id) {
        $db = Database::getInstance();
        $stmt = $db->query('SELECT * FROM ' . static::$table . ' WHERE id = ? LIMIT 1', [$id]);
        return $stmt->fetch();
    }
    
    public static function findBy($column, $value) {
        $db = Database::getInstance();
        $stmt = $db->query('SELECT * FROM ' . static::$table . ' WHERE ' . $column . ' = ? LIMIT 1', [$value]);
        return $stmt->fetch();
    }
    
    public static function all($limit = 50, $offset = 0) {
        $db = Database::getInstance();
        $stmt = $db->query(
            'SELECT * FROM ' . static::$table . ' LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        return $stmt->fetchAll();
    }
    
    public static function count() {
        $db = Database::getInstance();
        $stmt = $db->query('SELECT COUNT(*) as count FROM ' . static::$table);
        $result = $stmt->fetch();
        return $result['count'];
    }
    
    public static function create($data) {
        $db = Database::getInstance();
        return $db->insert(static::$table, $data);
    }
    
    public static function update($id, $data) {
        $db = Database::getInstance();
        $db->update(static::$table, $data, 'id = ?', [$id]);
        return self::find($id);
    }
    
    public static function delete($id) {
        $db = Database::getInstance();
        return $db->delete(static::$table, 'id = ?', [$id]);
    }
    
    public static function paginate($page = 1, $perPage = 50) {
        $db = Database::getInstance();
        
        $total = self::count();
        $offset = ($page - 1) * $perPage;
        
        $stmt = $db->query(
            'SELECT * FROM ' . static::$table . ' LIMIT ? OFFSET ?',
            [$perPage, $offset]
        );
        
        return [
            'data' => $stmt->fetchAll(),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => ceil($total / $perPage)
            ]
        ];
    }
}
