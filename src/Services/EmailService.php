<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

class EmailService
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private string $encryption;

    public function __construct()
    {
        $this->host = env('SMTP_HOST', '');
        $this->port = (int) env('SMTP_PORT', 465);
        $this->username = env('SMTP_USER', '');
        $this->password = env('SMTP_PASS', '');
        $this->fromEmail = env('SMTP_FROM_EMAIL', $this->username);
        $this->fromName = env('SMTP_FROM_NAME', 'TeachMe');
        $this->encryption = env('SMTP_ENCRYPTION', 'ssl');

        if (empty($this->host) || empty($this->username) || empty($this->password)) {
            throw new \RuntimeException('SMTP is not configured');
        }
    }

    public function sendOtpEmail(string $toEmail, string $recipientName, string $code): void
    {
        $displayName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
<p style="margin:0 0 12px;color:#303a50;font-size:16px;line-height:1.5;">Hi {$displayName},</p>
<p style="margin:0 0 24px;color:#5f6778;font-size:15px;line-height:1.6;">
  Use this code to verify your email and complete your TeachMe account setup:
</p>
<div style="text-align:center;margin:0 0 24px;">
  <span style="display:inline-block;padding:16px 32px;background-color:#f5f3ef;border:2px solid #303a50;border-radius:12px;color:#303a50;font-size:32px;font-weight:700;letter-spacing:8px;">{$safeCode}</span>
</div>
<p style="margin:0 0 8px;color:#5f6778;font-size:14px;line-height:1.5;">
  This code expires in <strong style="color:#303a50;">10 minutes</strong>.
</p>
<p style="margin:0;color:#5f6778;font-size:13px;line-height:1.5;">
  If you did not request this, you can safely ignore this email.
</p>
HTML;

        $this->send(
            $toEmail,
            $recipientName,
            'Your TeachMe verification code',
            $this->wrapLayout($body, 'Verify your email'),
            "Your TeachMe verification code is {$code}. It expires in 10 minutes."
        );
    }

    public function sendWelcomeEmail(string $toEmail, string $recipientName): void
    {
        $displayName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $appUrl = rtrim(env('FRONTEND_ORIGIN', 'https://teachme.mom'), '/');
        $safeUrl = htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
<p style="margin:0 0 12px;color:#303a50;font-size:16px;line-height:1.5;">Hi {$displayName},</p>
<p style="margin:0 0 20px;color:#5f6778;font-size:15px;line-height:1.6;">
  Welcome to TeachMe — your personal AI tutor for any topic. Your account is ready, and you can start learning right away.
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 24px;">
  <tr>
    <td style="padding:14px 16px;background-color:#f5f3ef;border:1px solid #ddd9d2;border-radius:12px;">
      <p style="margin:0 0 6px;color:#303a50;font-size:14px;font-weight:600;">Your free trial</p>
      <p style="margin:0;color:#5f6778;font-size:14px;line-height:1.5;">Generate your first interactive lesson on any topic — completely free.</p>
    </td>
  </tr>
</table>
<p style="margin:0 0 8px;color:#5f6778;font-size:14px;line-height:1.5;">Here's how to get started:</p>
<ol style="margin:0 0 24px;padding-left:20px;color:#5f6778;font-size:14px;line-height:1.7;">
  <li style="margin-bottom:6px;">Enter a topic you want to learn</li>
  <li style="margin-bottom:6px;">TeachMe builds a personalized lesson for you</li>
  <li>Practice with interactive exercises and instant feedback</li>
</ol>
<div style="text-align:center;margin:0 0 20px;">
  <a href="{$safeUrl}" style="display:inline-block;padding:14px 32px;background-color:#303a50;color:#f5f3ef;font-size:15px;font-weight:600;text-decoration:none;border-radius:999px;">Start learning</a>
</div>
<p style="margin:0;color:#5f6778;font-size:13px;line-height:1.5;text-align:center;">
  Questions? Just reply to this email — we're happy to help.
</p>
HTML;

        $this->send(
            $toEmail,
            $recipientName,
            'Welcome to TeachMe',
            $this->wrapLayout($body, 'Welcome to TeachMe'),
            "Welcome to TeachMe, {$recipientName}! Your account is ready. Start learning at {$appUrl}"
        );
    }

    public function sendPlanDayReadyEmail(
        string $toEmail,
        string $recipientName,
        string $topic,
        int $dayNumber,
        string $dayTitle,
        string $lessonUrl
    ): void {
        $displayName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $safeTopic = htmlspecialchars($topic, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($dayTitle, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($lessonUrl, ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
<p style="margin:0 0 12px;color:#303a50;font-size:16px;line-height:1.5;">Hi {$displayName},</p>
<p style="margin:0 0 20px;color:#5f6778;font-size:15px;line-height:1.6;">
  Day {$dayNumber} of your <strong style="color:#303a50;">{$safeTopic}</strong> learning plan is ready.
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 24px;">
  <tr>
    <td style="padding:14px 16px;background-color:#f5f3ef;border:1px solid #ddd9d2;border-radius:12px;">
      <p style="margin:0 0 4px;color:#303a50;font-size:14px;font-weight:600;">Today's lesson</p>
      <p style="margin:0;color:#5f6778;font-size:14px;line-height:1.5;">{$safeTitle}</p>
    </td>
  </tr>
</table>
<div style="text-align:center;margin:0 0 20px;">
  <a href="{$safeUrl}" style="display:inline-block;padding:14px 32px;background-color:#303a50;color:#f5f3ef;font-size:15px;font-weight:600;text-decoration:none;border-radius:999px;">Open today's lesson</a>
</div>
<p style="margin:0;color:#5f6778;font-size:13px;line-height:1.5;text-align:center;">
  Keep your streak going — each day builds on the last.
</p>
HTML;

        $this->send(
            $toEmail,
            $recipientName,
            "Day {$dayNumber} is ready — {$dayTitle}",
            $this->wrapLayout($body, "Day {$dayNumber} lesson ready"),
            "Hi {$recipientName}, Day {$dayNumber} of your {$topic} plan is ready: {$dayTitle}. Open: {$lessonUrl}"
        );
    }

    private function send(
        string $toEmail,
        string $recipientName,
        string $subject,
        string $htmlBody,
        string $altBody
    ): void {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->Port = $this->port;

            if ($this->encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }

            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($toEmail, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody;

            $mail->send();
        } catch (MailException $e) {
            throw new \RuntimeException('Failed to send email: ' . $mail->ErrorInfo, 0, $e);
        }
    }

    private function wrapLayout(string $content, string $title): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$safeTitle}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f3ef;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f5f3ef;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:480px;background-color:#f7f3ee;border:1px solid #ddd9d2;border-radius:16px;overflow:hidden;">
          <tr>
            <td style="background-color:#303a50;padding:28px 32px;text-align:center;">
              <h1 style="margin:0;color:#f5f3ef;font-size:24px;font-weight:700;letter-spacing:-0.02em;">TeachMe</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              {$content}
            </td>
          </tr>
          <tr>
            <td style="padding:20px 32px;border-top:1px solid #ddd9d2;text-align:center;">
              <p style="margin:0;color:#5f6778;font-size:12px;">© {$year} TeachMe · <a href="https://teachme.mom" style="color:#303a50;text-decoration:none;">teachme.mom</a></p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
