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
        return $this->deliver($now, 'weeks', $now->format('Y-m-d'), $retryFailed);
    }

    public function sendNow(DateTimeImmutable $now, string $requestId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
            throw new InvalidArgumentException('Ongeldige verzendopdracht.');
        }
        $errors = WeeklyMailConfig::errors($this->config);
        if ($errors) {
            throw new RuntimeException(implode(' ', $errors));
        }
        // enabled controls the scheduler; an authenticated manual action may run any day.
        $now = $now->setTimezone(new DateTimeZone('Europe/Brussels'));
        return $this->deliver($now, 'manual', hash('sha256', $requestId), false);
    }

    private function deliver(DateTimeImmutable $now, string $group, string $runId, bool $retryFailed): array
    {
        $storage = new WeeklyMailState($this->config['state_directory']);
        return $storage->locked(function (WeeklyMailState $storage) use ($now, $group, $runId, $retryFailed): array {
            $state = $storage->read();
            if ($group === 'manual' && isset($state[$group][$runId])) {
                $statuses = array_column($state[$group][$runId]['recipients'] ?? [], 'status');
                $expected = (int) ($state[$group][$runId]['recipient_count'] ?? count(WeeklyMailConfig::recipients($this->config)));
                $uncertain = count($statuses) !== $expected || !$statuses
                    || count(array_filter($statuses, static fn ($status) => $status !== 'sent')) > 0;
                return ['status' => $uncertain ? 'needs_review' : 'already_sent', 'sent' => 0];
            }
            $pending = [];
            $blocked = 0;
            foreach (WeeklyMailConfig::recipients($this->config) as $recipient) {
                $key = hash('sha256', strtolower($recipient));
                $status = $state[$group][$runId]['recipients'][$key]['status'] ?? null;
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
            if ($group === 'manual') {
                $state[$group][$runId]['recipient_count'] = count($pending);
            }
            foreach ($pending as $key => $recipient) {
                $entry = [
                    'status' => 'sending',
                    'attempted_at' => $now->format(DATE_ATOM),
                    'count' => count($orders),
                    'attempts' => 1 + (int) ($state[$group][$runId]['recipients'][$key]['attempts'] ?? 0),
                ];
                $state[$group][$runId]['recipients'][$key] = $entry;
                $state[$group][$runId]['updated_at'] = $now->format(DATE_ATOM);
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
                $state[$group][$runId]['recipients'][$key] = $entry;
                $storage->write($state);
            }
            return ['status' => $failed ? 'needs_review' : 'sent', 'sent' => $sent, 'failed' => $failed, 'contracts' => count($orders)];
        });
    }
}
