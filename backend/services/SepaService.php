<?php
/**
 * SEPA XML Generation Service
 * Creëert pain.008.001.02 XML bestanden voor incasso-opdrachten.
 */

namespace Horsterwold\Services;

require_once __DIR__ . '/../config.php';
use Exception;
use PDO;

class SepaService
{
    /**
     * Generate SEPA pain.008 XML format
     * 
     * @param array $transactions Array of [['name', 'iban', 'mandate_date', 'amount', 'description', 'lot_id']]
     */
    public function generateSepaXml(array $transactions): string
    {
        if (empty($transactions)) {
            throw new Exception("Geen transacties voor export.");
        }

        $settings = new SettingsService();
        $creditorName = $settings->get('sepa_creditor_name', SEPA_CREDITOR_NAME);
        $creditorIban = $settings->get('sepa_creditor_iban', SEPA_CREDITOR_IBAN);
        $creditorId   = $settings->get('sepa_creditor_id', SEPA_CREDITOR_ID);

        // Base Configuration
        $messageId = 'MSG' . date('YmdHis');
        $creationDateTime = date('Y-m-d\TH:i:sP');
        $numberOfTransactions = count($transactions);
        $controlSum = array_sum(array_column($transactions, 'amount'));
        
        // This is a simplified pain.008.001.02 XML generator.
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"></Document>');
        
        $cstmrDrctDbtInitn = $xml->addChild('CstmrDrctDbtInitn');

        // Group Header
        $grpHdr = $cstmrDrctDbtInitn->addChild('GrpHdr');
        $grpHdr->addChild('MsgId', $messageId);
        $grpHdr->addChild('CreDtTm', $creationDateTime);
        $grpHdr->addChild('NbOfTxs', $numberOfTransactions);
        $grpHdr->addChild('CtrlSum', number_format($controlSum, 2, '.', ''));
        $initgPty = $grpHdr->addChild('InitgPty');
        $initgPty->addChild('Nm', $creditorName);
        
        // Payment Information
        $pmtInf = $cstmrDrctDbtInitn->addChild('PmtInf');
        $pmtInf->addChild('PmtInfId', 'PMT' . date('YmdHis'));
        $pmtInf->addChild('PmtMtd', 'DD');
        $pmtInf->addChild('NbOfTxs', $numberOfTransactions);
        $pmtInf->addChild('CtrlSum', number_format($controlSum, 2, '.', ''));
        
        $pmtTpInf = $pmtInf->addChild('PmtTpInf');
        $pmtTpInf->addChild('SvcLvl')->addChild('Cd', 'SEPA');
        $pmtTpInf->addChild('LclInstrm')->addChild('Cd', 'CORE'); 
        $pmtTpInf->addChild('SeqTp', 'OOFF'); 
        
        $reqdColltnDt = date('Y-m-d', strtotime('+3 weekdays'));
        $pmtInf->addChild('ReqdColltnDt', $reqdColltnDt);
        
        $cdtr = $pmtInf->addChild('Cdtr');
        $cdtr->addChild('Nm', $creditorName);
        
        $cdtrAcct = $pmtInf->addChild('CdtrAcct');
        $cdtrAcct->addChild('Id')->addChild('IBAN', $creditorIban);
        
        $cdtrAgt = $pmtInf->addChild('CdtrAgt');
        $cdtrAgt->addChild('FinInstnId')->addChild('Othr')->addChild('Id', 'NOTPROVIDED'); 
        
        // Creditor Scheme Identification
        $cdtrSchmeId = $pmtInf->addChild('CdtrSchmeId');
        $id = $cdtrSchmeId->addChild('Id')->addChild('PrvtId')->addChild('Othr');
        $id->addChild('Id', $creditorId);
        $id->addChild('SchmeNm')->addChild('Prtry', 'SEPA');
        
        // Transactions
        foreach ($transactions as $t) {
            $tx = $pmtInf->addChild('DrctDbtTxInf');
            
            $pmtId = $tx->addChild('PmtId');
            $pmtId->addChild('EndToEndId', 'LOT' . $t['lot_id'] . '-' . date('Y'));
            
            $tx->addChild('InstdAmt', number_format($t['amount'], 2, '.', ''))->addAttribute('Ccy', 'EUR');
            
            $mndtId = 'MAND' . $t['lot_id'];
            $drctDbtTx = $tx->addChild('DrctDbtTx');
            $mndtRltdInf = $drctDbtTx->addChild('MndtRltdInf');
            $mndtRltdInf->addChild('MndtId', $mndtId);
            $mndtRltdInf->addChild('DtOfSgntr', date('Y-m-d', strtotime($t['mandate_date'])));
            
            $dbtrAgt = $tx->addChild('DbtrAgt');
            $dbtrAgt->addChild('FinInstnId')->addChild('Othr')->addChild('Id', 'NOTPROVIDED');
            
            $dbtr = $tx->addChild('Dbtr');
            $dbtr->addChild('Nm', mb_substr($t['name'], 0, 70, 'UTF-8'));
            
            $dbtrAcct = $tx->addChild('DbtrAcct');
            $dbtrAcct->addChild('Id')->addChild('IBAN', $t['iban']);
            
            $rmtInf = $tx->addChild('RmtInf');
            $rmtInf->addChild('Ustrd', mb_substr($t['description'], 0, 140, 'UTF-8'));
        }

        // Format to pretty string
        $dom = new \DOMDocument("1.0");
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        
        return $dom->saveXML();
    }
}
