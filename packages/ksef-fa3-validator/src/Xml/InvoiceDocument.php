<?php

declare(strict_types=1);

namespace KsefFa3\Xml;

use ArrayObject;
use DOMDocument;
use DOMElement;
use DOMXPath;
use SplObjectStorage;

final readonly class InvoiceDocument
{
    public const string FA3_NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    private DOMXPath $xpath;

    /** @var SplObjectStorage<DOMElement, string> */
    private SplObjectStorage $steps;

    /** @var ArrayObject<string, list<DOMElement>> */
    private ArrayObject $lines;

    public function __construct(public DOMDocument $document)
    {
        $this->steps = new SplObjectStorage;
        /** @var ArrayObject<string, list<DOMElement>> $lines */
        $lines = new ArrayObject;
        $this->lines = $lines;
        $this->xpath = new DOMXPath($document);
        $this->xpath->registerNamespace('fa', self::FA3_NAMESPACE);
    }

    /**
     * @return list<DOMElement>
     */
    public function nodes(string $xpath): array
    {
        $elements = [];

        foreach ($this->xpath->query($xpath) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    public function first(string $xpath): ?DOMElement
    {
        return $this->nodes($xpath)[0] ?? null;
    }

    public function fieldPath(DOMElement $element): string
    {
        $steps = [];

        for ($node = $element; ($parent = $node->parentNode) instanceof DOMElement; $node = $parent) {
            if (! $this->steps->offsetExists($node)) {
                $this->indexChildren($parent);
            }

            $steps[] = $this->steps[$node];
        }

        return implode('/', array_reverse($steps));
    }

    public function fieldOf(DOMElement $element): string
    {
        $path = $this->fieldPath($element);

        return $path === '' ? (string) $element->localName : $path;
    }

    /**
     * @return list<DOMElement>
     */
    public function elementsAt(int $line, string $localName, ?string $namespace): array
    {
        if ($this->lines->count() === 0) {
            $this->indexLines();
        }

        return $this->lines[self::lineKey($line, $localName, $namespace)] ?? [];
    }

    public function hasAttachment(): bool
    {
        return $this->first('/fa:Faktura/fa:Zalacznik') !== null;
    }

    private function indexChildren(DOMElement $parent): void
    {
        $counts = [];
        $children = [];

        for ($child = $parent->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $namespace = (string) $child->namespaceURI;
            $name = (string) $child->localName;
            $counts[$namespace][$name] = ($counts[$namespace][$name] ?? 0) + 1;
            $children[] = [$child, $namespace, $name, $counts[$namespace][$name]];
        }

        foreach ($children as [$child, $namespace, $name, $position]) {
            $this->steps[$child] = $counts[$namespace][$name] > 1 ? "{$name}[{$position}]" : $name;
        }
    }

    private function indexLines(): void
    {
        $lines = [];

        foreach ($this->document->getElementsByTagName('*') as $element) {
            $lines[self::lineKey($element->getLineNo(), (string) $element->localName, $element->namespaceURI)][] = $element;
        }

        $this->lines->exchangeArray($lines);
    }

    private static function lineKey(int $line, string $localName, ?string $namespace): string
    {
        return $line.'|'.($namespace ?? '').'|'.$localName;
    }
}
