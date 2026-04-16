<?php
/**
 * Diagnose script voor IP-verbinding
 */
require_once __DIR__ . '/../backend/config.php';

echo "<html><body style='font-family: sans-serif; padding: 2rem; background: #0f172a; color: white;'>";
echo "<h1>Netwerk Diagnose</h1>";
echo "<div style='background: rgba(255,255,255,0.1); padding: 1rem; border-radius: 8px;'>";
echo "<strong>Browser Host:</strong> " . ($_SERVER['HTTP_HOST'] ?? 'Niet gedetecteerd') . "<br>";
echo "<strong>Config APP_URL:</strong> " . APP_URL . "<br>";
echo "<strong>Protocol:</strong> " . (isset($_SERVER['HTTPS']) ? "HTTPS" : "HTTP") . "<br>";
echo "<strong>Server IP (Local):</strong> " . $_SERVER['SERVER_ADDR'] . "<br>";
echo "</div>";

echo "<h2>API Test:</h2>";
echo "<div id='api-status' style='background: rgba(0,0,0,0.3); padding: 1rem; border-radius: 8px;'>Bezig met testen...</div>";

echo "<script>
fetch('../backend/api/login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'check' })
})
.then(r => r.json())
.then(data => {
    const el = document.getElementById('api-status');
    el.style.color = '#4ade80';
    el.innerHTML = '✅ API is bereikbaar!<br>Response: ' + JSON.stringify(data);
})
.catch(err => {
    const el = document.getElementById('api-status');
    el.style.color = '#f87171';
    el.innerHTML = '❌ API is NIET bereikbaar!<br>Fout: ' + err.message;
});
</script>";

echo "<h2>Tips:</h2>";
echo "<ul>";
echo "<li>Als je dit op je laptop ziet maar NIET op je telefoon, blokkeert de <strong>Windows Firewall</strong> waarschijnlijk poort 80.</li>";
echo "<li>Zorg dat beide apparaten op <strong>hetzelfde Wi-Fi netwerk</strong> zitten.</li>";
echo "<li>Als de pagina laadt maar inloggen faalt, controleer dan of je browser <strong>cookies</strong> toestaat op dit IP-adres.</li>";
echo "</ul>";
echo "</body></html>";
