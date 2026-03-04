<?php

/**
 * email.php
 * Email sending via PHPMailer.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load Composer autoloader
$composer_autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

/**
 * Wraps content in a branded HTML email template using values from config.
 *
 * @param string $content  The inner HTML content.
 * @param string $preheader Short preview text shown by email clients.
 * @return string Full HTML email body.
 */
function email_template(string $content, string $preheader = ''): string
{
    global $config;
    $server   = htmlspecialchars($config['realm']['name']   ?? 'WoW Server');
    $site_url = htmlspecialchars($config['site']['base_url'] ?? '');
    $year     = date('Y');
    $accent   = '#c8a96e';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$server}</title>
</head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:Arial,sans-serif;">
  <!-- Preheader -->
  <span style="display:none;max-height:0;overflow:hidden;">{$preheader}</span>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
    <tr><td align="center" style="padding:32px 16px;">

      <!-- Card -->
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#12121f;border-radius:14px;border:1px solid #2a1a0a;overflow:hidden;">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1a0f07,#2a1a0a);padding:28px 36px;border-bottom:1px solid #3a2a1a;">
            <h1 style="margin:0;font-size:22px;letter-spacing:2px;color:{$accent};">{$server}</h1>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:32px 36px;color:#c0cce0;line-height:1.7;font-size:15px;">
            {$content}
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="padding:20px 36px;border-top:1px solid #1e1e2e;text-align:center;font-size:12px;color:#4a5568;">
            &copy; {$year} {$server}. This is an automated message — please do not reply directly.
            <br>
            <a href="{$site_url}" style="color:{$accent};text-decoration:none;">{$site_url}</a>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Sends a general email using PHPMailer.
 *
 * @param string $to      Recipient email address.
 * @param string $subject Email subject.
 * @param string $body    HTML body.
 * @param string $altBody Plain text fallback.
 * @return bool True on success, false on failure.
 */
function send_email(string $to, string $subject, string $body, string $altBody = ''): bool
{
    global $config;

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('PHPMailer class not found. Cannot send email.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $config['smtp']['host'];
        $mail->SMTPAuth   = $config['smtp']['auth'];
        $mail->Username   = $config['smtp']['username'];
        $mail->Password   = $config['smtp']['password'];
        $mail->SMTPSecure = ($config['smtp']['secure'] === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS
            : (strtolower((string)$config['smtp']['secure']) === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
        $mail->Port       = $config['smtp']['port'];

        $mail->setFrom($config['smtp']['from_email'], $config['smtp']['from_name']);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Sends a support ticket email, optionally with attachments.
 *
 * @param string $to          Recipient email address.
 * @param string $subject     Email subject.
 * @param string $body        HTML body.
 * @param array  $attachments Optional list of file paths to attach.
 * @return bool True on success, false on failure.
 */
function send_ticket_email(string $to, string $subject, string $body, array $attachments = []): bool
{
    global $config;

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('PHPMailer class not found. Cannot send email.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $config['smtp']['host'];
        $mail->SMTPAuth   = $config['smtp']['auth'];
        $mail->Username   = $config['smtp']['username'];
        $mail->Password   = $config['smtp']['password'];
        $mail->SMTPSecure = ($config['smtp']['secure'] === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS
            : (strtolower((string)$config['smtp']['secure']) === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
        $mail->Port       = $config['smtp']['port'];

        $mail->setFrom($config['smtp']['from_email'], $config['smtp']['support_from_name']);
        $mail->addAddress($to);

        foreach ($attachments as $attachment) {
            $mail->addAttachment($attachment);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Ticket could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
