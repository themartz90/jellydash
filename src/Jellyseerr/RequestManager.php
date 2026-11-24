<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Cache\AtomicJsonFile;
use Mk\Framework\Http\RemoteRequestException;
use Mk\Framework\Log;

/** Check the displayed version, serialize local writes and verify uncertain outcomes. */
final class RequestManager
{
    private const ACTIONS = ['approve', 'decline', 'retry', 'delete', 'edit', 'remove-files'];
    private const RECEIPT_TTL = 90;
    private AtomicJsonFile $state;

    public function __construct(private JellyseerrClient $client, string $cacheDirectory = RequestBrowser::CACHE_DIRECTORY)
    {
        $this->state = RequestBrowser::stateFile($client, $cacheDirectory);
    }

    /** @phpstan-assert-if-true 'approve'|'decline'|'retry'|'delete'|'edit'|'remove-files' $action */
    private static function validAction(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed>|null $card
     */
    public static function version(array $request, ?array $card = null): ?string
    {
        $card ??= RequestMapper::display($request);
        $identity = self::identity($card);
        if ($identity === null || !in_array($card['requestStatus'], [1, 2, 3, 4, 5], true)) {
            return null;
        }

        return hash('sha256', serialize([$identity, $card['requestStatus'], $card['mediaStatus'], $request['updatedAt'] ?? null]));
    }

    /** @param callable(): bool $allowed */
    public function execute(string $action, int $id, string $expected, callable $allowed, mixed $changes = null): string
    {
        if (!self::validAction($action) || $id < 1 || $id > 9_007_199_254_740_991 || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            throw new \InvalidArgumentException('Invalid request action.');
        }

        $edit = $action === 'edit' ? RequestEditor::submitted($changes) : null;
        $fileToken = $action === 'remove-files' && is_string($changes) ? $changes : null;
        if ($action === 'remove-files' && ($fileToken === null || preg_match('/^[a-f0-9]{64}$/D', $fileToken) !== 1)) {
            throw new \InvalidArgumentException('Invalid file removal confirmation.');
        }
        $fingerprint = $edit !== null ? hash('sha256', serialize($edit)) : $fileToken;

        return $this->state->withExclusiveLock(function () use ($action, $id, $expected, $allowed, $edit, $fingerprint, $fileToken): string {
            if (!self::permitted($allowed)) {
                return 'denied';
            }

            $state = $this->state->read() ?? [];
            $now = time();
            $receipts = array_filter($state['receipts'] ?? [], static fn (array $entry): bool => $entry['at'] >= $now - self::RECEIPT_TTL);
            $key = $id . ':' . $expected;
            $receipt = $receipts[$key] ?? null;
            if ($receipt !== null && $receipt['result'] === 'unknown') {
                return 'unknown';
            }
            if ($receipt !== null && $receipt['action'] === $action && ($receipt['fingerprint'] ?? null) === $fingerprint) {
                return $receipt['result'];
            }

            try {
                $request = $this->client->requestDetail($id);
                $before = RequestMapper::display($request);
            } catch (RemoteRequestException $e) {
                if ($e->getCode() === 404) {
                    $this->rotate($receipts);
                    return 'missing';
                }
                return self::explicitRefusal($e) ? 'refused' : 'unavailable';
            } catch (\RuntimeException) {
                return 'unavailable';
            }

            if ($expected !== self::version($request, $before) || !self::allowedState($action, $before['requestStatus'])) {
                $this->rotate($receipts);
                return 'stale';
            }
            $payload = null;
            $files = null;
            $targetKey = null;
            if ($edit !== null) {
                $options = (new RequestEditor($this->client))->options($request, $edit['destination'] ?? null);
                if ($options === null || $edit['optionsVersion'] !== $options['version']) {
                    $this->rotate($receipts);
                    return 'stale-options';
                }
                $payload = RequestEditor::payload($options, $edit);
                if ($payload === null) {
                    return 'invalid-edit';
                }
                if ($payload === $options['stored']['payload']) {
                    return 'unchanged';
                }
                try {
                    // The choices can take several reads. Recheck the record before PUT.
                    $latest = $this->client->requestDetail($id);
                } catch (\RuntimeException) {
                    return 'unavailable';
                }
                if ($expected !== self::version($latest) || RequestEditor::stored($latest) !== $options['stored']) {
                    $this->rotate($receipts);
                    return 'stale';
                }
            }
            if ($fileToken !== null) {
                try {
                    $files = (new LibraryFileRemoval($this->client))->target($request);
                    if ($files === null || !hash_equals($files['token'], $fileToken)) {
                        $this->rotate($receipts);
                        return 'file-target-changed';
                    }
                    $targetKey = 'files:' . $files['mediaId'] . ':' . (int) $files['is4k'];
                    if (($receipts[$targetKey]['result'] ?? null) === 'unknown') {
                        return 'unknown';
                    }
                    $latest = $this->client->requestDetail($id);
                } catch (\RuntimeException) {
                    return 'unavailable';
                }
                if ($expected !== self::version($latest) || LibraryFileRemoval::binding($latest) !== $files['binding']) {
                    $this->rotate($receipts);
                    return 'file-target-changed';
                }
            }
            if (!self::permitted($allowed)) {
                return 'denied';
            }

            // Publish intent before sending anything. A lost worker cannot make an unsafe replay.
            $receipts[$key] = ['at' => time(), 'action' => $action, 'result' => 'unknown', 'fingerprint' => $fingerprint];
            if ($targetKey !== null) {
                $receipts[$targetKey] = $receipts[$key];
            }
            $this->rotate($receipts, writing: true);
            if ($files !== null) {
                $result = $this->sendFileRemoval($files, $before);
            } elseif ($action === 'edit') {
                $result = $this->sendEdit($id, $request, $payload);
            } else {
                $result = $this->send($action, $id, $before);
            }
            $receipts[$key]['result'] = $result;
            if ($targetKey !== null && $result !== 'unknown') {
                unset($receipts[$targetKey]);
            }
            if ($result === 'refused') {
                unset($receipts[$key]);
            }
            try {
                $this->rotate($receipts);
            } catch (\RuntimeException $error) {
                // The durable intent still fences off old snapshots and prevents a replay.
                Log::logException($error);
            }

            return $result;
        });
    }

    /** @param 'approve'|'decline'|'retry'|'delete'|'edit'|'remove-files' $action */
    private static function allowedState(string $action, ?int $status): bool
    {
        return match ($action) {
            'approve', 'decline', 'edit' => $status === RequestMapper::REQUEST_PENDING,
            'retry' => $status === RequestMapper::REQUEST_FAILED,
            'delete', 'remove-files' => true,
        };
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $before */
    private function sendFileRemoval(array $target, array $before): string
    {
        try {
            $this->client->removeLibraryFiles($target['mediaId'], $target['is4k']);
        } catch (RemoteRequestException $error) {
            if (self::explicitRefusal($error)) {
                return 'refused';
            }
        } catch (\RuntimeException) {
            // A lost response does not tell us whether the library service acted.
        }
        try {
            $entry = $this->client->libraryEntry($target['connection'], $target['kind'], $target['arrId']);
            $after = RequestMapper::display($this->client->requestDetail($target['requestId']));
            if ($entry === null && self::identity($after) === self::identity($before)
                && ($target['upstream'] === '2.7.3' || $after['mediaStatus'] === RequestMapper::MEDIA_DELETED)) {
                return $target['upstream'] === '2.7.3' ? 'files-removed-legacy' : 'files-removed';
            }
        } catch (\RuntimeException) {
            // An unverified result stays fenced by the removal receipt.
        }

        return 'unknown';
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $payload */
    private function sendEdit(int $id, array $before, array $payload): string
    {
        $stored = RequestEditor::stored($before);
        $wire = ['mediaType' => $payload['mediaType']];
        if (isset($payload['seasons'])) {
            $wire['seasons'] = $payload['seasons'];
        }
        // TypeORM skips undefined columns. Leave the untouched overrides out
        // of the PUT, including null defaults and explicitly empty tags.
        foreach (['serverId', 'profileId'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== ($stored['payload'][$field] ?? null)) {
                $wire[$field] = $payload[$field];
            }
        }
        $acknowledged = false;
        try {
            if (!$this->client->editRequest($id, $wire)) {
                return 'refused';
            }
            $acknowledged = true;
        } catch (RemoteRequestException $e) {
            if (self::explicitRefusal($e) || $e->getCode() === 404) {
                return 'refused';
            }
        } catch (\RuntimeException) {
            // The edit may have arrived despite a lost response.
        }

        $identity = $stored['identity'] ?? null;
        for ($attempt = 0; $attempt < ($acknowledged ? 2 : 1); ++$attempt) {
            if ($attempt > 0) {
                // Jellyseerr 2.7.3 can answer a movie PUT before its save completes.
                usleep(150_000);
            }
            try {
                $saved = RequestEditor::stored($this->client->requestDetail($id));
                if ($saved !== null && $identity !== null && $saved['identity'] === $identity && $saved['payload'] === $payload) {
                    return $acknowledged ? 'edited' : 'observed-edited';
                }
            } catch (\RuntimeException) {
                // Only a read of the complete saved record can confirm the edit.
            }
        }

        return 'unknown';
    }

    /**
     * @param 'approve'|'decline'|'retry'|'delete' $action
     * @param array<string, mixed> $before
     */
    private function send(string $action, int $id, array $before): string
    {
        try {
            match ($action) {
                'approve' => $this->client->approve($id),
                'decline' => $this->client->decline($id),
                'retry' => $this->client->retry($id),
                'delete' => $this->client->deleteRequest($id),
            };
            return match ($action) {
                'approve' => 'approved', 'decline' => 'declined', 'retry' => 'retried', 'delete' => 'deleted',
            };
        } catch (RemoteRequestException $e) {
            if (self::explicitRefusal($e)) {
                return 'refused';
            }
        } catch (\RuntimeException) {
            // A timeout or incomplete response can follow acceptance upstream.
        }

        try {
            $after = RequestMapper::display($this->client->requestDetail($id));
            if ($action !== 'delete' && self::identity($before) === self::identity($after)) {
                $status = $after['requestStatus'];
                if ($action === 'decline' && $status === RequestMapper::REQUEST_DECLINED) {
                    return 'observed-declined';
                }
                if ($action !== 'decline' && in_array($status, [RequestMapper::REQUEST_APPROVED, RequestMapper::REQUEST_COMPLETED], true)) {
                    return 'observed-approved';
                }
            }
        } catch (RemoteRequestException $e) {
            if ($action === 'delete' && $e->getCode() === 404) {
                return 'observed-deleted';
            }
        } catch (\RuntimeException) {
            // A failed read cannot resolve an uncertain write.
        }

        return 'unknown';
    }

    /** @param array<string, mixed> $card @return array<mixed>|null */
    private static function identity(array $card): ?array
    {
        if ($card['mediaId'] === null || $card['tmdbId'] === null || $card['mediaType'] === null || $card['is4k'] === null
            || $card['seasonNumbers'] === null || ($card['mediaType'] === 'tv' && $card['seasonNumbers'] === [])) {
            return null;
        }

        return [$card['requestId'], $card['mediaId'], $card['tmdbId'], $card['mediaType'], $card['is4k'], $card['seasonNumbers'], $card['requestedAt']];
    }

    private static function explicitRefusal(RemoteRequestException $e): bool
    {
        return in_array($e->getCode(), [400, 401, 403, 405, 409, 422, 429], true);
    }

    /**
     * @param callable(): bool $allowed
     * @phpstan-impure The permission is read again before writing.
     */
    private static function permitted(callable $allowed): bool
    {
        try {
            return $allowed();
        } catch (\Throwable $error) {
            Log::logException($error);
            return false;
        }
    }

    /** @param array<string, mixed> $receipts */
    private function rotate(array $receipts, bool $writing = false): void
    {
        $this->state->write([
            'generation' => bin2hex(random_bytes(16)),
            'writing' => $writing,
            'receipts' => array_slice($receipts, -128, preserve_keys: true),
        ]);
    }
}
