<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\AiAssistant\Cron\Reap;
use Zero\AiAssistant\Model\Reaper;

/**
 * The reaper runs from cron every 15 minutes (A6-6): one reap per run, what it marked is logged, a failure never breaks cron.
 */
class ReapTest extends TestCase
{
    public function testTheJobIsDeclaredEveryFifteenMinutesInTheDefaultGroup(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/crontab.xml');
        $jobs = $xml->xpath('/config/group[@id="default"]/job[@name="zero_ai_assistant_reap"]');

        $this->assertCount(1, $jobs);
        $this->assertSame(Reap::class, (string) $jobs[0]['instance']);
        $this->assertSame('execute', (string) $jobs[0]['method']);
        $this->assertSame('*/15 * * * *', (string) $jobs[0]->schedule);
    }

    public function testOneReapAndWhatItMarkedIsLogged(): void
    {
        $reaper = $this->createMock(Reaper::class);
        $reaper->expects($this->once())->method('reap')->with()->willReturn(['changes' => 2, 'items' => 0]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('Zero_AiAssistant: settled after an hour stuck: changes=2');
        $logger->expects($this->never())->method('error');

        (new Reap($reaper, $logger))->execute();
    }

    public function testNothingStuckLogsNothing(): void
    {
        $reaper = $this->createMock(Reaper::class);
        $reaper->expects($this->once())->method('reap')->willReturn(['changes' => 0, 'items' => 0]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('error');

        (new Reap($reaper, $logger))->execute();
    }

    public function testAFailureIsLoggedAndCronGoesOn(): void
    {
        $reaper = $this->createStub(Reaper::class);
        $reaper->method('reap')->willThrowException(new \RuntimeException('Lost connection'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Zero_AiAssistant: the reaper failed: Lost connection');

        (new Reap($reaper, $logger))->execute();
    }
}
