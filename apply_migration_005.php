<?php
/**
 * Apply Migration 005: Fix Consumption Rollover
 * 
 * Dit script voert de migratie uit om alle consumption waarden te herberekenen
 * met de correcte rollover logica.
 */

echo "<!DOCTYPE html>\n";
echo "<html><head><meta charset='UTF-8'><title>Migration 005</title>";
echo "<style>body{font-family:monospace;padding:2rem;background:#1e293b;color:#e2e8f0;}";
echo "pre{background:#0f172a;padding:1rem;border-radius:8px;border:1px solid #334155;}</style>";
echo "</head><body>";
echo "<h1>🔄 Migration 005: Fix Consumption Rollover</h1>";
echo "<pre>";

require_once __DIR__ . '/database/migrations/005_fix_consumption_rollover.php';

echo "</pre>";
echo "<p><a href='public/admin/index.html' style='color:#60a5fa;'>← Terug naar Admin Dashboard</a></p>";
echo "</body></html>";
