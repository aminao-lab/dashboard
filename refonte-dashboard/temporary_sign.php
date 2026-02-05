<?php
$secretsPath = __DIR__ . '/config/secrets.php';
if (!file_exists($secretsPath)) {
  fwrite(STDERR, "❌ secrets.php introuvable: $secretsPath\n");
  exit(1);
}

$secrets = require $secretsPath;
if (!is_array($secrets)) {
  fwrite(STDERR, "❌ secrets.php doit retourner un array. Reçu: " . gettype($secrets) . "\n");
  exit(1);
}

$secret = $secrets['app_session_secret'] ?? '';
if (!is_string($secret) || $secret === '') {
  fwrite(STDERR, "❌ app_session_secret manquant ou vide dans config/secrets.php\n");
  exit(1);
}

$userId = '67a21edbeaae44f4240c2107'; // mets ton user_id
$exp = time() + 600; // 10 minutes
$sig = hash_hmac('sha256', $userId . '|' . $exp, $secret);

echo "http://localhost:8000/api/session_start.php?user_id=" . urlencode($userId) . "&exp=$exp&sig=$sig\n";
