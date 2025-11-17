<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/storageTestSmart.php';

final class storageSmartTest extends testCase
{
    public function testParseSmartOutputAtaStyle(): void
    {
        $lines = [
            'ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE',
            '  9 Power_On_Hours          0x0032   099   099   000    Old_age   Always       -       12345',
            'Self-test log structure revision number 1',
            '# 1  Extended offline    Completed without error       00%     12345         -',
        ];

        $result = \parseSmartOutput($lines);

        $this->assertEquals(12345, $result['powerOnHours']);
        $this->assertTrue($result['lastSelfTestLine'] !== null, 'Expected self-test line to be detected');
        $this->assertTrue(str_starts_with((string) $result['lastSelfTestLine'], '# 1'), 'Unexpected self-test line content');
    }

    public function testParseSmartOutputNvmeStyle(): void
    {
        $lines = [
            'SMART/Health Information (NVMe Log 0x02)',
            'Power On Hours:                         6789',
            'Self-test log structure revision number 1',
            '# 1  Short offline     Completed without error       00%     6789          -',
        ];

        $result = \parseSmartOutput($lines);

        $this->assertEquals(6789, $result['powerOnHours']);
        $this->assertTrue($result['lastSelfTestLine'] !== null, 'Expected NVMe self-test line to be detected');
    }

    public function testParseSmartOutputNoSelfTestSection(): void
    {
        $lines = [
            'ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE',
            '  9 Power_On_Hours          0x0032   099   099   000    Old_age   Always       -       42',
        ];

        $result = \parseSmartOutput($lines);

        $this->assertEquals(42, $result['powerOnHours']);
        $this->assertTrue($result['lastSelfTestLine'] === null, 'Self-test line should be null when section is missing');
    }

    public function testParseSmartOutputMissingHours(): void
    {
        $lines = [
            'Some unrelated SMART output without power-on hours',
            'Self-test log structure revision number 1',
            '# 1  Short offline     Completed without error       00%     -             -',
        ];

        $result = \parseSmartOutput($lines);

        $this->assertTrue($result['powerOnHours'] === null, 'Power-on hours should be null when not present');
        $this->assertTrue($result['lastSelfTestLine'] !== null, 'Expected self-test line to be captured even without hours');
    }

    public function testParseSmartOutputMalformedHours(): void
    {
        $lines = [
            'Power On Hours: not-a-number',
            'Self-test log structure revision number 1',
            '# 1  Short offline     Completed without error       00%     -             -',
        ];

        $result = \parseSmartOutput($lines);

        $this->assertTrue($result['powerOnHours'] === null, 'Malformed hours should result in null powerOnHours');
        $this->assertTrue($result['lastSelfTestLine'] !== null, 'Self-test line should still be detected');
    }

    public function testParseSmartOutputNoRelevantLines(): void
    {
        $lines = [
            'Completely unrelated output',
            'No SMART attributes here',
        ];

        $result = \parseSmartOutput($lines);

        $this->assertTrue($result['powerOnHours'] === null, 'No power-on hours expected from unrelated output');
        $this->assertTrue($result['lastSelfTestLine'] === null, 'No self-test line expected from unrelated output');
    }

    public function testParseSmartOutputUsesFirstSelfTestEntryOnly(): void
    {
        $lines = [
            'Self-test log structure revision number 1',
            '# 1  Short offline     Completed without error       00%     100          -',
            '# 2  Extended offline  Completed without error       00%     200          -',
        ];

        $result = \parseSmartOutput($lines);

        $this->assertTrue($result['lastSelfTestLine'] !== null, 'Expected self-test line to be captured');
        $this->assertTrue(strpos((string) $result['lastSelfTestLine'], '# 1') === 0, 'Expected only the first self-test entry to be used');
    }

    public function testParseSmartOutputSelfTestHeaderCaseInsensitive(): void
    {
        $lines = [
            'self-test log structure revision number 1',
            '# 1  Short offline     Completed without error       00%     300          -',
        ];

        $result = \parseSmartOutput($lines);

        $this->assertTrue($result['lastSelfTestLine'] !== null, 'Expected self-test section detection to be case-insensitive');
    }
}
