<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

use Faker\Factory;

/**
 * @internal
 */
final readonly class JsonReportArtifactsDTO
{
    /**
     * @param  string|null  $trace  (optional) trace
     * @param  string|null  $video  (optional) video
     * @param  list<string>  $screenshots  screenshots
     */
    public function __construct(
        public ?string $trace = null,
        public ?string $video = null,
        public array $screenshots = [],
    ) {}

    /**
     * Create a new JsonReportArtifactsDTO instance with the given trace.
     */
    public function withTrace(?string $trace): self
    {
        return new self(
            trace: $trace,
            video: $this->video,
            screenshots: $this->screenshots,
        );
    }

    /**
     * Create a new JsonReportArtifactsDTO instance with the given video.
     */
    public function withVideo(?string $video): self
    {
        return new self(
            trace: $this->trace,
            video: $video,
            screenshots: $this->screenshots,
        );
    }

    /**
     * Create a new JsonReportArtifactsDTO instance with the given screenshots.
     *
     * @param  array<int, string>  $screenshots
     */
    public function withScreenshots(array $screenshots): self
    {
        return new self(
            trace: $this->trace,
            video: $this->video,
            screenshots: array_values($screenshots),
        );
    }

    /**
     * Get the artifacts as an array.
     *
     * @return array{
     *  trace:string|null,
     *  video:string|null,
     *  screenshots:list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'trace' => $this->trace,
            'video' => $this->video,
            'screenshots' => $this->screenshots,
        ];
    }

    /**
     * Get the artifacts as a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Create a new JsonReportArtifactsDTO instance from a JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($data));

        return self::fromArray($data);
    }

    /**
     * Create a new JsonReportArtifactsDTO instance from an array.
     */
    public static function fromArray(mixed $array): self
    {
        assert(is_array($array));
        assert(array_key_exists('trace', $array));
        assert(array_key_exists('video', $array));
        assert(array_key_exists('screenshots', $array));
        assert(is_array($array['screenshots']));
        assert(is_string($array['trace']) || $array['trace'] === null);
        assert(is_string($array['video']) || $array['video'] === null);

        $screenshots = array_values($array['screenshots']);
        $typedScreenshots = [];
        foreach ($screenshots as $screenshot) {
            assert(is_string($screenshot));
            $typedScreenshots[] = $screenshot;
        }

        return new self(
            trace: $array['trace'],
            video: $array['video'],
            screenshots: $typedScreenshots,
        );
    }

    /**
     * Create a new JsonReportArtifactsDTO instance with the given trace, video and screenshots.
     */
    public static function fake(): self
    {
        return new self(
            trace: 'trace',
            video: 'video',
            screenshots: [Factory::create()->imageUrl()],
        );
    }
}
