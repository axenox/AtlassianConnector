<?php

namespace axenox\AtlassianConnector\Formulas;

use exface\Core\CommonLogic\Model\Formula;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\DataTypes\DataTypeCastingError;
use exface\Core\Factories\DataTypeFactory;

/**
 * Converts an Atlassian Document Format (ADF) document to Markdown.
 *
 * Use `=axenox.AtlassianConnector.AdfToMarkdown(DESCRIPTION)` to convert a
 * Jira description as semantic Markdown. Completed task items are rendered
 * as `- [x]` instead of strikethrough text.
 */
class AdfToMarkdown extends Formula
{
    /**
     * Converts an ADF JSON string to Markdown.
     *
     * @param string|null $adfJson
     * @return string
     */
    public function run(?string $adfJson = null): string
    {
        if ($adfJson === null || trim($adfJson) === '') {
            return '';
        }

        try {
            $document = JsonDataType::decodeJson($adfJson, true);
        } catch (DataTypeCastingError $e) {
            return '';
        }
        if (! is_array($document)) {
            return '';
        }

        return trim($this->renderNode($document));
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\Formula::getDataType()
     */
    public function getDataType()
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }

    /**
     * @param array $node
     * @param int $listDepth
     * @return string
     */
    private function renderNode(array $node, int $listDepth = 0): string
    {
        $type = $node['type'] ?? '';
        $content = $this->renderChildren($node, $listDepth);

        switch ($type) {
            case 'doc':
                return $content;
            case 'text':
                return $this->renderText((string) ($node['text'] ?? ''), $node['marks'] ?? []);
            case 'paragraph':
                return $content . "\n\n";
            case 'heading':
                $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 1)));
                return str_repeat('#', $level) . ' ' . trim($content) . "\n\n";
            case 'hardBreak':
                return "  \n";
            case 'rule':
                return "---\n\n";
            case 'blockquote':
                return $this->prefixLines(trim($content), '> ') . "\n\n";
            case 'codeBlock':
                $language = (string) ($node['attrs']['language'] ?? '');
                return '```' . $language . "\n" . rtrim($content) . "\n```\n\n";
            case 'bulletList':
                return $this->renderList($node, false, $listDepth) . ($listDepth === 0 ? "\n" : '');
            case 'orderedList':
                return $this->renderList($node, true, $listDepth) . ($listDepth === 0 ? "\n" : '');
            case 'taskList':
                return $this->renderTaskList($node, $listDepth) . ($listDepth === 0 ? "\n" : '');
            case 'taskItem':
                $checked = strtoupper((string) ($node['attrs']['state'] ?? '')) === 'DONE';
                return '- [' . ($checked ? 'x' : ' ') . '] ' . trim($content) . "\n";
            case 'listItem':
                return trim($content);
            case 'mention':
                return (string) ($node['attrs']['text'] ?? $node['attrs']['displayName'] ?? '@unknown');
            case 'emoji':
                return (string) ($node['attrs']['text'] ?? $node['attrs']['shortName'] ?? '');
            case 'status':
                return '[' . (string) ($node['attrs']['text'] ?? '') . ']';
            case 'inlineCard':
            case 'blockCard':
            case 'embedCard':
                $url = (string) ($node['attrs']['url'] ?? '');
                return $url === '' ? $content : '<' . $url . '>';
            case 'panel':
                $panelType = strtoupper((string) ($node['attrs']['panelType'] ?? 'info'));
                return '> **' . $panelType . ':** ' . str_replace("\n", "\n> ", trim($content)) . "\n\n";
            case 'table':
                return $this->renderTable($node) . "\n";
            case 'media':
                return $this->renderMedia($node);
            default:
                return $content;
        }
    }

    /**
     * @param array $node
     * @param int $listDepth
     * @return string
     */
    private function renderChildren(array $node, int $listDepth = 0): string
    {
        $result = '';
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $result .= $this->renderNode($child, $listDepth);
            }
        }
        return $result;
    }

    /**
     * @param string $text
     * @param array $marks
     * @return string
     */
    private function renderText(string $text, array $marks): string
    {
        foreach ($marks as $mark) {
            $type = $mark['type'] ?? '';
            switch ($type) {
                case 'strong':
                    $text = '**' . $text . '**';
                    break;
                case 'em':
                    $text = '*' . $text . '*';
                    break;
                case 'strike':
                    $text = '~~' . $text . '~~';
                    break;
                case 'code':
                    $text = '`' . str_replace('`', '\\`', $text) . '`';
                    break;
                case 'link':
                    $href = (string) ($mark['attrs']['href'] ?? '');
                    if ($href !== '') {
                        $text = '[' . $text . '](' . $href . ')';
                    }
                    break;
                case 'subsup':
                    $tag = ($mark['attrs']['type'] ?? '') === 'sub' ? 'sub' : 'sup';
                    $text = '<' . $tag . '>' . $text . '</' . $tag . '>';
                    break;
            }
        }
        return $text;
    }

    /**
     * @param array $node
     * @param bool $ordered
     * @param int $listDepth
     * @return string
     */
    private function renderList(array $node, bool $ordered, int $listDepth): string
    {
        $result = '';
        $number = (int) ($node['attrs']['order'] ?? 1);
        foreach ($node['content'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemContent = trim($this->renderChildren($item, $listDepth + 1));
            $prefix = $ordered ? $number++ . '. ' : '- ';
            $result .= str_repeat('  ', $listDepth) . $prefix
                . $this->indentContinuationLines($itemContent, $listDepth + 1) . "\n";
        }
        return $result;
    }

    /**
     * @param array $node
     * @param int $listDepth
     * @return string
     */
    private function renderTaskList(array $node, int $listDepth): string
    {
        $result = '';
        foreach ($node['content'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $checked = strtoupper((string) ($item['attrs']['state'] ?? '')) === 'DONE';
            $content = trim($this->renderChildren($item, $listDepth + 1));
            $result .= str_repeat('  ', $listDepth) . '- [' . ($checked ? 'x' : ' ') . '] '
                . $this->indentContinuationLines($content, $listDepth + 1) . "\n";
        }
        return $result;
    }

    /**
     * @param array $node
     * @return string
     */
    private function renderTable(array $node): string
    {
        $rows = [];
        foreach ($node['content'] ?? [] as $row) {
            $cells = [];
            foreach ($row['content'] ?? [] as $cell) {
                $cells[] = str_replace(["\r", "\n", '|'], ['', '<br>', '\\|'], trim($this->renderChildren($cell)));
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return '';
        }

        $columnCount = max(array_map('count', $rows));
        $rows = array_map(function (array $row) use ($columnCount): array {
            return array_pad($row, $columnCount, '');
        }, $rows);
        $markdown = '| ' . implode(' | ', $rows[0]) . " |\n";
        $markdown .= '| ' . implode(' | ', array_fill(0, $columnCount, '---')) . " |\n";
        foreach (array_slice($rows, 1) as $row) {
            $markdown .= '| ' . implode(' | ', $row) . " |\n";
        }
        return $markdown;
    }

    /**
     * @param array $node
     * @return string
     */
    private function renderMedia(array $node): string
    {
        $attrs = $node['attrs'] ?? [];
        $url = (string) ($attrs['url'] ?? '');
        $alt = (string) ($attrs['alt'] ?? $attrs['id'] ?? 'attachment');
        return $url === '' ? '[' . $alt . ']' : '![' . $alt . '](' . $url . ')';
    }

    /**
     * @param string $text
     * @param string $prefix
     * @return string
     */
    private function prefixLines(string $text, string $prefix): string
    {
        return $prefix . str_replace("\n", "\n" . $prefix, $text);
    }

    /**
     * @param string $text
     * @param int $depth
     * @return string
     */
    private function indentContinuationLines(string $text, int $depth): string
    {
        return str_replace("\n", "\n" . str_repeat('  ', $depth), $text);
    }
}