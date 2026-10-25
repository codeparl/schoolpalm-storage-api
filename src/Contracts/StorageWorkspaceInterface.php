<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

/**
 * Interface StorageWorkspaceInterface
 *
 * Provides an isolated, database-free environment for managing
 * document batch execution files.
 *
 * This contract abstracts workspace storage so pipeline stages
 * and queue workers interact with domain concepts rather than
 * physical filesystem paths.
 *
 * Workspace structure:
 *
 * plan.json
 * source.json
 * manifest.json
 * status.json
 *
 * chunks/
 *   1.json
 *   2.json
 *
 * rendered/
 *   1.pdf
 *   2.pdf
 *
 * final/
 *   document.pdf
 */
interface StorageWorkspaceInterface
{
    /**
     * Retrieves the unique identifier for this batch workspace.
     */
    public function id(): string;

    /**
     * Resolve a workspace file into a physical filesystem path.
     *
     * Example:
     *
     * rendered/1.pdf
     *
     * becomes:
     *
     * /storage/app/secure-docs/.../rendered/1.pdf
     */
    public function physicalPath(
        string $file
    ): string;

    /**
     * Stores execution metadata.
     *
     * @param  array<string,mixed>  $manifest
     */
    public function putManifest(
        array $manifest
    ): void;

    /**
     * Retrieves execution metadata.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array;

    /**
     * Store serialized document source.
     *
     * Source data is separated from plan metadata because
     * it may contain large runtime payloads.
     */
    public function putSource(
        array $source
    ): void;

    /**
     * Restore serialized document source.
     *
     * @return array<string,mixed>
     */
    public function source(): array;

    /**
     * Stores immutable execution plan.
     *
     * @param  array<string,mixed>  $plan
     */
    public function putPlan(
        array $plan
    ): void;

    /**
     * Retrieves execution plan.
     *
     * @return array<string,mixed>
     */
    public function plan(): array;

    /**
     * Stores current execution status.
     *
     * @param  array<string,mixed>  $status
     */
    public function putStatus(
        array $status
    ): void;

    /**
     * Updates existing status information.
     *
     * @param  array<string,mixed>  $status
     */
    public function updateStatus(
        array $status
    ): void;

    /**
     * Retrieves execution status.
     *
     * @return array<string,mixed>
     */
    public function status(): array;

    /**
     * Check whether status file exists.
     */
    public function statusExists(): bool;

    /**
     * Stores a dataset chunk.
     *
     * Chunks are persisted so queue workers do not need
     * the original data source.
     */
    public function putChunk(
        int $chunk,
        mixed $data
    ): void;

    /**
     * Retrieves a stored dataset chunk.
     */
    public function chunk(
        int $chunk
    ): mixed;

    /**
     * Stores generated chunk output.
     *
     * Example:
     *
     * rendered/1.pdf
     */
    public function putRendered(
        int $chunk,
        mixed $contents,
        string $type
    ): void;

    /**
     * Checks if a rendered chunk exists.
     *
     * Used for queue retries and idempotency.
     */
    public function renderedExists(
        int $chunk,
        string $type
    ): bool;

    /**
     * Returns rendered chunk artifact paths.
     *
     * Example:
     *
     * [
     *   rendered/1.pdf,
     *   rendered/2.pdf
     * ]
     */
    public function renderedChunkPaths(
        string $type
    ): array;

    /**
     * Stores final generated artifact after merge.
     *
     * Example:
     *
     * final/document.pdf
     */
    public function putFinal(
        mixed $contents,
        string $type
    ): void;

    /**
     * Checks if final generated artifact exists.
     */
    public function finalExists(
        string $type
    ): bool;

    /**
     * Retrieves final generated artifact.
     */
    public function final(
        string $type
    ): string;

    /**
     * Deletes entire workspace.
     */
    public function cleanup(): void;
}
