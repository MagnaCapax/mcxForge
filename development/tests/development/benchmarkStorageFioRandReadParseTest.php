<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkStorageFioRandRead.php';

final class benchmarkStorageFioRandReadParseTest extends testCase
{
    public function testParseIopsFromFioJson(): void
    {
        $json = <<<JSON
{
  "jobs": [
    {
      "jobname": "mcxForgeRandRead",
      "read": {
        "io_bytes": 123456789,
        "iops": 987.65
      }
    }
  ]
}
JSON;

        $iops = \benchmarkStorageFioRandReadParseIopsFromJson($json);
        $this->assertEquals(987.65, $iops);
    }

    public function testParseIopsReturnsNullWhenStructureMissing(): void
    {
        $json = '{"not_jobs": []}';

        $iops = \benchmarkStorageFioRandReadParseIopsFromJson($json);
        $this->assertTrue($iops === null);
    }

    public function testParseArgumentsDefaults(): void
    {
        [$device, $mode, $runtime, $bs, $iodepth, $scoreOnly, $colorEnabled] = \benchmarkStorageFioRandReadParseArguments(
            ['benchmarkStorageFioRandRead.php']
        );

        $this->assertTrue($device === null);
        $this->assertEquals('main', $mode);
        $this->assertEquals(120, $runtime);
        $this->assertEquals('512k', $bs);
        $this->assertEquals(16, $iodepth);
        $this->assertTrue($scoreOnly === false);
        $this->assertTrue($colorEnabled === true);
    }
}

