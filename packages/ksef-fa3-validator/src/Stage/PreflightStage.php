<?php

declare(strict_types=1);

namespace KsefFa3\Stage;

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Layer;
use KsefFa3\Xml\LineLocator;

final readonly class PreflightStage implements Stage
{
    public const int MAX_BYTES = 3_000_000;

    private const string UTF8_BOM = "\xEF\xBB\xBF";

    private const string XML_WHITESPACE = " \t\r\n";

    private const string PROLOG_ENCODING = '/^<\?xml[^>]*encoding\s*=\s*["\']([^"\']+)["\']/i';

    private const string DISCOURAGED_CHARACTERS = '/[\x{7F}-\x{84}\x{86}-\x{9F}\x{FDD0}-\x{FDEF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}\x{8FFFE}\x{8FFFF}\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}\x{10FFFE}\x{10FFFF}]/u';

    public function __construct(private FindingFactory $findings) {}

    public function layer(): Layer
    {
        return Layer::Security;
    }

    public function process(ValidationContext $context): StageOutcome
    {
        $bytes = $context->bytes;
        $hasBom = str_starts_with($bytes, self::UTF8_BOM);
        $body = $hasBom ? substr($bytes, strlen(self::UTF8_BOM)) : $bytes;

        if (trim($body, self::XML_WHITESPACE) === '') {
            return $this->stop($context, 'XML-EMPTY');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            return $this->stop($context, 'SEC-SIZE-LIMIT');
        }

        // ASCII in UTF-16 without a BOM is valid UTF-8 full of NUL bytes, which XML 1.0 forbids.
        if (! mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) {
            return $this->stop($context, 'TECH-ENCODING-UTF8');
        }

        if ($hasBom) {
            $context->add($this->findings->create($this->layer(), 'TECH-UTF8-BOM'));
        }

        if (preg_match(self::PROLOG_ENCODING, $body, $prolog) === 1 && strcasecmp($prolog[1], 'UTF-8') !== 0) {
            return $this->stop($context, 'TECH-PROLOG-ENCODING');
        }

        if (stripos($bytes, '<!DOCTYPE') !== false) {
            return $this->stop($context, 'SEC-DOCTYPE');
        }

        $this->reportDiscouragedCharacters($context);

        return StageOutcome::Continue;
    }

    private function stop(ValidationContext $context, string $id): StageOutcome
    {
        $context->add($this->findings->create($this->layer(), $id));

        return StageOutcome::Stop;
    }

    private function reportDiscouragedCharacters(ValidationContext $context): void
    {
        if (preg_match(self::DISCOURAGED_CHARACTERS, $context->bytes, $first, PREG_OFFSET_CAPTURE) === 1) {
            $context->add($this->findings->create(
                $this->layer(),
                'TECH-DISCOURAGED-CHAR',
                line: LineLocator::lineAt($context->bytes, $first[0][1]),
                params: ['count' => (int) preg_match_all(self::DISCOURAGED_CHARACTERS, $context->bytes)],
            ));
        }
    }
}
