<?php
header('Content-Type: application/json');

$dashboard_uuid = "ff4d2a46-9f8b-47fc-8d09-bd16a05112a4";

$login_url = "http://localhost:8088/api/v1/security/login";
$guest_url = "http://localhost:8088/api/v1/security/guest_token/";

// Step 1 — Login to get access_token
$payload = json_encode([
    "username" => "admin",
    "password" => "admin",
    "provider" => "db",
    "refresh" => true
]);

$ch = curl_init($login_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$result = curl_exec($ch);
curl_close($ch);

$access_token = json_decode($result, true)["access_token"];

// Step 2 — Request guest token
$payload = json_encode([
    "user" => ["username" => "guest"],
    "resources" => [
        ["type" => "dashboard", "id" => $dashboard_uuid]
    ],
    "rls" => []
]);

$ch = curl_init($guest_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $access_token"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$guest = curl_exec($ch);
curl_close($ch);

echo $guest;
