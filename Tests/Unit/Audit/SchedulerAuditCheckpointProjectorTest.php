<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Audit\InMemorySchedulerAuditCheckpointRepository;
use Vortos\Scheduler\Audit\SchedulerAuditCheckpointProjector;

/**
 * Unit tests for SchedulerAuditCheckpointProjector (E5).
 *
 * The projector writes a per-epoch HMAC-signed checkpoint every `epochSize` audit entries.
 * Checkpoints allow the chain verifier to skip earlier epochs in O(checkpoints) time.
 */
final class SchedulerAuditCheckpointProjectorTest extends TestCase
{
    private const HMAC_KEY  = 'unit-test-hmac-key';
    private const CHAIN_KEY = 'scheduler:tenant-1:testing';

    private InMemorySchedulerAuditCheckpointRepository $repository;
    private SchedulerAuditCheckpointProjector          $projector;

    protected function setUp(): void
    {
        $this->repository = new InMemorySchedulerAuditCheckpointRepository();
        $this->projector  = new SchedulerAuditCheckpointProjector(
            repository: $this->repository,
            hmacKey:    self::HMAC_KEY,
            epochSize:  10,
        );
    }

    public function test_no_checkpoint_before_first_epoch(): void
    {
        for ($seq = 0; $seq < 9; $seq++) {
            $this->projector->maybeCheckpoint(self::CHAIN_KEY, $seq, hash('sha256', (string) $seq));
        }

        self::assertSame([], $this->repository->all());
    }

    public function test_checkpoint_written_at_first_epoch_boundary(): void
    {
        for ($seq = 0; $seq <= 10; $seq++) {
            $this->projector->maybeCheckpoint(self::CHAIN_KEY, $seq, hash('sha256', (string) $seq));
        }

        $checkpoints = $this->repository->all();
        self::assertCount(1, $checkpoints);
        self::assertSame(1, $checkpoints[0]->epoch);
        self::assertSame(10, $checkpoints[0]->lastSequence);
        self::assertSame(10, $checkpoints[0]->entryCount);
        self::assertSame(self::CHAIN_KEY, $checkpoints[0]->chainKey);
    }

    public function test_multiple_epoch_boundaries_produce_multiple_checkpoints(): void
    {
        for ($seq = 0; $seq <= 30; $seq++) {
            $this->projector->maybeCheckpoint(self::CHAIN_KEY, $seq, hash('sha256', (string) $seq));
        }

        $checkpoints = $this->repository->all();
        self::assertCount(3, $checkpoints, 'Epochs 1, 2, 3 should each get a checkpoint');
        self::assertSame(1, $checkpoints[0]->epoch);
        self::assertSame(2, $checkpoints[1]->epoch);
        self::assertSame(3, $checkpoints[2]->epoch);
    }

    public function test_checkpoint_hmac_is_valid(): void
    {
        $hash = hash('sha256', 'hello');
        $this->projector->maybeCheckpoint(self::CHAIN_KEY, 10, $hash);

        $checkpoint = $this->repository->all()[0];
        self::assertTrue($this->projector->verifyCheckpoint($checkpoint));
    }

    public function test_tampered_cumulative_hash_fails_verification(): void
    {
        $this->projector->maybeCheckpoint(self::CHAIN_KEY, 10, hash('sha256', 'original'));

        $checkpoint = $this->repository->all()[0];

        $tampered = new \Vortos\Scheduler\Audit\SchedulerAuditCheckpoint(
            checkpointId:   $checkpoint->checkpointId,
            chainKey:       $checkpoint->chainKey,
            epoch:          $checkpoint->epoch,
            entryCount:     $checkpoint->entryCount,
            lastSequence:   $checkpoint->lastSequence,
            cumulativeHash: hash('sha256', 'tampered'), // Changed!
            hmac:           $checkpoint->hmac,
            createdAt:      $checkpoint->createdAt,
        );

        self::assertFalse($this->projector->verifyCheckpoint($tampered));
    }

    public function test_tampered_epoch_fails_verification(): void
    {
        $this->projector->maybeCheckpoint(self::CHAIN_KEY, 10, hash('sha256', 'data'));

        $checkpoint = $this->repository->all()[0];

        $tampered = new \Vortos\Scheduler\Audit\SchedulerAuditCheckpoint(
            checkpointId:   $checkpoint->checkpointId,
            chainKey:       $checkpoint->chainKey,
            epoch:          999, // Tampered!
            entryCount:     $checkpoint->entryCount,
            lastSequence:   $checkpoint->lastSequence,
            cumulativeHash: $checkpoint->cumulativeHash,
            hmac:           $checkpoint->hmac,
            createdAt:      $checkpoint->createdAt,
        );

        self::assertFalse($this->projector->verifyCheckpoint($tampered));
    }

    public function test_sequence_zero_does_not_write_checkpoint(): void
    {
        $this->projector->maybeCheckpoint(self::CHAIN_KEY, 0, hash('sha256', 'genesis'));

        self::assertSame([], $this->repository->all());
    }

    public function test_epoch_size_is_configurable(): void
    {
        $smallEpoch = new SchedulerAuditCheckpointProjector(
            repository: new InMemorySchedulerAuditCheckpointRepository(),
            hmacKey:    self::HMAC_KEY,
            epochSize:  5,
        );

        self::assertSame(5, $smallEpoch->getEpochSize());

        for ($seq = 0; $seq <= 5; $seq++) {
            $smallEpoch->maybeCheckpoint(self::CHAIN_KEY, $seq, hash('sha256', (string) $seq));
        }

        $repo = new \ReflectionProperty($smallEpoch, 'repository');
        $repo->setAccessible(true);
        /** @var InMemorySchedulerAuditCheckpointRepository $r */
        $r = $repo->getValue($smallEpoch);
        self::assertCount(1, $r->all());
        self::assertSame(1, $r->all()[0]->epoch);
    }

    public function test_epoch_size_below_one_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SchedulerAuditCheckpointProjector(
            repository: $this->repository,
            hmacKey:    self::HMAC_KEY,
            epochSize:  0,
        );
    }

    public function test_different_chain_keys_tracked_separately(): void
    {
        $chainA = 'scheduler:tenant-a:testing';
        $chainB = 'scheduler:tenant-b:testing';

        $this->projector->maybeCheckpoint($chainA, 10, hash('sha256', 'a'));
        $this->projector->maybeCheckpoint($chainB, 10, hash('sha256', 'b'));

        $allA = $this->repository->findByChainKey($chainA);
        $allB = $this->repository->findByChainKey($chainB);

        self::assertCount(1, $allA);
        self::assertCount(1, $allB);
        self::assertSame($chainA, $allA[0]->chainKey);
        self::assertSame($chainB, $allB[0]->chainKey);
    }

    public function test_find_latest_returns_highest_epoch(): void
    {
        for ($seq = 0; $seq <= 30; $seq++) {
            $this->projector->maybeCheckpoint(self::CHAIN_KEY, $seq, hash('sha256', (string) $seq));
        }

        $latest = $this->repository->findLatest(self::CHAIN_KEY);
        self::assertNotNull($latest);
        self::assertSame(3, $latest->epoch);
    }

    public function test_find_by_chain_key_from_epoch_filters_correctly(): void
    {
        for ($seq = 0; $seq <= 30; $seq++) {
            $this->projector->maybeCheckpoint(self::CHAIN_KEY, $seq, hash('sha256', (string) $seq));
        }

        $fromEpoch2 = $this->repository->findByChainKey(self::CHAIN_KEY, 2);
        self::assertCount(2, $fromEpoch2);
        self::assertSame(2, $fromEpoch2[0]->epoch);
        self::assertSame(3, $fromEpoch2[1]->epoch);
    }

    public function test_checkpoint_id_is_uuid_format(): void
    {
        $this->projector->maybeCheckpoint(self::CHAIN_KEY, 10, hash('sha256', 'data'));

        $id = $this->repository->all()[0]->checkpointId;
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );
    }
}
