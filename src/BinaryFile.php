<?php

namespace Proclame\ClubPlanner;

/**
 * An image or a PDF, as answered by the API's file endpoints.
 */
final readonly class BinaryFile
{
    public int $size;

    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {
        $this->size = strlen($bytes);
    }

    /**
     * @throws ClubPlannerException
     */
    public function saveTo(string $path): void
    {
        if (@file_put_contents($path, $this->bytes) === false) {
            throw new ClubPlannerException("Could not write the file to [{$path}].");
        }
    }
}
