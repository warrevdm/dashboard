<?php
namespace PHPMailer\PHPMailer;

// Loaded only by the CLI regression suite, never by application code.
#[\AllowDynamicProperties]
final class PHPMailer
{
    public const ENCRYPTION_STARTTLS = 'tls';
    public const ENCRYPTION_SMTPS = 'ssl';
    public static ?self $last = null;
    public array $addresses = [];
    public bool $smtp = false;
    public string $from = '';
    public function __construct(bool $exceptions) { self::$last = $this; }
    public function isSMTP(): void { $this->smtp = true; }
    public function setFrom(string $address, string $name): void { $this->from = $address; }
    public function addAddress(string $address): void { $this->addresses[] = $address; }
    public function isHTML(bool $html): void {}
    public function send(): bool { return true; }
}
