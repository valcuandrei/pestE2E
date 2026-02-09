<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class JsonReportErrorDTO
{
    /**
     * @param  string  $message  error message
     * @param  string|null  $stack  (optional) error stack
     */
    public function __construct(
        public string $message,
        public ?string $stack = null,
    ) {}

    /**
     * Create a new JsonReportErrorDTO instance with the given message.
     */
    public function withMessage(string $message): self
    {
        return new self(
            message: $message,
            stack: $this->stack,
        );
    }

    /**
     * Create a new JsonReportErrorDTO instance with the given stack.
     */
    public function withStack(?string $stack): self
    {
        return new self(
            message: $this->message,
            stack: $stack,
        );
    }

    /**
     * Get the error as an array.
     *
     * @return array{
     *  message:string,
     *  stack:string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'stack' => $this->stack,
        ];
    }

    /**
     * Get the error as a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Create a new JsonReportErrorDTO instance from a JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($data));

        return self::fromArray($data);
    }

    /**
     * Create a new JsonReportErrorDTO instance from an array.
     */
    public static function fromArray(mixed $array): self
    {
        assert(is_array($array));
        assert(array_key_exists('message', $array));
        assert(array_key_exists('stack', $array));
        assert(is_string($array['message']));
        assert(is_string($array['stack']) || $array['stack'] === null);

        return new self(
            message: $array['message'],
            stack: $array['stack'],
        );
    }

    /**
     * Create a new JsonReportErrorDTO instance with a fake error.
     */
    public static function fake(): self
    {
        return new self(
            message: 'Fake error message',
            stack: 'Fake error stack',
        );
    }
}
