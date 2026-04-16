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
    public function sendInvitationEmail(string $toEmail, string $name, string $lotNumber, string $magicLink): bool
    {
        try {
            $settings = new SettingsService();
            $parkName = $settings->get('park_name', 'Horsterwold');

            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $name);
            
            $mail->isHTML(true);
            $mail->Subject = "Uitnodiging: Geef uw meterstanden door — {$parkName} Kavel #{$lotNumber}";
            
            $body = "
                <div style='font-family: sans-serif; line-height: 1.6; color: #333;'>
                    <h2>Beste {$name},</h2>
                    <p>Het is weer tijd om de meterstanden door te geven voor uw kavel <strong>#{$lotNumber}</strong> op {$parkName}.</p>
                    <p>U kunt uw meterstanden eenvoudig en direct doorgeven via onze web-app. U heeft hiervoor geen wachtwoord nodig; de onderstaande link geeft u direct toegang tot uw persoonlijke invoerpagina.</p>
                    <p style='margin: 2rem 0;'>
                        <a href='{$magicLink}' style='background-color: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                            👉 Klik hier om uw meterstanden door te geven
                        </a>
                    </p>
                    <p><small><em>Let op: Deze link is uit veiligheidsoverwegingen 24 uur geldig en kan slechts eenmaal worden gebruikt om in te loggen.</em></small></p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 2rem 0;'>
                    <p>Met vriendelijke groet,<br><strong>Beheer {$parkName}</strong></p>
                </div>
            ";
            
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));
            
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
