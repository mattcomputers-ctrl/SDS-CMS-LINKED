<?php

declare(strict_types=1);

namespace SDS\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use SDS\Core\App;

/**
 * MailService — SMTP email wrapper using PHPMailer.
 */
class MailService
{
    /**
     * Send an email with optional file attachments.
     *
     * @param string|array $to          Recipient email(s)
     * @param string       $subject     Email subject
     * @param string       $body        HTML body
     * @param array        $attachments Array of file paths to attach
     * @return bool        True on success
     * @throws \RuntimeException on configuration or send failure
     */
    public static function send(
        string|array $to,
        string $subject,
        string $body,
        array $attachments = []
    ): bool {
        $config = App::config('mail');

        if (!$config || empty($config['smtp_host'])) {
            throw new \RuntimeException('Mail not configured. Add a mail section to config/config.php.');
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->Port       = (int) ($config['smtp_port'] ?? 587);
            $mail->SMTPSecure = $config['smtp_secure'] ?? PHPMailer::ENCRYPTION_STARTTLS;

            if (!empty($config['smtp_user'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $config['smtp_user'];
                $mail->Password = $config['smtp_password'] ?? '';
            }

            $mail->setFrom(
                $config['from_address'] ?? 'sds@company.com',
                $config['from_name'] ?? 'SDS System'
            );

            // Recipients
            $recipients = is_array($to) ? $to : [$to];
            foreach ($recipients as $addr) {
                $mail->addAddress(trim($addr));
            }

            // Attachments
            foreach ($attachments as $path) {
                if (is_array($path)) {
                    $mail->addAttachment($path['path'], $path['name'] ?? '');
                } else {
                    $mail->addAttachment($path);
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

            $mail->send();
            return true;

        } catch (MailException $e) {
            throw new \RuntimeException('Email send failed: ' . $mail->ErrorInfo);
        }
    }

    /**
     * Check if mail is configured.
     */
    public static function isConfigured(): bool
    {
        $config = App::config('mail');
        return $config !== null && !empty($config['smtp_host']);
    }

    /**
     * Get email addresses of all users flagged as regulatory.
     */
    public static function getRegulatoryEmails(): array
    {
        $db = \SDS\Core\Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT email FROM users WHERE is_regulatory = 1 AND is_active = 1 AND email IS NOT NULL AND email != ''"
        );
        return array_column($rows, 'email');
    }
}
