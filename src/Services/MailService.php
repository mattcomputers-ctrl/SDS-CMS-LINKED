<?php

declare(strict_types=1);

namespace SDS\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use SDS\Core\Database;

/**
 * MailService — SMTP email wrapper using PHPMailer.
 *
 * SMTP settings are read from the database settings table (mail.*)
 * which is configured via Admin > Settings in the web interface.
 */
class MailService
{
    /**
     * Send an email with optional file attachments.
     */
    public static function send(
        string|array $to,
        string $subject,
        string $body,
        array $attachments = []
    ): bool {
        $config = self::getMailConfig();

        if (empty($config['smtp_host'])) {
            throw new \RuntimeException('Mail not configured. Set SMTP details in Admin > Settings.');
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

            $recipients = is_array($to) ? $to : [$to];
            foreach ($recipients as $addr) {
                $mail->addAddress(trim($addr));
            }

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
     * Check if mail is configured (SMTP host is set in admin settings).
     */
    public static function isConfigured(): bool
    {
        $config = self::getMailConfig();
        return !empty($config['smtp_host']);
    }

    /**
     * Get email addresses of all users flagged as regulatory.
     */
    public static function getRegulatoryEmails(): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT email FROM users WHERE is_regulatory = 1 AND is_active = 1 AND email IS NOT NULL AND email != ''"
        );
        return array_column($rows, 'email');
    }

    /**
     * Load mail settings from the database settings table.
     */
    private static function getMailConfig(): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'mail.%'");

        $config = [];
        foreach ($rows as $row) {
            $key = str_replace('mail.', '', $row['key']);
            $config[$key] = $row['value'];
        }

        return $config;
    }
}
