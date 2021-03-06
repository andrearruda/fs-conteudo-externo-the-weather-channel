<?php
namespace App\V1\Action;

use Slim\Http\Request,
    Slim\Http\Response;

use phpQuery;
use FileSystemCache;
use Stringy\Stringy as S;
use App\V2\Service\SimpleXMLExtended;

final class WeatherChannelAction
{
    private $city_id, $city_name, $city_country;
    private $locale = null;
    private $path;

    public function __construct()
    {
    }

    public function __invoke(Request $request, Response $response, $args)
    {
        $this->setCityId($args['city-id']);

        if(isset($args['city-name']))
        {
            $this->setCityName($args['city-name']);
        }

        if(isset($args['locale']))
        {
            $this->setCityName($args['locale']);
        }

        $forceFileCached = isset($request->getQueryParams()['forceFileCached']) ? $request->getQueryParams()['forceFileCached'] : false;

        /** @var \Slim\Http\Uri $uri */
        $uri = $request->getUri();
        $this->path = sprintf('%s://%s', $uri->getScheme(), $uri->getHost() . ($uri->getPort() ? ':' .$uri->getPort() : '')) . '/uploads/images/';

        FileSystemCache::$cacheDir = __DIR__ . '/../../../../data/cache/tmp';
        $key = FileSystemCache::generateCacheKey(sprintf('v1.%s', $this->getCityId()));
        $data = FileSystemCache::retrieve($key);

        if($data === false || $forceFileCached == true)
        {
            $published = strtotime(json_decode(file_get_contents('http://dsx.weather.com/cs/v2/datetime/' . $this->getLocale() . '/' . $this->getCityId() . ':1:' . $this->getCityCountry()), true)['datetime']);
            $now = json_decode(file_get_contents('http://dsx.weather.com/wxd/v2/MORecord/' . $this->getLocale() . '/' . $this->getCityId() . ':1:' . $this->getCityCountry()))->MOData;
            $forecasts = json_decode(file_get_contents('http://dsx.weather.com/wxd/v2/15DayForecast/' . $this->getLocale() . '/' . $this->getCityId() . ':1:' . $this->getCityCountry()))->fcstdaily15alluoms->forecasts;

            $data = array(
                'info' => array(
                    'date' => array(
                        'created' => date('Y-m-d H:i:s'),
                        'published' => date('Y-m-d H:i:s', $published),
                    ),
                    'location' => $this->getLocation(),
                ),
                'now' => array(
                    'temp' => (string) $now->tmpC,
                    'prospect' => array(
                        'temp' => array(
                            'max' => (string) $now->tmpMx24C,
                            'min' => (string) $now->tmpMn24C
                        ),
                    ),
                    'midia' => array(
                        'icon' => $this->getPath() . $now->sky . '_icon.png',
                        'background' => $this->getPath() . $now->sky . '_bg.jpg'
                    )
                ),
                'forecasts' => array()
            );

            foreach ($forecasts as $i => $item)
            {
                if (isset($item->day))
                    $period_type = 'day';
                else
                    $period_type = 'night';

                $forecast = $item->$period_type;

                $data['forecasts'][$i] = array(
                    'weekday' => str_replace('-feira', '', $forecast->daypart_name),
                    'phrases' => array(
                        'pop' => $forecast->pop_phrase,
                        'narrative' => $item->metric->narrative
                    ),
                    'temp' => array(
                        'max' => (string) (!empty($item->metric->max_temp) ? $item->metric->max_temp : $item->metric->min_temp),
                        'min' => (string) $item->metric->min_temp
                    ),
                    'midia' => array(
                        'icon' => $this->getPath() . $item->$period_type->icon_code . '_icon.png'
                    )
                );

                if($i >= 4)
                {
                    break;
                }
            }

            FileSystemCache::store($key, $data, 1800);
        }

        $json = json_decode(json_encode($data));

        $xml = new SimpleXMLExtended('<root/>');
        $info = $xml->addChild('info');
        $info->addChild('date');
        $info->date->addChild('created', $json->info->date->created);
        $info->date->addChild('published', $json->info->date->published);
        $info->addChild('location')->addChild('city', $json->info->location->city);

        $now = $xml->addChild('now');
        $now->addChild('temp', $json->now->temp);
        $now->addChild('prospect');
        $now->prospect->addChild('temp');
        $now->prospect->temp->addChild('max', $json->now->prospect->temp->max);
        $now->prospect->temp->addChild('min', $json->now->prospect->temp->min);
        $now->addChild('midia');
        $now->midia->addChild('icon', $json->now->midia->icon);
        $now->midia->addChild('background', $json->now->midia->background);

        $forecasts = $xml->addChild('forecasts');
        foreach ($json->forecasts as $forecast)
        {
            $item = $forecasts->addChild('item');
            $item->addChild('weekday', $forecast->weekday);
            $item->addChild('phrases')->addChild('pop', $forecast->phrases->pop);
            $item->phrases->addChild('narrative', $forecast->phrases->narrative);
            $item->addChild('temp');
            $item->temp->addChild('max', $forecast->temp->max);
            $item->temp->addChild('min', $forecast->temp->min);
            $item->addChild('midia')->addChild('icon', $forecast->midia->icon);
        }

        $response->write($xml->asXML());
        $response = $response->withHeader('content-type', 'application/xml; charset=utf-8');
        return $response;
    }

    /**
     * @return mixed
     */
    private function getCityId()
    {
        return $this->city_id;
    }

    /**
     * @param mixed $city_id
     */
    private function setCityId($city_id)
    {
        $this->city_id = $city_id;
        $this->city_country = substr($this->city_id, 0, 2);

        if(is_null($this->getLocale()))
        {
            $locales = [
                'BR' => 'pt_BR',
                'AR' => 'es_AR',
                'US' => 'en_US'
            ];

            if(array_key_exists(strtoupper($this->city_country), $locales))
                $this->locale = $locales[strtoupper($this->city_country)];
            else
                $this->locale = 'pt_BR';
        }
    }

    /**
     * @return string
     */
    private function getLocale()
    {
        return $this->locale;
    }


    /**
     * @param $locale
     */
    private function setLocale($locale)
    {
        $this->locale = $locale;
    }

    /**
     * @return array
     */
    private function getLocation()
    {
        $doc = phpQuery::newDocumentFileHTML('http://www.weather.com/pt-BR/clima/hoje/l/' . $this->getCityId() . ':1:' . $this->getCityCountry());
        $doc->find('link')->remove();
        $doc->find('meta')->remove();

        $html = $doc['head']->html();
        preg_match("/\window.explicit_location_obj = (.*);/", $html, $matche); 		//https://regex101.com/r/gQ7hA1/1
        $location = json_decode($matche[1]);

        $city_name = !empty($this->getCityName()) ? $this->getCityName() : $location->cityNm;

        $data = array(
            'city' => (string) S::create($city_name)->toLowerCase()->titleize(['da', 'de', 'do']),
        );

        return $data;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return mixed
     */
    public function getCityName()
    {
        return $this->city_name;
    }

    /**
     * @param mixed $city_name
     */
    public function setCityName($city_name)
    {
        $this->city_name = mb_check_encoding($city_name, 'UTF-8') ? $city_name : utf8_encode($city_name);
    }

    /**
     * @return mixed
     */
    public function getCityCountry()
    {
        return $this->city_country;
    }
}