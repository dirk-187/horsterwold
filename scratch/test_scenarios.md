# Test Scenarios: Horsterwold Meterstanden Flow

Dit document beschrijft hoe je de gebruikersflow kunt testen na de data-preparatie.

## Voorbereiding
De database is gevuld met 205 kavels, inclusief de officiële namen uit de Excel en gegenereerde test-e-mailadressen (`bewoner[Nr]@horsterwold.test`).

---

## Scenario 1: Eindafrekening (Jaarafrekening)
*Doel: Testen van de bulk-afhandeling voor alle kavels.*

1. **Meterstanden Invoeren (Gesimuleerd)**
   - Om dit scenario te testen hebben we 'pending' meterstanden nodig voor alle kavels.
   - Run het script `php scratch/seed_test_readings.php` (reeds voorbereid) om voor alle 205 kavels een meterstand in te voeren.

2. **Admin Dashboard**
   - Log in als admin.
   - Ga naar de lijst met kavels. Je ziet nu dat alle kavels de status 'Actie' of 'Wacht' hebben.
   - Filter op 'Wacht op goedkeuring' om de ingediende standen te zien.

3. **Bulk Goedkeuring & Facturatie**
   - Selecteer alle kavels (of een batch).
   - Kies 'Goedkeuren'.
   - Ga naar het Facturatie-overzicht (Taak 6 feature indien reeds actief, anders per kavel).
   - Genereer de PDF voor kavel 159 (zoals in je voorbeeld).

---

## Scenario 2: Tussentijdse Verhuizing
*Doel: Testen of het systeem een kavel correct splitst bij een verhuizing.*

1. **Verhuizing Initiëren**
   - Kies een kavel die al een 'approved' jaarstand heeft (bijv. Kavel 1).
   - Klik in de admin op 'Verhuizing registreren' (indien beschikbaar) of simuleer een verhuizing-reading.

2. **Reading invoeren**
   - Dien een meterstand in met scenario `verhuizing`.
   - Admin keurt deze goed.

3. **Resultaat controleren**
   - Controleer of de oude bewoner op `inactive` wordt gezet.
   - Controleer of de kavel nu `user_id = NULL` heeft, klaar voor de nieuwe bewoner.
   - De beginstand voor de volgende (nieuwe) bewoner moet nu de eindstand van deze verhuis-meting zijn.

---

## Handige Scripts (Scratch)
- `php backend/scripts/import_setup.php`: (Reeds uitgevoerd) Herstelt de basis-data.
- `php scratch/seed_test_readings.php`: Vult de tabel met 205 pending readings voor testdoeleinden.
