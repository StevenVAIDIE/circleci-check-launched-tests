<?php

declare(strict_types=1);

namespace Akeneo\CircleCiDashboard\Infrastructure\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JunitAnalyzeTestsCommand extends Command
{
    protected static $defaultName = 'junit:analyze_tests';
    protected static $defaultDescription = 'Download all phpunit results from circle ci workflow id';

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('output_directory', InputArgument::REQUIRED, 'Output directory')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tests = [];
        $testTimming = [];
        $outputDirectory = $input->getArgument('output_directory');

        foreach (array_diff(scandir($outputDirectory), array('..', '.')) as $junitFile) {
            $tests = array_merge($tests, $this->getTests($outputDirectory, $junitFile));
            $testTimming = array_merge($testTimming, $this->getTestsTiming($outputDirectory, $junitFile));
        }

        $diff = array_unique(array_diff_assoc( $tests, array_unique( $tests ) ) );
        if (!empty($diff)) {
            var_dump("Diff found ! ");
            var_dump($diff);
        }

        arsort($testTimming);
        var_dump(array_slice($testTimming, 0, 30));
        return Command::SUCCESS;
    }

    private function getTests(string $outputDirectory, string $junitFile): array
    {
        $file = simplexml_load_file($outputDirectory . '/' . $junitFile);
        if (isset($file->testsuites)) {
            return $this->extractTestsuites([], $file->testsuites);
        }

        if (empty($file->testsuite)) {
            var_dump('No test case found in '. $junitFile);
            return [];
        }

        return $this->extractTestsuites([], $file->testsuite);
    }

    private function getTestsTiming(string $outputDirectory, string $junitFile): array
    {
        $result = [];
        $file = simplexml_load_file($outputDirectory . '/' . $junitFile);

        if (!isset($file->testsuite->testcase)) {
            return [];
        }

        foreach($file->testsuite->testcase as $testCase) {
            $testFingerprint = (string) $testCase['name'];
            if (isset($testCase['class'])) {
                $testFingerprint = $testCase['class'] . ':' . $testCase['name'];
            }

            if (isset($testCase['classname'])) {
                $testFingerprint = $testCase['classname'] . ':' . $testCase['name'];
            }

            $result[$testFingerprint] = (int) $testCase["time"];
        }

        return $result;
    }

    private function extractTestsuites(array $accu, \SimpleXMLElement $testSuites)
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

                $accu[] = $testFingerprint;
            }
        }

        if (isset($testSuites->testsuite)) {
            foreach ($testSuites->testsuite as $testsuite) {
                $accu = $this->extractTestsuites($accu, $testsuite);
            }
        }

        return $accu;
    }
}
