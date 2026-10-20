<?php
namespace axenox\AtlassianConnector\Common;

use exface\Core\DataTypes\JsonDataType;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use GuzzleHttp\Psr7\Request;

/**
 * Reads and updates Jira issue labels through a connection configured for the issue REST API.
 */
class JiraIssueLabelClient
{
    private HttpConnectionInterface $connection;

    /**
     * @param HttpConnectionInterface $connection Authenticated Jira connection with a base URL for relative issue paths.
     */
    public function __construct(HttpConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Checks the issue's current labels; an API error is not treated as a missing label.
     *
     * @throws \RuntimeException If Jira rejects the read or returns invalid labels.
     */
    public function hasLabel(string $issueKey, string $label) : bool
    {
        $response = $this->connection->sendRequest(new Request(
            'GET',
            'issue/' . rawurlencode($issueKey) . '?fields=labels',
            ['Accept' => 'application/json']
        ));
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Reading labels for Jira issue "' . $issueKey . '" failed with HTTP ' . $response->getStatusCode());
        }

        $payload = JsonDataType::decodeJson((string) $response->getBody(), true);
        $labels = $payload['fields']['labels'] ?? null;
        if (! is_array($labels)) {
            throw new \RuntimeException('Jira issue "' . $issueKey . '" returned invalid labels.');
        }

        return in_array($label, $labels, true);
    }

    /**
     * Adds one label without replacing the issue's other labels.
     *
     * @throws \RuntimeException If Jira rejects the update.
     */
    public function addLabel(string $issueKey, string $label) : void
    {
        $response = $this->connection->sendRequest(new Request(
            'PUT',
            'issue/' . rawurlencode($issueKey),
            ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            JsonDataType::encodeJson(['update' => ['labels' => [['add' => $label]]]])
        ));
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Adding label to Jira issue "' . $issueKey . '" failed with HTTP ' . $response->getStatusCode() . ': ' . substr((string) $response->getBody(), 0, 500));
        }
    }
}