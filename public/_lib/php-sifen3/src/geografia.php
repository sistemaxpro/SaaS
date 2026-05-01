<?php
    namespace sifen;

    use PDO;

    class Geografia {
        static $dbPath = __DIR__ . '/db/sifen.db';

        public static function getDep($cod) {
            $db = new PDO('sqlite:'.self::$dbPath);
            $sql = "SELECT COD_DEP, DESC_DEP FROM ref_geografica WHERE COD_DEP = {$cod} ORDER BY COD_DEP LIMIT 1";
            $stmt = $db->query($sql);
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            return $result;
        }

        public static function getDis($cod) {
            $db = new PDO('sqlite:'.self::$dbPath);
            $sql = "SELECT COD_DEP, DESC_DEP, COD_DIS, DESC_DIS FROM ref_geografica WHERE COD_DIS = {$cod} ORDER BY COD_DIS LIMIT 1";
            $stmt = $db->query($sql);
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            return $result;
        }

        public static function getCiu($cod) {
            $db = new PDO('sqlite:'.self::$dbPath);
            $sql = "SELECT * FROM ref_geografica WHERE COD_CIU = {$cod} ORDER BY COD_CIU LIMIT 1";
            $stmt = $db->query($sql);
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            return $result;
        }
    }

?>