<?php

final class WeeklyMailState
{
    public function __construct(private string $directory) {}

    public function read(): array
    {
        $path = $this->directory . '/status.json';
        if (!is_file($path)) {
            return ['version' => 1, 'weeks' => []];
        }
        $contents = @file_get_contents($path);
        $state = $contents === false ? null : json_decode($contents, true);
        if (!is_array($state) || ($state['version'] ?? null) !== 1 || !is_array($state['weeks'] ?? null)) {
            throw new RuntimeException('De verzendstatus is onleesbaar. Controleer de opgeslagen status vóór opnieuw verzenden.');
        }
        return $state;
    }

    public function write(array $state): void
    {
        // Keep roughly a year of delivery metadata, never customer contents.
        krsort($state['weeks']);
        $state['weeks'] = array_slice($state['weeks'], 0, 54, true);
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $temporary = tempnam($this->directory, '.status-');
        if ($temporary === false) {
            throw new RuntimeException('De verzendstatus kan niet worden opgeslagen.');
        }
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
                || !rename($temporary, $this->directory . '/status.json')) {
                throw new RuntimeException('De verzendstatus kan niet worden opgeslagen.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function locked(callable $callback): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('De opslagmap voor de weekmail kan niet worden gemaakt.');
        }
        $lock = @fopen($this->directory . '/send.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('De weekmail kan niet worden vergrendeld.');
        }
        @chmod($this->directory . '/send.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return ['status' => 'busy', 'sent' => 0];
            }
            return $callback($this);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
