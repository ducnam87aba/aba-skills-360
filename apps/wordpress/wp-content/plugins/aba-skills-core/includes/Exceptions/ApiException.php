<?php
/**
 * Domain exception carrying a REST-facing error code + HTTP status, so services
 * can signal failures without knowing about WP_Error, and controllers can map
 * them to the standard contract error shape (api-contract.md §6, §27-31).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Exceptions;

if (!defined('ABSPATH')) {
    exit;
}

class ApiException extends \RuntimeException {
    private string $apiCode;
    private int $status;
    private array $details;

    public function __construct(string $apiCode, string $message, int $status, array $details = []) {
        parent::__construct($message, $status);
        $this->apiCode = $apiCode;
        $this->status = $status;
        $this->details = $details;
    }

    public function apiCode(): string {
        return $this->apiCode;
    }

    public function status(): int {
        return $this->status;
    }

    public function details(): array {
        return $this->details;
    }
}
