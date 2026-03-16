<?php

namespace App\Command;

use App\Exception\FileException;
use App\Exception\OSMException;
use App\Model\Overpass\Element;
use App\Model\Overpass\Overpass;
use App\Wikidata\Wikidata;
use Exception;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Download in JSON format Wikidata item(s) defined in `name:etymology:wikidata` tag for each relation/way.
 *
 * @todo Download Wikidata item defined in `wikidata` tag.
 *
 * @package App\Command
 */
class WikidataCommand extends AbstractCommand
{
    /** {@inheritdoc} */
    protected static $defaultName = 'wikidata';

    /** @var string Wikidata item URL. */
    protected const URL = 'https://www.wikidata.org/wiki/Special:EntityData/';

    /** @var int Minimum delay between Wikidata requests in microseconds. */
    protected const REQUEST_INTERVAL_MICROSECONDS = 200000;

    /** @var int Maximum number of retries after the initial request. */
    protected const MAX_RETRIES = 4;

    /** @var int Default retry delay in milliseconds when Wikidata does not provide one. */
    protected const DEFAULT_RETRY_DELAY_MILLISECONDS = 1000;

    /** @var float|null Timestamp of the last outgoing Wikidata request. */
    private static ?float $lastRequestAt = null;

    /**
     * {@inheritdoc}
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    protected function configure(): void
    {
        parent::configure();

        $this->setDescription('Download data from Wikidata.');
    }

    /**
     * {@inheritdoc}
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Wikidata processing may need to decode large Overpass JSON files.
            ini_set('memory_limit', '-1');

            parent::execute($input, $output);

            $relationPath = sprintf('%s/overpass/%s', self::OUTPUTDIR, OverpassCommand::FILENAME_RELATION);
            if (!file_exists($relationPath) || !is_readable($relationPath)) {
                throw new FileException(sprintf('File "%s" doesn\'t exist or is not readable. You maybe need to run "overpass" command first.', $relationPath));
            }
            $wayPath = sprintf('%s/overpass/%s', self::OUTPUTDIR, OverpassCommand::FILENAME_WAY);
            if (!file_exists($wayPath) || !is_readable($wayPath)) {
                throw new FileException(sprintf('File "%s" doesn\'t exist or is not readable. You maybe need to run "overpass" command first.', $wayPath));
            }

            // Decode large Overpass files one by one to avoid keeping both payloads in memory.
            $elements = array_merge(
                self::extractElementsWithWikidata($relationPath),
                self::extractElementsWithWikidata($wayPath)
            );

            // Check count of elements with Wikidata information.
            if (count($elements) === 0) {
                $output->writeln('No element with Wikidata information!');
                return Command::SUCCESS;
            }

            // Create wikidata directory to store results.
            $outputDir = sprintf('%s/wikidata', self::OUTPUTDIR);
            if (!file_exists($outputDir) || !is_dir($outputDir)) {
                mkdir($outputDir, 0777, true);
            }

            $warnings = [];
            $progressBar = new ProgressBar($output, count($elements));
            $progressBar->start();

            foreach ($elements as $element) {
                /** @var string|null */
                $wikidataTag = $element->tags->wikidata ?? null; // @phpstan-ignore property.notFound
                /** @var string|null */
                $etymologyTag = $element->tags->{'name:etymology:wikidata'} ?? null; // @phpstan-ignore property.notFound

                // Download Wikidata item(s) defined in `name:etymology:wikidata` tag
                if (!is_null($etymologyTag) && $etymologyTag !== $wikidataTag) {
                    $identifiers = explode(';', $etymologyTag);
                    $identifiers = array_map('trim', $identifiers);

                    foreach ($identifiers as $identifier) {
                        // Check that the value of the tag is a valid Wikidata item identifier
                        if (preg_match('/^Q[0-9]+$/', $identifier) !== 1) {
                            $warnings[] = sprintf('Format of `name:etymology:wikidata` is invalid (%s) for %s(%d).', $identifier, $element->type, $element->id);
                            continue;
                        }

                        // Download Wikidata item
                        $path = sprintf('%s/%s.json', $outputDir, $identifier);
                        self::save($identifier, $element, $path, $warnings);
                    }
                }

                // Download Wikidata item defined in `wikidata` tag
                if (!is_null($wikidataTag)) {
                    // Check that the value of the tag is a valid Wikidata item identifier
                    if (preg_match('/^Q[0-9]+$/', $wikidataTag) !== 1) {
                        $warnings[] = sprintf('Format of `wikidata` is invalid (%s) for %s(%d).', $wikidataTag, $element->type, $element->id);
                        continue;
                    }

                    // Download Wikidata item
                    $path = sprintf('%s/%s.json', $outputDir, $wikidataTag);
                    if (!self::save($wikidataTag, $element, $path, $warnings)) {
                        continue;
                    }

                    $entity = Wikidata::read($path);

                    $identifiers = Wikidata::extractNamedAfter($entity);
                    if (!is_null($identifiers)) {
                        foreach ($identifiers as $identifier) {
                            // Check that the value of the tag is a valid Wikidata item identifier
                            if (preg_match('/^Q[0-9]+$/', $identifier) !== 1) {
                                $warnings[] = sprintf('Format of `P138` (NamedAfter) property is invalid (%s) for in item "%s".', $identifier, $wikidataTag);
                                continue;
                            }

                            // Download Wikidata item
                            $path = sprintf('%s/%s.json', $outputDir, $identifier);
                            self::save($identifier, $element, $path, $warnings);
                        }
                    }
                }

                $progressBar->advance();
            }

            $progressBar->finish();

            $output->writeln(['', ...$warnings]);

            return Command::SUCCESS;
        } catch (Exception $error) {
            $output->writeln(sprintf('<error>%s</error>', $error->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * Read an Overpass JSON result and keep only elements with Wikidata-related tags.
     *
     * @param string $path
     * @return Element[]
     */
    private static function extractElementsWithWikidata(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        /** @var Overpass|null */
        $overpass = json_decode($content);

        // Release the raw JSON string before filtering to reduce peak memory usage.
        unset($content);

        $elements = array_values(array_filter(
            $overpass->elements ?? [],
            function ($element): bool {
                return isset($element->tags) &&
                    (isset($element->tags->wikidata) || isset($element->tags->{'name:etymology:wikidata'})); // @phpstan-ignore property.notFound,property.notFound
            }
        ));

        unset($overpass);

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        return $elements;
    }

    /**
     * Send request and store result.
     * Display warning if the Wikidata item doesn't exist or if the process can't download the Wikidate item.
     * @see https://www.mediawiki.org/wiki/Wikibase/EntityData
     *
     * @param string $identifier Wikidata item identifier.
     * @param Element $element OpenStreetMap element (relation/way/node).
     * @param string $path Path where to store the result.
     * @param string[] $warnings
     * @return bool True when the file is available locally after the call.
     */
    private static function save(string $identifier, $element, string $path, array &$warnings = []): bool
    {
        if (file_exists($path) && is_readable($path)) {
            return true;
        }

        $url = sprintf('%s%s.json', self::URL, $identifier);

        $retryMiddleware = Middleware::retry(
            function ($retries, $request, $response, $exception) {
                if ($retries >= self::MAX_RETRIES) {
                    return false;
                }

                if ($response && $response->getStatusCode() === 429) {
                    return true;
                }

                return false;
            },
            function ($retries, ?ResponseInterface $response = null): int {
                return self::retryDelayMilliseconds($retries, $response);
            }
        );

        $stack = HandlerStack::create();
        $stack->push($retryMiddleware);

        try {
            self::throttleRequests();

            $client = new \GuzzleHttp\Client(['handler' => $stack]);
            $client->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'EqualStreetNames (+https://equalstreetnames.org)',
                ],
                'connect_timeout' => 10,
                'sink' => $path,
                'timeout' => 30,
            ]);

            return true;
        } catch (BadResponseException $exception) {
            self::cleanupPartialDownload($path);

            switch ($exception->getResponse()->getStatusCode()) {
                case 404:
                    $warnings[] = sprintf('<warning>Wikidata item %s for %s(%d) does not exist.</warning>', $identifier, $element->type, $element->id);
                    break;
                default:
                    $warnings[] = sprintf('<warning>Error while fetching Wikidata item %s for %s(%d): %s.</warning>', $identifier, $element->type, $element->id, $exception->getMessage());
                    break;
            }
        } catch (GuzzleException $exception) {
            self::cleanupPartialDownload($path);
            $warnings[] = sprintf('<warning>Error while fetching Wikidata item %s for %s(%d): %s.</warning>', $identifier, $element->type, $element->id, $exception->getMessage());
        }

        return false;
    }

    /**
     * Slow down outbound requests so Wikidata is less likely to rate-limit the process.
     *
     * @return void
     */
    private static function throttleRequests(): void
    {
        if (self::$lastRequestAt !== null) {
            $elapsedMicroseconds = (int) round((microtime(true) - self::$lastRequestAt) * 1000000);
            $sleepMicroseconds = self::REQUEST_INTERVAL_MICROSECONDS - $elapsedMicroseconds;

            if ($sleepMicroseconds > 0) {
                usleep($sleepMicroseconds);
            }
        }

        self::$lastRequestAt = microtime(true);
    }

    /**
     * Compute retry delay using Wikidata's Retry-After header when available.
     *
     * @param int $retries Current retry count.
     * @param ResponseInterface|null $response
     * @return int
     */
    private static function retryDelayMilliseconds(int $retries, ?ResponseInterface $response = null): int
    {
        if ($response !== null) {
            $retryAfter = $response->getHeaderLine('Retry-After');

            if ($retryAfter !== '') {
                if (ctype_digit($retryAfter)) {
                    return max((int) $retryAfter * 1000, self::DEFAULT_RETRY_DELAY_MILLISECONDS);
                }

                $retryAt = strtotime($retryAfter);
                if ($retryAt !== false) {
                    return max(($retryAt - time()) * 1000, self::DEFAULT_RETRY_DELAY_MILLISECONDS);
                }
            }
        }

        return self::DEFAULT_RETRY_DELAY_MILLISECONDS * $retries;
    }

    /**
     * Remove partial files left behind after failed requests.
     *
     * @param string $path
     * @return void
     */
    private static function cleanupPartialDownload(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
