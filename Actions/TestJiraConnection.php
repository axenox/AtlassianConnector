<?php
namespace axenox\AtlassianConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\Factories\DataConnectionFactory;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use GuzzleHttp\Psr7\Request;

/**
 * Tests a Jira HTTP connection by calling a configured REST endpoint.
 *
 * Configure `connection_alias` with an HTTP connection that uses
 * `AtlassianClientCredentials`. The default `myself` endpoint verifies both
 * token acquisition and access to Jira. Set `url` to test another endpoint;
 * the action returns its HTTP status and, optionally, a truncated response.
 *
 * @author saskia.hustinx
 */
class TestJiraConnection extends AbstractAction
{
    private $connectionAlias = null;

    private $url = 'myself';

    private $showResponse = true;

    private $responseMaxLength = 2000;

    /**
     * {@inheritDoc}
     */
    protected function init()
    {
        parent::init();
    }

    /**
    * The configured Jira data connection to test.
     *
     * @uxon-property connection_alias
     * @uxon-type metamodel:connection
     * @uxon-required true
     *
     * @param string $value
    * @return TestJiraConnection
     */
    public function setConnectionAlias(string $value): TestJiraConnection
    {
        $this->connectionAlias = $value;
        return $this;
    }

    /**
     * The relative Jira REST endpoint to call.
     *
     * @uxon-property url
     * @uxon-type string
     * @uxon-default myself
     *
     * @param string $value
    * @return TestJiraConnection
     */
    public function setUrl(string $value): TestJiraConnection
    {
        $this->url = $value;
        return $this;
    }

    /**
    * Include the response body in the result message.
     *
     * @uxon-property show_response
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
    * @return TestJiraConnection
     */
    public function setShowResponse(bool $value): TestJiraConnection
    {
        $this->showResponse = $value;
        return $this;
    }

    /**
     * Maximum number of response characters included in the result message.
     *
     * @uxon-property response_max_length
     * @uxon-type integer
     * @uxon-default 2000
     *
     * @param int $value
    * @return TestJiraConnection
     */
    public function setResponseMaxLength(int $value): TestJiraConnection
    {
        $this->responseMaxLength = max(0, $value);
        return $this;
    }

    /**
    * Calls Jira through the configured connection and returns its status and response.
     *
     * @param TaskInterface $task
     * @param DataTransactionInterface $transaction
     * @return ResultInterface
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction): ResultInterface
    {
        if ($this->connectionAlias === null) {
            throw new ActionConfigurationError($this, 'No Jira connection configured. Set `connection_alias` on the action.');
        }

        $connection = DataConnectionFactory::createFromModel($this->getWorkbench(), $this->connectionAlias);
        if (! $connection instanceof HttpConnectionInterface) {
            throw new ActionConfigurationError($this, 'The configured Jira connection must be an HTTP connection.');
        }

        $response = $connection->sendRequest(new Request('GET', $this->url, [
            'Accept' => 'application/json'
        ]));
        $statusCode = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        if ($statusCode >= 400) {
            throw new ActionRuntimeError($this, 'Jira returned HTTP ' . $statusCode . ': ' . substr($responseBody, 0, 500));
        }

        $responseData = json_decode($responseBody, true);
        $resultMessage = 'Jira connection succeeded with HTTP ' . $statusCode . '.';
        if ($this->showResponse && $this->responseMaxLength > 0) {
            $formattedResponse = is_array($responseData)
                ? json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : $responseBody;
            $resultMessage .= "\n\nResponse:\n" . substr($formattedResponse, 0, $this->responseMaxLength);
        }

        return ResultFactory::createMessageResult(
            $task,
            $resultMessage
        );
    }
}