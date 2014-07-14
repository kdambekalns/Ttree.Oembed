<?php
namespace Ttree\Oembed;

/*                                                                        *
 * This script belongs to the Flow package "Ttree.Oembed".                *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use Ttree\Oembed\Resource\AbstractResource;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cache\Frontend\AbstractFrontend;
use TYPO3\Flow\Log\SystemLoggerInterface;
use TYPO3\Flow\Utility\Arrays;

/**
 * oEmbed Consumer
 *
 * @author  Romain Ruetschi <romain.ruetschi@gmail.com>
 * @author  Dominique Feyer <dfeyer@ttree.ch>
 */
class Consumer {

	/**
	 * JSON format.
	 */
	const FORMAT_JSON = 'json';

	/**
	 * XML format.
	 */
	const FORMAT_XML = 'xml';

	/**
	 * Default format.
	 */
	const FORMAT_DEFAULT = self::FORMAT_JSON;

	/**
	 * oEmbed version.
	 */
	const VERSION = '1.0';

	/**
	 * @Flow\Inject
	 * @var Browser
	 */
	protected $browser;

	/**
	 * @var AbstractFrontend
	 */
	protected $resourceCache;

	/**
	 * @Flow\Inject
	 * @var ResourceFactory
	 */
	protected $resourceFactory;

	/**
	 * Providers
	 *
	 * @var Provider[]
	 */
	protected $providers = array();

	/**
	 * @var RequestParameters
	 */
	protected $requestParameters = NULL;

	/**
	 * @Flow\Inject
	 * @var SystemLoggerInterface
	 */
	protected $systemLogger;

	/**
	 * Set the available providers.
	 *
	 * @param array $providers
	 * @return \Ttree\Oembed\Consumer
	 */
	public function setProviders(array $providers) {
		$this->providers = $providers;

		return $this;
	}

	/**
	 * Consume an oEmbed resource using the specified provider if supplied
	 * or try to discover the right one.
	 *
	 * @param  string   $url                  The URL of the resource to consume.
	 * @param  Provider $provider             The provider to use.
	 * @param  string   $format               The format of the data to fetch.
	 * @return AbstractResource An object representation of the oEmbed resource or NULL on error
	 */
	public function consume($url, Provider $provider = NULL, $format = self::FORMAT_DEFAULT) {
		if ($this->requestParameters instanceof RequestParameters) {
			$cacheKey = sha1($url . json_encode($this->requestParameters->toArray()));
		} else {
			$cacheKey = sha1($url);
		}

		// Check if the resource is cached
		if ($this->resourceCache->has($cacheKey)) {
			return $this->resourceCache->get($cacheKey);
		}

		try {
			// Try to find a provider matching the supplied URL if no one has been supplied.
			if (!$provider) {
				$provider = $this->findProviderForUrl($url);
			}

			if ($provider instanceof Provider) {
				// If a provider was supplied or we found one, store the endpoint URL.
				$endPoint = $provider->getEndpoint();
			} else {
				// If no provider was found, try to discover the endpoint URL.
				$discover = new Discoverer();
				$endPoint = $discover->getEndpointForUrl($url);
			}

			$requestUrl = $this->buildOEmbedRequestUrl($url, $endPoint, $format);
			$content = $this->browser->getContent($requestUrl);

			$methodName = 'process' . ucfirst(strtolower($format)) . 'Response';

			$resource = $this->$methodName($content);

			// Save the resource in cache
			$this->resourceCache->set($cacheKey, $resource);
		} catch (Exception $exception) {
			$this->systemLogger->logException($exception);
			$resource = NULL;
		}

		return $this->postProcessResource($resource);
	}

	/**
	 * Process the JSON response returned by the provider.
	 *
	 * @param string $response The JSON data returned by the provider.
	 * @return AbstractResource
	 */
	protected function processJsonResponse($response) {
		return $this->resourceFactory->create(json_decode($response));
	}

	/**
	 * Process the XML response returned by the provider.
	 *
	 * @param string $response The XML data returned by the provider.
	 * @return AbstractResource
	 */
	protected function processXmlResponse($response) {
		return $this->resourceFactory->create(simplexml_load_string($response));
	}

	/**
	 * @param string $resource
	 * @return string
	 */
	protected function postProcessResource($resource) {
		if (empty($resource)) {
			return NULL;
		}

		return $resource;
	}

	/**
	 * Build the oEmbed request URL according to the specification.
	 *
	 * @param string $resource The URL of the resource to fetch.
	 * @param string $endPoint The provider endpoint URL
	 * @param string $format   The format of the response we'd like to receive.
	 * @return string
	 */
	protected function buildOEmbedRequestUrl($resource, $endPoint, $format = self::FORMAT_DEFAULT) {
		$parameters = array(
			'url'    => $resource,
			'format' => $format,
			'version' => self::VERSION
		);

		if ($this->getRequestParameters() !== NULL) {
			$parameters = Arrays::arrayMergeRecursiveOverrule($this->getRequestParameters()->toArray(), $parameters);
		}

		$urlParams = http_build_query($parameters, '', '&');
		$url       = $endPoint . ((strpos($endPoint, '?') !== FALSE) ? '&' : '?') . $urlParams;

		return $url;
	}

	/**
	 * Find an oEmbed provider matching the supplied URL.
	 *
	 * @param  string $url The URL to find an oEmbed provider for.
	 * @return \Ttree\Oembed\Provider
	 */
	protected function findProviderForUrl($url) {
		foreach ($this->providers as $provider) {
			if ($provider->match($url)) {
				return $provider;
			}
		}

		return NULL;
	}

	/**
	 * @param RequestParameters $requestParameters
	 */
	public function setRequestParameters(RequestParameters $requestParameters) {
		$this->requestParameters = $requestParameters;
	}

	/**
	 * @return RequestParameters
	 */
	public function getRequestParameters() {
		return $this->requestParameters;
	}
}

?>