<?php
namespace PHPMailer\PHPMailer;

// Loaded only by isolated regression suites, never by application code.
final class PHPMailer
{
    public const ENCRYPTION_STARTTLS = 'tls';
    public const ENCRYPTION_SMTPS = 'ssl';
    public static ?self $last = null;
    public array $addresses = [];
    public bool $smtp = false;
    public string $from = '';
    public $SMTPDebug, $Timeout, $Host, $Port, $SMTPAuth, $Username, $Password, $SMTPSecure;
    public $CharSet, $Subject, $Body, $AltBody;
    public function __set(string $name, $value): void { throw new \LogicException('Unknown PHPMailer property: ' . $name); }
    public function __construct(bool $exceptions) { self::$last = $this; }
    public function isSMTP(): void { $this->smtp = true; }
    public function setFrom(string $address, string $name): void { $this->from = $address; }
    public function addAddress(string $address): void { $this->addresses[] = $address; }
    public function isHTML(bool $html): void {}
    public function send(): bool
    {
        if ($capture = getenv('AAB_TEST_MAIL_OUTBOX')) {
            file_put_contents($capture, json_encode(['to' => $this->addresses, 'subject' => $this->Subject]) . "\n", FILE_APPEND);
        }
        if (getenv('AAB_TEST_MAIL_FAIL_RECIPIENT') === ($this->addresses[0] ?? null)) {
            throw new \RuntimeException('Synthetic SMTP failure with synthetic-password that must never be shown.');
        }
        return true;
    }
}
