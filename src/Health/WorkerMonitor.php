<?php

declare(strict_types=1);

namespace Mk\Framework\Health;

use Mk\Framework\Log;

final class WorkerMonitor
{
    private ?WorkerStatusRepository $repository;
    private readonly \Closure $repositoryFactory;

    public function __construct(?WorkerStatusRepository $repository = null, ?\Closure $repositoryFactory = null)
    {
        $this->repository = $repository;
        $this->repositoryFactory = $repositoryFactory ?? static fn (): WorkerStatusRepository => new WorkerStatusRepository();
    }

    public function run(string $component, callable $worker, string $source = 'default'): mixed
    {
        $token = $this->safeStart($component, $source);
        try {
            $result = $worker();
        } catch (\Throwable $error) {
            $this->safeFail($component, $token, $this->errorCode($error), $source);
            throw $error;
        }
        $this->safeSucceed($component, $token, $source);

        return $result;
    }

    public function observe(string $component, callable $worker, string $errorCode = 'delivery_failed', string $source = 'default'): mixed
    {
        $token = $this->safeStart($component, $source);
        try {
            $result = $worker();
        } catch (\Throwable $error) {
            $this->safeFail($component, $token, $this->errorCode($error), $source);
            throw $error;
        }
        if ($result === false) {
            $this->safeFail($component, $token, $errorCode, $source);
        } else {
            $this->safeSucceed($component, $token, $source);
        }

        return $result;
    }

    private function safeStart(string $component, string $source): ?string
    {
        try {
            $repository = $this->repository ?? ($this->repositoryFactory)();
            $token = $repository->start($component, $source);
            $this->repository = $repository;

            return $token;
        } catch (\Throwable $error) {
            $this->safeLog($error);
            return null;
        }
    }

    private function safeSucceed(string $component, ?string $token, string $source): void
    {
        if ($token === null) {
            return;
        }
        try {
            $this->repository?->succeed($component, $token, $source);
        } catch (\Throwable $error) {
            $this->safeLog($error);
        }
    }

    private function safeFail(string $component, ?string $token, string $errorCode, string $source): void
    {
        if ($token === null) {
            return;
        }
        try {
            $this->repository?->fail($component, $token, $errorCode, $source);
        } catch (\Throwable $error) {
            $this->safeLog($error);
        }
    }

    private function safeLog(\Throwable $error): void
    {
        try {
            Log::logException($error);
        } catch (\Throwable) {
        }
    }

    private function errorCode(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }
        if (preg_match('/(?:^|\D)(401|403)(?:\D|$)/', $message) === 1) {
            return 'authentication_failed';
        }
        if ($error instanceof \Dibi\Exception || $error instanceof \PDOException) {
            return 'database_failed';
        }

        return 'request_failed';
    }
}
