<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;

final class ServerActivityClient
{
    private string $url;
    private string $token;
    private bool $verifySsl;
    /** @var (\Closure(string): array<mixed>)|null */
    private ?\Closure $requester;

    /** @param (callable(string): array<mixed>)|null $requester */
    public function __construct(?string $url = null, ?string $token = null, ?bool $verifySsl = null, ?callable $requester = null)
    {
        $this->url = rtrim($url ?? Config::get('JELLYFIN_URL', '') ?? '', '/');
        $this->token = $token ?? Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', '')) ?? '';
        $this->verifySsl = $verifySsl ?? Config::bool('JELLYFIN_VERIFY_SSL', true);
        $this->requester = $requester !== null ? $requester(...) : null;
    }

    public function configured(): bool
    {
        return $this->dashboardUrl() !== null && $this->token !== '';
    }

    public function sourceKey(): string
    {
        return hash('sha256', json_encode([$this->url, $this->token, $this->verifySsl], JSON_THROW_ON_ERROR));
    }

    public function dashboardUrl(): ?string
    {
        $parts = parse_url($this->url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        return $this->url . '/web/';
    }

    /** @return array<mixed> */
    public function get(string $path, float $budget = 5.0): array
    {
        if (!$this->configured()) {
            throw new ServerActivityException('unconfigured');
        }
        if ($this->requester !== null) {
            return ($this->requester)($path);
        }
        if ($budget <= 0 || !function_exists('curl_init')) {
            throw new ServerActivityException();
        }
        $handle = curl_init($this->url . $path);
        if ($handle === false) {
            throw new ServerActivityException();
        }
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: MediaBrowser Token="' . $this->token . '"'],
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, max(1, (int) ($budget * 1000))),
            CURLOPT_TIMEOUT_MS => max(1, (int) ($budget * 1000)),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 2097152) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (in_array($status, [401, 403], true)) {
            throw new ServerActivityException('forbidden');
        }
        if ($ok === false || $status < 200 || $status >= 300) {
            throw new ServerActivityException();
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ServerActivityException();
        }
        if (!is_array($data)) {
            throw new ServerActivityException();
        }
        return $data;
    }
}
