<?php

class Database
{
    private $host = 'localhost';
    private $user = 'root';        // usuario por defecto en XAMPP/MAMP
    private $pass = '';            // contraseña vacía por defecto
    private $db   = 'sistema_ventas'; // <-- CAMBIALO si tu BD se llama distinto

    public function getConnection()
    {
        $conn = new mysqli($this->host, $this->user, $this->pass, $this->db);

        if ($conn->connect_error) {
            die('Error de conexión: ' . $conn->connect_error);
        }

        $conn->set_charset('utf8mb4');

        return $conn;
    }
}