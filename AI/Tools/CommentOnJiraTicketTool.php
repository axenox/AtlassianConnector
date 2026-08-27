<?php
namespace axenox\AtlassianConnector\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataConnectionFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use GuzzleHttp\Psr7\Request;

/**
 * AI tool that lets an LLM add a comment to a Jira ticket through a configured connection.
 *
 * Requires `axenox/genai` (optional/suggested dependency of this app) - only relevant
 * once this class is actually referenced as an agent tool.
 *
 * Configure `connection_alias` with an HTTP connection using `AtlassianClientCredentials`.
 * Restrict which tickets may be commented on via `allowed_key_prefixes` (e.g. project keys)
 * or `allowed_key_pattern` (a regular expression) - at least one of them should be set,
 * since neither is required by this class and an unrestricted tool can comment on any ticket
 * visible to the connection's Jira identity.
 *
 * ## Example
 *
 * ```
 *  {
 *      "comment_on_jira_ticket": {
 *          "alias": "axenox.AtlassianConnector.CommentOnJiraTicketTool",
 *          "description": "Adds a comment to a Jira ticket.",
 *          "connection_alias": "your.App.JiraConnection",
 *          "allowed_key_prefixes": ["ABC-", "DEF-"]
 *      }
 *  }
 *
 * ```
 *
 * @author saskia.hustinx
 */
class CommentOnJiraTicketTool extends AbstractAiTool
{
    public const ARG_KEY = 'key';

    public const ARG_COMMENT = 'comment';

    private ?string $connectionAlias = null;

    /**
     * @var string[]
     */
    private array $allowedKeyPrefixes = [];

    private ?string $allowedKeyPattern = null;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $key = trim((string) ($arguments[0] ?? ''));
        $comment = trim((string) ($arguments[1] ?? ''));

        if ($key === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: key');
        }
        if ($comment === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: comment');
        }
        if ($this->connectionAlias === null) {
            throw new AiToolRuntimeError($this, $prompt, 'No Jira connection configured. Set `connection_alias` on the tool.');
        }
        // Fail closed: without an explicit allow-list, no ticket may be commented on.
        if (! $this->isKeyAllowed($key)) {
            throw new AiToolRuntimeError($this, $prompt, 'Commenting on ticket "' . $key . '" is not allowed by this tool\'s configuration.');
        }

        try {
            $connection = DataConnectionFactory::createFromModel($this->getWorkbench(), $this->connectionAlias);
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to load the configured Jira connection: ' . $e->getMessage(), null, $e);
        }
        if (! $connection instanceof HttpConnectionInterface) {
            throw new AiToolRuntimeError($this, $prompt, 'The configured Jira connection must be an HTTP connection.');
        }

        try {
            $body = json_encode([
                'body' => [
                    'type' => 'doc',
                    'version' => 1,
                    'content' => $this->buildAdfParagraphs($comment)
                ]
            ], JSON_THROW_ON_ERROR);

            $response = $connection->sendRequest(new Request(
                'POST',
                'issue/' . rawurlencode($key) . '/comment',
                ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                $body
            ));
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to add a comment to Jira ticket "' . $key . '": ' . $e->getMessage(), null, $e);
        }
        if ($response === null) {
            throw new AiToolRuntimeError($this, $prompt, 'Jira returned no response while adding a comment to ticket "' . $key . '".');
        }
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            $responseBody = (string) $response->getBody();
            throw new AiToolRuntimeError($this, $prompt, 'Jira rejected the comment for "' . $key . '": HTTP ' . $statusCode . ' - ' . substr($responseBody, 0, 500));
        }

        return new AiToolResultString(
            $this,
            $arguments,
            'Comment added to Jira ticket "' . $key . '".',
            $this->getReturnDataType()
        );
    }

    /**
     * Converts plain text into ADF paragraph nodes, splitting on blank lines.
     *
     * @param string $text
     * @return array
     */
    protected function buildAdfParagraphs(string $text): array
    {
        $blocks = preg_split('/\R{2,}/', $text) ?: [$text];
        $content = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => $block]
                ]
            ];
        }
        if ($content === []) {
            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => $text]
                ]
            ];
        }
        return $content;
    }

    /**
     * @param string $key
     * @return bool
     */
    protected function isKeyAllowed(string $key): bool
    {
        if ($this->allowedKeyPattern !== null && preg_match($this->allowedKeyPattern, $key) === 1) {
            return true;
        }
        foreach ($this->allowedKeyPrefixes as $prefix) {
            if ($prefix !== '' && StringDataType::startsWith($key, $prefix, false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The Jira data connection to comment through.
     *
     * @uxon-property connection_alias
     * @uxon-type metamodel:connection
     * @uxon-required true
     *
     * @param string $value
     * @return CommentOnJiraTicketTool
     */
    public function setConnectionAlias(string $value): CommentOnJiraTicketTool
    {
        $this->connectionAlias = $value;
        return $this;
    }

    /**
     * Only allow commenting on tickets whose key starts with one of these project prefixes.
     *
     * @uxon-property allowed_key_prefixes
     * @uxon-type string[]
     * @uxon-template ["PROJECT-"]
     *
     * @param UxonObject $value
     * @return CommentOnJiraTicketTool
     */
    public function setAllowedKeyPrefixes(UxonObject $value): CommentOnJiraTicketTool
    {
        $prefixes = [];
        foreach ($value->toArray() as $prefix) {
            if (! is_string($prefix)) {
                throw new InvalidArgumentException('Every `allowed_key_prefixes` entry must be a string.');
            }
            $prefix = trim($prefix);
            if ($prefix === '') {
                throw new InvalidArgumentException('Empty entries are not allowed in `allowed_key_prefixes`.');
            }
            $prefixes[] = $prefix;
        }
        $this->allowedKeyPrefixes = array_values(array_unique($prefixes));
        return $this;
    }

    /**
     * Alternative/addition to `allowed_key_prefixes`: a regular expression the ticket key must match.
     *
     * @uxon-property allowed_key_pattern
     * @uxon-type string
     * @uxon-template /^PROJECT-\d+$/
     *
     * @param string $value
     * @return CommentOnJiraTicketTool
     */
    public function setAllowedKeyPattern(string $value): CommentOnJiraTicketTool
    {
        $this->allowedKeyPattern = $value;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getRules()
     */
    public function getRules(): ?string
    {
        if ($this->allowedKeyPrefixes === [] && $this->allowedKeyPattern === null) {
            return '- No Jira ticket keys are currently allowed. Do not call this tool.';
        }

        $rules = [];
        if ($this->allowedKeyPrefixes !== []) {
            $rules[] = '- Only comment on Jira ticket keys starting with one of these prefixes: "' . implode('", "', $this->allowedKeyPrefixes) . '".';
        }
        if ($this->allowedKeyPattern !== null) {
            $rules[] = '- Jira ticket keys matching this regular expression are also allowed: `' . $this->allowedKeyPattern . '`.';
        }
        $rules[] = '- Do not call this tool for a Jira ticket key outside the configured restrictions.';
        return implode("\n", $rules);
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_KEY)
                ->setDescription('The Jira issue key to comment on, e.g. "ABC-123".')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName(self::ARG_COMMENT)
                ->setDescription('The comment text to add to the ticket. Blank lines separate paragraphs.')
                ->setRequired(true)
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), StringDataType::class);
    }
}
