<?php

class User {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    // Buscar usuario por nombre
    public function findByName($nombre) {
        $sql = "SELECT * FROM usuarios WHERE nombre = ? LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $nombre);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
}