<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditModel;
use App\Models\SystemSettingsModel;
use App\Shared\SessionHelper;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class MailService
{
    private PHPMailer $mail;
    private ?\PDO $db = null;
    private SystemSettingsModel $settings;
    private string $lastError = '';

    public function __construct($conn)
    {
        $this->db = $conn instanceof \PDO ? $conn : null;
        $this->mail = new PHPMailer(true);
        $this->settings = new SystemSettingsModel($conn);

        $this->configureSMTP();
    }

    /**
     * Configure SMTP server from tblSystemSettings
     */
    private function configureSMTP(): void
    {
        $host = (string)($this->settings->get('SMTP_HOST') ?? 'localhost');
        $port = (int)($this->settings->get('SMTP_PORT') ?? 25);
        $user = (string)($this->settings->get('SMTP_USER') ?? '');
        $pass = (string)($this->settings->get('SMTP_PASS') ?? '');
        $ssl  = (string)($this->settings->get('SMTP_SSL') ?? '1');
        $useStartTls = in_array(strtolower(trim($ssl)), ['1', 'true', 'yes', 'on'], true);

        $this->mail->isSMTP();
        $this->mail->Host = $host;
        $this->mail->Port = $port;
        $this->mail->SMTPAuth = !empty($user);
        $this->mail->Username = $user;
        $this->mail->Password = $pass;
        $this->mail->SMTPAutoTLS = $useStartTls;

        // Only use STARTTLS when explicitly enabled via SMTP_SSL.
        if ($useStartTls) {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $this->mail->SMTPSecure = '';
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Send an email
     *
     * @param string $to Recipient email
     * @param string $subject Subject line
     * @param string $body HTML body
     * @param string|null $from Override FROM address
     * @return bool
     */
    public function sendEmail(string $to, string $subject, string $body, ?string $from = null): bool
    {
        $defaultFrom = (string)($this->settings->get('SMTP_FROM') ?? 'noreply@cbmsv2.local');
        $defaultFromName = trim((string)($this->settings->get('SMTP_FROM_NAME') ?? 'Defence Credit Card Portal'));
        $fromAddress = $from ?? $defaultFrom;

        try {
            $this->lastError = '';

            $this->mail->clearAllRecipients();
            $this->mail->setFrom($fromAddress, $defaultFromName !== '' ? $defaultFromName : 'Defence Credit Card Portal');
            $this->mail->addAddress($to);

            $normalizedBody = $this->stripUrlSchemes($body);
            $this->mail->isHTML(true);
            $this->mail->Subject = $subject;
            $this->mail->Body = $normalizedBody;
            $this->mail->AltBody = strip_tags($normalizedBody);

            $sent = $this->mail->send();
            $this->auditEmailSend($to, $subject, $fromAddress, $sent, null);
            return $sent;
        } catch (\Throwable $e) {
            $this->lastError = trim((string)$this->mail->ErrorInfo);
            if ($this->lastError === '') {
                $this->lastError = $e->getMessage();
            }

            $this->auditEmailSend($to, $subject, $fromAddress, false, $this->lastError);
            error_log('MailService failed: ' . $this->lastError);
            return false;
        }
    }

    private function auditEmailSend(string $to, string $subject, string $fromAddress, bool $success, ?string $errorMessage = null): void
    {
        if (!($this->db instanceof \PDO)) {
            return;
        }

        try {
            $audit = new AuditModel($this->db);
            $audit->insert([
                'UserID' => (int)(SessionHelper::get('auth.user_id') ?? 0) ?: null,
                'Username' => (string)(SessionHelper::get('auth.username') ?? ''),
                'Action' => $success ? 'EMAIL_SENT' : 'EMAIL_FAILED',
                'Entity' => 'Email',
                'EntityKey' => $to,
                'Details' => [
                    'to' => $to,
                    'from' => $fromAddress,
                    'subject' => $subject,
                    'status' => $success ? 'sent' : 'failed',
                    'error' => $errorMessage,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('MailService audit logging failed: ' . $e->getMessage());
        }
    }

    private function stripUrlSchemes(string $content): string
    {
        $normalized = preg_replace('#https?://(?=[A-Za-z0-9])#i', '', $content);
        return is_string($normalized) ? $normalized : $content;
    }
}
