<?php
// app/controllers/tiendanube/api.php
// Helper para llamadas a la API de Tienda Nube

function tn_request(string $method, string $endpoint, array $body = [], array $config = []): array {
    $storeId     = $config['store_id'];
    $accessToken = $config['access_token'];

    $url = "https://api.tiendanube.com/v1/{$storeId}/{$endpoint}";

    $headers = [
        "Authentication: bearer {$accessToken}",
        "User-Agent: TYPSistema (lomellofabrizio@gmail.com)",
        "Content-Type: application/json",
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) throw new Exception("cURL error: $curlError");

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        $msg = $data['description'] ?? $data['message'] ?? "HTTP $httpCode";
        throw new Exception("TN API error: $msg");
    }

    return $data ?? [];
}

function tn_get_config($conn): array {
    $row = $conn->query("SELECT store_id, access_token, id_deposito FROM tiendanube_config LIMIT 1")->fetch_assoc();
    if (!$row || !$row['store_id'] || !$row['access_token']) {
        throw new Exception('Tienda Nube no configurada. Ingresá el Store ID y Access Token.');
    }
    return $row;
}
