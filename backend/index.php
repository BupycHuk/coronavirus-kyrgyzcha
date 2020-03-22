<?php

require_once './vendor/autoload.php';

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Class CoronavirusTrackerApi
 */
class CoronavirusTrackerApi
{
    const API_URL = 'https://coronavirus-tracker-api.herokuapp.com/v2/locations?timelines=1';
    const CACHE_KEY = 'coronavirus-tracker';
    const CACHE_TIME = 3600;
    const CACHE_DIR = './cache';

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * CoronavirusTrackerApi constructor.
     */
    public function __construct()
    {
        $this->client = new GuzzleHttp\Client();
        $this->cache = new FilesystemAdapter('', 0, self::CACHE_DIR);
    }

    /**
     * @throws Exception
     */
    public function getData()
    {
        try {
            $response = $this->client->request('GET', self::API_URL);
        } catch (GuzzleException $e) {
            throw new Exception('Invalid response: ' . $e->getMessage());
        }
        $content = $response->getBody()->getContents();

        if (empty($content)) {
            throw new Exception('The response is empty');
        }
        $json = json_decode($content, true);
        if (empty($json)) {
            throw new Exception('Can\'t be parsed');
        }

        $json = $this->excludeDetailedTimeline($json);

        return $json;
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws Exception
     */
    public function getDataCached()
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TIME);
            return $this->getData();
        });
    }

    /**
     * @param array $json
     * @return array
     */
    private function excludeDetailedTimeline(array $json)
    {
        foreach ($json['locations'] as $locationKey => $location) {
            // Remove detailed timeline for each type (confirmed, deaths, recovered)
            foreach ($location['timelines'] as $timelineKey => $timeline) {
                unset($json['locations'][$locationKey]['timelines'][$timelineKey]['timeline']);

                $timeSeries = $timeline['timeline'];
                $prevDay = 0;
                if (count($timeSeries) > 1) {
                    array_pop($timeSeries);
                    $prevDay = array_pop($timeSeries);
                }

                $json['locations'][$locationKey]['timelines'][$timelineKey]['prev_day'] = $prevDay;
            }
        }
        return $json;
    }

}

$api = new CoronavirusTrackerApi();

try {
    header('Content-type: application/json');
    echo json_encode($api->getDataCached());
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
