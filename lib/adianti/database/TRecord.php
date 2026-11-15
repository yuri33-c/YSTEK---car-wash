<?php
namespace Adianti\Database;

use PDO;

abstract class TRecord
{
    protected $data = [];
    protected static $tablename;
    protected static $primarykey = 'id';

    public function __construct($id = null)
    {
        if ($id) {
            $this->load($id);
        }
    }

    public function __set($prop, $value)
    {
        $this->data[$prop] = $value;
    }

    public function __get($prop)
    {
        return $this->data[$prop] ?? null;
    }

    public function fromArray($data)
    {
        $this->data = array_merge($this->data, $data);
    }

    public function toArray()
    {
        return $this->data;
    }

    public function load($id)
    {
        $conn = TTransaction::get();
        $table = static::$tablename;
        $pk = static::$primarykey;
        $stmt = $conn->prepare("SELECT * FROM {$table} WHERE {$pk} = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $this->data = $row;
            return $this;
        }
        return null;
    }

    public function store()
    {
        $conn = TTransaction::get();
        $table = static::$tablename;
        $pk = static::$primarykey;

        if (empty($this->data[$pk])) {
            // INSERT
            unset($this->data[$pk]);
            $fields = implode(', ', array_keys($this->data));
            $binds = ':' . implode(', :', array_keys($this->data));
            $stmt = $conn->prepare("INSERT INTO {$table} ({$fields}) VALUES ({$binds}) RETURNING {$pk}");
            $stmt->execute($this->prepareParams($this->data));
            $res = $stmt->fetch();
            if ($res && isset($res[$pk])) {
                $this->data[$pk] = $res[$pk];
            }
        } else {
            // UPDATE
            $sets = [];
            foreach (array_keys($this->data) as $field) {
                if ($field !== $pk) {
                    $sets[] = "{$field} = :{$field}";
                }
            }
            $setStr = implode(', ', $sets);
            $stmt = $conn->prepare("UPDATE {$table} SET {$setStr} WHERE {$pk} = :{$pk}");
            $stmt->execute($this->prepareParams($this->data));
        }
        return $this->data[$pk] ?? true;
    }

    public function delete($id = null)
    {
        $id = $id ?? $this->data[static::$primarykey] ?? null;
        if (!$id) return false;
        $conn = TTransaction::get();
        $table = static::$tablename;
        $pk = static::$primarykey;
        $stmt = $conn->prepare("DELETE FROM {$table} WHERE {$pk} = :id");
        return $stmt->execute([':id' => $id]);
    }

    public static function getObjects($criteria = '', $params = [])
    {
        $conn = TTransaction::get();
        $table = static::$tablename;
        $sql = "SELECT * FROM {$table} " . ($criteria ? "WHERE {$criteria}" : "") . " ORDER BY " . static::$primarykey . " DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $objects = [];
        $class = get_called_class();
        foreach ($rows as $row) {
            $obj = new $class();
            $obj->fromArray($row);
            $objects[] = $obj;
        }
        return $objects;
    }

    private function prepareParams($data)
    {
        $params = [];
        foreach ($data as $key => $val) {
            // PDO/PostgreSQL envia false como '' (inválido para BOOLEAN): converte explicitamente.
            $params[":{$key}"] = is_bool($val) ? ($val ? 'true' : 'false') : $val;
        }
        return $params;
    }
}
