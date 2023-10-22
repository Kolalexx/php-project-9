<?php

namespace PostgreSQLTutorial;

/**
 * Создание класса Connection
 */
final class Connection
{
    /**
     * Connection
     * тип @var
     */
    private static ?Connection $conn = null;

    /**
     * Подключение к базе данных и возврат экземпляра объекта \PDO
     * @return \PDO
     * @throws \Exception
     */
    public function connect()
    {
        // чтение параметров в файле конфигурации ini
        $params = parse_ini_file('database.ini');
        if ($params === false) {
            throw new \Exception("Error reading database configuration file");
        }

        // подключение к базе данных postgresql
        $conStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $params['host'],
            $params['port'],
            $params['database'],
            $params['user'],
            $params['password']
        );

        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $pdo;
    }

    /**
     * возврат экземпляра объекта Connection
     * тип @return
     */
    public static function get()
    {
        if (null === static::$conn) {
            static::$conn = new self();
        }

        return static::$conn;
    }

    protected function __construct()
    {

    }

    public function insertLabel($name)
    {
        // подготовка запроса для добавления данных
        $sql = 'INSERT INTO labels(name) VALUES(:name)';
        $stmt = $this->pdo->prepare($sql);

        $now = Carbon::now();
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':created_at', $now);
        $stmt->execute();

        // возврат полученного значения id
        return $this->pdo->lastInsertId('labels_id_seq');
    }
}
