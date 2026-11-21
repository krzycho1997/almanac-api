<?php

declare(strict_types=1);

namespace App\PHPStan\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ArchitectureRulesTest extends TestCase
{
    private const string FIXTURES = 'tools/phpstan/tests/Architecture/Fixtures/';

    public function testRulesReportExactlyTheViolationsInTheFixtures(): void
    {
        // Given
        $expected = [
            'Briefing/ProbeUsesOtherModule.php' => ['phpat.modulesUseOtherModulesOnlyThroughContracts.Briefing'],
            'Briefing/ProbeUsesTestContract.php' => ['phpat.modulesUseOtherModulesOnlyThroughContracts.Briefing'],
            'Shared/ProbeUsesModule.php' => ['phpat.sharedDependsOnNoModule'],
            'Briefing/Controller/ProbeControllerUsesDoctrine.php' => ['phpat.edgeOnlyTranslatesInputAndOutput'],
            'Bookmark/Controller/ProbeControllerUsesDoctrineBridge.php' => ['phpat.edgeOnlyTranslatesInputAndOutput'],
            'Shared/ErrorHandler/ProbeListenerUsesDoctrine.php' => ['phpat.edgeOnlyTranslatesInputAndOutput'],
            'Briefing/Health/ProbeHealthCheckUsesDoctrine.php' => ['phpat.edgeOnlyTranslatesInputAndOutput'],
            'Briefing/ProbeServiceUsesHttp.php' => ['phpat.servicesAndExceptionsDoNotKnowHttp'],
            'Briefing/ProbeServiceUsesEdge.php' => ['phpat.servicesAndExceptionsDoNotKnowHttp'],
            'Identity/Exception/ProbeHttpException.php' => ['phpat.servicesAndExceptionsDoNotKnowHttp'],
            'Bookmark/Contract/ProbeContractUsesInternal.php' => ['phpat.contractsDependOnlyOnContracts'],
            'Bookmark/Contract/ProbeContractUsesDoctrineBridge.php' => ['phpat.modelAndContractsStayPure'],
            'Shared/Contract/ProbeSharedContractUsesDoctrine.php' => ['phpat.modelAndContractsStayPure'],
            'Identity/Contract/ProbeUserIdType.php' => ['phpat.modelAndContractsStayPure'],
            'Briefing/Model/ProbeDayIdType.php' => ['phpat.modelAndContractsStayPure', 'phpat.modelCarriesReadonlyData'],
            'Briefing/Model/ProbeMutableModel.php' => ['phpat.modelCarriesReadonlyData'],
            'Briefing/ProbeUsesSourceInterior.php' => ['phpat.sourceInteriorsStayPrivate.Apod'],
            'Briefing/Source/Apod/ProbeClientKnowsItsWiring.php' => ['phpat.sourceInteriorsKnowNoWiring', 'phpat.sourceInteriorsKnowNoWiring'],
            'Briefing/Source/Apod/ProbeClientUsesStorage.php' => ['phpat.sourceInteriorsKnowNoWiring'],
            'Briefing/Model/ProbeModelUsesEntity.php' => ['phpat.modelAndContractsStayPure'],
            'Briefing/Dto/Response/ProbeResponseUsesDomainEnum.php' => ['phpat.responseDtosOwnTheirTypes'],
            'Briefing/Dto/Response/ProbeResponseUsesModel.php' => ['phpat.responseDtosOwnTheirTypes'],
            'Shared/ProbeNotFinal.php' => ['phpat.classesAreFinal'],
            'Identity/Entity/ProbeEntityUsesServiceAndHttp.php' => ['phpat.entitiesStayBelowServices', 'phpat.entitiesStayBelowServices'],
            'Identity/Entity/ProbeEntityUsesMapper.php' => ['phpat.entitiesStayBelowServices'],
            'Bookmark/ProbeMintsDiscoveryId.php' => ['phpat.onlyTheExtractorMintsDiscoveryIds'],
            'Briefing/Discovery/ProbeMintsWithoutTheExtractor.php' => ['phpat.onlyTheExtractorMintsDiscoveryIds'],
            'Briefing/ProbeUsesPrometheus.php' => ['phpat.metricsGoThroughThePort'],
            'Briefing/ProbeUsesMetricsAdapter.php' => ['phpat.metricsGoThroughThePort'],
            'Briefing/ProbeUsesScrapedMetrics.php' => ['phpat.metricsGoThroughThePort'],
        ];

        // When
        $actual = $this->analyseFixtures();

        // Then
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    /**
     * @return array<string, list<string>>
     */
    private function analyseFixtures(): array
    {
        $root = \dirname(__DIR__, 4);
        $process = proc_open(
            [\PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--configuration', __DIR__.'/phpstan.neon', '--error-format=json', '--no-progress', '--memory-limit=1G'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertIsString($output);
        $report = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report, (string) $errorOutput);
        self::assertSame([], $report['errors'] ?? null, 'PHPStan reported errors outside the fixtures.');

        $files = $report['files'] ?? null;
        self::assertIsArray($files);
        $actual = [];
        foreach ($files as $path => $file) {
            self::assertIsString($path);
            self::assertIsArray($file);
            self::assertIsArray($file['messages'] ?? null);
            $relative = str_replace($root.'/'.self::FIXTURES, '', $path);
            foreach ($file['messages'] as $message) {
                self::assertIsArray($message);
                self::assertIsString($message['identifier'] ?? null, json_encode($message, \JSON_THROW_ON_ERROR));
                $actual[$relative][] = $message['identifier'];
            }
        }
        ksort($actual);

        return $actual;
    }
}
