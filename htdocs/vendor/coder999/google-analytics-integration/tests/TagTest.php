<?php

declare(strict_types=1);

namespace Coder999\Ga4\Tests;

use InvalidArgumentException;
use Coder999\Ga4\Tag;
use PHPUnit\Framework\TestCase;

final class TagTest extends TestCase
{
    public function testRendersTheGtagSnippetForAValidId(): void
    {
        $html = Tag::render('G-ABC1234567');

        $this->assertStringContainsString(
            'https://www.googletagmanager.com/gtag/js?id=G-ABC1234567',
            $html
        );
        $this->assertStringContainsString("gtag('config', 'G-ABC1234567')", $html);
        $this->assertStringContainsString('window.dataLayer = window.dataLayer || []', $html);
    }

    public function testReturnsAnEmptyStringWhenUnconfigured(): void
    {
        $this->assertSame('', Tag::render(''));
        $this->assertSame('', Tag::render('   '));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertStringContainsString('id=G-ABC1234567', Tag::render('  G-ABC1234567  '));
    }

    public function testUppercasesALowercaseId(): void
    {
        // Measurement IDs are uppercase and case-sensitive. The validator
        // is forgiving (the `i` flag), but a lowercase ID rendered as-is
        // configures a tag that reports to no property -- the page looks
        // instrumented and nothing ever errors.
        $html = Tag::render('g-abc123');

        $this->assertStringContainsString('id=G-ABC123', $html);
        $this->assertStringContainsString("gtag('config', 'G-ABC123')", $html);
        $this->assertStringNotContainsString('g-abc123', $html);
    }

    public function testRejectsAnIdThatIsNotAMeasurementId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('measurement ID');
        Tag::render('123456789');
    }

    public function testRejectsAnIdContainingMarkupAndDoesNotEchoItInTheMessage(): void
    {
        $payload = "G-ABC'</script><script>alert(1)</script>";

        try {
            Tag::render($payload);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            // The throw path is reached precisely because $payload failed
            // validation, so it is arbitrary attacker-shaped text. The
            // docblock's "validated rather than escaped" claim is false on
            // this path unless the raw payload is kept out of the message.
            $this->assertStringNotContainsString($payload, $e->getMessage());
            $this->assertStringNotContainsString('<script>', $e->getMessage());
        }
    }
}
