<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$token_json = file_get_contents(__DIR__ . "/superset_guest_token.php");
$token_data = json_decode($token_json, true);
$token = $token_data["token"] ?? null;

$version = $_GET["VersionID"] ?? "5";
?>

<script src="superset-embedded.min.js"></script>


<div id="superset-dashboard" style="width:100%; height:1200px; border:2px solid red;">
    Loading dashboard…
</div>


<script>
SupersetEmbeddedSdk.embedDashboard({
    id: "ff4d2a46-9f8b-47fc-8d09-bd16a05112a4",
    supersetDomain: "http://localhost:8088",
    mountPoint: document.getElementById("superset-dashboard"),
    token: "<?php echo $token; ?>",
    dashboardUiConfig: {
        hideTabBar: true,
        hideTitle: true
    },
    urlParams: {
        "VersionID": "<?php echo $version; ?>"
    }
});
</script>
