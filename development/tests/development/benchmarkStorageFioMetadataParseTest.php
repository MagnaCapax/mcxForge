<?php

declare(strict_types=1);

namespace mcxForge\Tests;

require_once __DIR__ . '/../../../bin/benchmarkStorageFioMetadata.php';

final class benchmarkStorageFioMetadataParseTest extends testCase
{
    public function testParseIopsFromFioJsonTotalsAcrossJobs(): void
    {
        $json = <<<JSON
{
  "jobs": [
    {
      "jobname": "mcxForgeMetadata-1",
      "read": {
        "iops": 100.5
      }
    },
    {
      "jobname": "mcxForgeMetadata-2",
      "read": {
        "iops": 200.5
      }
    }
  ]
}
JSON;

        $iops = \benchmarkStorageFioMetadataParseIopsFromJson($json);
        $this->assertEquals(301.0, $iops);
    }

    public function testParseIopsFromFioJsonReturnsNullWhenNoJobs(): void
    {
        $json = '{"jobs": []}';
        $iops = \benchmarkStorageFioMetadataParseIopsFromJson($json);
        $this->assertTrue($iops === null);
    }

    public function testParseArgumentsDefaultsAndRequireTargetDir(): void
    {
        $caught = false;

        try {
            \benchmarkStorageFioMetadataParseArguments(['benchmarkStorageFioMetadata.php']);
        } catch (\Throwable $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'Expected parse to exit or throw when --target-dir is missing');
    }
}

