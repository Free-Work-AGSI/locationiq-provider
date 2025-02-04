<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\LocationIQ;

use Geocoder\Collection;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\InvalidCredentials;
use Geocoder\Location;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Provider\Provider;
use Http\Client\HttpClient;

/**
 * @author Srihari Thalla <srihari@unwiredlabs.com>
 */
final class LocationIQ extends AbstractHttpProvider implements Provider
{
    /**
     * @var string
     */
    const BASE_API_URL = 'https://api.locationiq.com/v1';

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @param HttpClient $client an HTTP adapter
     * @param string $apiKey an API key
     */
    public function __construct(HttpClient $client, string $apiKey)
    {
        if (empty($apiKey)) {
            throw new InvalidCredentials('No API key provided.');
        }

        $this->apiKey = $apiKey;

        parent::__construct($client);
    }

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        if ($query->getData('autocomplete') == true) {
            return $this->autocompleteQuery($query);
        } else {
            return $this->searchQuery($query);
        }
    }

    /**
     * {@inheritdoc}
     */
    private function autocompleteQuery(GeocodeQuery $query): Collection
    {
        $address = $query->getText();
        $url = sprintf($this->getGeocodeAutocompleteEndpointUrl(), urlencode($address), $query->getLimit());
        $url = $this->addLocationIQParams($query, $url);

        $content = $this->executeQuery($url, $query->getLocale());
        $places = json_decode($content, true);
        $places = $this->filterPlaces($places);

        $results = [];
        foreach ($places as $place) {
            $results[] = $this->resultToArray($place);
        }

        return new AddressCollection($results);
    }

    private function filterPlaces(array $places): array
    {
        foreach ($places as $key => $place) {
            if ($place['class'] === 'place' && $place['type'] === 'suburb') {
                //La défense
                if ('La Défense' !== $place['address']['name'] ?? null && '92400' !==  $place['address']['postcode'] ?? null) {
                    unset($places[$key]);
                }
            }
        }

        return $places;
    }

    /**
     * {@inheritdoc}
     */
    private function searchQuery(GeocodeQuery $query): Collection
    {
        $address = $query->getText();
        $countrycodes = $query->getData('countrycodes');
        $tag = $query->getData('tag');
        $url = sprintf($this->getGeocodeSearchEndpointUrl(), urlencode($address), $query->getLimit(), $countrycodes, $tag);
        $url = $this->addLocationIQParams($query, $url);

        $content = $this->executeQuery($url, $query->getLocale());

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($content) || null === $doc->getElementsByTagName('searchresults')->item(0)) {
            throw InvalidServerResponse::create($url);
        }

        $searchResult = $doc->getElementsByTagName('searchresults')->item(0);
        $places = $searchResult->getElementsByTagName('place');

        if (null === $places || 0 === $places->length) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($places as $place) {
            $results[] = $this->xmlResultToArray($place, $place);
        }

        return new AddressCollection($results);
    }

    /**
     * {@inheritdoc}
     */
    public function reverseQuery(ReverseQuery $query): Collection
    {
        $coordinates = $query->getCoordinates();
        $longitude = $coordinates->getLongitude();
        $latitude = $coordinates->getLatitude();
        $url = sprintf($this->getReverseEndpointUrl(), $latitude, $longitude, $query->getData('zoom', 18));
        $content = $this->executeQuery($url, $query->getLocale());

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($content) || $doc->getElementsByTagName('error')->length > 0) {
            return new AddressCollection([]);
        }

        $searchResult = $doc->getElementsByTagName('reversegeocode')->item(0);
        $addressParts = $searchResult->getElementsByTagName('addressparts')->item(0);
        $result = $searchResult->getElementsByTagName('result')->item(0);

        return new AddressCollection([$this->xmlResultToArray($result, $addressParts)]);
    }

    /**
     * Add url param specific to LocationIQ
     * @param GeocodeQuery $query
     * @param string $url
     * @return string
     */
    private function addLocationIQParams(GeocodeQuery $query, string $url): string
    {
        if ($countrycodes = $query->getData('countrycodes')) {
            $url .= sprintf('&countrycodes=%s', $countrycodes);
        }

        if ($tag = $query->getData('tag')) {
            $url .= sprintf('&tag=%s', $tag);
        }

        if ($dedupe = $query->getData('dedupe')) {
            $url .= sprintf('&dedupe=%s', $dedupe);
        }

        if ($viewbox = $query->getData('viewbox')) {
            $url .= sprintf('&viewbox=%s', $viewbox);
        }

        return $url;
    }

    /**
     * @param array $arrayResult
     * @return Location
     */
    private function resultToArray(array $arrayResult): Location
    {
        $builder = new AddressBuilder($this->getName());

        $builder->setSubLocality($arrayResult['address']['suburb'] ?? null);
        $builder->setCountry($arrayResult['address']['country'] ?? null);
        $builder->setCoordinates($arrayResult['lat'], $arrayResult['lon']);

        if ($arrayResult['osm_type'] == 'way') {
            $builder->setStreetName($arrayResult['address']['name']);
            $builder->setStreetNumber($arrayResult['address']['house_number'] ?? null);
        }

        if ($countryCode = $arrayResult['address']['country_code']) {
            $builder->setCountryCode(strtoupper($countryCode));
        }

        if (!empty($arrayResult['boundingbox'])) {
            $builder->setBounds($arrayResult['boundingbox'][0], $arrayResult['boundingbox'][1], $arrayResult['boundingbox'][2], $arrayResult['boundingbox'][3]);
        }

        if (isset($arrayResult['address']['postcode']) && !empty($arrayResult['address']['postcode'])) {
            $builder->setPostalCode($arrayResult['address']['postcode']);
        }

        if (in_array($arrayResult['type'], ['state'])) {
            // state
            $adminLevel1 = $arrayResult['address']['name'] ?? null;
            if (null !== $adminLevel1) {
                $builder->addAdminLevel(1, $adminLevel1);
            }
        } elseif (in_array($arrayResult['type'], ['administrative'])) {
            // admin
            $adminLevel1 = $arrayResult['address']['state'] ?? null;
            if (null !== $adminLevel1) {
                $builder->addAdminLevel(1, $adminLevel1);
                $adminLevel2 = $arrayResult['address']['city'] ?? $arrayResult['address']['name'] ?? null;
                if (null !== $adminLevel2) {
                    $builder->addAdminLevel(2, $adminLevel2);
                }
            }
        } elseif (in_array($arrayResult['type'], ['city', 'town', 'village'])) {
            // city
            $builder->setLocality($arrayResult['address']['name']);
            $adminLevel1 = $arrayResult['address']['state'] ?? null;
            if (null !== $adminLevel1) {
                $builder->addAdminLevel(1, $adminLevel1);
                $adminLevel2 = $arrayResult['address']['county'] ?? $arrayResult['address']['city'] ?? null;
                if (null !== $adminLevel2) {
                    $builder->addAdminLevel(2, $adminLevel2);
                }
            }
        } else if ($arrayResult['class'] === 'landuse' && $arrayResult['type'] === 'commercial') {
            //Landuse Commercial (technopole)
            $builder->setLocality($arrayResult['address']['name']);
            $adminLevel1 = $arrayResult['address']['state'] ?? null;
            if (null !== $adminLevel1) {
                $builder->addAdminLevel(1, $adminLevel1);
                $adminLevel2 = $arrayResult['address']['county'] ?? null;
                if (null !== $adminLevel2) {
                    $builder->addAdminLevel(2, $adminLevel2);
                }
            }
        } else if ($arrayResult['class'] === 'place' && $arrayResult['type'] === 'suburb') {
            //suburb
            $builder->setLocality($arrayResult['address']['name']);
            $adminLevel1 = $arrayResult['address']['state'] ?? null;
            if (null !== $adminLevel1) {
                $builder->addAdminLevel(1, $adminLevel1);
                $adminLevel2 = $arrayResult['address']['county'] ?? null;
                if (null !== $adminLevel2) {
                    $builder->addAdminLevel(2, $adminLevel2);
                }
            }
        }

        return $builder->build();
    }

    /**
     * @param \DOMElement $resultNode
     * @param \DOMElement $addressNode
     *
     * @return Location
     */
    private function xmlResultToArray(\DOMElement $resultNode, \DOMElement $addressNode): Location
    {
        $builder = new AddressBuilder($this->getName());

        foreach (['state', 'county'] as $i => $tagName) {
            if (null !== ($adminLevel = $this->getNodeValue($addressNode->getElementsByTagName($tagName)))) {
                $builder->addAdminLevel($i + 1, $adminLevel, '');
            }
        }

        // get the first postal-code when there are many
        $postalCode = $this->getNodeValue($addressNode->getElementsByTagName('postcode'));
        if (!empty($postalCode)) {
            $postalCode = current(explode(';', $postalCode));
        }
        $builder->setPostalCode($postalCode);
        $builder->setStreetName($this->getNodeValue($addressNode->getElementsByTagName('road')) ?: $this->getNodeValue($addressNode->getElementsByTagName('pedestrian')));
        $builder->setStreetNumber($this->getNodeValue($addressNode->getElementsByTagName('house_number')));
        $builder->setLocality($this->getNodeValue($addressNode->getElementsByTagName('city')));
        $builder->setSubLocality($this->getNodeValue($addressNode->getElementsByTagName('suburb')));
        $builder->setCountry($this->getNodeValue($addressNode->getElementsByTagName('country')));
        $builder->setCoordinates($resultNode->getAttribute('lat'), $resultNode->getAttribute('lon'));

        $countryCode = $this->getNodeValue($addressNode->getElementsByTagName('country_code'));
        if (!is_null($countryCode)) {
            $builder->setCountryCode(strtoupper($countryCode));
        }

        $boundsAttr = $resultNode->getAttribute('boundingbox');
        if ($boundsAttr) {
            $bounds = [];
            list($bounds['south'], $bounds['north'], $bounds['west'], $bounds['east']) = explode(',', $boundsAttr);
            $builder->setBounds($bounds['south'], $bounds['north'], $bounds['west'], $bounds['east']);
        }

        return $builder->build();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'locationiq';
    }

    /**
     * @param string $url
     * @param string|null $locale
     *
     * @return string
     */
    private function executeQuery(string $url, string $locale = null): string
    {
        if (null !== $locale) {
            $url = sprintf('%s&accept-language=%s', $url, $locale);
        }

        return $this->getUrlContents($url);
    }

    private function getGeocodeSearchEndpointUrl(): string
    {
        return self::BASE_API_URL . '/search.php?q=%s&format=xmlv1.1&addressdetails=1&normalizecity=1&limit=%d&key=' . $this->apiKey;
    }

    private function getGeocodeAutocompleteEndpointUrl(): string
    {
        return self::BASE_API_URL . '/autocomplete.php?q=%s&addressdetails=1&normalizecity=1&limit=%d&key=' . $this->apiKey;
    }

    private function getReverseEndpointUrl(): string
    {
        return self::BASE_API_URL . '/reverse.php?format=xmlv1.1&lat=%F&lon=%F&addressdetails=1&normalizecity=1&zoom=%d&key=' . $this->apiKey;
    }

    private function getNodeValue(\DOMNodeList $element)
    {
        return $element->length ? $element->item(0)->nodeValue : null;
    }
}
