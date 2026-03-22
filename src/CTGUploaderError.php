<?php
declare(strict_types=1);

namespace CTG\Uploader;

// Typed error class for upload system failures
class CTGUploaderError extends \Exception {

    /* Constants */
    const TYPES = [
        // 1xxx — Directory
        'DIRECTORY_CREATE_FAILED' => 1000,
        'DIRECTORY_NOT_WRITABLE'  => 1001,
        // 2xxx — File operations
        'MOVE_FAILED'             => 2000,
        // 3xxx — Validation
        'INVALID_CONFIG'          => 3000,
    ];

    /* Instance Properties */
    public readonly string $type;
    public readonly string $msg;
    public readonly mixed  $data;
    private bool $_handled = false;

    // CONSTRUCTOR :: STRING|INT, ?STRING, MIXED -> $this
    // Creates a new error — accepts type name or integer code
    public function __construct(
        string|int $type,
        ?string    $msg = null,
        mixed      $data = null
    ) {
        if (is_string($type)) {
            $this->type = $type;
            $code = self::TYPES[$type]
                ?? throw new \InvalidArgumentException("Unknown CTGUploaderError type: {$type}");
        } else {
            $code = $type;
            $this->type = self::lookup($type)
                ?? throw new \InvalidArgumentException("Unknown CTGUploaderError code: {$type}");
        }

        $this->msg  = $msg ?? $this->type;
        $this->data = $data;
        parent::__construct($this->msg, $code);
    }

    /**
     *
     * Instance Methods
     *
     */

    // :: STRING|INT, (ctgUploaderError -> VOID) -> $this
    // Handle error if it matches the given type. Chainable.
    public function on(string|int $type, callable $handler): static {
        $code = is_string($type) ? (self::TYPES[$type] ?? null) : $type;

        if (!$this->_handled && $this->getCode() === $code) {
            $handler($this);
            $this->_handled = true;
        }
        return $this;
    }

    // :: (ctgUploaderError -> VOID) -> VOID
    // Handle error if no previous on() matched
    public function otherwise(callable $handler): void {
        if (!$this->_handled) {
            $handler($this);
        }
    }

    /**
     *
     * Static Methods
     *
     */

    // :: STRING|INT -> INT|STRING|NULL
    // Bidirectional lookup — name to code or code to name
    public static function lookup(string|int $key): int|string|null {
        if (is_string($key)) {
            return self::TYPES[$key] ?? null;
        }
        return array_search($key, self::TYPES, true) ?: null;
    }
}
