<?php
namespace axenox\AtlassianConnector\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\Behaviors\FileBehavior;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSources\FileDownloadConnectionInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Returns one authorized Jira attachment as a native GenAI tool-result file.
 *
 * A DataSheet read returns attachment metadata, not file bytes. This tool
 * verifies attachment membership before downloading the file through the Jira
 * connection. It never accepts a URL from the model.
 */
class ReadJiraAttachmentTool extends AbstractAiTool
{
    private const ATTACHMENT_OBJECT = 'axenox.AtlassianConnector.JIRA_ATTACHMENT';
    private const SUPPORTED_MIME_TYPES = ['image/png', 'image/jpeg', 'application/pdf'];

    private array $allowedKeyPrefixes = [];

    /**
     * Resolves the issue key and attachment ID and returns the downloaded file with its Jira provenance.
     */
    public function invoke(AiTaskHandlerInterface $agent, TaskInterface $prompt, array $arguments): AiToolResultInterface
    {
        $key = $arguments[0] ?? null;
        $id = $arguments[1] ?? null;
        if (! is_string($key) || preg_match('/\A([A-Z][A-Z0-9_]*)-[1-9][0-9]*\z/', $key, $matches) !== 1) {
            throw new AiToolRuntimeError($this, $prompt, 'Expected an exact Jira issue key, e.g. ABC-123.');
        }
        if (! is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
            throw new AiToolRuntimeError($this, $prompt, 'Expected a Jira attachment ID as a string of digits.');
        }
        if (! in_array($matches[1] . '-', $this->allowedKeyPrefixes, true)) {
            throw new AiToolRuntimeError($this, $prompt, 'The Jira project is not allowed by this tool.');
        }

        $attachments = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), self::ATTACHMENT_OBJECT);
        $object = $attachments->getMetaObject();
        $behaviors = $object->getBehaviors()->getByPrototypeClass(FileBehavior::class);
        $behavior = $behaviors->isEmpty() ? null : $behaviors->getFirst();
        $connection = $object->getDataConnection();
        $mimeAttribute = $behavior instanceof FileBehavior ? $behavior->getMimeTypeAttribute() : null;
        if (! $behavior instanceof FileBehavior || ! $behavior->getContentsAreUrls()
            || ! $connection instanceof FileDownloadConnectionInterface
            || $mimeAttribute === null
        ) {
            throw new AiToolRuntimeError($this, $prompt, 'Jira attachment file downloads are not configured.');
        }

        // Use the attributes configured by FileBehavior rather than assuming fixed metadata columns.
        $filenameAlias = $behavior->getFilenameAttribute()->getAlias();
        $mimeAlias = $mimeAttribute->getAlias();
        $contentAlias = $behavior->getContentsAttribute()->getAlias();
        $attachments->getColumns()->addMultiple(['id', $filenameAlias, $mimeAlias, $contentAlias]);
        // Scope the query by issue and match the ID locally. A UID filter can select the
        // standalone attachment endpoint, which does not establish issue membership.
        $attachments->getFilters()->addConditionFromString('issue_key', $key, ComparatorDataType::EQUALS);
        $attachments->dataRead();

        foreach ($attachments->getRows() as $row) {
            $rowId = $row['id'] ?? null;
            if ((! is_string($rowId) && ! is_int($rowId)) || (string) $rowId !== $id) {
                continue;
            }
            $mimeType = $row[$mimeAlias] ?? null;
            if (! in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
                throw new AiToolRuntimeError($this, $prompt, 'Only PNG, JPEG and PDF Jira attachments are supported.');
            }
            $filename = $row[$filenameAlias] ?? null;
            $url = $row[$contentAlias] ?? null;
            if (! is_string($filename) || trim($filename) === '' || ! is_string($url) || trim($url) === '') {
                throw new AiToolRuntimeError($this, $prompt, 'The Jira attachment is missing its filename or download address.');
            }

            // Reuse the file behavior's size limit and download from the verified row, not from model input.
            $maxBytes = (int) floor(($behavior->getMaxFileSizeInMb() ?? 5) * 1024 * 1024);
            $file = $connection->downloadFile($url, $filename, $mimeType, $maxBytes);
            if (! $file instanceof FileInfoInterface) {
                throw new AiToolRuntimeError($this, $prompt, 'The Jira connection did not return an attachable file.');
            }

            // The text identifies the source; a compatible adapter sends the file separately.
            return new AiToolResultString(
                $this->getWorkbench(),
                'File provided for Jira issue ' . $key . ', attachment ' . $id . ': ' . $filename,
                $this->getReturnDataType(),
                attachments: [$file]
            );
        }

        throw new AiToolRuntimeError($this, $prompt, 'The attachment was not found on the specified Jira issue or is not accessible.');
    }

    /**
     * Allowed Jira project prefixes, including the final hyphen. An empty list denies all reads.
     *
     * @uxon-property allowed_key_prefixes
     * @uxon-type string[]
     * @uxon-template ["ABC-"]
     * @uxon-required true
     */
    protected function setAllowedKeyPrefixes(UxonObject $value): self
    {
        $prefixes = $value->toArray();
        foreach ($prefixes as $prefix) {
            if (! is_string($prefix) || preg_match('/\A[A-Z][A-Z0-9_]*-\z/', $prefix) !== 1) {
                throw new InvalidArgumentException('Each `allowed_key_prefixes` entry must be a Jira project prefix such as ABC-.');
            }
        }
        $this->allowedKeyPrefixes = $prefixes;
        return $this;
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName('issue_key')
                ->setDescription('The exact Jira issue key used to list the attachment, e.g. ABC-123.')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName('attachment_id')
                ->setDescription('The attachment ID from that issue\'s attachment list, as a string of digits.')
                ->setRequired(true)
        ];
    }

    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), StringDataType::class);
    }
}
