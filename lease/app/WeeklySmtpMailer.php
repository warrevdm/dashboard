<?php

final class WeeklySmtpMailer
{
    public function __construct(private array $config) {}

    public static function assertAvailable(): void
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new RuntimeException('De mailbibliotheek ontbreekt. Voer composer install uit in lease.');
        }
    }

    public function send(string $recipient, array $message): void
    {
        self::assertAvailable();
        $smtp = $this->config['smtp'];
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->Timeout = 30;
        $mail->Timelimit = 60;
        $mail->Host = $smtp['host'];
        $mail->Port = $smtp['port'];
        $mail->SMTPAuth = $smtp['username'] !== '';
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPSecure = $smtp['encryption'] === 'smtps'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        // Keep PHPMailer's certificate and hostname verification enabled.
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->config['from_address'], $this->config['from_name']);
        $mail->addAddress($recipient);
        $mail->Subject = $message['subject'];
        $mail->isHTML(true);
        $mail->Body = $message['html'];
        $mail->AltBody = $message['text'];
        if (!$mail->send()) {
            throw new RuntimeException('De mailserver heeft de weekmail niet aanvaard.');
        }
    }
}
