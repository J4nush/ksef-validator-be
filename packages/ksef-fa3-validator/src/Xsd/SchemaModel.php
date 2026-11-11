<?php

declare(strict_types=1);

namespace KsefFa3\Xsd;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use KsefFa3\Xml\LibxmlGuard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SchemaModel
{
    private const string XSD = 'http://www.w3.org/2001/XMLSchema';

    private bool $loaded = false;

    /** @var array<string, DOMElement> */
    private array $globals = [];

    /** @var array<string, DOMElement> */
    private array $types = [];

    /** @var array<string, true> */
    private array $names = [];

    private function __construct(private readonly string $directory) {}

    public static function fromDirectory(string $directory): self
    {
        return new self($directory);
    }

    /**
     * @param  list<string>  $path
     * @return list<array{string, bool}>|null
     */
    public function children(array $path): ?array
    {
        $this->load();
        $declaration = $this->globals[array_shift($path) ?? ''] ?? null;
        $children = $declaration === null ? null : $this->childrenOf($declaration);

        foreach ($path as $name) {
            $next = null;

            foreach ($children ?? [] as [$childName, , $element]) {
                if ($childName === $name) {
                    $next = $element;
                    break;
                }
            }

            $children = $next === null ? null : $this->childrenOf($next);
        }

        return $children === null ? null : array_map(fn (array $child): array => [$child[0], $child[1]], $children);
    }

    public function declares(string $name): bool
    {
        $this->load();

        return isset($this->names[$name]);
    }

    /**
     * @return list<array{string, bool, DOMElement}>|null
     */
    private function childrenOf(DOMElement $declaration): ?array
    {
        $type = self::firstChild($declaration, 'complexType');

        if ($type === null && $declaration->hasAttribute('type')) {
            $type = $this->types[self::localName($declaration->getAttribute('type'))] ?? null;
        }

        return $type === null ? null : $this->particles($type, false);
    }

    /**
     * @return list<array{string, bool, DOMElement}>
     */
    private function particles(DOMElement $node, bool $optional): array
    {
        $particles = [];

        for ($child = $node->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $childOptional = $optional || $child->getAttribute('minOccurs') === '0';

            if ($child->localName === 'element') {
                $particles[] = [$child->getAttribute('name'), ! $childOptional, $child];

                continue;
            }

            $base = $child->localName === 'extension' ? $this->types[self::localName($child->getAttribute('base'))] ?? null : null;

            if ($base !== null) {
                $particles = [...$particles, ...$this->particles($base, $optional)];
            }

            if (in_array($child->localName, ['sequence', 'choice', 'all', 'complexContent', 'extension'], true)) {
                $particles = [...$particles, ...$this->particles($child, $childOptional)];
            }
        }

        return $particles;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        foreach (self::files($this->directory) as $file) {
            $document = new DOMDocument;
            [$parsed] = LibxmlGuard::run(fn (): bool => $document->loadXML((string) file_get_contents($file), LIBXML_NONET), $this->directory);

            if ($parsed) {
                $this->index($document);
            }
        }
    }

    private function index(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xsd', self::XSD);

        foreach ($xpath->query('/xsd:schema/xsd:element[@name]') ?: [] as $element) {
            if ($element instanceof DOMElement) {
                $this->globals[$element->getAttribute('name')] ??= $element;
            }
        }

        foreach ($xpath->query('/xsd:schema/xsd:complexType[@name]') ?: [] as $type) {
            if ($type instanceof DOMElement) {
                $this->types[$type->getAttribute('name')] ??= $type;
            }
        }

        foreach ($xpath->query('//xsd:element/@name') ?: [] as $name) {
            $this->names[$name->nodeValue ?? ''] = true;
        }
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'xsd') {
                $files[] = $file->getPathname();
            }
        }

        // Types from the top directory win over same named types in nested ones.
        usort($files, fn (string $a, string $b): int => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);

        return $files;
    }

    private static function firstChild(DOMElement $node, string $name): ?DOMElement
    {
        for ($child = $node->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if ($child->namespaceURI === self::XSD && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    private static function localName(string $qualifiedName): string
    {
        $colon = strrpos($qualifiedName, ':');

        return $colon === false ? $qualifiedName : substr($qualifiedName, $colon + 1);
    }
}
