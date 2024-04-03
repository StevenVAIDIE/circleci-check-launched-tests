<?php

declare(strict_types=1);

namespace Akeneo\CircleCiDashboard\Infrastructure\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CircleCiDownloadJunitArtefactsCommand extends Command
{
    protected static $defaultName = 'circleci:download_junit_artefacts';
    protected static $defaultDescription = 'Download all phpunit results from circle ci workflow id';

    public function __construct(private string $token)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workflow_id', InputArgument::REQUIRED, 'Circleci workflow id')
            ->addArgument('output_directory', InputArgument::REQUIRED, 'Output directory')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow_id');
        $outputDirectory = $input->getArgument('output_directory');

        $this->clearOutputDir($outputDirectory);
        $jobs = $this->getWorkflowJobs($workflowId);
        $junitArtefacts = $this->getJunitArtefacts($jobs);
        $this->downloadArtefactsByChunks($junitArtefacts, $outputDirectory);

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
     */
    private function getJunitArtefacts(array $jobs): array
    {
        $artefacts = [];
        foreach ($jobs as $job) {
            $curl = curl_init();
            $projectSlug = $job['project_slug'];
            $jobId = $job['job_number'];

            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://circleci.com/api/v1.1/project/$projectSlug/$jobId/artifacts",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    "Circle-Token: $this->token",
                ),
            ));

            $response = curl_exec($curl);
            $decodedResponse = json_decode($response, true);

            $artefacts = array_merge(
                $artefacts,
                array_map(
                    static fn(array $artefact) => ['path' => $artefact['path'], 'url' => $artefact['url']],
                    $decodedResponse
                )
            );
        }

        return array_filter($artefacts, static fn (array $artefact) => str_ends_with($artefact['path'], '.xml'));
    }

    /**
     * @return string[]
     */
    private function getWorkflowJobs(string $workflowId): array
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
          CURLOPT_URL => "https://circleci.com/api/v2/workflow/$workflowId/job",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            "Circle-Token: $this->token",
          ),
        ));

        $response = curl_exec($curl);
        $decodedResponse = json_decode($response, true);

        if ($decodedResponse['next_page_token'] !== null) {
            throw new \Exception("Unhandled next page token");
        }

        return array_filter(
            $decodedResponse['items'],
            fn (array $item) => $item['type'] === "build" && $item['status'] === "success"
        );
    }

    private function downloadArtefactsByChunks(array $artefacts, string $outputDirectory): void
    {
        var_dump(count($artefacts) . " artefacts will be downloaded");
        $artefactBatches = array_chunk($artefacts, 20);
        foreach ($artefactBatches as $i => $artefacts) {
            var_dump(sprintf("Download artefacts n°%d/%d", $i, count($artefactBatches)));

            $this->downloadArtefacts($artefacts, $outputDirectory);
        }
    }

    private function downloadArtefacts(array $artefacts, string $outputDirectory): void
    {
        $mh = curl_multi_init();
        $curlHandles = [];

        foreach($artefacts as $artefact){
            $curlHandle = curl_init();

            curl_setopt_array($curlHandle, array(
                CURLOPT_URL => $artefact['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    "Circle-Token: $this->token",
                ),
            ));

            curl_multi_add_handle($mh, $curlHandle);
            $curlHandles[] = $curlHandle;
        }

        $stillRunning = false;
        do {
            curl_multi_exec($mh, $stillRunning);
        } while ($stillRunning);

        $responses = [];
        foreach($curlHandles as $key => $curlHandle) {
            curl_multi_remove_handle($mh, $curlHandle);
            $responses[$key]['content'] = curl_multi_getcontent($curlHandle);
            $responses[$key]['url'] = $artefacts[$key]['url'];
            $responses[$key]['http_code'] = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
            curl_close($curlHandle);
        }

        var_dump(count($curlHandles) . " calls have been made");
        $notOkRequest = array_filter($responses, fn (array $response) => $response['http_code'] !== 200);
        if (count($notOkRequest)) {
            var_dump("Some request are invalid" . json_encode($notOkRequest));
        }

        curl_multi_close($mh);

        foreach ($responses as $response) {
            $exploded = explode('/', $response['url']);
            $outputFile = end($exploded);
            file_put_contents($outputDirectory . '/' . $outputFile, $response['content']);
        }
    }
}
