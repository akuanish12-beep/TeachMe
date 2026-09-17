<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

class SupportEmailService
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private string $encryption;
    private string $staffInbox;

    public function __construct()
    {
        $this->host = env('SUPPORT_SMTP_HOST', env('SMTP_HOST', ''));
        $this->port = (int) env('SUPPORT_SMTP_PORT', env('SMTP_PORT', 465));
        $this->username = env('SUPPORT_SMTP_USER', '');
        $this->password = env('SUPPORT_SMTP_PASS', '');
        $this->fromEmail = env('SUPPORT_SMTP_FROM', $this->username);
        $this->fromName = env('SUPPORT_SMTP_FROM_NAME', 'TeachMe Support');
        $this->encryption = env('SUPPORT_SMTP_ENCRYPTION', env('SMTP_ENCRYPTION', 'ssl'));
        $this->staffInbox = env('SUPPORT_INBOX_EMAIL', $this->fromEmail);

        if (empty($this->host) || empty($this->username) || empty($this->password)) {
            throw new \RuntimeException('Support SMTP is not configured');
        }
    }

    public function sendTicketCreatedToUser(string $toEmail, string $name, int $ticketId, string $subject): void
    {
        $displayName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $appUrl = rtrim(env('FRONTEND_ORIGIN', 'https://teachme.mom'), '/');
        $ticketUrl = htmlspecialchars("{$appUrl}/support?ticket={$ticketId}", ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
<p style="margin:0 0 12px;color:#303a50;font-size:16px;line-height:1.5;">Hi {$displayName},</p>
<p style="margin:0 0 16px;color:#5f6778;font-size:15px;line-height:1.6;">
  We received your support request <strong style="color:#303a50;">#{$ticketId}</strong> — <em>{$safeSubject}</em>.
</p>
<p style="margin:0 0 24px;color:#5f6778;font-size:14px;line-height:1.6;">
  Our team will review it and reply by email. You can also track updates on our support page.
</p>
<div style="text-align:center;">
  <a href="{$ticketUrl}" style="display:inline-block;padding:12px 28px;background-color:#303a50;color:#f5f3ef;font-size:14px;font-weight:600;text-decoration:none;border-radius:999px;">View ticket</a>
</div>
HTML;

        $this->send($toEmail, $name, "Support ticket #{$ticketId} received", $body, "We received your support request #{$ticketId}: {$subject}");
    }

    public function sendNewTicketToStaff(int $ticketId, string $guestName, string $guestEmail, string $subject, string $preview): void
    {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safePreview = htmlspecialchars(mb_substr($preview, 0, 500), ENT_QUOTES, 'UTF-8');
        $adminUrl = rtrim(env('APP_URL', 'https://teachme.mom'), '/') . '/api/staff/admin';

        $body = <<<HTML
<p style="margin:0 0 12px;color:#303a50;font-size:16px;font-weight:600;">New support ticket #{$ticketId}</p>
<p style="margin:0 0 8px;color:#5f6778;font-size:14px;"><strong>From:</strong> {$guestName} &lt;{$guestEmail}&gt;</p>
<p style="margin:0 0 16px;color:#5f6778;font-size:14px;"><strong>Subject:</strong> {$safeSubject}</p>
<p style="margin:0 0 20px;padding:12px 16px;background-color:#f5f3ef;border-radius:8px;color:#303a50;font-size:14px;line-height:1.5;">{$safePreview}</p>
<div style="text-align:center;">
  <a href="{$adminUrl}" style="display:inline-block;padding:12px 28px;background-color:#303a50;color:#f5f3ef;font-size:14px;font-weight:600;text-decoration:none;border-radius:999px;">Open admin panel</a>
</div>
HTML;

        $this->send($this->staffInbox, 'TeachMe Staff', "New ticket #{$ticketId}: {$subject}", $body, "New ticket #{$ticketId} from {$guestName}");
    }

    public function sendReplyToUser(string $toEmail, string $name, int $ticketId, string $subject, string $replyBody): void
    {
        $displayName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safeReply = nl2br(htmlspecialchars($replyBody, ENT_QUOTES, 'UTF-8'));
        $appUrl = rtrim(env('FRONTEND_ORIGIN', 'https://teachme.mom'), '/');

        $body = <<<HTML
<p style="margin:0 0 12px;color:#303a50;font-size:16px;line-height:1.5;">Hi {$displayName},</p>
<p style="margin:0 0 16px;color:#5f6778;font-size:15px;line-height:1.6;">
  We replied to your ticket <strong style="color:#303a50;">#{$ticketId}</strong> — {$safeSubject}:
</p>
<div style="margin:0 0 20px;padding:16px;background-color:#f5f3ef;border-left:4px solid #303a50;border-radius:8px;color:#303a50;font-size:14px;line-height:1.6;">{$safeReply}</div>
<p style="margin:0;color:#5f6778;font-size:13px;">Reply on <a href="{$appUrl}/support" style="color:#303a50;">teachme.mom/support</a></p>
HTML;

        $this->send($toEmail, $name, "Re: [Ticket #{$ticketId}] {$subject}", $body, "Reply to ticket #{$ticketId}:\n\n{$replyBody}");
    }

    public function sendUserReplyToStaff(int $ticketId, string $guestName, string $guestEmail, string $subject, string $replyBody): void
    {
        $safeReply = nl2br(htmlspecialchars($replyBody, ENT_QUOTES, 'UTF-8'));

        $body = <<<HTML
<p style="margin:0 0 12px;color:#303a50;font-size:16px;font-weight:600;">Customer replied — ticket #{$ticketId}</p>
<p style="margin:0 0 8px;color:#5f6778;font-size:14px;"><strong>From:</strong> {$guestName} &lt;{$guestEmail}&gt;</p>
<p style="margin:0 0 16px;color:#5f6778;font-size:14px;"><strong>Subject:</strong> {$subject}</p>
<div style="padding:16px;background-color:#f5f3ef;border-radius:8px;color:#303a50;font-size:14px;line-height:1.6;">{$safeReply}</div>
HTML;

        $this->send($this->staffInbox, 'TeachMe Staff', "Re: [Ticket #{$ticketId}] {$subject}", $body, "Customer reply on ticket #{$ticketId}:\n\n{$replyBody}");
    }

    private function send(string $toEmail, string $toName, string $subject, string $innerHtml, string $altBody): void
    {
        $mail = new PHPMailer(true);
        $year = date('Y');
        $html = <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#f5f3ef;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f5f3ef;padding:32px 16px;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:480px;background-color:#f7f3ee;border:1px solid #ddd9d2;border-radius:16px;overflow:hidden;">
<tr><td style="background-color:#303a50;padding:24px 32px;text-align:center;"><h1 style="margin:0;color:#f5f3ef;font-size:22px;font-weight:700;">TeachMe Support</h1></td></tr>
<tr><td style="padding:32px;">{$innerHtml}</td></tr>
<tr><td style="padding:16px 32px;border-top:1px solid #ddd9d2;text-align:center;"><p style="margin:0;color:#5f6778;font-size:12px;">© {$year} TeachMe</p></td></tr>
</table></td></tr></table></body></html>
HTML;

        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->Port = $this->port;
            $mail->SMTPSecure = $this->encryption === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $altBody;
            $mail->send();
        } catch (MailException $e) {
            throw new \RuntimeException('Failed to send support email: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
