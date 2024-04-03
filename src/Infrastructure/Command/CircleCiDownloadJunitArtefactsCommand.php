<?php

declare(strict_types=1);

namespace Akeneo\CircleCiDashboard\Infrastructure\Command;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CircleCiDownloadJunitArtefactsCommand extends Command
{
    protected static $defaultName = 'circleci:download_junit_artefacts';
    protected static $defaultDescription = 'Download all phpunit results from circle ci workflow id';

    public function __construct(private readonly ClientInterface $circleCiClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workflow_id', InputArgument::REQUIRED, 'Circleci workflow id')
            ->addArgument('output_directory', InputArgument::OPTIONAL, 'Output directory', 'output/artefacts')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow_id');
        $outputDirectory = $input->getArgument('output_directory');

        $this->clearOutputDir($outputDirectory);
        try {
            $jobs = $this->getWorkflowJobs($workflowId);
            $junitArtefacts = $this->getJunitArtefactsForJobs($jobs);
            $this->downloadArtefacts($junitArtefacts, $outputDirectory);
        } catch (\Throwable $e) {
            $output->writeln("Unable to download junit artefacts");
            $output->writeln($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    function clearOutputDir(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory);

            return;
        }

        $iterator = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach($files as $file) {
            if ($file->isDir()){
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
    }

    /**
     * @return string[]
     * @throws GuzzleException
     * @throws \Exception
     */
    private function getWorkflowJobs(string $workflowId): array
    {
        $response = $this->circleCiClient->request('GET', "api/v2/workflow/$workflowId/job", [
            'headers' => [
                'Content-Type: application/json',
            ]
        ]);

        $decodedResponse = json_decode($response->getBody()->getContents(), true);
        if ($decodedResponse['next_page_token'] !== null) {
            throw new \Exception("Unhandled next page token");
        }

        return array_filter(
            $decodedResponse['items'],
            fn (array $item) => $item['type'] === "build" && $item['status'] === "success"
        );
    }

    /**
     * @return string[]
     * @throws GuzzleException
     */
    private function getJunitArtefactsForJobs(array $jobs): array
    {
        $artefacts = [];
        foreach ($jobs as $job) {
            $projectSlug = $job['project_slug'];
            $jobId = $job['job_number'];

            $response = $this->circleCiClient->request('GET', "api/v1.1/project/$projectSlug/$jobId/artifacts", [
                'headers' => [
                    'Content-Type: application/json',
                ]
            ]);

            $decodedArtefacts = json_decode($response->getBody()->getContents(), true);
            $junitArtefacts = array_filter(
                $decodedArtefacts,
                static fn (array $artefact) => str_ends_with($artefact['path'], '.xml')
            );

            $artefacts = array_merge($artefacts, array_map(
                static fn (array $artefact) => [
                    'filename' => sprintf('%s-%s-%s', $jobId, $artefact['node_index'], basename($artefact['path'])),
                    'url' => $artefact['url']
                ],
                $junitArtefacts
            ));
        }

        return $artefacts;
    }

    private function downloadArtefacts(array $artefacts, string $outputDirectory): void
    {
        $pool = new Pool(
            $this->circleCiClient,
            $this->generatePromises($artefacts, $outputDirectory),
            [
                'concurrency' => 20,
                'fulfilled' => function (ResponseInterface $response, int $key) use ($artefacts, $outputDirectory) {
                },
                'rejected' => function (RequestException $reason) {
                    throw new \Exception('Request rejected', previous: $reason);
                },
            ],
        );

        $promise = $pool->promise();
        $promise->wait();
    }

    private function generatePromises(array $artefacts, string $outputDirectory): \Iterator
    {
        foreach ($artefacts as $index => $artefact) {
            yield $index => fn () => $this->circleCiClient->requestAsync('GET', $artefact['url'], [
                'sink' => $outputDirectory . '/' . $artefact['filename']
            ]);
        }
    }
}
