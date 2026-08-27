<?php

namespace axenox\AtlassianConnector\Common;

/**
 * Converts a controlled Markdown subset to Atlassian Document Format nodes.
 *
 * Unsupported Markdown remains visible as plain text instead of being dropped
 * or interpreted as HTML. Heading levels are shifted below the managed parent
 * section so converted content cannot escape that section.
 */
class MarkdownToAdfConverter
{
    /**
     * Converts Markdown into top-level ADF block nodes.
     *
     * @param string $markdown
     * @param int $parentHeadingLevel
     * @return array
     */
    public function convert(string $markdown, int $parentHeadingLevel): array
    {
        $lines = preg_split('/\R/', trim($markdown)) ?: [];
        $headingOffset = $this->getHeadingOffset($lines, $parentHeadingLevel);
        $nodes = [];

        for ($index = 0, $lineCount = count($lines); $index < $lineCount;) {
            $line = $lines[$index];
            if (trim($line) === '') {
                $index++;
                continue;
            }

            // Parse fenced code blocks, including an optional language identifier.
            if (preg_match('/^\s*```([^`]*)$/', $line, $match) === 1) {
                $nodes[] = $this->parseCodeBlock($lines, $index, trim($match[1]));
                continue;
            }
            // Parse md-style tables into ADF table, row, header, and cell nodes.
            if ($this->isTableStart($lines, $index)) {
                $nodes[] = $this->parseTable($lines, $index);
                continue;
            }
            // Parse markdown headings and shift them below the managed Jira section.
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $match) === 1) {
                $level = min(6, strlen($match[1]) + $headingOffset);
                if ($level > $parentHeadingLevel) {
                    $nodes[] = [
                        'type' => 'heading',
                        'attrs' => ['level' => $level],
                        'content' => $this->parseInline(trim($match[2]))
                    ];
                } else {
                    $nodes[] = $this->buildParagraph($line);
                }
                $index++;
                continue;
            }
            // Parse Markdown horizontal rules as ADF rules.
            if (preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1) {
                $nodes[] = ['type' => 'rule'];
                $index++;
                continue;
            }
            // Parse consecutive blockquote lines recursively as nested ADF blocks.
            if (preg_match('/^\s*>\s?(.*)$/', $line) === 1) {
                $nodes[] = $this->parseBlockquote($lines, $index, $parentHeadingLevel);
                continue;
            }
            // Parse unordered, ordered, and md-style checklist items as ADF lists.
            if ($this->matchListItem($line) !== null) {
                $nodes[] = $this->parseList($lines, $index);
                continue;
            }

            // Parse all remaining lines as paragraphs with inline Markdown marks.
            $nodes[] = $this->parseParagraph($lines, $index);
        }

        return $nodes;
    }

    /**
     * Shifts the highest Markdown heading one level below the managed section.
     *
     * @param array $lines
     * @param int $parentHeadingLevel
     * @return int
     */
    private function getHeadingOffset(array $lines, int $parentHeadingLevel): int
    {
        $minimumLevel = null;
        $inCodeBlock = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                $inCodeBlock = ! $inCodeBlock;
                continue;
            }
            if (! $inCodeBlock && preg_match('/^(#{1,6})\s+/', $line, $match) === 1) {
                $level = strlen($match[1]);
                $minimumLevel = $minimumLevel === null ? $level : min($minimumLevel, $level);
            }
        }
        return $minimumLevel === null ? 0 : $parentHeadingLevel + 1 - $minimumLevel;
    }

    /**
     * Parses a fenced code block, preserving unterminated fences as code content.
     *
     * @param array $lines
     * @param int $index
     * @param string $language
     * @return array
     */
    private function parseCodeBlock(array $lines, int &$index, string $language): array
    {
        $index++;
        $code = [];
        while ($index < count($lines) && preg_match('/^\s*```\s*$/', $lines[$index]) !== 1) {
            $code[] = $lines[$index++];
        }
        if ($index < count($lines)) {
            $index++;
        }
        $node = [
            'type' => 'codeBlock',
            'content' => $code === [] ? [] : [['type' => 'text', 'text' => implode("\n", $code)]]
        ];
        if ($language !== '') {
            $node['attrs'] = ['language' => $language];
        }
        return $node;
    }

    /**
     * Parses consecutive quote lines into an ADF blockquote.
     *
     * @param array $lines
     * @param int $index
     * @param int $parentHeadingLevel
     * @return array
     */
    private function parseBlockquote(array $lines, int &$index, int $parentHeadingLevel): array
    {
        $quotedLines = [];
        while ($index < count($lines) && preg_match('/^\s*>\s?(.*)$/', $lines[$index], $match) === 1) {
            $quotedLines[] = $match[1];
            $index++;
        }
        return [
            'type' => 'blockquote',
            'content' => $this->convert(implode("\n", $quotedLines), min(5, $parentHeadingLevel + 1))
        ];
    }

    /**
     * Parses a contiguous list, including md-style task items.
     *
     * @param array $lines
     * @param int $index
     * @return array
     */
    private function parseList(array $lines, int &$index): array
    {
        $first = $this->matchListItem($lines[$index]);
        $ordered = $first['ordered'];
        $taskList = ! $ordered && $first['task'] !== null;
        $items = [];
        $start = $first['number'] ?? 1;

        while ($index < count($lines)) {
            $item = $this->matchListItem($lines[$index]);
            if ($item === null || $item['ordered'] !== $ordered || (! $ordered && ($item['task'] !== null) !== $taskList)) {
                break;
            }
            // Jira does not accept taskList/taskItem consistently in issue descriptions.
            $text = $taskList ? '[' . ($item['task'] ? 'x' : ' ') . '] ' . $item['text'] : $item['text'];
            $items[] = ['type' => 'listItem', 'content' => [$this->buildParagraph($text)]];
            $index++;
        }

        if ($taskList) {
            return ['type' => 'bulletList', 'content' => $items];
        }
        $node = ['type' => $ordered ? 'orderedList' : 'bulletList', 'content' => $items];
        if ($ordered && $start !== 1) {
            $node['attrs'] = ['order' => $start];
        }
        return $node;
    }

    /**
     * Recognizes one flat Markdown list item.
     *
     * @param string $line
     * @return array|null
     */
    private function matchListItem(string $line): ?array
    {
        if (preg_match('/^\s*([-+*])\s+(?:\[([ xX])\]\s+)?(.*)$/', $line, $match) === 1) {
            return [
                'ordered' => false,
                'number' => null,
                'task' => isset($match[2]) && $match[2] !== '' ? strtolower($match[2]) === 'x' : null,
                'text' => $match[3]
            ];
        }
        if (preg_match('/^\s*(\d+)[.)]\s+(.*)$/', $line, $match) === 1) {
            return [
                'ordered' => true,
                'number' => (int) $match[1],
                'task' => null,
                'text' => $match[2]
            ];
        }
        return null;
    }

    /**
     * Determines whether the current and next lines form a Markdown table header.
     *
     * @param array $lines
     * @param int $index
     * @return bool
     */
    private function isTableStart(array $lines, int $index): bool
    {
        return isset($lines[$index + 1])
            && strpos($lines[$index], '|') !== false
            && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*$/', $lines[$index + 1]) === 1;
    }

    /**
     * Parses a markdown table into ADF table rows and cells.
     *
     * @param array $lines
     * @param int $index
     * @return array
     */
    private function parseTable(array $lines, int &$index): array
    {
        $headers = $this->splitTableRow($lines[$index]);
        $index += 2;
        $rows = [$this->buildTableRow($headers, true)];
        while ($index < count($lines) && trim($lines[$index]) !== '' && strpos($lines[$index], '|') !== false) {
            $cells = array_pad($this->splitTableRow($lines[$index]), count($headers), '');
            $rows[] = $this->buildTableRow(array_slice($cells, 0, count($headers)), false);
            $index++;
        }
        return [
            'type' => 'table',
            'content' => $rows
        ];
    }

    /**
     * Splits a table row while retaining escaped pipe characters in cell text.
     *
     * @param string $line
     * @return array
     */
    private function splitTableRow(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line);
        $line = preg_replace('/\|$/', '', $line);
        $placeholder = "\0PIPE\0";
        $line = str_replace('\\|', $placeholder, $line);
        return array_map(function (string $cell) use ($placeholder): string {
            return str_replace($placeholder, '|', trim($cell));
        }, explode('|', $line));
    }

    /**
     * Builds an ADF table row from Markdown cell values.
     *
     * @param array $cells
     * @param bool $header
     * @return array
     */
    private function buildTableRow(array $cells, bool $header): array
    {
        $content = [];
        foreach ($cells as $cell) {
            $content[] = [
                'type' => $header ? 'tableHeader' : 'tableCell',
                'content' => [$this->buildParagraph($cell)]
            ];
        }
        return ['type' => 'tableRow', 'content' => $content];
    }

    /**
     * Collects ordinary lines into one paragraph with ADF hard breaks.
     *
     * @param array $lines
     * @param int $index
     * @return array
     */
    private function parseParagraph(array $lines, int &$index): array
    {
        $paragraphLines = [];
        while ($index < count($lines) && trim($lines[$index]) !== '') {
            if ($paragraphLines !== [] && $this->startsBlock($lines, $index)) {
                break;
            }
            $paragraphLines[] = $lines[$index++];
        }
        $content = [];
        foreach ($paragraphLines as $lineIndex => $line) {
            if ($lineIndex > 0) {
                $content[] = ['type' => 'hardBreak'];
            }
            array_push($content, ...$this->parseInline($line));
        }
        return ['type' => 'paragraph', 'content' => $content];
    }

    /**
     * Checks whether a line starts a supported block construct.
     *
     * @param array $lines
     * @param int $index
     * @return bool
     */
    private function startsBlock(array $lines, int $index): bool
    {
        $line = $lines[$index];
        return preg_match('/^\s*```/', $line) === 1
            || preg_match('/^(#{1,6})\s+/', $line) === 1
            || preg_match('/^\s*>/', $line) === 1
            || preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1
            || $this->matchListItem($line) !== null
            || $this->isTableStart($lines, $index);
    }

    /**
     * Builds an ADF paragraph and parses supported inline Markdown.
     *
     * @param string $text
     * @return array
     */
    private function buildParagraph(string $text): array
    {
        $content = $this->parseInline($text);
        return $content === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $content];
    }

    /**
     * Converts common inline Markdown and leaves every unmatched fragment as text.
     *
     * @param string $text
     * @return array
     */
    private function parseInline(string $text): array
    {
        // One ordered expression preserves unmatched Markdown as ordinary ADF text nodes.
        $pattern = '/(`+)(.+?)\1|!\[([^\]]*)\]\(([^)]+)\)|\[([^\]]+)\]\(([^)]+)\)|\*\*(.+?)\*\*|__(.+?)__|~~(.+?)~~|(?<!\*)\*([^*]+)\*(?!\*)|(?<!_)_([^_]+)_(?!_)/';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $nodes = [];
        $offset = 0;
        foreach ($matches as $match) {
            $matchedText = $match[0][0];
            $matchOffset = $match[0][1];
            if ($matchOffset > $offset) {
                $nodes[] = ['type' => 'text', 'text' => substr($text, $offset, $matchOffset - $offset)];
            }
            if ($match[1][1] >= 0) {
                $nodes[] = $this->buildMarkedText($match[2][0], 'code');
            } elseif ($match[3][1] >= 0) {
                // Images are unsupported in ADF without an uploaded media ID, so retain readable Markdown text.
                $nodes[] = ['type' => 'text', 'text' => $matchedText];
            } elseif ($match[5][1] >= 0) {
                $nodes[] = [
                    'type' => 'text',
                    'text' => $match[5][0],
                    'marks' => [['type' => 'link', 'attrs' => ['href' => $match[6][0]]]]
                ];
            } elseif ($match[7][1] >= 0 || $match[8][1] >= 0) {
                $nodes[] = $this->buildMarkedText($match[7][1] >= 0 ? $match[7][0] : $match[8][0], 'strong');
            } elseif ($match[9][1] >= 0) {
                $nodes[] = $this->buildMarkedText($match[9][0], 'strike');
            } else {
                $value = $match[10][1] >= 0 ? $match[10][0] : $match[11][0];
                $nodes[] = $this->buildMarkedText($value, 'em');
            }
            $offset = $matchOffset + strlen($matchedText);
        }
        if ($offset < strlen($text)) {
            $nodes[] = ['type' => 'text', 'text' => substr($text, $offset)];
        }
        return $nodes;
    }

    /**
     * Builds one ADF text node with a mark.
     *
     * @param string $text
     * @param string $mark
     * @return array
     */
    private function buildMarkedText(string $text, string $mark): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => [['type' => $mark]]];
    }
}