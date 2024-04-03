<?php

declare(strict_types=1);

namespace Akeneo\CircleCiDashboard\Infrastructure\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JunitExtractTestsCommand extends Command
{
    protected static $defaultName = 'junit:extract_test';
    protected static $defaultDescription = 'Merge junit results';

    protected function configure(): void
    {
        $this
            ->addArgument('input_directory', InputArgument::REQUIRED, 'Input directory')
            ->addArgument('output', InputArgument::REQUIRED, 'Output')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tests = [];
        $inputDirectory = $input->getArgument('input_directory');
        $outputFile = $input->getArgument('output');

        foreach (array_diff(scandir($inputDirectory), array('..', '.')) as $junitFile) {
            $tests = array_merge($tests, $this->getTests($output, $inputDirectory, $junitFile));
        }

        sort($tests);
        $uniqueTest = array_values(array_unique($tests));
        $encodedTests = json_encode($uniqueTest, JSON_PRETTY_PRINT);
        file_put_contents($outputFile, $encodedTests);

        $diff = array_diff_assoc($tests, array_unique($tests));
        if (!empty($diff)) {
            var_dump(sprintf("%s tests are launched several times !", count($diff)));
            var_dump(sprintf("%s tests are launched several times !", count(array_unique($diff))));
            //var_dump($diff);
        }

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
