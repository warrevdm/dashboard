<?php

require_once __DIR__ . '/WeeklyMailConfig.php';
require_once __DIR__ . '/WeeklyMailMessage.php';
require_once __DIR__ . '/WeeklyMailState.php';

final class WeeklyMailRunner
{
    public function __construct(private array $config, private Closure $loadOrders, private Closure $sendMail) {}

    public static function isDue(DateTimeImmutable $now, int $hour): bool
    {
        $local = $now->setTimezone(new DateTimeZone('Europe/Brussels'));
        return $local->format('N') === '2' && (int) $local->format('G') >= $hour;
    }

    public function run(DateTimeImmutable $now, bool $retryFailed = false): array
    {
        if (($this->config['enabled'] ?? false) !== true) {
            return ['status' => 'disabled', 'sent' => 0];
        }
        $errors = WeeklyMailConfig::errors($this->config);
        if ($errors) {
            throw new RuntimeException(implode(' ', $errors));
        }
        if (!self::isDue($now, $this->config['send_hour'])) {
            return ['status' => 'not_due', 'sent' => 0];
        }
        $now = $now->setTimezone(new DateTimeZone('Europe/Brussels'));
        $week = $now->format('Y-m-d');
        $storage = new WeeklyMailState($this->config['state_directory']);
        return $storage->locked(function (WeeklyMailState $storage) use ($now, $week, $retryFailed): array {
            $state = $storage->read();
            $pending = [];
            $blocked = 0;
            foreach (WeeklyMailConfig::recipients($this->config) as $recipient) {
                $key = hash('sha256', strtolower($recipient));
                $status = $state['weeks'][$week]['recipients'][$key]['status'] ?? null;
                if ($status === 'sent') {
                    continue;
                }
                // A crash or SMTP timeout can leave delivery uncertain. Never resend
                // automatically: an operator must first check the mailbox/server log.
                if ($status !== null && !$retryFailed) {
                    $blocked++;
                    continue;
                }
                $pending[$key] = $recipient;
            }
            if (!$pending) {
                return ['status' => $blocked ? 'needs_review' : 'already_sent', 'sent' => 0];
            }

            $orders = ($this->loadOrders)($now);
            $message = WeeklyMailMessage::build($orders, $now, $this->config['base_url']);
            $sent = 0;
            $failed = $blocked;
            foreach ($pending as $key => $recipient) {
                $entry = [
                    'status' => 'sending',
                    'attempted_at' => $now->format(DATE_ATOM),
                    'count' => count($orders),
                    'attempts' => 1 + (int) ($state['weeks'][$week]['recipients'][$key]['attempts'] ?? 0),
                ];
                $state['weeks'][$week]['recipients'][$key] = $entry;
                $state['weeks'][$week]['updated_at'] = $now->format(DATE_ATOM);
                // Persist before contacting SMTP, under the same lock as delivery.
                $storage->write($state);
                try {
                    ($this->sendMail)($recipient, $message);
                    $entry['status'] = 'sent';
                    $entry['sent_at'] = $now->format(DATE_ATOM);
                    $sent++;
                } catch (Throwable $error) {
                    $entry['status'] = 'failed';
                    $entry['error'] = 'Verzending niet bevestigd; controleer de mailbox en mailserver vóór opnieuw proberen.';
                    // Do not store SMTP responses, credentials or customer contents.
                    $failed++;
                }
                $state['weeks'][$week]['recipients'][$key] = $entry;
                $storage->write($state);
            }
            return ['status' => $failed ? 'needs_review' : 'sent', 'sent' => $sent, 'failed' => $failed, 'contracts' => count($orders)];
        });
    }
}
