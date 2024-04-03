<?php

declare(strict_types=1);

namespace Akeneo\CircleCiDashboard\Infrastructure\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateDiffStatTestsCommand extends Command
{
    protected static $defaultName = 'diff:test';
    protected static $defaultDescription = 'Diff between tests';

    protected function configure(): void
    {
        $this
            ->addArgument('old', InputArgument::REQUIRED, 'Old extracted tests')
            ->addArgument('new', InputArgument::REQUIRED, 'New extracted tests')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $old = $input->getArgument('old');
        $new = $input->getArgument('new');

        $decodedOld = json_decode(file_get_contents($old));
        $decodedNew = json_decode(file_get_contents($new));

        $output->writeln('Old ci contain test:' . count($decodedOld));
        $output->writeln('New ci contain test:' . count($decodedNew));

        $onlyInOld = array_values(array_diff($decodedOld, $decodedNew));
        $onlyInNew = array_values(array_diff($decodedNew, $decodedOld));

        $output->writeln('Only in old:' . count($onlyInOld));
        $output->writeln('Only in new:' . count($onlyInNew));

        file_put_contents('onlyInOld.json', json_encode($onlyInOld, JSON_PRETTY_PRINT));
        file_put_contents('onlyInNew.json', json_encode($onlyInNew, JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }

    private function getTests(OutputInterface $output, string $outputDirectory, string $junitFile): array
    {
        $file = simplexml_load_file($outputDirectory . '/' . $junitFile);
        if (isset($file->testsuites)) {
            return $this->extractTestSuites([], $file->testsuites);
        }

        if (empty($file->testsuite)) {
            $output->writeln(sprintf('<info>No test case found in %s</info>', $junitFile));
            return [];
        }

        return $this->extractTestSuites([], $file->testsuite);
    }

    private function extractTestSuites(array $accumulator, \SimpleXMLElement $testSuites)
    {
        if (isset($testSuites->testcase)) {
            foreach($testSuites->testcase as $testCase) {
                $testFingerprint = (string) $testCase['name'];
                if (isset($testCase['class'])) {
                    $testFingerprint = $testCase['class'] . ':' . $testCase['name'];
                }

                if (isset($testCase['classname'])) {
                    $testFingerprint = $testCase['classname'] . ':' . $testCase['name'];
                }

                $accumulator[] = $testFingerprint;
            }
        }

        if (isset($testSuites->testsuite)) {
            foreach ($testSuites->testsuite as $testsuite) {
                $accumulator = $this->extractTestsuites($accumulator, $testsuite);
            }
        }

        return $accumulator;
    }
}
