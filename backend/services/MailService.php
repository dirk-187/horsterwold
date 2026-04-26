<?php
/**
 * MailService — Handles sending emails (Invoices, Invitations) via PHPMailer.
 */

namespace Horsterwold\Services;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService
{
    private function getMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        
        // SMTP Settings from config.php
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = MAIL_ENCRYPTION; // 'ssl' (port 465) or 'tls' (port 587)
        $mail->Port       = MAIL_PORT;
        $mail->Timeout    = 15;

        // Op Windows/XAMPP ontbreken vaak SSL-certificaten — bypass verificatie in development
        if (defined('APP_ENV') && APP_ENV === 'development') {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }
        
        $settings = new SettingsService();
        $fromAddr = MAIL_FROM_ADDR;
        $fromName = $settings->get('park_name', MAIL_FROM_NAME);

        $mail->setFrom($fromAddr, $fromName);
        $mail->CharSet = 'UTF-8';
        
        return $mail;

    }

    /**
     * Send an invitation (login link) to a resident
     */
    public function sendInvitationEmail(string $toEmail, string $name, string $lotNumber, string $magicLink, string $expiryDate = '', string $scenario = 'jaarafrekening', string $customBody = ''): bool
    {
        try {
            $settings = new SettingsService();
            $parkName = $settings->get('park_name', 'Horsterwold');

            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $name);
            
            $mail->isHTML(true);
            
            if ($scenario === 'herinnering') {
                $mail->Subject = "HERINNERING: Geef uw meterstanden door — {$parkName} Kavel #{$lotNumber}";
            } else {
                $mail->Subject = "Uitnodiging: Geef uw meterstanden door — {$parkName} Kavel #{$lotNumber}";
            }
            
            $formattedExpiry = $expiryDate ? date('d-m-Y', strtotime($expiryDate)) : date('d-m-Y', strtotime('+7 days'));

            // Het aanpasbare deel of de standaardtekst
            if ($customBody) {
                // Vervang eventuele placeholders in het handmatige deel
                $customPart = str_replace('{name}', $name, $customBody);
                $customPart = str_replace('{lot}', $lotNumber, $customPart);
                $customPart = str_replace('{link}', $magicLink, $customPart);
                $customPart = str_replace('{expiry}', $formattedExpiry, $customPart);
                $customPart = nl2br(htmlspecialchars($customPart));
            } else {
                $reminderText = "";
                if ($scenario === 'herinnering') {
                    $reminderText = "<p style='color: #ef4444; font-weight: bold;'>LET OP: De meterstanden moeten voor {$formattedExpiry} verzonden zijn. Wanneer dat niet het geval is, worden er administratiekosten in rekening gebracht.</p>";
                }
                $customPart = "{$reminderText}<p>Let op: Deze link is geldig tot <strong>{$formattedExpiry}</strong>.</p>";
            }

            // De volledige HTML wrapper (gebaseerd op het origineel van de gebruiker)
            $logoUrl = APP_URL . '/public/logo/logo_VVE.jpg';
            $body = "
                <div style='font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #eee; padding: 2rem; border-radius: 12px;'>
                    <div style='text-align: center; margin-bottom: 2rem;'>
                        <img src='{$logoUrl}' alt='Logo VVE' style='max-height: 80px;'>
                    </div>
                    <h2 style='color: #1e293b;'>Beste {$name},</h2>
                    <p>Het is weer tijd om de meterstanden door te geven voor uw kavel <strong>#{$lotNumber}</strong> op {$parkName}.</p>
                    <p>U kunt uw meterstanden eenvoudig en direct doorgeven via onze web-app. U heeft hiervoor geen wachtwoord nodig; de onderstaande link geeft u direct toegang tot uw persoonlijke invoerpagina.</p>
                    
                    <div style='margin: 2.5rem 0;'>
                        <p style='margin-bottom: 0.75rem; font-weight: 600; color: #1e293b;'>Geef je meterstanden nu door</p>
                        <a href='{$magicLink}' style='background-color: #2563eb; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>
                            Klik hier
                        </a>
                    </div>

                    <div style='margin-top: 2rem; font-size: 0.95rem; color: #475569;'>
                        {$customPart}
                    </div>

                    <hr style='border: 0; border-top: 1px solid #eee; margin: 2rem 0;'>
                    <p style='font-size: 0.9rem;'>Met vriendelijke groet,<br><strong>Beheer {$parkName}</strong></p>
                </div>
            ";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br />', '</div>', '</p>'], ["\n", "\n", "\n", "\n\n"], $body));
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mail Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send invoice PDF to resident
     */
    public function sendInvoiceEmail(string $toEmail, string $pdfPath, string $lotNumber): bool
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail);
            
            $mail->isHTML(true);
            $mail->Subject = "Uw Jaarafrekening " . date('Y') . " - Kavel " . $lotNumber;
            
            $body = "
                <div style='font-family: sans-serif; line-height: 1.6; color: #333;'>
                    <p>Geachte heer/mevrouw,</p>
                    <p>Bijgevoegd vindt u de jaarafrekening voor kavel <strong>{$lotNumber}</strong>.</p>
                    <p>Mocht u vragen hebben naar aanleiding van deze afrekening, dan kunt u contact opnemen met het beheer.</p>
                    <br>
                    <p>Met vriendelijke groet,<br><strong>Beheer Horsterwold</strong></p>
                </div>
            ";
            
            $mail->Body = $body;
            $mail->addAttachment($pdfPath, "Factuur_Kavel_{$lotNumber}.pdf");
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Invoice Mail Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send admin logic magic link
     */
    public function sendAdminLoginEmail(string $toEmail, string $magicLink): bool
    {
        try {
            $settings = new SettingsService();
            $parkName = $settings->get('park_name', 'Horsterwold');

            $mail = $this->getMailer();
            $mail->addAddress($toEmail);
            
            $mail->isHTML(true);
            $mail->Subject = "Beheerders Login — {$parkName}";
            
            $body = "
                <div style='font-family: sans-serif; line-height: 1.6; color: #333;'>
                    <h2>Beheerders Login</h2>
                    <p>Hier is uw persoonlijke inloglink voor het beheer van {$parkName}. Deze link is 1 uur geldig.</p>
                    <p style='margin: 2rem 0;'>
                        <a href='{$magicLink}' style='background-color: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                            👉 Inloggen als Beheerder
                        </a>
                    </p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 2rem 0;'>
                    <p>Met vriendelijke groet,<br><strong>Systeem {$parkName}</strong></p>
                </div>
            ";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Admin Login Mail Error: " . $e->getMessage());
            return false;
        }
    }
}
