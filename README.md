# Horsterwold Meterstanden

PWA + Admin Dashboard voor het automatiseren van meterstanden voor 205 kavels in recreatiepark Horsterwold.

## Tech Stack
- **Frontend:** Vanilla JS + HTML5 + CSS3 (PWA)
- **Backend:** PHP 8.x (geen framework)
- **Database:** MySQL (PDO)
- **Storage:** Google Cloud Storage / AWS S3
- **OCR:** Google Cloud Vision / AWS Textract

## Lokale omgeving opzetten (XAMPP)

1. Maak een database aan: `CREATE DATABASE horsterwold CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
2. Importeer het schema: `mysql -u root horsterwold < database/schema.sql`
3. Importeer de kavels: `mysql -u root horsterwold < database/seed_lots.sql`
4. Kopieer de config: `cp backend/config.example.php backend/config.php` en vul je waarden in
5. Zet de document root op de map `public/`

## Mappenstructuur

```
Horsterwold/
├── backend/
│   ├── config.example.php      # Configuratie template (kopieer naar config.php)
│   ├── core/
│   │   └── Database.php        # PDO singleton
│   ├── services/
│   │   ├── AuthService.php     # Magic link authenticatie
│   │   ├── AfwijkingService.php # Afwijking detectie (>20% afwijking)
│   │   ├── OcrService.php      # OCR integratie [Fase 3]
│   │   └── BillingService.php  # Bereken afrekening [Fase 5]
│   ├── api/
│   │   ├── readings.php        # POST/GET meterstanden [Fase 3]
│   │   └── login.php           # Magic link endpoints [Fase 2]
│   └── admin/
│       └── ...                 # Admin dashboard endpoints [Fase 4]
├── database/
│   ├── schema.sql              # Alle CREATE TABLE statements
│   └── seed_lots.sql           # 205 kavels seed data
├── public/                     # Web root
│   ├── index.html              # PWA startpagina [Fase 3]
│   └── admin/                  # Admin dashboard [Fase 4]
├── projectplanning/
│   ├── 1_prompt.md
│   └── 2_projectplan.md
└── Ontvangen documenten/
    └── Kopie van 2025 Sheet eindafrekening.xlsx
```

## Fasen

| Fase | Status | Beschrijving |
|------|--------|--------------|
| 1 | ✅ Gereed | Specificatie & Datamodel |
| 2 | 🔲 Gepland | Backend & Database setup (XAMPP) |
| 3 | 🔲 Gepland | OCR integratie & PWA frontend |
| 4 | 🔲 Gepland | Admin dashboard & afwijking detectie |
| 5 | 🔲 Gepland | Berekening jaarafrekening & facturering |
| 6 | 🔲 Gepland | Testen & uitrol |
