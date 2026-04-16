<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #333; line-height: 1.6; }
        .header { margin-bottom: 30px; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; }
        .header h1 { color: #3b82f6; margin: 0; }
        .invoice-info { margin-bottom: 30px; }
        .invoice-info table { width: 100%; }
        .invoice-info td { vertical-align: top; }
        .section-title { background: #f3f4f6; padding: 5px 10px; font-weight: bold; margin: 20px 0 10px 0; }
        .data-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .data-table th { text-align: left; border-bottom: 1px solid #e5e7eb; padding: 10px; font-size: 14px; }
        .data-table td { border-bottom: 1px solid #f3f4f6; padding: 10px; font-size: 14px; }
        .text-right { text-align: right; }
        .totals-table { width: 300px; margin-left: auto; border-top: 2px solid #3b82f6; padding-top: 10px; }
        .totals-table td { padding: 5px 10px; }
        .grand-total { font-size: 18px; font-weight: bold; color: #3b82f6; }
        .footer { margin-top: 50px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>JAARAFREKENING <?php echo date('Y'); ?></h1>
        <p><?php echo APP_NAME; ?></p>
    </div>

    <div class="invoice-info">
        <table>
            <tr>
                <td>
                    <strong>Aan:</strong><br>
                    Bewoner Kavel <?php echo $billingData['lot']['lot_number']; ?><br>
                    Horsterwoldpark<br>
                    Postcode / Plaats
                </td>
                <td class="text-right">
                    <strong>Factuurnummer:</strong> INV-<?php echo str_pad($billingData['lot_id'], 3, '0', STR_PAD_LEFT); ?>-<?php echo date('Y'); ?><br>
                    <strong>Datum:</strong> <?php echo date('d-m-Y'); ?><br>
                    <strong>Periode:</strong> 01-01 Tot 31-12-<?php echo date('Y'); ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">VERBRUIKSKOSTEN</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Omschrijving</th>
                <th class="text-right">Verbruik</th>
                <th class="text-right">Tarief (excl.)</th>
                <th class="text-right">Totaal (€)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Gasverbruik</td>
                <td class="text-right"><?php echo number_format($billingData['consumption']['gas'], 2, ',', '.'); ?> m³</td>
                <td class="text-right">€ <?php echo number_format($billingData['tariffs']['gas_price_per_m3'], 4, ',', '.'); ?></td>
                <td class="text-right">€ <?php echo number_format($billingData['costs']['gas'], 2, ',', '.'); ?></td>
            </tr>
            <tr>
                <td>Waterverbruik</td>
                <td class="text-right"><?php echo number_format($billingData['consumption']['water'], 2, ',', '.'); ?> m³</td>
                <td class="text-right">€ <?php echo number_format($billingData['tariffs']['water_price_per_m3'], 4, ',', '.'); ?></td>
                <td class="text-right">€ <?php echo number_format($billingData['costs']['water'], 2, ',', '.'); ?></td>
            </tr>
            <tr>
                <td>Elektraverbruik</td>
                <td class="text-right"><?php echo number_format($billingData['consumption']['elec'], 2, ',', '.'); ?> kWh</td>
                <td class="text-right">€ <?php echo number_format($billingData['tariffs']['electricity_price_per_kwh'], 4, ',', '.'); ?></td>
                <td class="text-right">€ <?php echo number_format($billingData['costs']['elec'], 2, ',', '.'); ?></td>
            </tr>
            <?php if ($billingData['consumption']['solar'] > 0): ?>
            <tr>
                <td>Opwekking Zonnepanelen (Credit)</td>
                <td class="text-right"><?php echo number_format($billingData['consumption']['solar'], 2, ',', '.'); ?> kWh</td>
                <td class="text-right">€ <?php echo number_format($billingData['tariffs']['solar_return_price_per_kwh'], 4, ',', '.'); ?></td>
                <td class="text-right">-€ <?php echo number_format($billingData['costs']['solar_credit'], 2, ',', '.'); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="section-title">VASTE LASTEN</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Omschrijving</th>
                <th></th>
                <th class="text-right">Totaal (€)</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Vastrecht Gas (12 mnd)</td><td></td><td class="text-right">€ <?php echo number_format($billingData['fixed']['gas'], 2, ',', '.'); ?></td></tr>
            <tr><td>Vastrecht Water (12 mnd)</td><td></td><td class="text-right">€ <?php echo number_format($billingData['fixed']['water'], 2, ',', '.'); ?></td></tr>
            <tr><td>Vastrecht Elektra (12 mnd)</td><td></td><td class="text-right">€ <?php echo number_format($billingData['fixed']['elec'], 2, ',', '.'); ?></td></tr>
            <tr><td>VVE Bijdrage Jaar</td><td></td><td class="text-right">€ <?php echo number_format($billingData['fixed']['vve'], 2, ',', '.'); ?></td></tr>
            <tr><td>Erfpacht Jaar</td><td></td><td class="text-right">€ <?php echo number_format($billingData['fixed']['erfpacht'], 2, ',', '.'); ?></td></tr>
        </tbody>
    </table>

    <?php if ($billingData['summary']['correction'] != 0): ?>
    <div class="section-title">CORRECTIES</div>
    <table class="data-table">
        <tbody>
            <tr>
                <td><?php echo $billingData['summary']['correction_reason'] ?: 'Handmatige correctie'; ?></td>
                <td class="text-right">€ <?php echo number_format($billingData['summary']['correction'], 2, ',', '.'); ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <table class="totals-table">
        <tr>
            <td>Subtotaal (excl. BTW)</td>
            <td class="text-right">€ <?php echo number_format($billingData['summary']['subtotal_ex_vat'], 2, ',', '.'); ?></td>
        </tr>
        <tr>
            <td>BTW (<?php echo $billingData['summary']['vat_rate']; ?>%)</td>
            <td class="text-right">€ <?php echo number_format($billingData['summary']['vat_amount'], 2, ',', '.'); ?></td>
        </tr>
        <tr class="grand-total">
            <td>TOTAAL BEREKEND</td>
            <td class="text-right">€ <?php echo number_format($billingData['summary']['total'], 2, ',', '.'); ?></td>
        </tr>
    </table>

    <div class="footer">
        Deze afrekening is automatisch gegenereerd via het Horsterwold Meterstanden portaal.<br>
        Betaling dient te geschieden via bankoverschrijving naar IBAN: NL00 BANK 0000 0000 00 o.v.v. Kavel <?php echo $billingData['lot']['lot_number']; ?>.
    </div>
</body>
</html>
