<?php
// Embed Metabase Dashboard ID 2 (CBMS GOL Demo) into CBMSv21
$SECRET_KEY   = "ab58e53e08d926c5dd7200b260281cbce6cb33ea85a78af58e9accca1dc325f1";
$DASHBOARD_ID = 2;   // ← this is your dashboard

$payload = [
    "resource" => ["dashboard" => $DASHBOARD_ID],
    "params"   => new stdClass(),                                   // add filters later if needed
    "exp"      => time() + (10 * 365 * 24 * 60 * 60)                 // valid 10 years
];

$token     = base64_encode(json_encode($payload));
$signature = hash_hmac('sha256', $token, $SECRET_KEY);

$iframe_url = "http://localhost:3000/public/dashboard/1af4005e-6c29-4cd6-93ee-019af145be80";
?>

<iframe src="<?= htmlspecialchars($iframe_url) ?>" 
        width="100%" 
        height="900" 
        frameborder="0" 
        allowtransparency 
        allowfullscreen>
</iframe>