<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Session\PlaybackPersistenceScenarios;
use PHPUnit\Framework\TestCase;

/** A registered tab ends the hold of a destination that is no longer registered, on the listener's word. */
final class PlaybackOverridePersistenceTest extends TestCase
{
    use PlaybackPersistenceScenarios;

    /**
     * @param array<string,mixed> $asker
     * @return array<string,mixed>
     */
    private function override(PlaybackCoordinator $service, Uuid $owner, array $asker, int $revision, ?string $requestId = null): array
    {
        return $service->execute($owner, PlaybackOperation::Override, ['requestId' => $requestId ?? new Uuid()->toString(), 'expectedRevision' => $revision,
            'incarnationId' => $asker['incarnationId']], $asker['capability']);
    }

    /**
     * @param array<string,mixed> $asker
     * @param array<string,mixed>|null $authority The endpoint whose capability travels with the request, when it is not the asker's.
     */
    private function assertOverrideRefused(string $reason, PlaybackCoordinator $service, Uuid $owner, array $asker, int $revision, ?array $authority = null): void
    {
        $before = $this->committed($owner);
        $this->assertRefused($reason, $service, $owner, PlaybackOperation::Override, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $revision,
            'incarnationId' => $asker['incarnationId']], $authority ?? $asker);
        self::assertSame($before, $this->committed($owner), $reason);
    }

    /**
     * A runner that was shown a play and never answered it: the play expired, the session is unknown
     * and a stop is owed to the runner.
     *
     * @return array{runner:array<string,mixed>,asker:array<string,mixed>,askerRegistration:array<string,mixed>,play:array<string,mixed>,unknown:array<string,mixed>}
     */
    private function runnerThatWasShownAPlay(PlaybackCoordinator $service, Uuid $owner): array
    {
        $runner = $this->endpoint($service, $owner);
        $stopped = $this->stopped($service, $owner, $runner);
        $askerRegistration = $this->tabOnAPage($owner);
        $asker = $service->execute($owner, PlaybackOperation::Endpoint, $askerRegistration)['endpoint'];
        $this->controlFrom($service, $owner, $asker, $stopped['revision'], 'play');
        $play = $this->pending($service, $owner, $runner)['commands'][0];
        self::assertSame('play', $play['type']);
        $this->expireCommands($owner);
        $unknown = $this->pending($service, $owner, $asker);
        self::assertSame('unknown', $unknown['observedState']);
        self::assertSame([['stop', $runner['incarnationId']]], $this->unanswered($owner));
        return ['runner' => $runner, 'asker' => $asker, 'askerRegistration' => $askerRegistration, 'play' => $play, 'unknown' => $unknown];
    }

    public function testAnOverrideEndsTheHoldOfARunnerThatIsNoLongerRegistered(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['runner' => $runner, 'asker' => $asker, 'unknown' => $unknown] = $this->runnerThatWasShownAPlay($service, $owner);

        // While the runner is registered the server could still reach it, and nothing tells a crashed tab from a playing one.
        $this->assertOverrideRefused('The destination is still connected. Stop playback there.', $service, $owner, $asker, $unknown['revision']);

        $this->expireRegistration($runner['incarnationId']);
        $requestId = new Uuid()->toString();
        $overridden = $this->override($service, $owner, $asker, $unknown['revision'], $requestId);

        self::assertNull($overridden['activeIncarnationId']);
        self::assertNull($overridden['selectedDestinationId']);
        self::assertNull($overridden['transfer']);
        self::assertSame('paused', $overridden['desiredState']);
        self::assertSame('paused', $overridden['observedState']);
        self::assertSame(42.5, $overridden['position']);
        self::assertSame($unknown['revision'] + 1, $overridden['revision']);
        self::assertSame(['requestId' => $requestId, 'commandId' => $requestId, 'outcome' => 'overridden', 'rejectionReason' => null], $overridden['requestResult']);
        // The override proves nothing about the runner: it is retired and keeps what it last reported.
        self::assertSame(['observed_state' => 'unknown', 'position' => 42.5, 'retired' => true, 'available' => false], $this->incarnation($runner['incarnationId']));
        // The owed stop is closed, and the override is one settled row addressed to the overridden incarnation.
        self::assertSame([], $this->unanswered($owner));
        self::assertSame(
            ['type' => 'stop', 'incarnation_id' => $runner['incarnationId'], 'epoch' => $runner['epoch'], 'outcome' => 'overridden', 'answered' => true, 'shown' => false, 'revision' => $overridden['revision']],
            $this->observer->fetchAssociative('SELECT type,incarnation_id,epoch,outcome,acknowledged_at IS NOT NULL AS answered,disclosed_at IS NOT NULL AS shown,revision FROM playback_commands WHERE id=?', [$requestId]),
        );
        // No stop is issued again.
        $this->expireCommands($owner);
        $later = $this->pending($service, $owner, $asker);
        self::assertSame($overridden['revision'], $later['revision']);
        self::assertSame([], $this->unanswered($owner));

        // The asking tab then starts playback through the ordinary path.
        $play = $this->controlFrom($service, $owner, $asker, $later['revision'], 'play');
        self::assertSame('retained', $play['requestResult']['outcome']);
        $transfer = $service->execute($owner, PlaybackOperation::Transfer, ['requestId' => new Uuid()->toString(), 'expectedRevision' => $play['revision'], 'targetIncarnationId' => $asker['incarnationId']]);
        self::assertNull($transfer['transfer']['sourceIncarnationId']);
        self::assertSame(42.5, $transfer['position']);
    }

    public function testAnOverrideIsAcceptedWhateverWasLastKnownAboutTheGoneRunner(): void
    {
        $service = $this->coordinator();
        /** @var array<string,array{\Closure(Uuid, array<string,mixed>): array<string,mixed>, string, float}> $runners Each brings the runner to its state and returns the last snapshot; then what the runner last reported and the cursor. */
        $runners = [
            'confirmed that it plays' => [fn(Uuid $owner, array $runner): array => $this->playing($service, $owner, $runner), 'playing', 42.5],
            'was shown a start it has not answered' => [function (Uuid $owner, array $runner) use ($service): array {
                $stopped = $this->stopped($service, $owner, $runner);
                return $this->controlFrom($service, $owner, $runner, $stopped['revision'], 'play');
            }, 'stopped', 42.5],
            'confirmed a pause and then answered a seek' => [function (Uuid $owner, array $runner) use ($service): array {
                $paused = $this->paused($service, $owner, $runner);
                $this->controlFrom($service, $owner, $runner, $paused['revision'], 'seek', ['position' => 90.0]);
                return $this->answer($service, $owner, $runner, 'applied', 90.0);
            }, 'paused', 90.0],
        ];
        foreach ($runners as $case => [$state, $reported, $cursor]) {
            $owner = $this->createUser();
            $runner = $this->endpoint($service, $owner);
            $before = $state($owner, $runner);
            $asker = $this->endpoint($service, $owner);
            $this->expireRegistration($runner['incarnationId']);
            // No proof releases this runner.
            self::assertSame($runner['incarnationId'], $this->pending($service, $owner, $asker)['activeIncarnationId'], $case);

            $overridden = $this->override($service, $owner, $asker, $before['revision']);

            self::assertNull($overridden['activeIncarnationId'], $case);
            self::assertSame('paused', $overridden['desiredState'], $case);
            self::assertSame('paused', $overridden['observedState'], $case);
            self::assertSame($cursor, $overridden['position'], $case);
            self::assertSame($before['revision'] + 1, $overridden['revision'], $case);
            self::assertSame('overridden', $overridden['requestResult']['outcome'], $case);
            self::assertSame([$reported, true], [$this->incarnation($runner['incarnationId'])['observed_state'], $this->incarnation($runner['incarnationId'])['retired']], $case);
            self::assertSame([], $this->unanswered($owner), $case);
        }
    }

    public function testAnOverrideFailsAnUncertainTransferWhoseTargetIsNoLongerRegistered(): void
    {
        $service = $this->coordinator();
        foreach (['the source is registered', 'the source is no longer registered', 'the transfer has no source'] as $case) {
            $owner = $this->createUser();
            ['source' => $source, 'target' => $target, 'request' => $request, 'snapshot' => $uncertain] = $this->transferBetweenPages($service, $owner, $case !== 'the transfer has no source', 'uncertain');
            $asker = $this->endpoint($service, $owner);

            // A target that is still registered can still answer for itself.
            $this->assertOverrideRefused('The destination is still connected. Stop playback there.', $service, $owner, $asker, $uncertain['revision']);
            $this->expireRegistration($target['incarnationId']);
            if ($case === 'the source is no longer registered') {
                $this->expireRegistration($source['incarnationId']);
            }
            $requestId = new Uuid()->toString();

            $overridden = $this->override($service, $owner, $asker, $uncertain['revision'], $requestId);

            self::assertNull($overridden['transfer'], $case);
            self::assertSame('failed', $service->execute($owner, PlaybackOperation::Transfer, $request)['transferResult']['state'], $case);
            self::assertSame('paused', $overridden['desiredState'], $case);
            self::assertSame('paused', $overridden['observedState'], $case);
            // Only a source that is still registered keeps the session.
            $keeps = $case === 'the source is registered';
            self::assertSame($keeps ? $source['incarnationId'] : null, $overridden['activeIncarnationId'], $case);
            self::assertSame($keeps ? $source['destinationId'] : null, $overridden['selectedDestinationId'], $case);
            self::assertSame($uncertain['revision'] + ($case === 'the source is no longer registered' ? 2 : 1), $overridden['revision'], $case);
            self::assertSame([], $this->unanswered($owner), $case);
            // Each overridden incarnation is retired and not recorded as stopped; the row names the target.
            self::assertSame(['unknown', true], [$this->incarnation($target['incarnationId'])['observed_state'], $this->incarnation($target['incarnationId'])['retired']], $case);
            if ($source !== null) {
                self::assertSame(['playing', !$keeps], [$this->incarnation($source['incarnationId'])['observed_state'], $this->incarnation($source['incarnationId'])['retired']], $case);
            }
            self::assertSame(['requestId' => $requestId, 'commandId' => $requestId, 'outcome' => 'overridden', 'rejectionReason' => null], $overridden['requestResult'], $case);
            self::assertSame($target['incarnationId'], $this->observer->fetchOne('SELECT incarnation_id FROM playback_commands WHERE id=?', [$requestId]), $case);
        }
    }

    public function testAnOverrideIsRefusedWhileATransferIsOpenInAnotherStateThanUncertain(): void
    {
        $service = $this->coordinator();
        foreach (['preparing', 'quiescing', 'starting'] as $state) {
            $owner = $this->createUser();
            ['target' => $target, 'snapshot' => $open] = $this->transferBetweenPages($service, $owner, true, $state);
            $asker = $this->endpoint($service, $owner);
            $this->expireRegistration($target['incarnationId']);

            $this->assertOverrideRefused('Playback controls wait for transfer reconciliation.', $service, $owner, $asker, $open['revision']);
        }
    }

    public function testAnOverrideIsRefusedWithoutTheRevisionTheAuthorityOrAHoldToEnd(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['runner' => $runner, 'asker' => $asker, 'unknown' => $unknown] = $this->runnerThatWasShownAPlay($service, $owner);
        $this->expireRegistration($runner['incarnationId']);
        $revision = $unknown['revision'];

        $this->assertOverrideRefused('Playback revision conflict.', $service, $owner, $asker, $revision - 1);
        $this->assertOverrideRefused('An endpoint capability is required.', $service, $owner, $asker, $revision, ['capability' => null]);
        $this->assertOverrideRefused('Endpoint authority is invalid.', $service, $owner, $asker, $revision, ['capability' => str_repeat('0', 64)]);
        $this->assertOverrideRefused('Endpoint authority is invalid.', $service, $owner, $asker, $revision, $runner);
        // Another account cannot ask with this account's tab, although its incarnation and capability are valid and its own.
        $before = $this->committed($owner);
        $this->assertOverrideRefused('Endpoint authority is invalid.', $service, $this->createUser(), $asker, $revision);
        self::assertSame($before, $this->committed($owner));

        // A tab that is not registered any more cannot ask: neither one whose registration lapsed nor
        // one that its own later registration retired.
        $lapsed = $this->endpoint($service, $owner);
        $this->expireRegistration($lapsed['incarnationId']);
        $this->assertOverrideRefused('Connect this browser before playing here.', $service, $owner, $lapsed, $revision);
        $registration = $this->tabOnAPage($owner);
        $retired = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        $service->execute($owner, PlaybackOperation::Endpoint, array_diff_key($registration, ['pageId' => true]));
        $this->assertOverrideRefused('Connect this browser before playing here.', $service, $owner, $retired, $revision);

        // A destination of another kind is not overridable.
        $this->manager->getConnection()->executeStatement("UPDATE playback_destinations SET kind='cast' WHERE id=(SELECT destination_id FROM playback_destination_incarnations WHERE id=?)", [$runner['incarnationId']]);
        $this->assertOverrideRefused('No playback is waiting to be overridden.', $service, $owner, $asker, $revision);
        $this->manager->getConnection()->executeStatement("UPDATE playback_destinations SET kind='browser' WHERE id=(SELECT destination_id FROM playback_destination_incarnations WHERE id=?)", [$runner['incarnationId']]);

        // Once the hold has ended there is nothing left to override.
        $overridden = $this->override($service, $owner, $asker, $revision);
        $this->assertOverrideRefused('No playback is waiting to be overridden.', $service, $owner, $asker, $overridden['revision']);

        // A session nobody ever played holds nothing either.
        $fresh = $this->createUser();
        $first = $this->endpoint($service, $fresh);
        $this->assertOverrideRefused('No playback is waiting to be overridden.', $service, $fresh, $first, $service->execute($fresh, PlaybackOperation::Snapshot, [])['revision']);
    }

    public function testARunnerAProofReleasesInTheSameRequestCountsAsOverridden(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $runner = $this->endpoint($service, $owner);
        $paused = $this->paused($service, $owner, $runner);
        $asker = $this->endpoint($service, $owner);
        $lapsed = $this->endpoint($service, $owner);
        $this->expireRegistration($runner['incarnationId']);
        $this->expireRegistration($lapsed['incarnationId']);
        $requestId = new Uuid()->toString();

        // A tab that is not registered cannot ask, even when the proof would have released the runner in its request.
        $this->assertOverrideRefused('Connect this browser before playing here.', $service, $owner, $lapsed, $paused['revision']);
        self::assertFalse($this->incarnation($runner['incarnationId'])['retired']);

        // No snapshot ran since the lapse, so the session still names the runner when the request arrives.
        $overridden = $this->override($service, $owner, $asker, $paused['revision'], $requestId);

        self::assertNull($overridden['activeIncarnationId']);
        self::assertSame('paused', $overridden['observedState']);
        self::assertSame(43.0, $overridden['position']);
        self::assertSame($paused['revision'] + 1, $overridden['revision']);
        self::assertSame(['requestId' => $requestId, 'commandId' => $requestId, 'outcome' => 'overridden', 'rejectionReason' => null], $overridden['requestResult']);
        self::assertSame($runner['incarnationId'], $this->observer->fetchOne('SELECT incarnation_id FROM playback_commands WHERE id=?', [$requestId]));
        self::assertSame(['paused', true], [$this->incarnation($runner['incarnationId'])['observed_state'], $this->incarnation($runner['incarnationId'])['retired']]);

        $after = $this->committed($owner);
        self::assertSame($overridden['requestResult'], $this->override($service, $owner, $asker, $paused['revision'], $requestId)['requestResult']);
        self::assertSame($after, $this->committed($owner));
    }

    public function testARepeatedOverrideReturnsItsRecordedResultAndItsIdentityServesNoOtherRequest(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['runner' => $runner, 'asker' => $asker, 'askerRegistration' => $askerRegistration, 'unknown' => $unknown] = $this->runnerThatWasShownAPlay($service, $owner);
        $this->expireRegistration($runner['incarnationId']);
        $requestId = new Uuid()->toString();
        $first = $this->override($service, $owner, $asker, $unknown['revision'], $requestId);
        $after = $this->committed($owner);

        // The same request again, as sent: the session changed once.
        $again = $this->override($service, $owner, $asker, $unknown['revision'], $requestId);
        self::assertSame($first['requestResult'], $again['requestResult']);
        self::assertSame($first['revision'], $again['revision']);
        self::assertSame($after, $this->committed($owner));

        // The asking tab registered again in between; the request is still the same request.
        $reconnected = $service->execute($owner, PlaybackOperation::Endpoint, $askerRegistration)['endpoint'];
        self::assertNotSame($asker['incarnationId'], $reconnected['incarnationId']);
        self::assertSame($first['requestResult'], $this->override($service, $owner, $reconnected, $first['revision'], $requestId)['requestResult']);
        self::assertSame($first['revision'], $service->execute($owner, PlaybackOperation::Snapshot, [])['revision']);

        // The identity names this override only, and an override cannot borrow the identity of another request.
        $this->assertRefused('The playback request identity has already been used for another payload.', $service, $owner, PlaybackOperation::Control,
            ['requestId' => $requestId, 'expectedRevision' => $first['revision'], 'action' => 'play']);
        $play = $this->controlFrom($service, $owner, $reconnected, $first['revision'], 'play');
        $this->assertRefused('The playback request identity has already been used for another payload.', $service, $owner, PlaybackOperation::Override,
            ['requestId' => $play['requestResult']['requestId'], 'expectedRevision' => $play['revision'], 'incarnationId' => $reconnected['incarnationId']], $reconnected);
    }

    public function testAnAnswerFromTheOldRunnerThatLandsFirstKeepsItsPlace(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['runner' => $runner, 'asker' => $asker, 'unknown' => $unknown] = $this->runnerThatWasShownAPlay($service, $owner);
        $this->expireRegistration($runner['incarnationId']);

        // The runner was only cut off. It answers the stop it is owed, which renews its registration and moves the session.
        $answered = $this->answer($service, $owner, $runner, 'stopped');
        self::assertSame('stopped', $answered['observedState']);

        $this->assertOverrideRefused('Playback revision conflict.', $service, $owner, $asker, $unknown['revision']);
        $this->assertOverrideRefused('The destination is still connected. Stop playback there.', $service, $owner, $asker, $answered['revision']);
        self::assertSame($runner['incarnationId'], $service->execute($owner, PlaybackOperation::Snapshot, [])['activeIncarnationId']);
    }

    public function testAnOverriddenTabThatReturnsChangesNothingForTheSession(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        $registration = $this->tabOnAPage($owner);
        $runner = $service->execute($owner, PlaybackOperation::Endpoint, $registration)['endpoint'];
        $stopped = $this->stopped($service, $owner, $runner);
        $asker = $this->endpoint($service, $owner);
        $this->controlFrom($service, $owner, $asker, $stopped['revision'], 'play');
        $play = $this->pending($service, $owner, $runner)['commands'][0];
        $this->expireCommands($owner);
        $unknown = $this->pending($service, $owner, $asker);
        $stop = $this->pending($service, $owner, $runner)['commands'][0];
        self::assertSame('stop', $stop['type']);
        $this->expireRegistration($runner['incarnationId']);
        $overridden = $this->override($service, $owner, $asker, $unknown['revision']);
        // The asking tab takes playback over.
        $taken = $this->playing($service, $owner, $asker);
        $session = $this->committed($owner);

        // The old tab was only offline. Its late answers name commands that are closed.
        foreach ([[$play, 'playing'], [$stop, 'stopped']] as [$command, $outcome]) {
            try {
                $this->ack($service, $owner, $runner, $command, $outcome);
                self::fail('A late answer to a superseded command was accepted.');
            } catch (ConflictException $error) {
                self::assertSame('Command outcome has already been recorded.', $error->getMessage());
            }
        }
        self::assertSame($session, $this->committed($owner));

        // Its capability still reads the session, and it is shown that it lost it and has nothing to do.
        $seen = $this->pending($service, $owner, $runner);
        self::assertSame($asker['incarnationId'], $seen['activeIncarnationId']);
        self::assertSame([], $seen['commands']);
        self::assertGreaterThan($overridden['revision'], $seen['revision']);

        // Its page registers again with its page identity and finds nothing to succeed.
        $returned = $service->execute($owner, PlaybackOperation::Endpoint, $registration);
        self::assertSame($asker['incarnationId'], $returned['activeIncarnationId']);
        self::assertSame($taken['revision'], $returned['revision']);
        self::assertSame('playing', $returned['observedState']);

        // Its close report for the overridden incarnation changes only that incarnation's row.
        $closed = $service->execute($owner, PlaybackOperation::Close, ['incarnationId' => $runner['incarnationId'],
            'checkpoint' => ['revision' => $unknown['revision'], 'media' => null, 'currentTrackIndex' => 0, 'position' => 12.0]], $runner['capability']);
        self::assertSame($asker['incarnationId'], $closed['activeIncarnationId']);
        self::assertSame($taken['revision'], $closed['revision']);
        self::assertSame(['observed_state' => 'stopped', 'position' => 12.0, 'retired' => true, 'available' => false], $this->incarnation($runner['incarnationId']));
    }

    public function testAnOverrideCommitsOrRollsBackAsOne(): void
    {
        $owner = $this->createUser();
        $service = $this->coordinator();
        ['runner' => $runner, 'asker' => $asker, 'unknown' => $unknown] = $this->runnerThatWasShownAPlay($service, $owner);
        $this->expireRegistration($runner['incarnationId']);
        $before = $this->committed($owner);

        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        self::assertSame('overridden', $this->override($service, $owner, $asker, $unknown['revision'])['requestResult']['outcome']);
        $connection->rollBack();

        self::assertSame($before, $this->committed($owner));
        self::assertSame($runner['incarnationId'], $service->execute($owner, PlaybackOperation::Snapshot, [])['activeIncarnationId']);
    }
}
